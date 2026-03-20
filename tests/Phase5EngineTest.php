<?php

namespace Luany\Lte\Tests;

use Luany\Lte\ComponentStack;
use Luany\Lte\Engine;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 — Engine integration tests.
 *
 * Full render pipeline: .lte source → compile → execute → HTML output.
 */
class Phase5EngineTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/lte_p5_views_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/lte_p5_cache_' . uniqid();

        mkdir($this->viewsDir, 0755, true);
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
        ComponentStack::reset();
    }

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

    // ── @json ─────────────────────────────────────────────────────────────────

    public function test_json_renders_array_as_json(): void
    {
        $this->write('j.lte', '@json($data)');
        $out = $this->render('j', ['data' => ['name' => 'António']]);
        $decoded = json_decode($out, true);
        $this->assertSame('António', $decoded['name']);
    }

    public function test_json_escapes_html_entities(): void
    {
        $this->write('j.lte', '@json($data)');
        $out = $this->render('j', ['data' => ['html' => '<script>alert(1)</script>']]);
        // < and > must be entity-encoded in the output
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('\u003C', $out);
        $this->assertStringContainsString('\u003E', $out);
    }

    public function test_json_renders_nested_structures(): void
    {
        $this->write('j.lte', '@json($data)');
        $out = $this->render('j', ['data' => ['users' => [['id' => 1], ['id' => 2]]]]);
        $decoded = json_decode($out, true);
        $this->assertCount(2, $decoded['users']);
    }

    public function test_json_with_pretty_print_flag(): void
    {
        $this->write('j.lte', '@json($data, JSON_PRETTY_PRINT)');
        $out = $this->render('j', ['data' => ['a' => 1]]);
        // Pretty print adds newlines/indentation
        $this->assertStringContainsString("\n", $out);
    }

    public function test_json_usable_in_script_tag(): void
    {
        $this->write('j.lte', '<script>const config = @json($cfg);</script>');
        $out = $this->render('j', ['cfg' => ['debug' => false, 'version' => '1.0']]);
        $this->assertStringContainsString('<script>', $out);
        $this->assertStringContainsString('const config =', $out);
        // The JSON must be valid
        preg_match('/const config = (.+);/', $out, $m);
        $decoded = json_decode($m[1], true);
        $this->assertSame('1.0', $decoded['version']);
    }

    // ── @class ────────────────────────────────────────────────────────────────

    public function test_class_renders_unconditional_classes(): void
    {
        $this->write('c.lte', '<div @class(["btn", "btn-lg"])></div>');
        $out = $this->render('c');
        $this->assertStringContainsString('class="btn btn-lg"', $out);
    }

    public function test_class_renders_true_conditional_class(): void
    {
        $this->write('c.lte', '<div @class(["btn", "active" => $on])></div>');
        $out = $this->render('c', ['on' => true]);
        $this->assertStringContainsString('class="btn active"', $out);
    }

    public function test_class_omits_false_conditional_class(): void
    {
        $this->write('c.lte', '<div @class(["btn", "active" => $on])></div>');
        $out = $this->render('c', ['on' => false]);
        $this->assertStringContainsString('class="btn"', $out);
        $this->assertStringNotContainsString('active', $out);
    }

    public function test_class_with_multiple_conditions(): void
    {
        $this->write('c.lte', '<div @class(["base", "primary" => $primary, "disabled" => $disabled])></div>');
        $out = $this->render('c', ['primary' => true, 'disabled' => false]);
        $this->assertStringContainsString('class="base primary"', $out);
    }

    // ── @component / @slot / @endslot / @endcomponent ─────────────────────────

    public function test_component_renders_basic_component(): void
    {
        $this->write('components/alert.lte', '<div class="alert">{{ $slot }}</div>');
        $this->write('page.lte', '@component("components.alert")Default content.@endcomponent');
        $out = $this->render('page');
        $this->assertStringContainsString('<div class="alert">', $out);
        $this->assertStringContainsString('Default content.', $out);
    }

    public function test_component_with_explicit_data(): void
    {
        $this->write('components/alert.lte', '<div class="alert alert-{{ $type }}">{{ $slot }}</div>');
        $this->write('page.lte', '@component("components.alert", ["type" => "error"])Oops.@endcomponent');
        $out = $this->render('page');
        $this->assertStringContainsString('alert-error', $out);
        $this->assertStringContainsString('Oops.', $out);
    }

    public function test_component_named_slot(): void
    {
        $this->write('components/card.lte',
            '<div class="card"><h2>{{ $title }}</h2><p>{{ $slot }}</p></div>'
        );
        $this->write('page.lte',
            '@component("components.card")' .
            '@slot("title")Card Title@endslot' .
            'Card body content.' .
            '@endcomponent'
        );
        $out = $this->render('page');
        $this->assertStringContainsString('Card Title', $out);
        $this->assertStringContainsString('Card body content.', $out);
    }

    public function test_component_multiple_named_slots(): void
    {
        $this->write('components/panel.lte',
            '<div><header>{{ $header }}</header><main>{{ $slot }}</main><footer>{{ $footer }}</footer></div>'
        );
        $this->write('page.lte',
            '@component("components.panel")' .
            '@slot("header")Top@endslot' .
            'Middle' .
            '@slot("footer")Bottom@endslot' .
            '@endcomponent'
        );
        $out = $this->render('page');
        $this->assertStringContainsString('Top', $out);
        $this->assertStringContainsString('Middle', $out);
        $this->assertStringContainsString('Bottom', $out);
    }

    public function test_component_without_named_slot_uses_default(): void
    {
        $this->write('components/simple.lte', '<p>{{ $slot }}</p>');
        $this->write('page.lte', '@component("components.simple")Just text.@endcomponent');
        $out = $this->render('page');
        $this->assertStringContainsString('Just text.', $out);
    }

    public function test_component_slot_variable_is_trimmed(): void
    {
        $this->write('components/trim.lte', '[{{ $slot }}]');
        $this->write('page.lte', "@component(\"components.trim\")\n  content  \n@endcomponent");
        $out = $this->render('page');
        $this->assertStringContainsString('[content]', $out);
    }

    public function test_nested_components(): void
    {
        // Slot content is already-rendered HTML — must use {!! !!} to avoid double-escaping
        $this->write('components/outer.lte', '<outer>{!! $slot !!}</outer>');
        $this->write('components/inner.lte', '<inner>{!! $slot !!}</inner>');
        $this->write('page.lte',
            '@component("components.outer")' .
            '@component("components.inner")Deep@endcomponent' .
            '@endcomponent'
        );
        $out = $this->render('page');
        $this->assertStringContainsString('<outer>', $out);
        $this->assertStringContainsString('<inner>Deep</inner>', $out);
    }

    public function test_component_receives_explicit_data_as_variables(): void
    {
        $this->write('components/badge.lte', '<span class="{{ $color }}">{{ $label }}</span>');
        $this->write('page.lte', '@component("components.badge", ["color" => "red", "label" => "New"])@endcomponent');
        $out = $this->render('page');
        $this->assertStringContainsString('class="red"', $out);
        $this->assertStringContainsString('>New<', $out);
    }

    // ── Line-number error enrichment ──────────────────────────────────────────

    public function test_error_message_contains_lte_line_number(): void
    {
        // Use @php(throw ...) to force a clean error with zero PHP warnings.
        // The previous approach used $undeclared->method() which emitted a
        // PHP Warning (undefined variable) before the Error — PHPUnit 11
        // treats that Warning as a risky test even when the Error is caught.
        $this->write('broken.lte', "<p>line 1</p>\n<p>line 2</p>\n@php(throw new \\RuntimeException('forced error'))");

        try {
            $this->render('broken');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('broken.lte', $e->getMessage());
        }
    }

    public function test_compile_error_includes_view_name(): void
    {
        // A view with a parse error (unclosed echo tag)
        $this->write('bad.lte', '{{ $unclosed');

        try {
            $this->render('bad');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bad', $e->getMessage());
        }
    }

    // ── Parser line tracking ──────────────────────────────────────────────────

    public function test_parser_tracks_line_numbers_in_nodes(): void
    {
        $parser = new \Luany\Lte\Parser();
        $source = "<p>line 1</p>\n{{ \$name }}\n@if(\$active)";
        $ast    = $parser->parse($source);

        // Find echo node
        $echoNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'echo'));
        $this->assertCount(1, $echoNodes);
        $this->assertSame(2, $echoNodes[0]['line']);

        // Find directive node
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertCount(1, $dirNodes);
        $this->assertSame(3, $dirNodes[0]['line']);
    }

    public function test_parser_tracks_line_in_text_nodes(): void
    {
        $parser = new \Luany\Lte\Parser();
        $ast    = $parser->parse("<p>first</p>\n<p>second</p>");
        $textNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'text'));
        $this->assertSame(1, $textNodes[0]['line']);
    }
}