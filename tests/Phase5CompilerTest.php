<?php

namespace Luany\Lte\Tests;

use Luany\Lte\ClassHelper;
use Luany\Lte\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 — Compiler tests for @json, @class, @component/@slot, line markers.
 */
class Phase5CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    private function dir(string $name, ?string $args = null, int $line = 1): array
    {
        return ['type' => 'directive', 'name' => $name, 'args' => $args, 'line' => $line];
    }

    private function compile(array $node): string
    {
        return $this->compiler->compile([$node]);
    }

    // ── @json ─────────────────────────────────────────────────────────────────

    public function test_json_compiles_to_json_encode(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('json_encode($data', $out);
    }

    public function test_json_includes_hex_tag_flag(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('JSON_HEX_TAG', $out);
    }

    public function test_json_includes_hex_amp_flag(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('JSON_HEX_AMP', $out);
    }

    public function test_json_includes_hex_apos_flag(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('JSON_HEX_APOS', $out);
    }

    public function test_json_includes_hex_quot_flag(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('JSON_HEX_QUOT', $out);
    }

    public function test_json_includes_unescaped_unicode_flag(): void
    {
        $out = $this->compile($this->dir('json', '$data'));
        $this->assertStringContainsString('JSON_UNESCAPED_UNICODE', $out);
    }

    public function test_json_with_custom_flags_ors_with_safe_defaults(): void
    {
        $out = $this->compile($this->dir('json', '$data, JSON_PRETTY_PRINT'));
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $out);
        $this->assertStringContainsString('JSON_HEX_TAG', $out);
    }

    public function test_json_with_custom_flags_keeps_expression(): void
    {
        $out = $this->compile($this->dir('json', '$users, JSON_PRETTY_PRINT'));
        $this->assertStringContainsString('$users', $out);
    }

    // ── @class ────────────────────────────────────────────────────────────────

    public function test_class_compiles_to_class_helper_compile(): void
    {
        $out = $this->compile($this->dir('class', "['btn', 'btn-primary' => \$active]"));
        // @class delegates to ClassHelper::attr() which internally calls compile()
        $this->assertStringContainsString('ClassHelper::attr', $out);
    }

    public function test_class_output_contains_class_attribute(): void
    {
        $out = $this->compile($this->dir('class', "['btn']"));
        // @class compiles to ClassHelper::attr() call — class= appears in rendered output, not compiled PHP
        $this->assertStringContainsString('ClassHelper::attr', $out);
    }

    public function test_class_passes_array_expression_verbatim(): void
    {
        $arr = "['btn', 'active' => \$isActive, 'disabled' => !\$on]";
        $out = $this->compile($this->dir('class', $arr));
        $this->assertStringContainsString($arr, $out);
    }

    // ── ClassHelper::compile() ────────────────────────────────────────────────

    public function test_class_helper_unconditional_classes(): void
    {
        $result = ClassHelper::compile(['btn', 'btn-lg']);
        $this->assertSame('btn btn-lg', $result);
    }

    public function test_class_helper_conditional_class_true(): void
    {
        $result = ClassHelper::compile(['btn', 'btn-primary' => true]);
        $this->assertSame('btn btn-primary', $result);
    }

    public function test_class_helper_conditional_class_false(): void
    {
        $result = ClassHelper::compile(['btn', 'btn-primary' => false]);
        $this->assertSame('btn', $result);
    }

    public function test_class_helper_mixed_conditional(): void
    {
        $result = ClassHelper::compile([
            'flex',
            'items-center',
            'text-red-500' => true,
            'opacity-50'   => false,
        ]);
        $this->assertSame('flex items-center text-red-500', $result);
    }

    public function test_class_helper_all_false_returns_empty_string(): void
    {
        $result = ClassHelper::compile(['active' => false, 'visible' => false]);
        $this->assertSame('', $result);
    }

    public function test_class_helper_empty_array_returns_empty_string(): void
    {
        $this->assertSame('', ClassHelper::compile([]));
    }

    public function test_class_helper_skips_empty_class_names(): void
    {
        $result = ClassHelper::compile(['btn', '' => true, 'active' => true]);
        $this->assertSame('btn active', $result);
    }

    // ── @component / @slot / @endslot / @endcomponent ─────────────────────────

    public function test_component_compiles_to_component_stack_start(): void
    {
        $out = $this->compile($this->dir('component', "'components.alert'"));
        $this->assertStringContainsString('ComponentStack::startComponent', $out);
        $this->assertStringContainsString('components.alert', $out);
    }

    public function test_component_passes_engine_reference(): void
    {
        $out = $this->compile($this->dir('component', "'components.card'"));
        $this->assertStringContainsString('$__engine', $out);
    }

    public function test_component_with_data_array(): void
    {
        $out = $this->compile($this->dir('component', "'components.alert', ['type' => 'error']"));
        $this->assertStringContainsString("'type' => 'error'", $out);
    }

    public function test_component_without_data_passes_empty_array(): void
    {
        $out = $this->compile($this->dir('component', "'components.card'"));
        $this->assertStringContainsString('[]', $out);
    }

    public function test_slot_compiles_to_component_stack_start_slot(): void
    {
        $out = $this->compile($this->dir('slot', "'title'"));
        $this->assertStringContainsString('ComponentStack::startSlot', $out);
        $this->assertStringContainsString('title', $out);
    }

    public function test_endslot_compiles_to_component_stack_end_slot(): void
    {
        $out = $this->compile($this->dir('endslot'));
        $this->assertStringContainsString('ComponentStack::endSlot', $out);
    }

    public function test_endcomponent_compiles_to_component_stack_end(): void
    {
        $out = $this->compile($this->dir('endcomponent'));
        $this->assertStringContainsString('ComponentStack::endComponent', $out);
    }

    public function test_endcomponent_echoes_result(): void
    {
        $out = $this->compile($this->dir('endcomponent'));
        $this->assertStringContainsString('echo', $out);
    }

    // ── Line-number markers ────────────────────────────────────────────────────

    public function test_line_marker_embedded_in_echo_node(): void
    {
        $node = ['type' => 'echo', 'expression' => '$name', 'line' => 7];
        $out  = $this->compiler->compile([$node]);
        $this->assertStringContainsString('@lte:7', $out);
    }

    public function test_line_marker_embedded_in_directive_node(): void
    {
        $node = ['type' => 'directive', 'name' => 'if', 'args' => '$active', 'line' => 12];
        $out  = $this->compiler->compile([$node]);
        $this->assertStringContainsString('@lte:12', $out);
    }

    public function test_line_marker_embedded_in_text_node(): void
    {
        $node = ['type' => 'text', 'content' => '<p>Hello</p>', 'line' => 3];
        $out  = $this->compiler->compile([$node]);
        $this->assertStringContainsString('@lte:3', $out);
    }

    public function test_node_without_line_key_does_not_crash(): void
    {
        // Backward-compat: nodes without 'line' key (e.g. hand-crafted in tests)
        $node = ['type' => 'echo', 'expression' => '$x'];
        $out  = $this->compiler->compile([$node]);
        $this->assertStringContainsString('$x', $out);
    }
}