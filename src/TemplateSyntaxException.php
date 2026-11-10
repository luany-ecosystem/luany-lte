<?php

namespace Luany\Lte;

/**
 * Thrown for malformed templates: unclosed tags, unbalanced block
 * directives (stray @endsection / @endpush, unclosed @section ...).
 *
 * Extends \RuntimeException, so existing `catch (\RuntimeException)` code
 * keeps working.
 */
class TemplateSyntaxException extends \RuntimeException
{
}
