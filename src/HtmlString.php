<?php

namespace Luany\Lte;

/**
 * Wraps a trusted HTML string so that it is not escaped on output, e.g. as
 * the value of an inline section: new HtmlString('<em>Home</em>').
 */
final class HtmlString implements Htmlable, \Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
