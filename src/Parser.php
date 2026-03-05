<?php

namespace Luany\Lte;

/**
 * LTE Parser
 * Converts .lte source code into Abstract Syntax Tree (AST)
 * 
 * Tokens:
 * - {{ $var }}          → Echo (escaped)
 * - {!! $var !!}        → Raw echo (unescaped)
 * - @directive          → Directive
 * - {{-- comment --}}   → Comment (removed)
 */
class Parser
{
    private string $source;
    private int $position = 0;
    private int $length = 0;
    
    /**
     * Parse LTE source into AST
     */
    public function parse(string $source): array
    {
        $this->source = $source;
        $this->position = 0;
        $this->length = strlen($source);
        
        $nodes = [];
        
        while ($this->position < $this->length) {
            $node = $this->parseNext();
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        
        return $nodes;
    }
    
    /**
     * Parse next token
     */
    private function parseNext(): ?array
    {
        // Try comment
        if ($this->match('{{--')) {
            return $this->parseComment();
        }
        
        // Try raw echo
        if ($this->match('{!!')) {
            return $this->parseRawEcho();
        }
        
        // Try echo
        if ($this->match('{{')) {
            return $this->parseEcho();
        }
        
        // Try directive
        if ($this->match('@')) {
            return $this->parseDirective();
        }
        
        // Plain text
        return $this->parseText();
    }
    
    /**
     * Parse comment {{-- ... --}}
     */
    private function parseComment(): ?array
    {
        $end = strpos($this->source, '--}}', $this->position);
        if ($end === false) {
            throw new \RuntimeException('Unclosed comment');
        }
        
        $this->position = $end + 4;
        return null; // Comments are not included in AST
    }
    
    /**
     * Parse echo {{ $var }}
     */
    private function parseEcho(): array
    {
        $end = strpos($this->source, '}}', $this->position);
        if ($end === false) {
            throw new \RuntimeException('Unclosed echo tag');
        }
        
        $expression = trim(substr($this->source, $this->position, $end - $this->position));
        $this->position = $end + 2;
        
        return [
            'type' => 'echo',
            'expression' => $expression
        ];
    }
    
    /**
     * Parse raw echo {!! $var !!}
     */
    private function parseRawEcho(): array
    {
        $end = strpos($this->source, '!!}', $this->position);
        if ($end === false) {
            throw new \RuntimeException('Unclosed raw echo tag');
        }
        
        $expression = trim(substr($this->source, $this->position, $end - $this->position));
        $this->position = $end + 3;
        
        return [
            'type' => 'raw_echo',
            'expression' => $expression
        ];
    }
    
    /**
     * Parse LTE directive starting with '@'.
     *
     * Handles:
     *  - Standard directives (@name(args))
     *  - PHP blocks (@php ... @endphp)
     */
    private function parseDirective(): array
    {
        $nameEnd = $this->position;
        while ($nameEnd < $this->length &&
            (ctype_alnum($this->source[$nameEnd]) || $this->source[$nameEnd] === '_')) {
            $nameEnd++;
        }

        $name = substr($this->source, $this->position, $nameEnd - $this->position);
        $this->position = $nameEnd;

        // @php block (no parens) — consume raw PHP until @endphp
        // Never parse LTE directives inside — prevents @csrf, {{ }}, {!! !!} from
        // being processed inside PHP strings or expressions
        if ($name === 'php' && ($this->position >= $this->length || $this->source[$this->position] !== '(')) {
            $end = strpos($this->source, '@endphp', $this->position);
            if ($end === false) {
                throw new \RuntimeException('Unclosed @php block — missing @endphp');
            }
            $content = substr($this->source, $this->position, $end - $this->position);
            $this->position = $end + strlen('@endphp');
            return ['type' => 'php_block', 'content' => $content];
        }

        $args = null;
        if ($this->position < $this->length && $this->source[$this->position] === '(') {
            $args = $this->parseDirectiveArgs();
        }

        return ['type' => 'directive', 'name' => $name, 'args' => $args];
    }
    
    /**
     * Parse directive arguments (arg1, arg2)
     */
    private function parseDirectiveArgs(): string
    {
        $this->position++; // Skip opening (
        
        $depth = 1;
        $start = $this->position;
        
        while ($this->position < $this->length && $depth > 0) {
            $char = $this->source[$this->position];
            
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '\'' || $char === '"') {
                // Skip quoted strings
                $this->skipString($char);
                continue;
            }
            
            $this->position++;
        }
        
        if ($depth !== 0) {
            throw new \RuntimeException('Unclosed directive arguments');
        }
        
        return trim(substr($this->source, $start, $this->position - $start - 1));
    }
    
    /**
     * Skip quoted string
     */
    private function skipString(string $quote): void
    {
        $this->position++; // Skip opening quote
        
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];
            
            if ($char === '\\') {
                $this->position += 2; // Skip escaped character
                continue;
            }
            
            if ($char === $quote) {
                $this->position++; // Skip closing quote
                return;
            }
            
            $this->position++;
        }
    }
    
    /**
     * Parse plain text
     */
    private function parseText(): array
    {
        $start = $this->position;
        
        // Find next special token
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];
            $next = $this->position + 1 < $this->length ? $this->source[$this->position + 1] : '';
            
            // Check for tokens
            if ($char === '@' || 
                ($char === '{' && ($next === '{' || $next === '!'))) {
                break;
            }
            
            $this->position++;
        }
        
        $text = substr($this->source, $start, $this->position - $start);
        
        return [
            'type' => 'text',
            'content' => $text
        ];
    }
    
    /**
     * Check if current position matches pattern
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
}