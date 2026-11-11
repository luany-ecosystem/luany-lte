<?php

namespace Luany\Lte\Ast;

/**
 * One branch of a block: the directive that starts it (@if, @elseif, @else,
 * @forelse, @empty, …) and the nodes it contains.
 */
final class Branch
{
    /**
     * @param list<Node> $children
     */
    public function __construct(
        public readonly TokenNode $directive,
        public array $children = [],
    ) {}
}
