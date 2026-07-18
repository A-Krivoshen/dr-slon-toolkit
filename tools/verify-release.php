<?php

declare(strict_types=1);

const DSTK_RELEASE_MAX_ARCHIVE_SIZE = 52428800;
const DSTK_RELEASE_MAX_UNCOMPRESSED_SIZE = 209715200;
const DSTK_RELEASE_MAX_ENTRIES = 10000;

function dstk_release_assert_version(string $version): void
{
    if (preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version) !== 1) {
        throw new RuntimeException('Invalid release version: ' . $version);
    }
}

function dstk_release_metadata_value(string $pattern, string $contents, string $label): string
{
    $count = preg_match_all($pattern, $contents, $matches);

    if ($count !== 1 || ! isset($matches[1][0])) {
        throw new RuntimeException('Unable to determine a unique ' . $label . '.');
    }

    return trim((string) $matches[1][0]);
}

function dstk_release_assert_metadata(string $main_file, string $readme, string $version): void
{
    dstk_release_assert_version($version);

    $header_version = dstk_release_metadata_value('/^[ \t]*\* Version:\s*([^\r\n]+)/m', $main_file, 'plugin header version');
    $constant_version = dstk_release_metadata_value(
        '/^[ \t]*const[ \t]+DSTK_VERSION[ \t]*=[ \t]*[\'\"]([^\'\"]+)[\'\"][ \t]*;[ \t]*$/m',
        $main_file,
        'DSTK_VERSION value'
    );
    $stable_version = dstk_release_metadata_value('/^Stable tag:\s*([^\r\n]+)/mi', $readme, 'readme stable tag');
    $update_uri = dstk_release_metadata_value('/^[ \t]*\* Update URI:\s*([^\r\n]+)/m', $main_file, 'plugin Update URI');

    if ($header_version !== $version || $constant_version !== $version || $stable_version !== $version) {
        throw new RuntimeException('Plugin header, DSTK_VERSION, readme stable tag, and release version must match.');
    }

    if ($update_uri !== 'https://github.com/A-Krivoshen/dr-slon-toolkit') {
        throw new RuntimeException('Plugin Update URI is missing or invalid.');
    }
}

function dstk_release_source_version(string $source_directory): string
{
    $source_directory = rtrim($source_directory, '/\\');
    $main_file = file_get_contents($source_directory . '/dr-slon-toolkit.php');
    $readme = file_get_contents($source_directory . '/readme.txt');

    if (! is_string($main_file) || ! is_string($readme)) {
        throw new RuntimeException('Unable to read plugin source metadata.');
    }

    $version = dstk_release_metadata_value('/^[ \t]*\* Version:\s*([^\r\n]+)/m', $main_file, 'plugin header version');
    dstk_release_assert_metadata($main_file, $readme, $version);

    return $version;
}

function dstk_release_safe_relative_path(string $name, string $prefix): string
{
    if (! str_starts_with($name, $prefix)) {
        throw new RuntimeException('Archive entry is outside the plugin root: ' . $name);
    }

    if (str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        throw new RuntimeException('Archive contains an unsafe path: ' . $name);
    }

    $relative = substr($name, strlen($prefix));

    if ($relative === '') {
        return '';
    }

    $path = str_ends_with($relative, '/') ? substr($relative, 0, -1) : $relative;

    if ($path === '') {
        throw new RuntimeException('Archive contains an unsafe path: ' . $name);
    }

    foreach (explode('/', $path) as $segment) {
        $base_name = strtoupper(explode('.', $segment, 2)[0]);

        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || str_contains($segment, ':')
            || str_ends_with($segment, '.')
            || str_ends_with($segment, ' ')
            || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/', $base_name) === 1
        ) {
            throw new RuntimeException('Archive contains an unsafe path: ' . $name);
        }
    }

    return $relative;
}

