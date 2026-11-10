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
    /**
     * Directives that never take arguments. For these, whitespace followed by
     * '(' is plain text (e.g. `@endif (closing note)`), not an argument list.
     * `@empty` is included because inside @forelse it is argument-less.
     */
    private const NO_ARG_DIRECTIVES = [
        'else', 'endif', 'endunless', 'endforeach', 'endfor', 'endwhile',
        'empty', 'endforelse', 'endempty', 'endphp', 'csrf', 'auth', 'endauth', 'guest',
        'endguest', 'endsection', 'stop', 'show', 'endpush', 'endstyle',
        'endscript', 'styles', 'scripts', 'endslot', 'endcomponent',
        'endisset', 'endifempty', 'parent',
    ];

    /** @var \Closure(string): bool */
    private \Closure $isDirective;

    private string $source;
    private int    $position = 0;
    private int    $length   = 0;
    private int    $line     = 1;   // current 1-based line counter
    private int    $directiveEnd = -1; // offset just past the last parsed directive

    /**
     * @param (callable(string): bool)|null $isDirective  Decides whether `@name` is a
     *        directive. Defaults to the built-in directive set; the Engine passes
     *        Compiler::isDirective() so custom directives are recognised too.
     */
    public function __construct(?callable $isDirective = null)
    {
        $this->setDirectiveResolver($isDirective);
    }

    /**
     * Replace the predicate that decides whether `@name` is a directive.
     *
     * @param (callable(string): bool)|null $isDirective
     */
    public function setDirectiveResolver(?callable $isDirective): void
    {
        $this->isDirective = $isDirective !== null
            ? \Closure::fromCallable($isDirective)
            : static fn (string $name): bool => in_array($name, Compiler::BUILTIN_DIRECTIVES, true);
    }

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
        $this->directiveEnd = -1;

        $nodes = [];

        while ($this->position < $this->length) {
            $before = $this->position;
            $node   = $this->parseNext();
            if ($node !== null) {
                $nodes[] = $node;
            }

            // Safety net: every iteration must consume input. A parser that
            // stalls would otherwise spin forever and take the worker down.
            if ($this->position <= $before) {
                throw new \RuntimeException(
                    "LTE parser made no progress at offset {$before} (line {$this->line})."
                );
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
        if ($this->source[$this->position] === '@') {
            switch ($this->atKind()) {
                case 'escape_at':
                    return $this->parseEscapedAt();
                case 'escape_echo':
                    return $this->parseEscapedEcho();
                case 'directive':
                    $this->position++; // skip '@'
                    $node = $this->parseDirective();
                    $this->directiveEnd = $this->position;
                    return $node;
            }
        }
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
        } elseif (!in_array($name, self::NO_ARG_DIRECTIVES, true)) {
            // Allow horizontal whitespace between the name and '(' — `@if ($x)`.
            // Only consumed when an argument list actually follows.
            $peek = $this->position;
            while ($peek < $this->length && ($this->source[$peek] === ' ' || $this->source[$peek] === "\t")) {
                $peek++;
            }
            if ($peek > $this->position && $peek < $this->length && $this->source[$peek] === '(') {
                $this->position = $peek;
                $args = $this->parseDirectiveArgs($startLine);
            }
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

    // ── Escapes @@ and @{{ ... }} ──────────────────────────────────────────────

    /**
     * `@@name` → literal text `@name` (the name is consumed so it is never
     * parsed as a directive); a lone `@@` → `@`.
     *
     * @return array<string, mixed>
     */
    private function parseEscapedAt(): array
    {
        $startLine       = $this->line;
        $this->position += 2; // skip '@@'
        $nameEnd         = $this->scanWord($this->position);
        $text            = '@' . substr($this->source, $this->position, $nameEnd - $this->position);
        $this->position  = $nameEnd;

        return ['type' => 'text', 'content' => $text, 'line' => $startLine];
    }

    /**
     * `@{{ ... }}` → literal text `{{ ... }}` (for client-side frameworks).
     *
     * @return array<string, mixed>
     */
    private function parseEscapedEcho(): array
    {
        $startLine = $this->line;
        $start     = $this->position + 1; // keep the '{{'
        $end       = strpos($this->source, '}}', $start + 2);
        if ($end === false) {
            throw new \RuntimeException("Unclosed escaped echo tag on line {$startLine}.");
        }

        $this->advanceLinesTo($end + 2);
        $this->position = $end + 2;

        return [
            'type'    => 'text',
            'content' => substr($this->source, $start, $end + 2 - $start),
            'line'    => $startLine,
        ];
    }

    // ── Plain text ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseText(): array
    {
        $startLine = $this->line;
        $start     = $this->position;

        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            // Stop at a real token start — but always consume at least one
            // character so that a lone '{!' or similar can never stall the parser.
            if ($this->position > $start && $this->isTokenStart()) {
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

    private static function isClosingDirective(string $name): bool
    {
        return str_starts_with($name, 'end')
            || in_array($name, ['else', 'elseif', 'stop', 'show'], true);
    }

    private function isWordChar(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '_');
    }

    /** Return the offset just past the run of word characters starting at $pos. */
    private function scanWord(int $pos): int
    {
        while ($pos < $this->length && $this->isWordChar($this->source[$pos])) {
            $pos++;
        }
        return $pos;
    }

    /**
     * Classify the '@' at the current position.
     *
     * Returns null when the '@' is plain text: when the name is not a known
     * directive (e.g. CSS `@media`), or when it follows a word character
     * (e.g. `dev@php.net`) — except for closing/branch directives such as
     * `@endsection` / `@else`, which may directly follow text.
     */
    private function atKind(): ?string
    {
        $pos = $this->position;

        // A word character right before '@' makes it text — unless that word is
        // the directive we just parsed (`@endpush@push`, `@endsection@style`).
        $afterWord = $pos > 0
            && $pos !== $this->directiveEnd
            && $this->isWordChar($this->source[$pos - 1]);

        $next = $this->source[$pos + 1] ?? '';
        if (!$afterWord && $next === '@') {
            return 'escape_at';
        }
        if (!$afterWord && $next === '{' && ($this->source[$pos + 2] ?? '') === '{') {
            return 'escape_echo';
        }

        $nameEnd = $this->scanWord($pos + 1);
        if ($nameEnd === $pos + 1) {
            return null;
        }

        $name = substr($this->source, $pos + 1, $nameEnd - $pos - 1);

        // After a word character only closing/branch directives are honoured, so
        // `Hello@endsection` keeps working while `dev@php.net` / `me@if.com` stay text.
        if ($afterWord && !self::isClosingDirective($name)) {
            return null;
        }

        return ($this->isDirective)($name) ? 'directive' : null;
    }

    /**
     * Whether a token ({{, {!!, @) starts at the current position.
     * Must agree exactly with the dispatch in parseNext().
     */
    private function isTokenStart(): bool
    {
        $char = $this->source[$this->position];

        if ($char === '@') {
            return $this->atKind() !== null;
        }

        if ($char === '{') {
            return substr($this->source, $this->position, 2) === '{{'
                || substr($this->source, $this->position, 3) === '{!!';
        }

        return false;
    }

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