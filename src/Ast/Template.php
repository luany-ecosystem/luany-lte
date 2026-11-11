<?php

namespace Luany\Lte\Ast;

/**
 * Root of a parsed template.
 */
final class Template extends Node
{
    /**
     * @param list<Node> $children
     */
    public function __construct(public readonly array $children)
    {
        parent::__construct(1, 1);
    }

    public function tokens(): array
    {
        $tokens = [];
        foreach ($this->children as $child) {
            array_push($tokens, ...$child->tokens());
        }
        return $tokens;
    }

    /**
     * Depth-first walk over every node (blocks before their children).
     *
     * @param callable(Node, int): void $visitor Receives the node and its depth
     */
    public function walk(callable $visitor): void
    {
        $walk = function (array $nodes, int $depth) use (&$walk, $visitor): void {
            foreach ($nodes as $node) {
                $visitor($node, $depth);
                if ($node instanceof BlockNode) {
                    foreach ($node->branches as $branch) {
                        $walk($branch->children, $depth + 1);
                    }
                }
            }
        };

        $walk($this->children, 0);
    }
}
