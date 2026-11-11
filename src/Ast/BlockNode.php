<?php

namespace Luany\Lte\Ast;

/**
 * A block directive with its branches and closing directive, e.g.
 * if(…) … elseif(…) … else … endif, forelse(…) … empty … endforelse,
 * or section('x') … endsection.
 */
final class BlockNode extends Node
{
    /**
     * @param list<Branch> $branches First branch is the opening directive
     */
    public function __construct(
        public readonly string $name,
        public array $branches,
        public ?TokenNode $closing = null,
    ) {
        $open = $branches[0]->directive;
        parent::__construct($open->line, $open->column);
    }

    public function opening(): TokenNode
    {
        return $this->branches[0]->directive;
    }

    public function tokens(): array
    {
        $tokens = [];
        foreach ($this->branches as $branch) {
            $tokens[] = $branch->directive->token;
            foreach ($branch->children as $child) {
                array_push($tokens, ...$child->tokens());
            }
        }
        if ($this->closing !== null) {
            $tokens[] = $this->closing->token;
        }
        return $tokens;
    }
}
