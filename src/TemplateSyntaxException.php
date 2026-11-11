<?php

namespace Luany\Lte;

/**
 * Thrown for malformed templates: unclosed or mismatched block directives,
 * misplaced @else / @elseif / @empty, missing arguments, and PHP syntax
 * errors inside {{ }}, {!! !!}, @php or directive arguments.
 *
 * $templateLine / $templateColumn point into the .lte source (0 = unknown).
 *
 * Extends \RuntimeException, so existing `catch (\RuntimeException)` code
 * keeps working.
 */
class TemplateSyntaxException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $templateLine = 0,
        public readonly int $templateColumn = 0,
        public readonly ?string $view = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
