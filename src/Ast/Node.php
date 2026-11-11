<?php

namespace Luany\Lte\Ast;

/**
 * Base class of every LTE syntax-tree node.
 */
abstract class Node
{
    public function __construct(
        public readonly int $line = 0,
        public readonly int $column = 0,
    ) {}

    /**
     * Flat parser tokens represented by this node, in source order
     * (what the compiler turns into PHP).
     *
     * @return list<array<string, mixed>>
     */
    abstract public function tokens(): array;
}
