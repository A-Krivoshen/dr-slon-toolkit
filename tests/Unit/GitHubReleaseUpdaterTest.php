<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Integrations\GitHubReleaseUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubReleaseUpdaterTest extends TestCase
{
    private const PLUGIN_FILE = '/var/www/wp-content/plugins/dr-slon-toolkit/dr-slon-toolkit.php';
    private const PLUGIN_BASENAME = 'dr-slon-toolkit/dr-slon-toolkit.php';
    private const UPDATE_URI = 'https://github.com/A-Krivoshen/dr-slon-toolkit';

    /** @var list<string> */
    private array $temporary_files = [];

    protected function setUp(): void
    {
        $GLOBALS['dstk_test_site_transients'] = [];
        $GLOBALS['dstk_test_transient_expirations'] = [];
        $GLOBALS['dstk_test_http_response'] = new \WP_Error('http_error', 'HTTP unavailable');
        $GLOBALS['dstk_test_http_calls'] = 0;
        $GLOBALS['dstk_test_download_result'] = null;
        $GLOBALS['dstk_test_deleted_files'] = [];
        $GLOBALS['wp_filesystem'] = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_valid_release_asset_is_accepted(): void
    {
        $release = GitHubReleaseUpdater::parse_release_payload($this->payload('release-package'));

        self::assertNotNull($release);
        self::assertSame('0.9.0', $release['version']);
        self::assertSame(hash('sha256', 'release-package'), $release['sha256']);
        self::assertSame(strlen('release-package'), $release['asset_size']);
        self::assertStringEndsWith('/dr-slon-toolkit-0.9.0.zip', $release['package_url']);
    }

    public function test_unexpected_asset_metadata_is_rejected(): void
    {
        $payload = $this->payload('release-package');
        $payload['assets'][0]['browser_download_url'] = self::UPDATE_URI . '/archive/refs/tags/v0.9.0.zip';
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));

        $payload = $this->payload('release-package');
        $payload['assets'][0]['digest'] = 'sha256:not-a-digest';
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));

        $payload = $this->payload('release-package');
        $payload['assets'][0]['size'] = (string) strlen('release-package');
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));

        $payload = $this->payload('release-package');
        $payload['assets'][0]['size'] = 52428801;
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));
    }

    public function test_prerelease_and_non_semver_tags_are_rejected(): void
    {
        $payload = $this->payload('release-package');
        $payload['prerelease'] = true;
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));

        $payload = $this->payload('release-package');
        $payload['tag_name'] = 'latest';
        self::assertNull(GitHubReleaseUpdater::parse_release_payload($payload));
    }

    public function test_update_offer_binds_all_package_metadata(): void
    {
        $offer = $this->store_offer('trusted-package');

        self::assertSame('0.9.0', $offer['version']);
        self::assertSame('v0.9.0', $offer['dstk_release_tag']);
        self::assertSame(self::UPDATE_URI . '/releases/tag/v0.9.0', $offer['url']);
        self::assertSame(self::UPDATE_URI . '/releases/download/v0.9.0/dr-slon-toolkit-0.9.0.zip', $offer['package']);
        self::assertSame(hash('sha256', 'trusted-package'), $offer['dstk_release_sha256']);
        self::assertSame(strlen('trusted-package'), $offer['dstk_release_size']);
    }

    public function test_prior_filter_file_is_verified_for_single_upgrade_shape(): void
    {
        $file = $this->temporary_file('trusted-package');
        $offer = $this->store_offer('trusted-package');
        $updater = $this->updater();

        $result = $updater->verify_download(
            $file,
            $offer['package'],
            null,
            ['type' => 'plugin', 'plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($file, $result);
        self::assertFileExists($file);
    }

    public function test_prior_filter_file_is_verified_for_bulk_shape_without_type(): void
    {
        $file = $this->temporary_file('trusted-package');
        $offer = $this->store_offer('trusted-package');
        $updater = $this->updater();

        $result = $updater->verify_download(
            $file,
            $offer['package'],
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($file, $result);
    }

    public function test_plugin_list_shape_without_type_is_recognized(): void
    {
        $file = $this->temporary_file('trusted-package');
        $offer = $this->store_offer('trusted-package');

        $result = $this->updater()->verify_download(
            $file,
            $offer['package'],
            null,
            ['plugins' => ['another/plugin.php', self::PLUGIN_BASENAME]]
        );

        self::assertSame($file, $result);
    }

    public function test_explicit_non_target_plugin_is_not_mistaken_for_bulk_target(): void
    {
        $reply = 'leave-this-reply-alone';

        $result = $this->updater()->verify_download(
            $reply,
            'https://example.com/other.zip',
            null,
            [
                'plugin'  => 'another/plugin.php',
                'plugins' => [self::PLUGIN_BASENAME],
            ]
        );

        self::assertSame($reply, $result);
    }

    public function test_prior_wp_error_is_preserved(): void
    {
        $error = new \WP_Error('earlier_filter', 'Earlier filter failed');

        $result = $this->updater()->verify_download(
            $error,
            'not-even-a-package',
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($error, $result);
    }

    public function test_prior_filter_file_with_wrong_digest_is_rejected_but_not_deleted(): void
    {
        $file = $this->temporary_file('tampered-package');
        $offer = $this->store_offer('expected-package');

        self::assertSame(strlen('tampered-package'), strlen('expected-package'));

        $result = $this->updater()->verify_download(
            $file,
            $offer['package'],
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('dstk_update_digest', $result->get_error_code());
        self::assertFileExists($file);
        self::assertSame([], $GLOBALS['dstk_test_deleted_files']);
    }

    public function test_downloaded_file_with_wrong_size_is_rejected_and_deleted(): void
    {
        $file = $this->temporary_file('wrong-size');
        $offer = $this->store_offer('expected-package');
        $GLOBALS['dstk_test_download_result'] = $file;

        $result = $this->updater()->verify_download(
            false,
            $offer['package'],
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('dstk_update_size', $result->get_error_code());
        self::assertFileDoesNotExist($file);
        self::assertSame([$file], $GLOBALS['dstk_test_deleted_files']);
    }

    public function test_mutating_any_bound_offer_metadata_invalidates_the_package(): void
    {
        $file = $this->temporary_file('trusted-package');
        $offer = $this->store_offer('trusted-package');
        $mutations = [
            'version'             => ['0.9.1', 'dstk_update_package'],
            'dstk_release_tag'    => ['v0.9.1', 'dstk_update_package'],
            'url'                 => [self::UPDATE_URI . '/releases/tag/v0.9.1', 'dstk_update_package'],
            'package'             => [self::UPDATE_URI . '/releases/download/v0.9.1/dr-slon-toolkit-0.9.1.zip', 'dstk_update_package'],
            'dstk_release_sha256' => [str_repeat('b', 64), 'dstk_update_digest'],
            'dstk_release_size'   => [strlen('trusted-package') + 1, 'dstk_update_size'],
        ];

        foreach ($mutations as $field => [$value, $error_code]) {
            $mutated = $offer;
            $mutated[$field] = $value;
            $this->set_update_offer($mutated);

            $result = $this->updater()->verify_download(
                $file,
                $offer['package'],
                null,
                ['plugin' => self::PLUGIN_BASENAME]
            );

            self::assertInstanceOf(\WP_Error::class, $result, 'Mutation was accepted for ' . $field);
            self::assertSame($error_code, $result->get_error_code());
        }
    }

    public function test_download_uses_stored_offer_instead_of_mutated_api_cache(): void
    {
        $file = $this->temporary_file('trusted-package');
        $offer = $this->store_offer('trusted-package');
        $this->seed_release_cache($this->release('changed-package'));

        $result = $this->updater()->verify_download(
            $file,
            $offer['package'],
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($file, $result);
    }

    public function test_extracted_source_is_read_through_wp_filesystem(): void
    {
        $offer = $this->store_offer('trusted-package');
        $root = '/virtual/upgrade/dr-slon-toolkit';
        $files = [
            $root . '/dr-slon-toolkit.php' => "<?php\n/**\n * Version: 0.9.0\n * Update URI: " . self::UPDATE_URI . "\n */\nconst DSTK_VERSION = '0.9.0';\n",
            $root . '/readme.txt' => "=== Dr.Slon Toolkit ===\nStable tag: 0.9.0\n",
            $root . '/vendor/autoload.php' => '<?php',
        ];
        $filesystem = new class ($files) {
            /** @var array<string,string> */
            private array $files;

            /** @var list<string> */
            public array $reads = [];

            /** @param array<string,string> $files */
            public function __construct(array $files)
            {
                $this->files = $files;
            }

            public function is_file(string $file): bool
            {
                return array_key_exists($file, $this->files);
            }

            public function get_contents(string $file): string|false
            {
                $this->reads[] = $file;
                return $this->files[$file] ?? false;
            }
        };
        $GLOBALS['wp_filesystem'] = $filesystem;

        $result = $this->updater()->validate_package_source(
            $root . '/',
            '/virtual/upgrade',
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($root . '/', $result);
        self::assertSame(
            [$root . '/dr-slon-toolkit.php', $root . '/readme.txt'],
            $filesystem->reads
        );
        self::assertSame('0.9.0', $offer['version']);
    }

    public function test_source_selection_preserves_prior_wp_error(): void
    {
        $error = new \WP_Error('source_error', 'Extraction failed');

        $result = $this->updater()->validate_package_source(
            $error,
            '',
            null,
            ['plugin' => self::PLUGIN_BASENAME]
        );

        self::assertSame($error, $result);
    }

    public function test_recent_stale_release_is_reused_without_extending_its_age(): void
    {
        $fetched_at = time() - (6 * DAY_IN_SECONDS);
        $GLOBALS['dstk_test_site_transients'][GitHubReleaseUpdater::CACHE_KEY] = [
            'schema'     => 2,
            'next_check' => time() - 1,
            'fetched_at' => $fetched_at,
            'release'    => $this->release('trusted-package'),
        ];

        $offer = $this->updater()->filter_update(
            false,
            ['UpdateURI' => self::UPDATE_URI],
            self::PLUGIN_BASENAME,
            []
        );
        $cached = $GLOBALS['dstk_test_site_transients'][GitHubReleaseUpdater::CACHE_KEY];

        self::assertIsArray($offer);
        self::assertSame($fetched_at, $cached['fetched_at']);
        self::assertSame(1, $GLOBALS['dstk_test_http_calls']);
        self::assertLessThanOrEqual(DAY_IN_SECONDS, $GLOBALS['dstk_test_transient_expirations'][GitHubReleaseUpdater::CACHE_KEY]);
    }

    public function test_expired_stale_release_is_dropped_after_api_failure(): void
    {
        $GLOBALS['dstk_test_site_transients'][GitHubReleaseUpdater::CACHE_KEY] = [
            'schema'     => 2,
            'next_check' => time() - 1,
            'fetched_at' => time() - (7 * DAY_IN_SECONDS) - 1,
            'release'    => $this->release('trusted-package'),
        ];

        $offer = $this->updater()->filter_update(
            false,
            ['UpdateURI' => self::UPDATE_URI],
            self::PLUGIN_BASENAME,
            []
        );
        $cached = $GLOBALS['dstk_test_site_transients'][GitHubReleaseUpdater::CACHE_KEY];

        self::assertFalse($offer);
        self::assertNull($cached['release']);
        self::assertSame(0, $cached['fetched_at']);
        self::assertSame(HOUR_IN_SECONDS, $GLOBALS['dstk_test_transient_expirations'][GitHubReleaseUpdater::CACHE_KEY]);
    }

    private function updater(): GitHubReleaseUpdater
    {
        return new GitHubReleaseUpdater(self::PLUGIN_FILE, '0.9.0');
    }

    /** @return array<string,mixed> */
    private function store_offer(string $contents): array
    {
        $this->seed_release_cache($this->release($contents));
        $offer = $this->updater()->filter_update(
            false,
            ['UpdateURI' => self::UPDATE_URI],
            self::PLUGIN_BASENAME,
            []
        );

        self::assertIsArray($offer);
        $this->set_update_offer($offer);

        return $offer;
    }

    /** @param array<string,mixed> $offer */
    private function set_update_offer(array $offer): void
    {
        $GLOBALS['dstk_test_site_transients']['update_plugins'] = (object) [
            'response' => [
                self::PLUGIN_BASENAME => (object) $offer,
            ],
        ];
    }

    /** @param array<string,mixed> $release */
    private function seed_release_cache(array $release): void
    {
        $GLOBALS['dstk_test_site_transients'][GitHubReleaseUpdater::CACHE_KEY] = [
            'schema'     => 2,
            'next_check' => time() + HOUR_IN_SECONDS,
            'fetched_at' => time(),
            'release'    => $release,
        ];
    }

    /** @return array<string,mixed> */
    private function release(string $contents): array
    {
        $release = GitHubReleaseUpdater::parse_release_payload($this->payload($contents));
        self::assertNotNull($release);
        return $release;
    }

    private function temporary_file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dstk-updater-');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents($file, $contents));
        $this->temporary_files[] = $file;
        return $file;
    }

    /** @return array<string,mixed> */
    private function payload(string $contents): array
    {
        return [
            'tag_name'     => 'v0.9.0',
            'draft'        => false,
            'prerelease'   => false,
            'body'         => 'Release notes',
            'published_at' => '2026-07-18T12:00:00Z',
            'assets'       => [
                [
                    'name'                 => 'dr-slon-toolkit-0.9.0.zip',
                    'state'                => 'uploaded',
                    'content_type'         => 'application/zip',
                    'size'                 => strlen($contents),
                    'digest'               => 'sha256:' . hash('sha256', $contents),
                    'browser_download_url' => self::UPDATE_URI . '/releases/download/v0.9.0/dr-slon-toolkit-0.9.0.zip',
                ],
            ],
        ];
    }
}
