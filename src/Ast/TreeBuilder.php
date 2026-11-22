<?php

namespace Luany\Lte\Ast;

use Luany\Lte\Aliases;
use Luany\Lte\ExpressionScanner;
use Luany\Lte\TemplateSyntaxException;

/**
 * TreeBuilder
 *
 * Turns the parser's flat token list into a syntax tree and validates it at
 * compile time:
 *
 *   - every block directive is closed by the right directive
 *     (@if … @endif, @section … @endsection/@stop, @forelse … @endforelse, …)
 *   - branch directives sit where they belong (@elseif/@else in @if, @empty in
 *     @forelse, nothing after @else, no duplicated @else / @empty)
 *   - directives that need arguments have them
 *   - the PHP inside {{ }}, {!! !!}, @php and directive arguments is
 *     syntactically valid (checked with PHP's own parser, nothing is executed)
 *
 * Errors are TemplateSyntaxException with the template line and column.
 * Directives registered with Compiler::directive() are left untouched.
 */
final class TreeBuilder
{
    /**
     * Block directives: opener => [closers, allowed branch directives].
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const BLOCKS = [
        'if'        => [['endif'], ['elseif', 'else']],
        'unless'    => [['endunless'], ['else']],
        'isset'     => [['endisset'], ['else']],
        'ifempty'   => [['endifempty'], ['else']],
        'auth'      => [['endauth'], ['else']],
        'guest'     => [['endguest'], ['else']],
        'foreach'   => [['endforeach'], []],
        'for'       => [['endfor'], []],
        'while'     => [['endwhile'], []],
        'forelse'   => [['endforelse'], ['empty']],
        'empty'     => [['endempty'], ['else']],   // @empty($x) … @endempty
        'section'   => [['endsection', 'stop'], []],
        'push'      => [['endpush'], []],
        'component' => [['endcomponent'], []],
        'slot'      => [['endslot'], []],
        'style'     => [['endstyle'], []],
        'script'    => [['endscript'], []],
    ];

    /** Directives that must have an argument list. */
    private const NEEDS_ARGS = [
        'if', 'elseif', 'unless', 'isset', 'ifempty', 'foreach', 'forelse', 'for', 'while',
        'extends', 'yield', 'include', 'push', 'stack', 'component', 'slot', 'json', 'class',
        'dump', 'dd', 'method',
    ];

    /** @var (callable(string): bool)|null */
    private $isCustom;

    private bool $lint;

    private Aliases $aliases;

    /** @var list<array{name: string, written: string, glued: string, line: int}> Closers glued to text, e.g. "@endslotCard" */
    private array $glued = [];

    /**
     * @param (callable(string): bool)|null $isCustom True for directives registered by the app
     * @param bool                          $lint     Check embedded PHP syntax
     */
    public function __construct(?callable $isCustom = null, bool $lint = true, ?Aliases $aliases = null)
    {
        $this->isCustom = $isCustom;
        $this->lint     = $lint;
        $this->aliases  = $aliases ?? Aliases::none();
    }

    /** The directive as the author wrote it (`@fimse`, not `@endif`). */
    private static function shown(TokenNode $node): string
    {
        return (string) ($node->token['written'] ?? $node->name());
    }

