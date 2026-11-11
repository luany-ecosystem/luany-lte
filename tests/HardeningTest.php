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
        $this->assertSame([['type' => 'text', 'content' => '{!x', 'line' => 1, 'column' => 1]], $ast);
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
        $this->expectExceptionMessage('Unexpected @endsection: there is no open block to close (line 2, column 1).');
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
            $this->assertStringContainsString('Unexpected @endpush: there is no open block to close (line 1', $e->getMessage());
            $this->assertSame(1, $e->templateLine);
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
            $this->assertStringContainsString('Unexpected @endsection: @push opened on line 2 is still open', $e->getMessage());
            $this->assertSame(3, $e->templateLine);
        }

        $this->expectExceptionMessage('Unclosed @section: missing @endsection or @stop (line 1, column 1).');
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

    // ── #10 unwinding on exceptions ───────────────────────────────────────────

    public function test_throwing_component_does_not_leak_stack_or_buffers(): void
    {
        $this->write('components/box.lte', '<b>{{ $slot }}</b>');
        $this->write('bad.lte', "@component('components.box')@component('components.box'){{ (function () { throw new \\LogicException('boom'); })() }}@endcomponent@endcomponent");
        $this->write('good.lte', "@component('components.box')ok@endcomponent");

        $level = ob_get_level();
        try {
            $this->engine->render('bad');
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }

        $this->assertSame($level, ob_get_level());
        $this->assertFalse(\Luany\Lte\ComponentStack::isActive());
        $this->assertSame(0, \Luany\Lte\SectionStack::openDepth());
        $this->assertSame('<b>ok</b>', $this->engine->render('good'));
    }

    public function test_throwing_section_body_unwinds_section_stack(): void
    {
        $this->write('layouts/s.lte', '@yield("c")');
        $this->write('bad.lte', "@extends('layouts.s')@section('c')@push('x'){{ (function () { throw new \\LogicException('boom'); })() }}@endpush@endsection");
        $level = ob_get_level();
        try {
            $this->engine->render('bad');
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
        }
        $this->assertSame($level, ob_get_level());
        $this->assertSame(0, \Luany\Lte\SectionStack::openDepth());
        $this->assertSame('fine', $this->renderString("@extends('layouts.s')@section('c')fine@endsection"));
    }

    // ── #9 @forelse / @empty ──────────────────────────────────────────────────

    public function test_forelse_with_empty_traversable_shows_empty_branch(): void
    {
        $src = '@forelse($items as $i)[{{ $i }}]@empty none@endforelse';
        $this->assertSame(' none', $this->renderString($src, ['items' => new \ArrayIterator([])]));
        $this->assertSame(' none', $this->renderString($src, ['items' => (function () { yield from []; })()]));
        $this->assertSame('[1][2]', $this->renderString($src, ['items' => new \ArrayIterator([1, 2])]));
        $this->assertSame('[a]', $this->renderString($src, ['items' => (function () { yield 'a'; })()]));
        $this->assertSame(' none', $this->renderString($src, ['items' => null]));
    }

    public function test_nested_forelse_and_forelse_without_empty(): void
    {
        $src = '@forelse($rows as $r)<@forelse($r as $c){{ $c }}@empty-@endforelse>@empty none@endforelse|@forelse($rows as $r)x@endforelse';
        $this->assertSame('<12><->|xx', $this->renderString($src, ['rows' => [[1, 2], []]]));
    }

    public function test_empty_with_argument_outside_forelse(): void
    {
        $this->assertSame('empty', $this->renderString('@empty($x)empty@endempty', ['x' => []]));
        $this->assertSame('', $this->renderString('@empty($x)empty@endempty', ['x' => [1]]));
    }

    public function test_bare_empty_outside_forelse_is_a_syntax_error(): void
    {
        $this->expectException(\Luany\Lte\TemplateSyntaxException::class);
        $this->expectExceptionMessage('bare @empty belongs to @forelse (line 2, column 1)');
        $this->renderString("x\n@empty y");
    }

    // ── #12 compiled cache ────────────────────────────────────────────────────

    public function test_default_cache_dir_is_private_per_user(): void
    {
        $engine = new Engine($this->viewsDir);
        $dir    = $engine->getCachePath();

        $this->assertNotSame(sys_get_temp_dir() . '/lte_cache', $dir);
        $this->assertDirectoryExists($dir);
        $this->assertFalse(is_link($dir));
        if (function_exists('posix_geteuid')) {
            $this->assertStringEndsWith('/lte_cache_' . posix_geteuid(), $dir);
            $this->assertSame(posix_geteuid(), fileowner($dir));
            $this->assertSame(0700, fileperms($dir) & 0777);
        }
    }

    public function test_symlinked_default_cache_dir_is_refused(): void
    {
        $method = new \ReflectionMethod(Engine::class, 'assertPrivateDirectory');
        $link   = $this->cacheDir . '/link';
        symlink($this->cacheDir, $link);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a real directory');
        $method->invoke(null, $link);
    }

    public function test_loose_permissions_on_owned_cache_dir_are_tightened(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('posix extension required');
        }
        $dir = $this->cacheDir . '/loose';
        mkdir($dir);
        chmod($dir, 0777);

        (new \ReflectionMethod(Engine::class, 'assertPrivateDirectory'))->invoke(null, $dir);
        clearstatcache();
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    public function test_cache_write_is_atomic_and_leaves_no_temp_files(): void
    {
        $engine = new Engine($this->viewsDir, $this->cacheDir, false);
        $this->write('c.lte', 'v1');
        $this->assertSame('v1', $engine->render('c'));

        $this->assertCount(1, glob($this->cacheDir . '/*.php') ?: []);
        $this->assertSame([], glob($this->cacheDir . '/*.tmp') ?: []);
    }

    public function test_same_second_edit_is_recompiled(): void
    {
        $engine = new Engine($this->viewsDir, $this->cacheDir, false);
        $this->write('c.lte', 'v1');
        $this->assertSame('v1', $engine->render('c'));

        $this->write('c.lte', 'v2'); // same second: mtime unchanged at 1s resolution
        $this->assertSame('v2', $engine->render('c'));
    }

    public function test_up_to_date_cache_is_reused(): void
    {
        $engine = new Engine($this->viewsDir, $this->cacheDir, false);
        $this->write('c.lte', 'v1');
        touch($this->viewsDir . '/c.lte', time() - 100);
        $this->assertSame('v1', $engine->render('c'));

        $cached = glob($this->cacheDir . '/*.php') ?: [];
        $this->assertCount(1, $cached);
        $mtime = filemtime($cached[0]);
        file_put_contents($cached[0], 'from-cache');
        touch($cached[0], (int) $mtime);

        $this->assertSame('from-cache', $engine->render('c'));
    }

    public function test_registering_directive_invalidates_cache(): void
    {
        $engine = new Engine($this->viewsDir, $this->cacheDir, false);
        $this->write('d.lte', '@shout(\'hi\')');
        touch($this->viewsDir . '/d.lte', time() - 100);
        $this->assertSame("@shout('hi')", $engine->render('d'));

        $engine->getCompiler()->directive('shout', fn (string $a): string => "<?php echo strtoupper({$a}); ?>");
        $this->assertSame('HI', $engine->render('d'));
    }

    // ── #7 multi-level layouts ────────────────────────────────────────────────

    public function test_three_level_layout_chain(): void
    {
        $this->write('layouts/base.lte', '<html><title>@yield("title", "Site")</title><body>@yield("body")</body></html>');
        $this->write('layouts/app.lte', "@extends('layouts.base')@section('body')<nav/>@yield('content')@endsection");
        $src = "@extends('layouts.app')@section('title', 'Home')@section('content')<p>hi</p>@endsection";

        $this->assertSame(
            '<html><title>Home</title><body><nav/><p>hi</p></body></html>',
            $this->renderString($src)
        );
    }

    public function test_child_section_wins_over_parent_layout_default(): void
    {
        $this->write('layouts/base.lte', '[@yield("side")]');
        $this->write('layouts/mid.lte', "@extends('layouts.base')@section('side')mid-default@endsection");

        $this->assertSame('[child]', $this->renderString("@extends('layouts.mid')@section('side')child@endsection"));
        $this->assertSame('[mid-default]', $this->renderString("@extends('layouts.mid')"));
    }

    public function test_extends_inside_partial_does_not_hijack_layout(): void
    {
        $this->write('layouts/page.lte', '<page>@yield("c")</page>');
        $this->write('layouts/other.lte', '<other>@yield("c")</other>');
        $this->write('partials/p.lte', "@extends('layouts.other')partial");

        $this->assertSame('<page>[partial]</page>', $this->renderString("@extends('layouts.page')@section('c')[@include('partials.p')]@endsection"));
    }

    public function test_circular_extends_throws(): void
    {
        $this->write('layouts/a.lte', "@extends('layouts.b')");
        $this->write('layouts/b.lte', "@extends('layouts.a')");

        $this->expectException(\Luany\Lte\TemplateSyntaxException::class);
        $this->expectExceptionMessage('Circular @extends detected');
        $this->renderString("@extends('layouts.a')");
    }
}
