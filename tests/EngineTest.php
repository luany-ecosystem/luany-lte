<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Engine;
use Luany\Lte\AssetStack;
use Luany\Lte\SectionStack;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LTE Engine — full render pipeline
 *
 * Tests the complete flow: .lte source → Parser → AST → Compiler → PHP → output.
 * Uses a temporary directory for views and cache to remain self-contained.
 */
class EngineTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/lte_test_views_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/lte_test_cache_' . uniqid();

        mkdir($this->viewsDir, 0755, true);
        mkdir($this->viewsDir . '/layouts', 0755, true);
        mkdir($this->viewsDir . '/components', 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->engine = new Engine(
            viewsPath:  $this->viewsDir,
            cachePath:  $this->cacheDir,
            autoReload: true
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->viewsDir);
        $this->removeDir($this->cacheDir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function write(string $path, string $content): void
    {
        $fullPath = $this->viewsDir . '/' . ltrim($path, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($fullPath, $content);
    }

    private function render(string $view, array $data = []): string
    {
        return $this->engine->render($view, $data);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        rmdir($dir);
    }

    // ── Basic render ─────────────────────────────────────────────────────────

    public function test_render_plain_html(): void
    {
        $this->write('simple.lte', '<h1>Hello</h1>');
        $this->assertSame('<h1>Hello</h1>', $this->render('simple'));
    }

    public function test_render_echo_variable(): void
    {
        $this->write('hello.lte', '<p>{{ $name }}</p>');
        $out = $this->render('hello', ['name' => 'World']);
        $this->assertSame('<p>World</p>', $out);
    }

    public function test_echo_escapes_html(): void
    {
        $this->write('escape.lte', '{{ $value }}');
        $out = $this->render('escape', ['value' => '<script>alert(1)</script>']);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_raw_echo_does_not_escape(): void
    {
        $this->write('raw.lte', '{!! $html !!}');
        $out = $this->render('raw', ['html' => '<b>bold</b>']);
        $this->assertSame('<b>bold</b>', $out);
    }

    public function test_comment_is_not_rendered(): void
    {
        $this->write('comment.lte', '<p>{{-- hidden --}}visible</p>');
        $out = $this->render('comment');
        $this->assertStringNotContainsString('hidden', $out);
        $this->assertStringContainsString('visible', $out);
    }

    // ── View not found ────────────────────────────────────────────────────────

    public function test_missing_view_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->render('does.not.exist');
    }

    // ── Conditionals ─────────────────────────────────────────────────────────

    public function test_if_true_renders_block(): void
    {
        $this->write('cond.lte', '@if($show)<p>visible</p>@endif');
        $out = $this->render('cond', ['show' => true]);
        $this->assertStringContainsString('visible', $out);
    }

    public function test_if_false_skips_block(): void
    {
        $this->write('cond.lte', '@if($show)<p>visible</p>@endif');
        $out = $this->render('cond', ['show' => false]);
        $this->assertStringNotContainsString('visible', $out);
    }

    public function test_if_else(): void
    {
        $this->write('ifelse.lte', '@if($admin)<p>admin</p>@else<p>user</p>@endif');

        $this->assertStringContainsString('admin', $this->render('ifelse', ['admin' => true]));
        $this->assertStringContainsString('user',  $this->render('ifelse', ['admin' => false]));
    }

    // ── Loops ─────────────────────────────────────────────────────────────────

    public function test_foreach_renders_each_item(): void
    {
        $this->write('loop.lte', '@foreach($items as $item)<li>{{ $item }}</li>@endforeach');
        $out = $this->render('loop', ['items' => ['a', 'b', 'c']]);

        $this->assertStringContainsString('<li>a</li>', $out);
        $this->assertStringContainsString('<li>b</li>', $out);
        $this->assertStringContainsString('<li>c</li>', $out);
    }

    public function test_foreach_empty_array_renders_nothing(): void
    {
        $this->write('emptyloop.lte', '@foreach($items as $item)<li>{{ $item }}</li>@endforeach');
        $out = $this->render('emptyloop', ['items' => []]);
        $this->assertStringNotContainsString('<li>', $out);
    }

    // ── @forelse ──────────────────────────────────────────────────────────────

    public function test_forelse_renders_items_when_not_empty(): void
    {
        $this->write('forelse.lte',
            '@forelse($users as $user)<p>{{ $user }}</p>@empty<p>none</p>@endforelse'
        );
        $out = $this->render('forelse', ['users' => ['Alice', 'Bob']]);

        $this->assertStringContainsString('<p>Alice</p>', $out);
        $this->assertStringContainsString('<p>Bob</p>', $out);
        $this->assertStringNotContainsString('none', $out);
    }

    public function test_forelse_renders_empty_state_when_array_empty(): void
    {
        $this->write('forelse.lte',
            '@forelse($users as $user)<p>{{ $user }}</p>@empty<p>No users found.</p>@endforelse'
        );
        $out = $this->render('forelse', ['users' => []]);

        $this->assertStringNotContainsString('<p>Alice</p>', $out);
        $this->assertStringContainsString('No users found.', $out);
    }

    public function test_forelse_with_null_renders_empty_state(): void
    {
        $this->write('forelse_null.lte',
            '@forelse($items as $item){{ $item }}@empty<p>empty</p>@endforelse'
        );
        $out = $this->render('forelse_null', ['items' => null]);
        $this->assertStringContainsString('empty', $out);
    }

    // ── Layout system ─────────────────────────────────────────────────────────

    public function test_extends_and_yield(): void
    {
        $this->write('layouts/main.lte',
            '<!DOCTYPE html><html><head><title>@yield("title")</title></head><body>@yield("content")</body></html>'
        );
        $this->write('pages/home.lte',
            '@extends("layouts.main")@section("title", "Home")@section("content")<h1>Hello</h1>@endsection'
        );

        $out = $this->render('pages.home');

        $this->assertStringContainsString('<title>Home</title>', $out);
        $this->assertStringContainsString('<h1>Hello</h1>', $out);
        $this->assertStringContainsString('<!DOCTYPE html>', $out);
    }

    public function test_yield_with_default_value(): void
    {
        $this->write('layouts/base.lte',
            '<title>@yield("title", "Default Title")</title>'
        );
        $this->write('pages/nodesc.lte',
            '@extends("layouts.base")@section("content")x@endsection'
        );

        $out = $this->render('pages.nodesc');
        $this->assertStringContainsString('Default Title', $out);
    }

    // ── @include ─────────────────────────────────────────────────────────────

    public function test_include_renders_partial(): void
    {
        $this->write('components/greeting.lte', '<p>Hello!</p>');
        $this->write('page.lte', '<div>@include("components.greeting")</div>');

        $out = $this->render('page');
        $this->assertStringContainsString('<p>Hello!</p>', $out);
    }

    public function test_include_passes_parent_variables(): void
    {
        $this->write('components/name.lte', '<p>{{ $name }}</p>');
        $this->write('withvars.lte', '@include("components.name")');

        $out = $this->render('withvars', ['name' => 'Luany']);
        $this->assertStringContainsString('<p>Luany</p>', $out);
    }

    public function test_include_with_extra_data(): void
    {
        $this->write('components/card.lte', '<div>{{ $title }}</div>');
        $this->write('cards.lte', '@include("components.card", ["title" => "My Card"])');

        $out = $this->render('cards');
        $this->assertStringContainsString('<div>My Card</div>', $out);
    }

    // ── Asset directives (v0.2) ───────────────────────────────────────────────

    public function test_style_block_renders_in_styles_position(): void
    {
        $this->write('layouts/assets.lte',
            '<head>@styles</head><body>@yield("content")</body>'
        );
        $this->write('pages/styled.lte',
            '@extends("layouts.assets")@section("content")<p>hi</p>@endsection@style.card { color: red; }@endstyle'
        );

        $out = $this->render('pages.styled');

        $this->assertStringContainsString('<style>', $out);
        $this->assertStringContainsString('.card { color: red; }', $out);
        // Style must appear inside <head>
        $this->assertLessThan(strpos($out, '<body>'), strpos($out, '<style>'));
    }

    public function test_style_deduplication(): void
    {
        $this->write('components/btn.lte',
            '@style.btn { padding: 1rem; }@endstyle<button>click</button>'
        );
        $this->write('layouts/dedup.lte', '<head>@styles</head><body>@yield("c")</body>');
        $this->write('pages/dedup.lte',
            '@extends("layouts.dedup")'
            . '@section("c")'
            . '@include("components.btn")'
            . '@include("components.btn")'
            . '@include("components.btn")'
            . '@endsection'
        );

        $out = $this->render('pages.dedup');
        $this->assertSame(1, substr_count($out, '.btn { padding: 1rem; }'));
    }

    public function test_script_defer_renders_with_defer_attribute(): void
    {
        $this->write('layouts/js.lte', '<body>@yield("c")@scripts</body>');
        $this->write('pages/js.lte',
            '@extends("layouts.js")'
            . '@section("c")<p>x</p>@endsection'
            . '@script(defer)console.log("ready");@endscript'
        );

        $out = $this->render('pages.js');
        $this->assertStringContainsString('<script defer>', $out);
        $this->assertStringContainsString('console.log("ready");', $out);
    }

    // ── Stack directives (v0.2) ───────────────────────────────────────────────

    public function test_push_and_stack(): void
    {
        $this->write('layouts/stack.lte',
            '<head>@stack("head")</head><body>@yield("content")</body>'
        );
        $this->write('pages/meta.lte',
            '@extends("layouts.stack")'
            . '@push("head")<meta name="description" content="test">@endpush'
            . '@section("content")<p>hi</p>@endsection'
        );

        $out = $this->render('pages.meta');
        $this->assertStringContainsString('<meta name="description" content="test">', $out);
        $this->assertLessThan(strpos($out, '<body>'), strpos($out, '<meta'));
    }

    public function test_multiple_push_calls_accumulate(): void
    {
        $this->write('layouts/multistack.lte',
            '@stack("scripts")'
        );
        $this->write('multi.lte',
            '@extends("layouts.multistack")'
            . '@push("scripts")<script>A</script>@endpush'
            . '@push("scripts")<script>B</script>@endpush'
        );

        $out = $this->render('multi');
        $this->assertStringContainsString('<script>A</script>', $out);
        $this->assertStringContainsString('<script>B</script>', $out);
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    public function test_cached_file_is_created(): void
    {
        $this->write('cached.lte', '<p>cached</p>');
        $this->render('cached');

        $cachedFiles = glob($this->cacheDir . '/*.php');
        $this->assertNotEmpty($cachedFiles);
    }

    public function test_clear_cache_removes_compiled_files(): void
    {
        $this->write('toclear.lte', '<p>x</p>');
        $this->render('toclear');

        $this->engine->clearCache();

        $cachedFiles = glob($this->cacheDir . '/*.php');
        $this->assertEmpty($cachedFiles);
    }

    // ── State isolation ───────────────────────────────────────────────────────

    public function test_section_stack_resets_between_renders(): void
    {
        $this->write('layouts/r.lte', '@yield("title")');
        $this->write('pages/r1.lte', '@extends("layouts.r")@section("title", "First")');
        $this->write('pages/r2.lte', '@extends("layouts.r")@section("title", "Second")');

        $out1 = $this->render('pages.r1');
        $out2 = $this->render('pages.r2');

        $this->assertStringContainsString('First',  $out1);
        $this->assertStringContainsString('Second', $out2);
        $this->assertStringNotContainsString('First', $out2);
    }

    public function test_asset_stack_resets_between_renders(): void
    {
        $this->write('layouts/a.lte', '@styles@yield("c")');
        $this->write('pages/a1.lte',
            '@extends("layouts.a")@section("c")x@endsection@style.first{}@endstyle'
        );
        $this->write('pages/a2.lte',
            '@extends("layouts.a")@section("c")x@endsection'
        );

        $this->render('pages.a1');
        $out2 = $this->render('pages.a2');

        $this->assertStringNotContainsString('.first{}', $out2);
    }
}