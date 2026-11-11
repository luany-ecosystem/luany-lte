<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Ast\BlockNode;
use Luany\Lte\Ast\Template;
use Luany\Lte\Ast\TokenNode;
use Luany\Lte\Compiler;
use Luany\Lte\Engine;
use Luany\Lte\Parser;
use Luany\Lte\TemplateSyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * Real syntax tree, compile-time structure validation and PHP linting (#13).
 */
class AstTest extends TestCase
{
    private Compiler $compiler;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
        $compiler       = $this->compiler;
        $this->parser   = new Parser(fn (string $n) => $compiler->isDirective($n));
    }

    private function tree(string $source): Template
    {
        return $this->compiler->buildTree($this->parser->parse($source));
    }

    private function assertSyntaxError(string $source, string $message, int $line, int $column = 0): void
    {
        try {
            $this->tree($source);
            $this->fail("Expected TemplateSyntaxException for:\n{$source}");
        } catch (TemplateSyntaxException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($line, $e->templateLine, 'line');
            if ($column > 0) {
                $this->assertSame($column, $e->templateColumn, 'column');
            }
        }
    }

    // ── Tree shape ───────────────────────────────────────────────────────────

    public function test_if_elseif_else_becomes_one_block_with_branches(): void
    {
        $tree = $this->tree("@if(\$a)\nA\n@elseif(\$b)\nB\n@else\nC\n@endif");

        $this->assertCount(1, $tree->children);
        $block = $tree->children[0];
        $this->assertInstanceOf(BlockNode::class, $block);
        $this->assertSame('if', $block->name);
        $this->assertSame(['if', 'elseif', 'else'], array_map(fn ($b) => $b->directive->name(), $block->branches));
        $this->assertSame('endif', $block->closing->name());
        $this->assertSame(1, $block->line);
    }

    public function test_nested_blocks_and_walk_depths(): void
    {
        $tree = $this->tree("@section('c')\n@foreach(\$xs as \$x)\n@if(\$x){{ \$x }}@endif\n@endforeach\n@endsection");

        $seen = [];
        $tree->walk(function ($node, int $depth) use (&$seen) {
            if ($node instanceof BlockNode) {
                $seen[] = "{$node->name}@{$depth}";
            }
        });

        $this->assertSame(['section@0', 'foreach@1', 'if@2'], $seen);
    }

    public function test_forelse_with_empty_branch_and_inline_section_is_a_leaf(): void
    {
        $tree = $this->tree("@section('title', 'Home')\n@forelse(\$xs as \$x)\n{{ \$x }}\n@empty\nnone\n@endforelse");

        $this->assertInstanceOf(TokenNode::class, $tree->children[0]);
        $forelse = array_values(array_filter($tree->children, fn ($n) => $n instanceof BlockNode))[0];
        $this->assertSame(['forelse', 'empty'], array_map(fn ($b) => $b->directive->name(), $forelse->branches));
    }

    public function test_tree_compiles_to_the_same_php_as_the_token_list(): void
    {
        $source = "@extends('layouts.app')\n@section('c')\n@forelse(\$xs as \$x)\n<b>{{ \$x }}</b>\n@empty\n-\n@endforelse\n@endsection";
        $tokens = $this->parser->parse($source);

        $this->assertSame($this->compiler->compile($tokens), $this->compiler->compileTree($this->compiler->buildTree($tokens)));
    }

    // ── Structure errors ─────────────────────────────────────────────────────

    public function test_unclosed_block_reports_opening_position(): void
    {
        $this->assertSyntaxError("<ul>\n  @foreach(\$xs as \$x)\n  <li>{{ \$x }}</li>\n</ul>", 'Unclosed @foreach: missing @endforeach', 2, 3);
    }

    public function test_mismatched_closer(): void
    {
        $this->assertSyntaxError("@if(\$a)\n@foreach(\$b as \$c)\n@endif", '@foreach opened on line 2 is still open (expected @endforeach)', 3, 1);
    }

    public function test_stray_closer(): void
    {
        $this->assertSyntaxError("ok\n@endif", 'Unexpected @endif: there is no open block to close', 2, 1);
    }

    public function test_else_outside_if_and_after_else(): void
    {
        $this->assertSyntaxError("@foreach(\$a as \$b)\n@else\n@endforeach", 'Unexpected @else inside @foreach (line 1)', 2);
        $this->assertSyntaxError("@if(\$a)\n@else\n@elseif(\$b)\n@endif", '@else (line 2) must be the last branch of @if', 3);
        $this->assertSyntaxError("@if(\$a)\n@else\n@else\n@endif", 'must be the last branch', 3);
    }

    public function test_duplicate_empty_in_forelse(): void
    {
        $this->assertSyntaxError("@forelse(\$a as \$b)\n@empty\n@empty\n@endforelse", 'Duplicate @empty in @forelse', 3);
    }

    public function test_missing_arguments(): void
    {
        $this->assertSyntaxError("@if\nx\n@endif", '@if requires arguments', 1, 1);
        $this->assertSyntaxError('@include', '@include requires arguments', 1, 1);
    }

    public function test_glued_closer_gets_a_hint(): void
    {
        $this->assertSyntaxError(
            "@component('card')\n@slot('title')Title@endslotBody\n@endcomponent",
            '"@endslotBody" on line 2 is read as text: separate the directive from the text ("@endslot Body")',
            3
        );
    }

    // ── PHP linting ──────────────────────────────────────────────────────────

    public function test_php_syntax_error_in_echo(): void
    {
        $this->assertSyntaxError("<p>\n  {{ \$user->name( }}\n</p>", 'PHP syntax error in {{ }} on line 2, column 3', 2, 3);
    }

    public function test_php_syntax_error_in_directive_arguments(): void
    {
        $this->assertSyntaxError("ok\n@if(\$a ===)\nx\n@endif", 'PHP syntax error in @if on line 2', 2);
        $this->assertSyntaxError('@foreach($items into $i) @endforeach', 'PHP syntax error in @foreach', 1);
    }

    public function test_php_syntax_error_in_multiline_php_block_points_at_the_bad_line(): void
    {
        $this->assertSyntaxError("a\n@php\n\$x = 1;\n\$y = ;\n@endphp", 'PHP syntax error in @php block on line 4', 4);
    }

    public function test_valid_complex_expressions_pass(): void
    {
        $tree = $this->tree(implode("\n", [
            "{{ \$a ?? 'default' }}",
            "{!! json_encode(['a' => [1, 2]], JSON_PRETTY_PRINT) !!}",
            "@foreach(\$rows as \$key => [\$x, \$y]) {{ \$key }} @endforeach",
            "@for(\$i = 0; \$i < 3; \$i++) {{ \$i }} @endfor",
            "@php(\$total = array_sum(array_map(fn (\$r) => \$r['qty'] * \$r['price'], \$rows)))",
            "@class(['btn', 'active' => \$on])",
            "@include('partials.row', ['row' => \$rows[0] ?? null])",
            "@json(['x' => \$x])",
            "@section('title', \$page?->title ?? 'Home')",
        ]));

        $this->assertNotEmpty($tree->children);
    }

    public function test_custom_directives_are_not_validated(): void
    {
        $this->compiler->directive('money', fn ($args) => "<?php echo number_format({$args}, 2); ?>");
        $this->compiler->directive('endif', fn () => '<?php endif; ?>'); // overriding a closer disables its structure checks

        $tree = $this->tree("@money(\$price)\n@endif");
        $this->assertCount(3, $tree->children);
    }

    // ── Engine integration ───────────────────────────────────────────────────

    public function test_engine_reports_view_name_line_and_column(): void
    {
        $dir = sys_get_temp_dir() . '/lte_ast_' . bin2hex(random_bytes(4));
        mkdir($dir . '/views/pages', 0777, true);
        file_put_contents($dir . '/views/pages/home.lte', "<h1>Hi</h1>\n@if(\$user)\n  {{ \$user->name }}\n");

        $engine = new Engine($dir . '/views', $dir . '/cache', true);

        try {
            $engine->render('pages.home', ['user' => null]);
            $this->fail('Expected TemplateSyntaxException');
        } catch (TemplateSyntaxException $e) {
            $this->assertSame('pages.home', $e->view);
            $this->assertSame(2, $e->templateLine);
            $this->assertSame(1, $e->templateColumn);
            $this->assertStringStartsWith('LTE compilation error in view [pages.home]: Unclosed @if', $e->getMessage());
        }

        try {
            $engine->parseView('pages.home');
            $this->fail('Expected TemplateSyntaxException');
        } catch (TemplateSyntaxException $e) {
            $this->assertSame('pages.home', $e->view);
        }

        file_put_contents($dir . '/views/pages/ok.lte', "@if(\$a)A@endif");
        $this->assertInstanceOf(Template::class, $engine->parseView('pages.ok'));
    }
}
