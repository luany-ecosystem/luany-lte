<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Engine;
use Luany\Lte\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for parser/compiler/runtime hardening.
 * Assertions use exact output wherever possible.
 */
class HardeningTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/lte_hard_views_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/lte_hard_cache_' . uniqid();
        mkdir($this->viewsDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->engine = new Engine($this->viewsDir, $this->cacheDir, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->viewsDir);
        $this->removeDir($this->cacheDir);
    }

    private function write(string $path, string $content): void
    {
        $full = $this->viewsDir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $content);
    }

    /** @param array<string, mixed> $data */
    private function renderString(string $source, array $data = []): string
    {
        $this->write('t.lte', $source);
        return $this->engine->render('t', $data);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    // ── #1 parser hang on '{!' ────────────────────────────────────────────────

    public function test_lone_brace_bang_does_not_hang(): void
    {
        $this->assertSame('a {!x', $this->renderString('a {!x'));
    }

    public function test_brace_bang_variants_render_literally(): void
    {
        $this->assertSame('{! {!x} {', $this->renderString('{! {!x} {'));
        $this->assertSame('x {!', $this->renderString('x {!'));
    }

    public function test_parser_text_node_for_brace_bang(): void
    {
        $ast = (new Parser())->parse('{!x');
        $this->assertSame([['type' => 'text', 'content' => '{!x', 'line' => 1]], $ast);
    }
}
