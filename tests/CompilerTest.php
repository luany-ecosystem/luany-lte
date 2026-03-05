<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LTE Compiler
 *
 * Validates that each directive node produces the correct PHP output.
 * Covers every case in the Compiler switch block.
 */
class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    private function dir(string $name, ?string $args = null): array
    {
        return ['type' => 'directive', 'name' => $name, 'args' => $args];
    }

    private function compile(array $node): string
    {
        return $this->compiler->compile([$node]);
    }

    // ── Echo output ───────────────────────────────────────────────────────────

    public function test_echo_compiles_with_htmlspecialchars(): void
    {
        $out = $this->compiler->compile([['type' => 'echo', 'expression' => '$name']]);
        $this->assertStringContainsString('htmlspecialchars', $out);
        $this->assertStringContainsString('$name', $out);
        $this->assertStringContainsString('ENT_QUOTES', $out);
    }

    public function test_raw_echo_compiles_without_escaping(): void
    {
        $out = $this->compiler->compile([['type' => 'raw_echo', 'expression' => '$html']]);
        $this->assertStringContainsString('echo $html', $out);
        $this->assertStringNotContainsString('htmlspecialchars', $out);
    }

    public function test_text_node_passes_through_unchanged(): void
    {
        $out = $this->compiler->compile([['type' => 'text', 'content' => '<p>Hello</p>']]);
        $this->assertSame('<p>Hello</p>', $out);
    }

    // ── Conditionals ─────────────────────────────────────────────────────────

    public function test_if_directive(): void
    {
        $this->assertSame('<?php if($active): ?>', $this->compile($this->dir('if', '$active')));
    }

    public function test_elseif_directive(): void
    {
        $this->assertSame('<?php elseif($other): ?>', $this->compile($this->dir('elseif', '$other')));
    }

    public function test_else_directive(): void
    {
        $this->assertSame('<?php else: ?>', $this->compile($this->dir('else')));
    }

    public function test_endif_directive(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compile($this->dir('endif')));
    }

    public function test_unless_directive(): void
    {
        $this->assertSame('<?php if(!($banned)): ?>', $this->compile($this->dir('unless', '$banned')));
    }

    public function test_endunless_directive(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compile($this->dir('endunless')));
    }

    // ── Loops ─────────────────────────────────────────────────────────────────

    public function test_foreach_directive(): void
    {
        $this->assertSame('<?php foreach($users as $user): ?>', $this->compile($this->dir('foreach', '$users as $user')));
    }

    public function test_endforeach_directive(): void
    {
        $this->assertSame('<?php endforeach; ?>', $this->compile($this->dir('endforeach')));
    }

    public function test_for_directive(): void
    {
        $out = $this->compile($this->dir('for', '$i = 0; $i < 10; $i++'));
        $this->assertStringContainsString('for(', $out);
    }

    public function test_endfor_directive(): void
    {
        $this->assertSame('<?php endfor; ?>', $this->compile($this->dir('endfor')));
    }

    public function test_while_directive(): void
    {
        $this->assertSame('<?php while($condition): ?>', $this->compile($this->dir('while', '$condition')));
    }

    public function test_endwhile_directive(): void
    {
        $this->assertSame('<?php endwhile; ?>', $this->compile($this->dir('endwhile')));
    }

    // ── @forelse / @empty / @endforelse ───────────────────────────────────────

    public function test_forelse_creates_temp_var_and_foreach(): void
    {
        $out = $this->compile($this->dir('forelse', '$users as $user'));
        $this->assertStringContainsString('$__lte_fe1 = $users', $out);
        $this->assertStringContainsString('!empty($__lte_fe1)', $out);
        $this->assertStringContainsString('foreach ($__lte_fe1 as $user)', $out);
    }

    public function test_empty_closes_foreach_and_opens_else(): void
    {
        $out = $this->compile($this->dir('empty'));
        $this->assertStringContainsString('endforeach', $out);
        $this->assertStringContainsString('else:', $out);
    }

    public function test_endforelse_closes_if(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compile($this->dir('endforelse')));
    }

    public function test_forelse_depth_increments_for_nested_loops(): void
    {
        $out = $this->compiler->compile([
            $this->dir('forelse', '$posts as $post'),
            $this->dir('forelse', '$tags as $tag'),
        ]);
        $this->assertStringContainsString('__lte_fe1', $out);
        $this->assertStringContainsString('__lte_fe2', $out);
    }

    public function test_forelse_depth_resets_between_compile_calls(): void
    {
        $out1 = $this->compiler->compile([$this->dir('forelse', '$a as $x')]);
        $out2 = $this->compiler->compile([$this->dir('forelse', '$b as $y')]);
        $this->assertStringContainsString('__lte_fe1', $out1);
        $this->assertStringContainsString('__lte_fe1', $out2);
    }

    public function test_forelse_with_key_value_syntax(): void
    {
        $out = $this->compile($this->dir('forelse', '$data as $key => $value'));
        $this->assertStringContainsString('$__lte_fe1 = $data', $out);
        $this->assertStringContainsString('as $key => $value', $out);
    }

    // ── PHP blocks ────────────────────────────────────────────────────────────

    public function test_php_inline_with_args(): void
    {
        $this->assertSame('<?php $x = 1; ?>', $this->compile($this->dir('php', '$x = 1')));
    }

    public function test_php_block_without_args_opens_tag(): void
    {
        $this->assertSame('<?php ', $this->compile($this->dir('php')));
    }

    public function test_endphp_closes_tag(): void
    {
        $this->assertSame(' ?>', $this->compile($this->dir('endphp')));
    }

    // ── Security ─────────────────────────────────────────────────────────────

    public function test_csrf_generates_hidden_input(): void
    {
        $out = $this->compile($this->dir('csrf'));
        $this->assertStringContainsString('<input', $out);
        $this->assertStringContainsString('type="hidden"', $out);
        $this->assertStringContainsString('name="csrf_token"', $out);
        $this->assertStringContainsString('htmlspecialchars', $out);
    }

    public function test_method_generates_hidden_input_uppercased(): void
    {
        $out = $this->compile($this->dir('method', "'put'"));
        $this->assertStringContainsString('name="_method"', $out);
        $this->assertStringContainsString('value="PUT"', $out);
    }

    // ── Auth guards ───────────────────────────────────────────────────────────

    public function test_auth_checks_session(): void
    {
        $out = $this->compile($this->dir('auth'));
        $this->assertStringContainsString('isset', $out);
        $this->assertStringContainsString("'user_id'", $out);
    }

    public function test_endauth_closes_if(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compile($this->dir('endauth')));
    }

    public function test_guest_is_negation_of_auth(): void
    {
        $guest = $this->compile($this->dir('guest'));
        $this->assertStringContainsString('!isset', $guest);
    }

    public function test_endguest_closes_if(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compile($this->dir('endguest')));
    }

    // ── Layout system ─────────────────────────────────────────────────────────

    public function test_extends_sets_layout(): void
    {
        $out = $this->compile($this->dir('extends', "'layouts.main'"));
        $this->assertStringContainsString('SectionStack::setLayout', $out);
        $this->assertStringContainsString('layouts.main', $out);
    }

    public function test_section_inline_calls_set(): void
    {
        $out = $this->compile($this->dir('section', "'title', 'My Page'"));
        $this->assertStringContainsString('SectionStack::set', $out);
        $this->assertStringContainsString('title', $out);
    }

    public function test_section_block_calls_start(): void
    {
        $out = $this->compile($this->dir('section', "'content'"));
        $this->assertStringContainsString('SectionStack::start', $out);
    }

    public function test_endsection_calls_end(): void
    {
        $out = $this->compile($this->dir('endsection'));
        $this->assertStringContainsString('SectionStack::end', $out);
    }

    public function test_stop_is_alias_for_endsection(): void
    {
        $this->assertSame(
            $this->compile($this->dir('endsection')),
            $this->compile($this->dir('stop'))
        );
    }

    public function test_yield_calls_section_stack_get(): void
    {
        $out = $this->compile($this->dir('yield', "'content'"));
        $this->assertStringContainsString('SectionStack::get', $out);
        $this->assertStringContainsString('content', $out);
    }

    public function test_yield_with_default_value(): void
    {
        $out = $this->compile($this->dir('yield', "'title', 'Default'"));
        $this->assertStringContainsString('SectionStack::get', $out);
        $this->assertStringContainsString('Default', $out);
    }

    // ── Asset directives (v0.2) ───────────────────────────────────────────────

    public function test_style_calls_asset_stack_start_style(): void
    {
        $this->assertStringContainsString('AssetStack::startStyle', $this->compile($this->dir('style')));
    }

    public function test_endstyle_calls_asset_stack_end_style(): void
    {
        $this->assertStringContainsString('AssetStack::endStyle', $this->compile($this->dir('endstyle')));
    }

    public function test_script_calls_asset_stack_start_script(): void
    {
        $this->assertStringContainsString('AssetStack::startScript', $this->compile($this->dir('script')));
    }

    public function test_script_with_defer_option(): void
    {
        $out = $this->compile($this->dir('script', 'defer'));
        $this->assertStringContainsString('AssetStack::startScript', $out);
        $this->assertStringContainsString('defer', $out);
    }

    public function test_endscript_calls_asset_stack_end_script(): void
    {
        $this->assertStringContainsString('AssetStack::endScript', $this->compile($this->dir('endscript')));
    }

    public function test_styles_renders_accumulated_styles(): void
    {
        $this->assertStringContainsString('AssetStack::renderStyles', $this->compile($this->dir('styles')));
    }

    public function test_scripts_renders_accumulated_scripts(): void
    {
        $this->assertStringContainsString('AssetStack::renderScripts', $this->compile($this->dir('scripts')));
    }

    // ── Stack directives (v0.2) ───────────────────────────────────────────────

    public function test_push_calls_section_stack_start_push(): void
    {
        $out = $this->compile($this->dir('push', "'head'"));
        $this->assertStringContainsString('SectionStack::startPush', $out);
        $this->assertStringContainsString('head', $out);
    }

    public function test_endpush_calls_section_stack_end_push(): void
    {
        $this->assertStringContainsString('SectionStack::endPush', $this->compile($this->dir('endpush')));
    }

    public function test_stack_calls_section_stack_get_stack(): void
    {
        $out = $this->compile($this->dir('stack', "'head'"));
        $this->assertStringContainsString('SectionStack::getStack', $out);
        $this->assertStringContainsString('head', $out);
    }

    // ── Unknown directives ────────────────────────────────────────────────────

    public function test_unknown_directive_passes_through(): void
    {
        $out = $this->compile($this->dir('customThing', "'arg'"));
        $this->assertStringContainsString('@customThing', $out);
    }

    // ── Custom directives ─────────────────────────────────────────────────────

    public function test_custom_directive_is_called(): void
    {
        $this->compiler->directive('datetime', function ($args) {
            return "<?php echo date('d/m/Y', strtotime({$args})); ?>";
        });

        $out = $this->compile($this->dir('datetime', '$post->created_at'));
        $this->assertStringContainsString("date('d/m/Y'", $out);
        $this->assertStringContainsString('$post->created_at', $out);
    }

    // ── Debug helpers ─────────────────────────────────────────────────────────

    public function test_dump_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'dump', 'args' => '$user']
        ]);
        $this->assertStringContainsString('var_dump($user)', $output);
    }

    public function test_dd_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'dd', 'args' => '$user']
        ]);
        $this->assertStringContainsString('var_dump($user)', $output);
        $this->assertStringContainsString('die', $output);
    }

    // ── @isset / @endisset ────────────────────────────────────────────────────

    public function test_isset_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'isset', 'args' => '$user']
        ]);
        $this->assertStringContainsString('isset($user)', $output);
    }

    public function test_endisset_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'endisset', 'args' => null]
        ]);
        $this->assertStringContainsString('endif', $output);
    }

    // ── @ifempty / @endifempty ────────────────────────────────────────────────

    public function test_ifempty_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'ifempty', 'args' => '$items']
        ]);
        $this->assertStringContainsString('empty($items)', $output);
    }

    public function test_endifempty_directive(): void
    {
        $output = $this->compiler->compile([
            ['type' => 'directive', 'name' => 'endifempty', 'args' => null]
        ]);
        $this->assertStringContainsString('endif', $output);
    }

}