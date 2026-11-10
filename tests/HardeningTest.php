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

    // ── #5 string-aware scanning ──────────────────────────────────────────────

    public function test_echo_containing_closing_delimiter_in_string(): void
    {
        $this->assertSame('}} x', $this->renderString("{{ '}}' }} x"));
        $this->assertSame('a!!}b', $this->renderString("{!! 'a!!}' !!}b"));
        $this->assertSame('1', $this->renderString("{{ ['k' => ['v' => 1]]['k']['v'] }}"));
    }

    public function test_json_with_nested_array_argument(): void
    {
        $this->assertSame('{"a":1,"b":2}', $this->renderString("@json(['a' => 1, 'b' => 2])"));
        $this->assertSame("[\n    1,\n    2\n]", $this->renderString('@json([1, 2], JSON_PRETTY_PRINT)'));
    }

    public function test_include_with_multi_key_data_array(): void
    {
        $this->write('partial.lte', '{{ $a }}-{{ $b }}');
        $this->assertSame('1-2', $this->renderString("@include('partial', ['a' => 1, 'b' => 2])"));
    }

    public function test_inline_section_with_function_call_value(): void
    {
        $this->write('layout.lte', '<title>@yield(\'t\')</title>');
        $this->assertSame(
            '<title>a, b</title>',
            $this->renderString("@extends('layout')@section('t', implode(', ', ['a', 'b']))")
        );
    }

    public function test_directive_args_with_parens_and_quotes_in_strings(): void
    {
        $this->assertSame('ok', $this->renderString("@if(\$x === ')' || in_array(\$x, ['(', \"\\\")\"]))ok@endif", ['x' => ')']));
    }

    public function test_scanner_split_and_literal_detection(): void
    {
        $this->assertSame(
            ["'x'", "['a' => 1, 'b' => f(2, 3)]", '"c,d"'],
            \Luany\Lte\ExpressionScanner::split("'x', ['a' => 1, 'b' => f(2, 3)], \"c,d\"")
        );
        $this->assertTrue(\Luany\Lte\ExpressionScanner::isStringLiteral("'it\\'s'"));
        $this->assertFalse(\Luany\Lte\ExpressionScanner::isStringLiteral("'a' . 'b'"));
        $this->assertFalse(\Luany\Lte\ExpressionScanner::isStringLiteral('$view'));
    }

    // ── #6 dynamic view / section names ───────────────────────────────────────

    public function test_dynamic_include_extends_and_section_names(): void
    {
        $this->write('partials/card.lte', '[card {{ $n }}]');
        $this->write('layouts/app.lte', '<main>@yield($slot)</main>');

        $src = "@extends(\$layout)@section(\$slot)@include(\$view, ['n' => 1])@include('partials.' . \$kind)@endsection";
        $this->assertSame(
            '<main>[card 1][card 2]</main>',
            $this->renderString($src, ['layout' => 'layouts.app', 'slot' => 'body', 'view' => 'partials.card', 'kind' => 'card', 'n' => 2])
        );
    }

    public function test_dynamic_inline_section_push_and_stack_names(): void
    {
        $src = "@section(\$name, 'v')@yield(\$name)|@push(\$s)p@endpush@stack(\$s)";
        $this->assertSame('v|p', $this->renderString($src, ['name' => 'x', 's' => 'js']));
    }

    public function test_legacy_unquoted_and_double_quoted_names_still_work(): void
    {
        $this->write('layouts/plain.lte', '[@yield("c")]');
        $this->assertSame('[ok]', $this->renderString('@extends(layouts.plain)@section("c")ok@endsection'));
    }

    public function test_quote_in_name_cannot_break_out_of_generated_code(): void
    {
        $this->assertSame('', $this->renderString("@yield('a\\'); echo \"pwned\"; //')"));
    }

    // ── #11 XSS ───────────────────────────────────────────────────────────────

    public function test_inline_section_value_is_escaped(): void
    {
        $this->write('layouts/x.lte', '<title>@yield("title")</title>@yield("body")');
        $src = "@extends('layouts.x')@section('title', \$t)@section('body')<b>{{ \$t }}</b>@endsection";
        $this->assertSame(
            '<title>&lt;script&gt;alert(1)&lt;/script&gt;</title><b>&lt;script&gt;alert(1)&lt;/script&gt;</b>',
            $this->renderString($src, ['t' => '<script>alert(1)</script>'])
        );
    }

    public function test_yield_default_is_escaped(): void
    {
        $this->assertSame('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $this->renderString('@yield("missing", $d)', ['d' => '"><img src=x onerror=alert(1)>']));
    }

    public function test_htmlable_values_are_not_escaped(): void
    {
        $html = new \Luany\Lte\HtmlString('<em>Home</em>');
        $this->assertSame('<em>Home</em>|<em>Home</em>|<em>Home</em>', $this->renderString(
            "@section('t', \$h)@yield('t')|{{ \$h }}|@yield('none', \$h)",
            ['h' => $html]
        ));
    }

    public function test_class_directive_escapes_class_names(): void
    {
        $this->assertSame(
            '<div class="a &quot; onmouseover=&quot;alert(1)"></div>',
            $this->renderString('<div @class([\'a\', $c => true])></div>', ['c' => '" onmouseover="alert(1)'])
        );
    }

    public function test_echo_substitutes_invalid_utf8_instead_of_emptying(): void
    {
        $this->assertSame("a\u{FFFD}b&#039;", $this->renderString('{{ $s }}', ['s' => "a\xC3b'"]));
    }

    public function test_method_directive_is_escaped_and_supports_expressions(): void
    {
        $this->assertSame('<input type="hidden" name="_method" value="PUT">', $this->renderString("@method('put')"));
        $this->assertSame(
            '<input type="hidden" name="_method" value="&quot;&gt;X">',
            $this->renderString('@method($m)', ['m' => '">x'])
        );
    }

    // ── #8 section stack ──────────────────────────────────────────────────────

    public function test_nested_sections_keep_outer_section(): void
    {
        $this->write('layouts/n.lte', '[@yield("outer")][@yield("inner")]');
        $src = "@extends('layouts.n')@section('outer')a @section('inner')b@endsection c@endsection";
        $this->assertSame('[a  c][b]', $this->renderString($src));
    }

    public function test_push_inside_section(): void
    {
        $this->write('layouts/p.lte', '[@yield("c")][@stack("js")]');
        $this->assertSame('[x  y][j]', $this->renderString("@extends('layouts.p')@section('c')x @push('js')j@endpush y@endsection"));
    }

    public function test_stray_endsection_throws_with_line(): void
    {
        $this->expectException(\Luany\Lte\TemplateSyntaxException::class);
        $this->expectExceptionMessage('Unexpected @endsection on line 2: no open @section.');
        $this->renderString("a\n@endsection b");
    }

    public function test_stray_endpush_does_not_swallow_outer_buffer(): void
    {
        $level = ob_get_level();
        ob_start();
        echo '[OUTER]';
        try {
            $this->renderString('a@endpush b');
            $this->fail('Expected TemplateSyntaxException');
        } catch (\Luany\Lte\TemplateSyntaxException $e) {
            $this->assertStringContainsString('Unexpected @endpush on line 1', $e->getMessage());
        }
        $this->assertSame($level + 1, ob_get_level());
        $this->assertSame('[OUTER]', ob_get_clean());
    }

    public function test_mismatched_and_unclosed_blocks_throw(): void
    {
        try {
            $this->renderString("@section('a')\n@push('b')\n@endsection");
            $this->fail('Expected TemplateSyntaxException');
        } catch (\Luany\Lte\TemplateSyntaxException $e) {
            $this->assertStringContainsString('Unexpected @endsection on line 3: @push opened on line 2 is still open.', $e->getMessage());
        }

        $this->expectExceptionMessage('Unclosed @section opened on line 1: missing @endsection.');
        $this->renderString("@section('a') never closed");
    }

    public function test_runtime_stray_end_throws_and_preserves_buffers(): void
    {
        $level = ob_get_level();
        ob_start();
        echo 'keep';
        try {
            \Luany\Lte\SectionStack::reset();
            \Luany\Lte\SectionStack::end();
            $this->fail('Expected TemplateSyntaxException');
        } catch (\Luany\Lte\TemplateSyntaxException $e) {
            $this->assertSame('Cannot use @endsection without a matching @section.', $e->getMessage());
        }
        $this->assertSame($level + 1, ob_get_level());
        $this->assertSame('keep', ob_get_clean());
    }
}
