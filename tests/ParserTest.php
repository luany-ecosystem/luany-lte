<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LTE Parser
 *
 * Validates that the character-by-character tokenizer correctly
 * produces AST nodes — with zero regex in the pipeline.
 */
class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    // ── Plain text ────────────────────────────────────────────────────────────

    public function test_plain_text_produces_text_node(): void
    {
        $ast = $this->parser->parse('<h1>Hello</h1>');
        $this->assertCount(1, $ast);
        $this->assertSame('text', $ast[0]['type']);
        $this->assertSame('<h1>Hello</h1>', $ast[0]['content']);
    }

    public function test_empty_string_produces_no_meaningful_nodes(): void
    {
        $ast = $this->parser->parse('');
        $content = implode('', array_map(fn($n) => $n['content'] ?? '', $ast));
        $this->assertSame('', $content);
    }

    // ── Echo ──────────────────────────────────────────────────────────────────

    public function test_echo_tag_produces_echo_node(): void
    {
        $ast = $this->parser->parse('{{ $name }}');
        $echoNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'echo'));
        $this->assertCount(1, $echoNodes);
        $this->assertSame('$name', $echoNodes[0]['expression']);
    }

    public function test_echo_with_method_call(): void
    {
        $ast = $this->parser->parse('{{ $user->name }}');
        $echo = array_values(array_filter($ast, fn($n) => $n['type'] === 'echo'))[0];
        $this->assertSame('$user->name', $echo['expression']);
    }

    public function test_echo_with_function_call(): void
    {
        $ast = $this->parser->parse('{{ strtoupper($text) }}');
        $echo = array_values(array_filter($ast, fn($n) => $n['type'] === 'echo'))[0];
        $this->assertSame('strtoupper($text)', $echo['expression']);
    }

    public function test_multiple_echo_tags(): void
    {
        $ast = $this->parser->parse('{{ $first }} {{ $last }}');
        $echoNodes = array_filter($ast, fn($n) => $n['type'] === 'echo');
        $this->assertCount(2, $echoNodes);
    }

    // ── Raw echo ──────────────────────────────────────────────────────────────

    public function test_raw_echo_produces_raw_echo_node(): void
    {
        $ast = $this->parser->parse('{!! $html !!}');
        $rawNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'raw_echo'));
        $this->assertCount(1, $rawNodes);
        $this->assertSame('$html', $rawNodes[0]['expression']);
    }

    // ── Comments ──────────────────────────────────────────────────────────────

  public function test_comment_is_removed_from_ast(): void
  {
      $ast = $this->parser->parse('{{-- this is a comment --}}');

      $this->assertIsArray($ast);
      $this->assertEmpty($ast);
  }


    public function test_comment_between_content_is_removed(): void
    {
        $ast = $this->parser->parse('<p>{{-- comment --}}Hello</p>');
        $allText = implode('', array_map(fn($n) => $n['type'] === 'text' ? $n['content'] : '', $ast));
        $this->assertStringNotContainsString('comment', $allText);
        $this->assertStringContainsString('Hello', $allText);
    }

    // ── Directives ────────────────────────────────────────────────────────────

    public function test_directive_without_args(): void
    {
        $ast = $this->parser->parse('@endif');
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertCount(1, $dirNodes);
        $this->assertSame('endif', $dirNodes[0]['name']);
        $this->assertNull($dirNodes[0]['args']);
    }

    public function test_directive_with_simple_args(): void
    {
        $ast = $this->parser->parse('@if($active)');
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertSame('if', $dirNodes[0]['name']);
        $this->assertSame('$active', $dirNodes[0]['args']);
    }

    public function test_directive_with_quoted_string_args(): void
    {
        $ast = $this->parser->parse("@extends('layouts.main')");
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertSame('extends', $dirNodes[0]['name']);
        $this->assertSame("'layouts.main'", $dirNodes[0]['args']);
    }

    public function test_directive_with_nested_parentheses(): void
    {
        $ast = $this->parser->parse("@if(in_array(\$role, ['admin', 'mod']))");
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertSame('if', $dirNodes[0]['name']);
        $this->assertStringContainsString('in_array', $dirNodes[0]['args']);
    }

    public function test_directive_with_comma_in_quoted_arg(): void
    {
        $ast = $this->parser->parse("@section('title', 'Hello, World')");
        $dirNodes = array_values(array_filter($ast, fn($n) => $n['type'] === 'directive'));
        $this->assertSame('section', $dirNodes[0]['name']);
        $this->assertStringContainsString('Hello, World', $dirNodes[0]['args']);
    }

    // ── Mixed content ─────────────────────────────────────────────────────────

    public function test_mixed_text_and_echo(): void
    {
        $ast = $this->parser->parse('<p>Hello, {{ $name }}!</p>');
        $types = array_column($ast, 'type');
        $this->assertContains('text', $types);
        $this->assertContains('echo', $types);
    }

    public function test_full_template_node_sequence(): void
    {
        $template = "@extends('layouts.main')\n@section('content')\n<h1>{{ \$title }}</h1>\n@endsection";
        $ast = $this->parser->parse($template);
        $types = array_column($ast, 'type');
        $this->assertContains('directive', $types);
        $this->assertContains('echo', $types);
        $this->assertContains('text', $types);
    }

    // ── Error handling ────────────────────────────────────────────────────────

    public function test_unclosed_echo_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->parser->parse('{{ $unclosed');
    }

    public function test_unclosed_comment_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->parser->parse('{{-- unclosed');
    }

    public function test_unclosed_raw_echo_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->parser->parse('{!! $unclosed');
    }
}