<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/verify-release.php';

final class ReleaseVerifierTest extends TestCase
{
    private const VERSION = '0.9.0';

    private string $temporary_directory;

    protected function setUp(): void
    {
        $this->temporary_directory = sys_get_temp_dir() . '/dstk-release-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary_directory, 0700));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->temporary_directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporary_directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->temporary_directory);
    }

    public function test_valid_release_archive_is_accepted(): void
    {
        $archive = $this->create_archive();

        \dstk_verify_release_archive($archive, self::VERSION);

        self::addToAssertionCount(1);
    }

    public function test_archive_metadata_versions_must_match(): void
    {
        $archive = $this->create_archive(
            [
                'dr-slon-toolkit.php' => $this->main_file('0.9.1'),
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must match');

        \dstk_verify_release_archive($archive, self::VERSION);
    }

    public function test_unsafe_archive_paths_are_rejected(): void
    {
        $unsafe_names = [
            'outside/file.php',
            'dr-slon-toolkit/../escape.php',
            'dr-slon-toolkit//absolute.php',
            'dr-slon-toolkit/path\\escape.php',
            'dr-slon-toolkit/C:/escape.php',
            'dr-slon-toolkit/src//duplicate.php',
            'dr-slon-toolkit/CON.php',
            'dr-slon-toolkit/trailing.',
        ];

        foreach ($unsafe_names as $name) {
            try {
                \dstk_release_safe_relative_path($name, 'dr-slon-toolkit/');
                self::fail('Unsafe path was accepted: ' . $name);
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_archive_symlink_is_rejected(): void
    {
        $archive_path = $this->create_archive(['linked-file' => 'target']);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($archive_path));
        self::assertTrue(
            $archive->setExternalAttributesName(
                'dr-slon-toolkit/linked-file',
                \ZipArchive::OPSYS_UNIX,
                0120777 << 16
            )
        );
        self::assertTrue($archive->close());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');

        \dstk_verify_release_archive($archive_path, self::VERSION);
    }

    public function test_case_ambiguous_archive_entries_are_rejected(): void
    {
        $archive = $this->create_archive(
            [
                'src/Example.php' => '<?php',
                'src/example.php' => '<?php',
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate or ambiguous');

        \dstk_verify_release_archive($archive, self::VERSION);
    }

    public function test_oversized_build_archive_is_rejected_before_opening(): void
    {
        $archive_path = $this->archive_path();
        $stream = fopen($archive_path, 'w+b');
        self::assertIsResource($stream);
        self::assertTrue(ftruncate($stream, \DSTK_RELEASE_MAX_ARCHIVE_SIZE + 1));
        self::assertTrue(fclose($stream));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('size is outside');

        \dstk_verify_release_archive($archive_path, self::VERSION);
    }

    /**
     * @param array<string,string> $overrides
     */
    private function create_archive(array $overrides = []): string
    {
        $files = array_replace(
            [
                'LICENSE' => 'GPL-2.0-or-later',
                'assets/admin/info-panel.css' => '.info {}',
                'assets/admin/settings.css' => '.settings {}',
                'dr-slon-toolkit.php' => $this->main_file(self::VERSION),
                'readme.txt' => "=== Dr.Slon Toolkit ===\nStable tag: " . self::VERSION . "\n",
                'src/Core/Plugin.php' => '<?php',
                'src/Integrations/GitHubReleaseUpdater.php' => '<?php',
                'uninstall.php' => '<?php',
                'vendor/autoload.php' => '<?php',
            ],
            $overrides
        );
        $archive = new \ZipArchive();
        $archive_path = $this->archive_path();
        self::assertTrue($archive->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($archive->addEmptyDir('dr-slon-toolkit'));

        foreach ($files as $file => $contents) {
            self::assertTrue($archive->addFromString('dr-slon-toolkit/' . $file, $contents));
        }

        self::assertTrue($archive->close());

        return $archive_path;
    }

    private function archive_path(): string
    {
        return $this->temporary_directory . '/dr-slon-toolkit-' . self::VERSION . '.zip';
    }

    private function main_file(string $constant_version): string
    {
        return "<?php\n/**\n * Version: " . self::VERSION . "\n * Update URI: https://github.com/A-Krivoshen/dr-slon-toolkit\n */\nconst DSTK_VERSION = '" . $constant_version . "';\n";
    }
}
