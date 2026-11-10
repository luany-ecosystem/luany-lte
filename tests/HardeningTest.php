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

    // ── #3 whitespace before '(' ──────────────────────────────────────────────

    public function test_whitespace_before_directive_parenthesis(): void
    {
        $this->assertSame('yes', $this->renderString('@if ($x)yes@else no@endif', ['x' => true]));
        $this->assertSame('123', $this->renderString("@foreach\t(\$xs as \$x){{ \$x }}@endforeach", ['xs' => [1, 2, 3]]));
    }

    public function test_whitespace_not_consumed_without_parenthesis(): void
    {
        $this->assertSame('a b', $this->renderString('@if(true)a@endif b'));
        $this->assertSame('ok (note)', $this->renderString('@if(true)ok@endif (note)'));
    }

    public function test_parser_reads_args_after_whitespace(): void
    {
        $ast = (new Parser())->parse('@if  ($a && $b)');
        $this->assertSame('$a && $b', $ast[0]['args']);
    }

    // ── #4 '@' in plain text, @@ and @{{ }} escapes ──────────────────────────

    public function test_emails_are_plain_text(): void
    {
        $this->assertSame('Mail dev@php.net or me@if.com', $this->renderString('Mail dev@php.net or me@if.com'));
    }

    public function test_unknown_at_words_are_plain_text(): void
    {
        $css = '@media (max-width: 600px) { a { color: red } } @import url(x.css); @ alone @';
        $this->assertSame($css, $this->renderString($css));
    }

    public function test_double_at_escapes_directive(): void
    {
        $this->assertSame('Use @if($x) and @ and @foo', $this->renderString('Use @@if($x) and @@ and @@foo'));
    }

    public function test_at_echo_escape_outputs_literal_braces(): void
    {
        $this->assertSame('<p>{{ message }}</p>', $this->renderString('<p>@{{ message }}</p>'));
    }

    public function test_directive_after_word_char_only_for_closers(): void
    {
        $this->assertSame('Hi', $this->renderString('@if(true)Hi@endif'));
        $this->assertSame('ab', $this->renderString('@if(true)a@endif@if(true)b@endif'));
        $this->assertSame(' no', $this->renderString('@if(false)yes@else no@endif'));
    }

    public function test_custom_directive_is_recognised_and_others_are_text(): void
    {
        $this->engine->getCompiler()->directive('hello', fn (?string $a): string => "<?php echo 'Hello ' . {$a}; ?>");
        $this->assertSame('Hello Ana @world', $this->renderString("@hello('Ana') @world"));
    }
}
