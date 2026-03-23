<?php

namespace Luany\Lte;

/*
 * LTE Parser
 *
 * Converts .lte source code into an Abstract Syntax Tree (AST).
 *
 * Tokens:
 *   {{ $var }}          → echo (HTML-escaped)
 *   {!! $var !!}        → raw_echo (unescaped)
 *   @directive(args)    → directive
 *   @php ... @endphp    → php_block
 *   {{-- comment --}}   → removed from AST
 *   plain text          → text
 *
 * Line tracking (Phase 5):
 *   Every node now carries a 'line' key with the 1-based source line number
 *   where that token starts. The Compiler uses this to embed PHP comments
 *   that allow the Engine to map runtime errors back to .lte source lines.
 */
class Parser
{
    private string $source;
    private int    $position = 0;
    private int    $length   = 0;
    private int    $line     = 1;   // current 1-based line counter

    /**
     * Parse LTE source into AST.
     *
     * @param  string  $source  Raw .lte template source
     * @return array<int, array<string, mixed>>  Array of AST nodes
     */
    public function parse(string $source): array
    {
        $this->source   = $source;
        $this->position = 0;
        $this->length   = strlen($source);
        $this->line     = 1;

        $nodes = [];

        while ($this->position < $this->length) {
            $node = $this->parseNext();
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    // ── Token dispatch ─────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function parseNext(): ?array
    {
        if ($this->match('{{--')) return $this->parseComment();
        if ($this->match('{!!'))  return $this->parseRawEcho();
        if ($this->match('{{'))   return $this->parseEcho();
        if ($this->match('@'))    return $this->parseDirective();
        return $this->parseText();
    }

    // ── Comment {{-- ... --}} ──────────────────────────────────────────────────

    private function parseComment(): null
    {
        $end = strpos($this->source, '--}}', $this->position);
        if ($end === false) {
            throw new \RuntimeException(
                "Unclosed comment on line {$this->line}."
            );
        }

        // Advance line counter past the comment content
        $this->advanceLinesTo($end + 4);
        $this->position = $end + 4;

        return null; // Comments are not included in the AST
    }

    // ── Echo {{ $var }} ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseEcho(): array
    {
        $startLine = $this->line;
        $end = strpos($this->source, '}}', $this->position);
        if ($end === false) {
            throw new \RuntimeException(
                "Unclosed echo tag on line {$startLine}."
            );
        }

        $expression = trim(substr($this->source, $this->position, $end - $this->position));
        $this->advanceLinesTo($end + 2);
        $this->position = $end + 2;

        return ['type' => 'echo', 'expression' => $expression, 'line' => $startLine];
    }

    // ── Raw echo {!! $var !!} ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseRawEcho(): array
    {
        $startLine = $this->line;
        $end = strpos($this->source, '!!}', $this->position);
        if ($end === false) {
            throw new \RuntimeException(
                "Unclosed raw echo tag on line {$startLine}."
            );
        }

        $expression = trim(substr($this->source, $this->position, $end - $this->position));
        $this->advanceLinesTo($end + 3);
        $this->position = $end + 3;

        return ['type' => 'raw_echo', 'expression' => $expression, 'line' => $startLine];
    }

    // ── Directive @name(...) ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseDirective(): array
    {
        $startLine = $this->line;

        // Read directive name: [a-zA-Z0-9_]+
        $nameEnd = $this->position;
        while ($nameEnd < $this->length &&
               (ctype_alnum($this->source[$nameEnd]) || $this->source[$nameEnd] === '_')) {
            $nameEnd++;
        }

        $name           = substr($this->source, $this->position, $nameEnd - $this->position);
        $this->position = $nameEnd;

        // @php block (without parentheses) — consume raw content until @endphp
        if ($name === 'php' &&
            ($this->position >= $this->length || $this->source[$this->position] !== '(')) {

            $end = strpos($this->source, '@endphp', $this->position);
            if ($end === false) {
                throw new \RuntimeException(
                    "Unclosed @php block — missing @endphp (opened on line {$startLine})."
                );
            }

            $content = substr($this->source, $this->position, $end - $this->position);
            $this->advanceLinesTo($end + strlen('@endphp'));
            $this->position = $end + strlen('@endphp');

            return ['type' => 'php_block', 'content' => $content, 'line' => $startLine];
        }

        $args = null;
        if ($this->position < $this->length && $this->source[$this->position] === '(') {
            $args = $this->parseDirectiveArgs($startLine);
        }

        return ['type' => 'directive', 'name' => $name, 'args' => $args, 'line' => $startLine];
    }

    private function parseDirectiveArgs(int $startLine): string
    {
        $this->position++; // skip opening '('

        $depth = 1;
        $start = $this->position;

        while ($this->position < $this->length && $depth > 0) {
            $char = $this->source[$this->position];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === "'" || $char === '"') {
                $this->skipString($char);
                continue;
            } elseif ($char === "\n") {
                $this->line++;
            }

            $this->position++;
        }

        if ($depth !== 0) {
            throw new \RuntimeException(
                "Unclosed directive arguments on line {$startLine}."
            );
        }

        return trim(substr($this->source, $start, $this->position - $start - 1));
    }

    private function skipString(string $quote): void
    {
        $this->position++; // skip opening quote

        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            if ($char === '\\') {
                $this->position += 2;
                continue;
            }

            if ($char === "\n") {
                $this->line++;
            }

            if ($char === $quote) {
                $this->position++;
                return;
            }

            $this->position++;
        }
    }

    // ── Plain text ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseText(): array
    {
        $startLine = $this->line;
        $start     = $this->position;

        while ($this->position < $this->length) {
            $char = $this->source[$this->position];
            $next = $this->position + 1 < $this->length
                ? $this->source[$this->position + 1]
                : '';

            // Stop at any token start
            if ($char === '@' ||
                ($char === '{' && ($next === '{' || $next === '!'))) {
                break;
            }

            if ($char === "\n") {
                $this->line++;
            }

            $this->position++;
        }

        $text = substr($this->source, $start, $this->position - $start);

        return ['type' => 'text', 'content' => $text, 'line' => $startLine];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Check if the current position matches $pattern.
     * If it matches, advance position past it and return true.
     * Does NOT update line counter — callers that skip multi-line content
     * must call advanceLinesTo() explicitly.
     */
    private function match(string $pattern): bool
    {
        $len = strlen($pattern);
        if ($this->position + $len > $this->length) {
            return false;
        }

        if (substr($this->source, $this->position, $len) === $pattern) {
            $this->position += $len;
            return true;
        }

        return false;
    }

    /**
     * Count newlines in source between current position and $targetPos,
     * updating $this->line accordingly.
     *
     * Called before jumping $this->position forward by a large amount
     * (e.g. after strpos() finds a closing tag) so line tracking stays accurate.
     */
    private function advanceLinesTo(int $targetPos): void
    {
        $slice      = substr($this->source, $this->position, $targetPos - $this->position);
        $this->line += substr_count($slice, "\n");
    }
}