    /**
     * Expected closers in the author's language: a block opened with an alias
     * (`@paracada`) is told to close with the alias (`@fimparacada`).
     *
     * @param list<string> $closers
     */
    private function closersFor(BlockNode $block, array $closers): string
    {
        $aliased = isset($block->opening()->token['written']);

        return '@' . implode(' or @', array_map(
            fn (string $c): string => $aliased ? $this->aliases->canonical($c) : $c,
            $closers
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $tokens Output of Parser::parse()
     * @throws TemplateSyntaxException
     */
    public function build(array $tokens): Template
    {
        $this->glued = $this->findGluedClosers($tokens);

        /** @var list<BlockNode> $stack */
        $stack = [];
        $root  = [];

        // Children list currently being filled (root or innermost branch).
        $append = function (Node $node) use (&$stack, &$root): void {
            if ($stack === []) {
                $root[] = $node;
                return;
            }
            $block    = $stack[array_key_last($stack)];
            $branches = $block->branches;
            $branches[array_key_last($branches)]->children[] = $node;
        };

        foreach ($tokens as $token) {
            $node = new TokenNode($token);
            $this->lintToken($node);

            if ($node->type() !== 'directive' || $this->custom((string) $node->name())) {
                $append($node);
                continue;
            }

            $name = (string) $node->name();

            if ($this->opensBlock($node)) {
                $block = new BlockNode($name, [new Branch($node)]);
                $append($block);
                $stack[] = $block;
                continue;
            }

            if ($this->isBranch($node)) {
                $this->addBranch($stack, $node);
                continue;
            }

            if ($this->isCloser($name)) {
                $this->close($stack, $node);
                continue;
            }

            $append($node);
        }

        if ($stack !== []) {
            $open    = $stack[array_key_last($stack)];
            $closers = self::BLOCKS[$open->name][0];
            throw $this->error(
                'Unclosed @' . self::shown($open->opening()) . ': missing ' . $this->closersFor($open, $closers) . $this->gluedHint($closers) . '.',
                $open->opening()
            );
        }

        return new Template($root);
    }

    // ── Structure ───────────────────────────────────────────────────────────

    private function opensBlock(TokenNode $node): bool
    {
        $name = (string) $node->name();

        if (!isset(self::BLOCKS[$name])) {
            return false;
        }

        $args = $node->token['args'] ?? null;

        return match ($name) {
            // @section('title', 'Inline value') is not a block
            'section' => $args !== null && trim((string) $args) !== '' && count(ExpressionScanner::split((string) $args)) < 2,
            // bare @empty is the @forelse branch
            'empty'   => $args !== null && trim((string) $args) !== '',
            default   => true,
        };
    }

    private function isBranch(TokenNode $node): bool
    {
        $name = (string) $node->name();
        $args = $node->token['args'] ?? null;

        return $name === 'elseif' || $name === 'else'
            || ($name === 'empty' && ($args === null || trim((string) $args) === ''));
    }

    private function isCloser(string $name): bool
    {
        foreach (self::BLOCKS as [$closers]) {
            if (in_array($name, $closers, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<BlockNode> $stack
     */
    private function addBranch(array $stack, TokenNode $node): void
    {
        $name = (string) $node->name();
        $top  = $stack === [] ? null : $stack[array_key_last($stack)];

        $shown = self::shown($node);

        if ($top === null || !in_array($name, self::BLOCKS[$top->name][1], true)) {
            $where = $top === null ? 'outside any block' : 'inside @' . self::shown($top->opening()) . " (line {$top->line})";
            $hint  = $name === 'empty' ? " — bare @{$shown} belongs to @" . (isset($node->token['written']) ? $this->aliases->canonical('forelse') : 'forelse') : '';
            throw $this->error("Unexpected @{$shown} {$where}{$hint}.", $node);
        }

        $lastNode = $top->branches[array_key_last($top->branches)]->directive;

        if ($lastNode->name() === 'else') {
            throw $this->error("Unexpected @{$shown}: @" . self::shown($lastNode) . " (line {$lastNode->line}) must be the last branch of @" . self::shown($top->opening()) . '.', $node);
        }

        if ($name === 'empty') {
            foreach ($top->branches as $branch) {
                if ($branch->directive->name() === 'empty') {
                    throw $this->error("Duplicate @{$shown} in @" . self::shown($top->opening()) . '.', $node);
                }
            }
        }

        $top->branches[] = new Branch($node);
    }

    /**
     * @param list<BlockNode> $stack
     */
    private function close(array &$stack, TokenNode $node): void
    {
        $name = (string) $node->name();
        $top  = $stack === [] ? null : $stack[array_key_last($stack)];

        $shown = self::shown($node);

        if ($top === null) {
            throw $this->error("Unexpected @{$shown}: there is no open block to close.", $node);
        }

        if (!in_array($name, self::BLOCKS[$top->name][0], true)) {
            throw $this->error(
                "Unexpected @{$shown}: @" . self::shown($top->opening()) . " opened on line {$top->line} is still open (expected "
                . $this->closersFor($top, self::BLOCKS[$top->name][0]) . ')' . $this->gluedHint(self::BLOCKS[$top->name][0]) . '.',
                $node
            );
        }

        $top->closing = $node;
        array_pop($stack);
    }

    /**
     * Text such as "Title@endslotCard": the closer is glued to the following
     * letters, so it is read as one unknown word ("endslotCard") and stays text.
     *
     * @param array<int, array<string, mixed>> $tokens
     * @return list<array{name: string, written: string, glued: string, line: int}>
     */
    private function findGluedClosers(array $tokens): array
    {
        $closers = []; // written closer => English closer
        foreach (self::BLOCKS as [$names]) {
            foreach ($names as $closer) {
                $closers[$closer] = $closer;
            }
        }
        foreach ($this->aliases->all() as $written => $english) {
            if (in_array($english, $closers, true)) {
                $closers[$written] = $english;
            }
        }
        uksort($closers, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $pattern = $this->aliases->isEmpty() ? '/@([A-Za-z_][A-Za-z0-9_]*)/' : '/@([\p{L}_][\p{L}\p{N}_]*)/u';

        $found = [];
        foreach ($tokens as $token) {
            if (($token['type'] ?? null) !== 'text' || !str_contains((string) $token['content'], '@')) {
                continue;
            }
            if (preg_match_all($pattern, (string) $token['content'], $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($m[1] as [$word, $offset]) {
                foreach ($closers as $closer => $english) {
                    $closer = (string) $closer;
                    if ($word !== $closer && str_starts_with($word, $closer)) {
                        $line    = (int) ($token['line'] ?? 0) + substr_count(substr((string) $token['content'], 0, $offset), "\n");
                        $found[] = ['name' => $english, 'written' => $closer, 'glued' => '@' . $word, 'line' => $line];
                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param list<string> $closers
     */
    private function gluedHint(array $closers): string
    {
        foreach ($this->glued as $g) {
            if (in_array($g['name'], $closers, true)) {
                $rest = substr($g['glued'], strlen($g['written']) + 1);
                return " — \"{$g['glued']}\" on line {$g['line']} is read as text: separate the directive from the text"
                    . " (\"@{$g['written']} {$rest}\")";
            }
        }
        return '';
    }

    private function custom(string $name): bool
    {
        return $this->isCustom !== null && ($this->isCustom)($name);
    }

    // ── Embedded PHP ────────────────────────────────────────────────────────

    private function lintToken(TokenNode $node): void
    {
        $token = $node->token;

        switch ($node->type()) {
            case 'echo':
                $this->lintPhp('(' . $token['expression'] . ');', $node, '{{ }}');
                return;

            case 'raw_echo':
                $this->lintPhp('(' . $token['expression'] . ');', $node, '{!! !!}');
                return;

            case 'php_block':
                $this->lintPhp($token['content'] . "\n?>", $node, '@php block');
                return;

            case 'directive':
                $this->lintDirective($node);
                return;
        }
    }

    private function lintDirective(TokenNode $node): void
    {
        $name = (string) $node->name();

        if ($this->custom($name)) {
            return;
        }

        $args    = $node->token['args'] ?? null;
        $hasArgs = $args !== null && trim((string) $args) !== '';

        if (!$hasArgs) {
            if (in_array($name, self::NEEDS_ARGS, true)) {
                $shown = self::shown($node);
                throw $this->error("@{$shown} requires arguments, e.g. @{$shown}(…).", $node);
            }
            return;
        }

        $code = match ($name) {
            'if', 'elseif', 'unless' => "if ({$args}) {}",
            'isset'                  => "if (isset({$args})) {}",
            'ifempty', 'empty'       => "if (empty({$args})) {}",
            'foreach', 'forelse'     => "foreach ({$args}) {}",
            'for'                    => "for ({$args}) {}",
            'while'                  => "while ({$args}) {}",
            'php'                    => "{$args};",
            default                  => "__lte({$args});",
        };

        $this->lintPhp($code, $node, '@' . self::shown($node));
    }

    private function lintPhp(string $code, TokenNode $node, string $where): void
    {
        if (!$this->lint) {
            return;
        }

        try {
            // TOKEN_PARSE runs PHP's parser (nothing is executed); only the
            // ParseError matters, the tokens are discarded.
            $tokens = token_get_all('<?php ' . $code, TOKEN_PARSE);
            unset($tokens);
        } catch (\ParseError $e) {
            $line = $node->line + max(0, $e->getLine() - 1);
            throw new TemplateSyntaxException(
                "PHP syntax error in {$where} on line {$line}" . ($e->getLine() <= 1 && $node->column > 0 ? ", column {$node->column}" : '')
                . ': ' . $e->getMessage() . '.',
                0,
                $e,
                $line,
                $e->getLine() <= 1 ? $node->column : 0,
            );
        }
    }

    private function error(string $message, TokenNode $node): TemplateSyntaxException
    {
        $at = " (line {$node->line}" . ($node->column > 0 ? ", column {$node->column}" : '') . ')';

        return new TemplateSyntaxException(
            rtrim($message, '.') . $at . '.',
            0,
            null,
            $node->line,
            $node->column,
        );
    }
}