function dstk_verify_release_archive(string $archive_path, string $version): void
{
    dstk_release_assert_version($version);

    if (basename($archive_path) !== 'dr-slon-toolkit-' . $version . '.zip') {
        throw new RuntimeException('Release archive filename does not match the release version.');
    }

    clearstatcache(true, $archive_path);
    $archive_size = is_file($archive_path) ? filesize($archive_path) : false;

    if (! is_int($archive_size) || $archive_size < 1 || $archive_size > DSTK_RELEASE_MAX_ARCHIVE_SIZE) {
        throw new RuntimeException('Release archive size is outside the allowed range.');
    }

    $prefix = 'dr-slon-toolkit/';
    $archive = new ZipArchive();

    if ($archive->open($archive_path) !== true) {
        throw new RuntimeException('Unable to open release archive: ' . $archive_path);
    }

    try {
        if ($archive->numFiles < 1 || $archive->numFiles > DSTK_RELEASE_MAX_ENTRIES) {
            throw new RuntimeException('Release archive contains an invalid number of entries.');
        }

        $names = [];
        $files = [];
        $uncompressed_size = 0;

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            $name = $archive->getNameIndex($index);

            if (! is_string($name)) {
                throw new RuntimeException('Unable to read an archive entry name.');
            }

            $relative = dstk_release_safe_relative_path($name, $prefix);
            $normalized_name = strtolower(rtrim($relative, '/'));

            if (isset($names[$normalized_name])) {
                throw new RuntimeException('Archive contains a duplicate or ambiguous entry: ' . $name);
            }

            $names[$normalized_name] = true;
            $stat = $archive->statIndex($index);

            if (! is_array($stat) || ! isset($stat['size']) || ! is_int($stat['size']) || $stat['size'] < 0) {
                throw new RuntimeException('Unable to determine archive entry size: ' . $name);
            }

            $uncompressed_size += $stat['size'];

            if ($stat['size'] > DSTK_RELEASE_MAX_UNCOMPRESSED_SIZE || $uncompressed_size > DSTK_RELEASE_MAX_UNCOMPRESSED_SIZE) {
                throw new RuntimeException('Release archive expands beyond the allowed size.');
            }

            if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new RuntimeException('Release archive contains an encrypted entry: ' . $name);
            }

            $operations = 0;
            $attributes = 0;

            if (
                $archive->getExternalAttributesIndex($index, $operations, $attributes)
                && $operations === ZipArchive::OPSYS_UNIX
            ) {
                $file_type = ($attributes >> 16) & 0170000;

                if ($file_type === 0120000) {
                    throw new RuntimeException('Archive contains a symbolic link: ' . $name);
                }

                if ($file_type !== 0 && $file_type !== 0040000 && $file_type !== 0100000) {
                    throw new RuntimeException('Archive contains a special file: ' . $name);
                }
            }

            if ($relative !== '' && ! str_ends_with($relative, '/')) {
                $files[$relative] = true;
            }
        }

        $required = [
            'LICENSE',
            'assets/admin/info-panel.css',
            'assets/admin/settings.css',
            'dr-slon-toolkit.php',
            'readme.txt',
            'src/Core/Plugin.php',
            'src/Integrations/GitHubReleaseUpdater.php',
            'uninstall.php',
            'vendor/autoload.php',
        ];

        foreach ($required as $file) {
            if (! isset($files[$file])) {
                throw new RuntimeException('Required release file is missing: ' . $file);
            }
        }

        foreach (array_keys($files) as $file) {
            if (
                str_starts_with($file, 'tests/')
                || str_starts_with($file, '.git')
                || str_starts_with($file, '.phpunit')
                || in_array($file, ['composer.json', 'composer.lock', 'AGENTS.md', 'phpcs.xml.dist', 'phpunit.xml.dist'], true)
            ) {
                throw new RuntimeException('Development file found in release: ' . $file);
            }
        }

        $main_file = $archive->getFromName($prefix . 'dr-slon-toolkit.php');
        $readme = $archive->getFromName($prefix . 'readme.txt');

        if (! is_string($main_file) || ! is_string($readme)) {
            throw new RuntimeException('Unable to read release metadata files.');
        }

        dstk_release_assert_metadata($main_file, $readme, $version);
    } finally {
        $archive->close();
    }
}

function dstk_release_cli(array $arguments): int
{
    if (count($arguments) === 3 && $arguments[1] === '--source') {
        fwrite(STDOUT, dstk_release_source_version((string) $arguments[2]) . "\n");
        return 0;
    }

    if (count($arguments) === 3) {
        $archive_path = (string) $arguments[1];
        $version = (string) $arguments[2];
        dstk_verify_release_archive($archive_path, $version);
        fwrite(STDOUT, 'Release archive verified: ' . $archive_path . "\n");
        return 0;
    }

    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/verify-release.php --source <source-directory>\n");
    fwrite(STDERR, "  php tools/verify-release.php <archive.zip> <version>\n");
    return 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(dstk_release_cli($argv));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
