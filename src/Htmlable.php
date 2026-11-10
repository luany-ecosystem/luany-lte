<?php

namespace Luany\Lte;

/**
 * A value that is already safe HTML.
 *
 * Escaping helpers ({{ }}, inline @section values, @yield defaults) output
 * toHtml() verbatim instead of HTML-escaping the value. Use this only for
 * markup you trust.
 */
interface Htmlable
{
    public function toHtml(): string;
}
