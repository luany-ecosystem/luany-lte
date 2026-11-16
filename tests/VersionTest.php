<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Engine::VERSION is part of the compiled-view cache key, so it must follow
 * the releases. The release script stamps it; this guards against drift.
 */
class VersionTest extends TestCase
{
    public function test_engine_version_matches_the_latest_release_in_the_changelog(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        $this->assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m), 'no released version in CHANGELOG.md');

        $this->assertSame($m[1], Engine::VERSION);
    }
}
