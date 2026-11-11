<?php

namespace Luany\Lte\Ast;

/**
 * A leaf node wrapping one parser token: text, {{ echo }}, {!! raw !!},
 * @php…@endphp block, or a directive that does not open a block.
 */
final class TokenNode extends Node
{
    /**
     * @param array<string, mixed> $token
     */
    public function __construct(public readonly array $token)
    {
        parent::__construct((int) ($token['line'] ?? 0), (int) ($token['column'] ?? 0));
    }

    public function type(): string
    {
        return (string) $this->token['type'];
    }

    public function name(): ?string
    {
        return isset($this->token['name']) ? (string) $this->token['name'] : null;
    }

    public function tokens(): array
    {
        return [$this->token];
    }
}
