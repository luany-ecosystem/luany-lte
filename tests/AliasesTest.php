<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Aliases;
use Luany\Lte\Compiler;
use Luany\Lte\Engine;
use Luany\Lte\Parser;
use Luany\Lte\TemplateSyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * Portuguese directive aliases ("Luany em Português", L1).
 */
class AliasesTest extends TestCase
{
    private function compile(string $source, string $syntax = 'pt', ?Compiler $compiler = null): string
    {
        $compiler ??= new Compiler();
        $aliases  = Aliases::for($syntax);
        $compiler->setAliases($aliases);
        $parser = new Parser(fn (string $n): bool => $compiler->isDirective($n), $aliases);

        return $compiler->compileTree($compiler->buildTree($parser->parse($source)));
    }

    /** @return array<string, array{string, string}> */
    public static function pairs(): array
    {
        return [
            'if'        => ['@if($a) A @elseif($b) B @else C @endif', '@se($a) A @senaose($b) B @senao C @fimse'],
            'unless'    => ['@unless($a) x @endunless', '@exceto($a) x @fimexceto'],
            'isset'     => ['@isset($a) x @endisset', '@definido($a) x @fimdefinido'],
            'ifempty'   => ['@ifempty($a) x @endifempty', '@sevazio($a) x @fimsevazio'],
            'empty'     => ['@empty($a) x @endempty', '@vazio($a) x @fimvazio'],
            'foreach'   => ['@foreach($xs as $x) {{ $x }} @endforeach', '@paracada($xs as $x) {{ $x }} @fimparacada'],
            'forelse'   => ['@forelse($xs as $x) {{ $x }} @empty none @endforelse', '@listar($xs as $x) {{ $x }} @vazio none @fimlistar'],
            'for'       => ['@for($i = 0; $i < 3; $i++) x @endfor', '@para($i = 0; $i < 3; $i++) x @fimpara'],
            'while'     => ['@while($a) x @endwhile', '@enquanto($a) x @fimenquanto'],
            'layout'    => ["@extends('l')\n@section('c') x @endsection\n@yield('c')", "@estende('l')\n@secao('c') x @fimsecao\n@exibir('c')"],
            'include'   => ["@include('p')", "@incluir('p')"],
            'component' => ["@component('c') x @endcomponent", "@componente('c') x @fimcomponente"],
            'method'    => ["@method('PUT')", "@metodo('PUT')"],
            'auth'      => ['@auth x @else y @endauth', '@autenticado x @senao y @fimautenticado'],
            'guest'     => ['@guest x @endguest', '@visitante x @fimvisitante'],
            'class'     => ["<p @class(['a' => \$b])>", "<p @classe(['a' => \$b])>"],
        ];
    }

    /** @dataProvider pairs */
    #[\PHPUnit\Framework\Attributes\DataProvider('pairs')]
    public function test_alias_compiles_exactly_like_english(string $english, string $portuguese): void
    {
        $this->assertSame($this->compile($english, 'en'), $this->compile($portuguese));
    }

    public function test_accented_forms_and_spelling_variants(): void
    {
        $expected = $this->compile("@section('c') x @endsection @if(\$a) @method('PUT') @elseif(\$b) @else @endif", 'en');

        foreach (['secao', 'seccao', 'seção', 'secção'] as $s) {
            $this->assertSame($expected, $this->compile("@{$s}('c') x @fim{$s} @se(\$a) @método('PUT') @senãose(\$b) @senão @fimse"), $s);
        }
    }

    public function test_english_and_portuguese_mix_in_one_template(): void
    {
        $this->assertSame(
            $this->compile('@if($a) @foreach($xs as $x) x @endforeach @endif', 'en'),
            $this->compile('@se($a) @foreach($xs as $x) x @fimparacada @endif')
        );
    }

    public function test_aliases_are_lowercase_only_like_english_directives(): void
    {
        $this->assertStringNotContainsString('<?php if', $this->compile('@Se($a) x'));
        $this->assertStringNotContainsString('<?php if', $this->compile('@If($a) x'));
    }

