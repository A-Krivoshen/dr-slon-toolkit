<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SeoFrameworkDetectorTest extends TestCase
{
    public function test_comparable_urls_normalize_equivalent_percent_encoding(): void
    {
        $detector = new SeoFrameworkDetector();
        $method = new ReflectionMethod($detector, 'normalize_comparable_url');
        $method->setAccessible(true);

        $encoded = $method->invoke($detector, 'https://EXAMPLE.test:443/%7euser/%d0%bf?q=%7e');
        $canonical = $method->invoke($detector, 'https://example.test/~user/%D0%BF?q=~');

        self::assertSame('https://example.test/~user/%D0%BF?q=~', $encoded);
        self::assertSame($encoded, $canonical);
    }
}
