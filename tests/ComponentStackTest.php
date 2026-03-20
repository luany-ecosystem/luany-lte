<?php

namespace Luany\Lte\Tests;

use Luany\Lte\ComponentStack;
use Luany\Lte\Engine;
use PHPUnit\Framework\TestCase;

class ComponentStackTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;
    private Engine $engine;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/lte_cs_views_' . uniqid();
        $this->cacheDir = sys_get_temp_dir() . '/lte_cs_cache_' . uniqid();

        mkdir($this->viewsDir, 0755, true);
        mkdir($this->cacheDir, 0755, true);

        $this->engine = new Engine(
            viewsPath:  $this->viewsDir,
            cachePath:  $this->cacheDir,
            autoReload: true
        );

        ComponentStack::reset();
    }

    protected function tearDown(): void
    {
        ComponentStack::reset();
        $this->removeDir($this->viewsDir);
        $this->removeDir($this->cacheDir);
    }

    private function write(string $path, string $content): void
    {
        $fullPath = $this->viewsDir . '/' . ltrim($path, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($fullPath, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        rmdir($dir);
    }

    // ── startComponent / endComponent ─────────────────────────────────────────

    public function test_start_and_end_component_renders_view(): void
    {
        $this->write('comp.lte', '<b>{{ $slot }}</b>');

        ComponentStack::startComponent($this->engine, 'comp');
        echo 'hello';
        $out = ComponentStack::endComponent();

        $this->assertSame('<b>hello</b>', $out);
    }

    public function test_end_component_without_start_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@endcomponent/');
        ComponentStack::endComponent();
    }

    // ── startSlot / endSlot ───────────────────────────────────────────────────

    public function test_named_slot_is_available_as_variable(): void
    {
        $this->write('comp.lte', '{{ $title }}|{{ $slot }}');

        ComponentStack::startComponent($this->engine, 'comp');
        ComponentStack::startSlot('title');
        echo 'My Title';
        ComponentStack::endSlot();
        echo 'body';
        $out = ComponentStack::endComponent();

        $this->assertSame('My Title|body', $out);
    }

    public function test_multiple_named_slots(): void
    {
        $this->write('comp.lte', '[{{ $header }}][{{ $footer }}][{{ $slot }}]');

        ComponentStack::startComponent($this->engine, 'comp');
        ComponentStack::startSlot('header');
        echo 'H';
        ComponentStack::endSlot();
        echo 'BODY';
        ComponentStack::startSlot('footer');
        echo 'F';
        ComponentStack::endSlot();
        $out = ComponentStack::endComponent();

        $this->assertSame('[H][F][BODY]', $out);
    }

    public function test_start_slot_without_component_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        ComponentStack::startSlot('title');
    }

    public function test_end_slot_without_active_slot_does_not_crash(): void
    {
        // Verify that calling endSlot() when no named slot is active
        // (i.e. the orphan path where frame['active'] === null) does not throw.
        //
        // We test this through the Engine so that all ob_start/ob_get_clean
        // calls are managed inside Engine::evaluate() — not exposed to PHPUnit.
        //
        // Template: opens a slot, ends it, then calls endSlot again (orphan).
        // The second @endslot is the orphan case we are testing.
        $this->write('components/comp.lte', '{{ $slot }}');

        // page.lte: uses @component with one named slot + one extra @endslot (orphan)
        $this->write('page.lte',
            '@component("components.comp")' .
            '@slot("title")Title content@endslot' .
            '@endslot' .   // ← orphan: no active named slot at this point
            'Body content.' .
            '@endcomponent'
        );

        // Must not throw
        $out = $this->engine->render('page');
        $this->assertIsString($out);
        $this->assertStringContainsString('Body content.', $out);
    }

    // ── reset() ───────────────────────────────────────────────────────────────

    public function test_reset_clears_all_state(): void
    {
        $this->write('comp.lte', '{{ $slot }}');
        ComponentStack::startComponent($this->engine, 'comp');

        // Reset without completing — should not leave dangling output buffers
        ComponentStack::reset();

        // Can start a new component without issues
        ComponentStack::startComponent($this->engine, 'comp');
        echo 'clean';
        $out = ComponentStack::endComponent();
        $this->assertSame('clean', $out);
    }

    // ── Nested components ─────────────────────────────────────────────────────

    public function test_nested_components_resolve_independently(): void
    {
        // Slot content is already-rendered HTML — use {!! !!} to avoid double-escaping
        $this->write('outer.lte', '<outer>{!! $slot !!}</outer>');
        $this->write('inner.lte', '<inner>{!! $slot !!}</inner>');

        ComponentStack::startComponent($this->engine, 'outer');

        ComponentStack::startComponent($this->engine, 'inner');
        echo 'deep';
        $innerOut = ComponentStack::endComponent();
        echo $innerOut;

        $out = ComponentStack::endComponent();

        $this->assertSame('<outer><inner>deep</inner></outer>', $out);
    }

    // ── Data passing ──────────────────────────────────────────────────────────

    public function test_explicit_data_is_available_as_variables(): void
    {
        $this->write('comp.lte', '{{ $color }}-{{ $slot }}');

        ComponentStack::startComponent($this->engine, 'comp', ['color' => 'red']);
        echo 'text';
        $out = ComponentStack::endComponent();

        $this->assertSame('red-text', $out);
    }

    public function test_named_slot_overrides_explicit_data_key(): void
    {
        // If both explicit data and a slot have the same key, slot wins
        $this->write('comp.lte', '{{ $title }}');

        ComponentStack::startComponent($this->engine, 'comp', ['title' => 'from-data']);
        ComponentStack::startSlot('title');
        echo 'from-slot';
        ComponentStack::endSlot();
        $out = ComponentStack::endComponent();

        $this->assertSame('from-slot', $out);
    }

    // ── Default slot trimming ─────────────────────────────────────────────────

    public function test_default_slot_is_trimmed(): void
    {
        $this->write('comp.lte', '[{{ $slot }}]');

        ComponentStack::startComponent($this->engine, 'comp');
        echo "\n  content  \n";
        $out = ComponentStack::endComponent();

        $this->assertSame('[content]', $out);
    }
}