    public function test_english_syntax_leaves_portuguese_words_as_text(): void
    {
        $source = 'Contacte @se @paracada @secção @fimse';
        $this->assertStringContainsString($source, $this->compile($source, 'en'));
    }

    public function test_errors_name_the_directive_as_written(): void
    {
        $cases = [
            '@paracada($xs as $x) x @fimse'        => 'Unexpected @fimse: @paracada opened on line 1 is still open (expected @fimparacada)',
            '@se($a) x'                            => 'Unclosed @se: missing @fimse',
            '@fimse'                               => 'Unexpected @fimse: there is no open block to close',
            '@se($a) @senao @senaose($b) @fimse'   => 'Unexpected @senaose: @senao (line 1) must be the last branch of @se',
            '@paracada'                            => '@paracada requires arguments',
            '@se($a +) x @fimse'                   => 'PHP syntax error in @se',
            '@vazio'                               => 'bare @vazio belongs to @listar',
        ];
        foreach ($cases as $source => $message) {
            try {
                $this->compile($source);
                $this->fail("No error for: {$source}");
            } catch (TemplateSyntaxException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $source);
            }
        }
    }

    public function test_english_opener_keeps_english_hint(): void
    {
        $this->expectExceptionMessage('(expected @endif)');
        $this->compile('@if($a) x @fimparacada');
    }

    public function test_glued_closer_hint_knows_aliases(): void
    {
        $this->expectExceptionMessage('"@fimseTexto" on line 1 is read as text: separate the directive from the text ("@fimse Texto")');
        $this->compile('@se($a) x @fimseTexto');
    }

    public function test_custom_directive_wins_over_alias(): void
    {
        $compiler = new Compiler();
        $compiler->directive('se', fn (?string $args): string => '<b>custom</b>');

        $this->assertStringContainsString('<b>custom</b>', $this->compile('@se', 'pt', $compiler));
    }

    public function test_table_is_consistent(): void
    {
        $seen = [];
        foreach (Aliases::entries('pt') as $entry) {
            $this->assertContains($entry['en'], Compiler::BUILTIN_DIRECTIVES, "unknown directive {$entry['en']}");
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $entry['pt'], 'canonical form is lowercase ASCII');
            foreach ([$entry['pt'], ...$entry['variants']] as $written) {
                $this->assertSame(mb_strtolower($written), $written, "{$written} must be lowercase");
                $this->assertNotContains($written, Compiler::BUILTIN_DIRECTIVES, "{$written} collides with an English directive");
                $this->assertArrayNotHasKey($written, $seen, "{$written} is listed twice");
                $seen[$written] = true;
            }
        }
    }

    public function test_engine_parser_follows_the_syntax(): void
    {
        $engine = (new Engine(sys_get_temp_dir()))->setSyntax('pt');
        $tokens = $engine->getParser()->parse('@se($a) x @fimse');

        $this->assertSame('if', $tokens[0]['name']);
        $this->assertSame('se', $tokens[0]['written']);
    }

    public function test_unknown_syntax_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Aliases::for('fr');
    }

    public function test_engine_renders_portuguese_and_keys_the_cache_by_syntax(): void
    {
        $dir = sys_get_temp_dir() . '/lte-pt-' . bin2hex(random_bytes(4));
        mkdir($dir . '/views', 0777, true);
        file_put_contents($dir . '/views/lista.lte', "@paracada(\$itens as \$i)<li>{{ \$i }}</li>@fimparacada");

        try {
            $pt = (new Engine($dir . '/views', $dir . '/cache'))->setSyntax('pt');
            $this->assertSame('<li>a</li><li>b</li>', trim($pt->render('lista', ['itens' => ['a', 'b']])));

            // Same view, English syntax: a different cache entry, and the words stay text.
            $en = new Engine($dir . '/views', $dir . '/cache');
            $this->assertStringContainsString('@paracada', $en->render('lista', ['itens' => ['a']]));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
