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

    // ── #2 newlines after echoes/directives ───────────────────────────────────

    public function test_newlines_between_echoes_are_preserved(): void
    {
        $this->assertSame("A\nB\n", $this->renderString("{{ \$a }}\n{{ \$b }}\n", ['a' => 'A', 'b' => 'B']));
    }

    public function test_newlines_after_directives_are_preserved(): void
    {
        $src = "<pre>\n@if(true)\nline1\n@endif\nline2\n</pre>";
        $this->assertSame("<pre>\n\nline1\n\nline2\n</pre>", $this->renderString($src));
    }

    public function test_blank_lines_and_crlf_are_preserved(): void
    {
        $this->assertSame("x\n\n\ny", $this->renderString("{{ 'x' }}\n\n\n{!! 'y' !!}"));
        $this->assertSame("x\r\ny", $this->renderString("{{ 'x' }}\r\ny"));
    }

    public function test_newline_after_php_block_and_comment_is_preserved(): void
    {
        $this->assertSame("\n1\n", $this->renderString("@php \$n = 1; @endphp\n{{ \$n }}\n"));
        $this->assertSame("a\nb", $this->renderString("a{{-- c --}}\nb"));
    }

    public function test_plain_text_email_layout_is_exact(): void
    {
        $src = "Hello {{ \$name }},\n\n@foreach(\$items as \$i)\n- {{ \$i }}\n@endforeach\nBye";
        $this->assertSame(
            "Hello Ana,\n\n\n- a\n\n- b\n\nBye",
            $this->renderString($src, ['name' => 'Ana', 'items' => ['a', 'b']])
        );
    }
}
