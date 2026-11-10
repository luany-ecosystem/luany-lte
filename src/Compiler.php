<?php

namespace Luany\Lte;

/*
 * LTE Compiler
 *
 * Compiles AST nodes produced by Parser into executable PHP code.
 *
 * Phase 5 additions:
 *
 *   @json($data)                     — safe JSON output (HTML-entity encoded)
 *   @json($data, JSON_PRETTY_PRINT)  — with custom flags (OR-ed with safe defaults)
 *
 *   @class(['base', 'active' => $isActive, 'disabled' => !$on])
 *     → runtime evaluation via ClassHelper::compile()
 *
 *   @component('view', ['key' => 'val']) / @slot('name') / @endslot / @endcomponent
 *     → ComponentStack-based nested rendering with named slots
 *
 * Line-number embedding:
 *   Every compiled node starts with a PHP comment `<?php /* @lte:{LINE} *\/ ?>` so that
 *   the Engine can map PHP runtime errors back to the originating .lte source line.
 *
 * Asset placeholder strategy (Bug fix — render-order):
 *   @styles and @scripts are compiled to HTML comment placeholders instead of
 *   immediate AssetStack::render*() calls. The Engine replaces these markers
 *   AFTER the complete layout evaluation, ensuring that @style/@script blocks
 *   from all @include'd components are registered before they are flushed —
 *   regardless of where @styles/@scripts appear in the layout (head or body).
 *
 *   Placeholder format:
 *     <!--__LTE_STYLES__-->   →  replaced by Engine::resolveAssetPlaceholders()
 *     <!--__LTE_SCRIPTS__-->  →  replaced by Engine::resolveAssetPlaceholders()
 */
class Compiler
{
    /** Directive names handled natively by compileDirective(). */
    public const BUILTIN_DIRECTIVES = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
        'foreach', 'endforeach', 'for', 'endfor', 'while', 'endwhile',
        'forelse', 'empty', 'endforelse',
        'php', 'endphp',
        'csrf', 'method',
        'auth', 'endauth', 'guest', 'endguest',
        'extends', 'section', 'endsection', 'stop', 'yield',
        'include',
        'style', 'endstyle', 'script', 'endscript', 'styles', 'scripts',
        'push', 'endpush', 'stack',
        'json', 'class',
        'component', 'slot', 'endslot', 'endcomponent',
        'dump', 'dd',
        'isset', 'endisset', 'ifempty', 'endifempty',
    ];

    /** @var array<string, callable> */
    private array $directives  = [];
    private int   $forelseDepth = 0;

    public function __construct()
    {
        $this->registerDefaultDirectives();
    }

    /**
     * Compile an AST into a PHP string.
     */
    /** @param array<int, array<string, mixed>> $ast */
    public function compile(array $ast): string
    {
        $this->forelseDepth = 0;

        $php = '';
        foreach ($ast as $node) {
            foreach ($this->compileNode($node) as $piece) {
                $php = $this->append($php, $piece);
            }
        }
        return $php;
    }

    /**
     * Concatenate a compiled piece, preserving template whitespace.
     *
     * PHP swallows a single newline ("\n" or "\r\n") that immediately follows
     * a closing `?>` tag. Whenever a piece starting with a newline is appended
     * right after a `?>`, an extra "\n" is inserted for PHP to consume so the
     * rendered output keeps the template's newlines exactly.
     */
    private function append(string $php, string $piece): string
    {
        if ($piece === '') {
            return $php;
        }

        if (($piece[0] === "\n" || str_starts_with($piece, "\r\n")) && str_ends_with($php, '?>')) {
            $php .= "\n";
        }

        return $php . $piece;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>  Compiled pieces (line marker first, if any)
     */
    private function compileNode(array $node): array
    {
        $line   = $node['line'] ?? null;
        $marker = ($line !== null) ? "<?php /* @lte:{$line} */ ?>" : '';

        switch ($node['type']) {
            case 'text':
                return [$marker, $node['content']];

            case 'echo':
                return [$marker,
                    "<?php echo \\Luany\\Lte\\Escaper::escape({$node['expression']} ?? ''); ?>"];

            case 'raw_echo':
                return [$marker, "<?php echo {$node['expression']}; ?>"];

            case 'php_block':
                return [$marker, "<?php\n{$node['content']}\n?>"];

            case 'directive':
                return [$marker, $this->compileDirective($node)];

            default:
                return [];
        }
    }

    /** @param array<string, mixed> $node */
    private function compileDirective(array $node): string
    {
        $name = $node['name'];
        $args = $node['args'];

        if (isset($this->directives[$name])) {
            return call_user_func($this->directives[$name], $args);
        }

        switch ($name) {

            // ── Conditionals ──────────────────────────────────────────────────
            case 'if':        return "<?php if({$args}): ?>";
            case 'elseif':    return "<?php elseif({$args}): ?>";
            case 'else':      return '<?php else: ?>';
            case 'endif':     return '<?php endif; ?>';
            case 'unless':    return "<?php if(!({$args})): ?>";
            case 'endunless': return '<?php endif; ?>';

            // ── Loops ─────────────────────────────────────────────────────────
            case 'foreach':    return "<?php foreach({$args}): ?>";
            case 'endforeach': return '<?php endforeach; ?>';
            case 'for':        return "<?php for({$args}): ?>";
            case 'endfor':     return '<?php endfor; ?>';
            case 'while':      return "<?php while({$args}): ?>";
            case 'endwhile':   return '<?php endwhile; ?>';

            // ── @forelse / @empty / @endforelse ───────────────────────────────
            case 'forelse':
                $this->forelseDepth++;
                $tmp = '__lte_fe' . $this->forelseDepth;

                $asPos = strrpos($args, ' as ');
                if ($asPos === false) {
                    return "<?php foreach({$args}): ?>";
                }

                $iterable = trim(substr($args, 0, $asPos));
                $asVar    = trim(substr($args, $asPos + 4));

                return "<?php \${$tmp} = {$iterable}; if (!empty(\${$tmp})): ?>"
                     . "<?php foreach (\${$tmp} as {$asVar}): ?>";

            case 'empty':
                return '<?php endforeach; ?>' . '<?php else: ?>';

            case 'endforelse':
                $this->forelseDepth = max(0, $this->forelseDepth - 1);
                return '<?php endif; ?>';

            // ── Inline PHP ────────────────────────────────────────────────────
            case 'php':
                return $args !== null ? "<?php {$args}; ?>" : '<?php ';
            case 'endphp':
                return ' ?>';

            // ── Security ──────────────────────────────────────────────────────
            case 'csrf':
                return '<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars('
                    . '(string)(isset($_SESSION[\'csrf_token\']) '
                    . '? $_SESSION[\'csrf_token\'] '
                    . ': ($_SESSION[\'csrf_token\'] = bin2hex(random_bytes(32)))), '
                    . 'ENT_QUOTES, \'UTF-8\'); ?>">';

            case 'method':
                $methodArg = trim((string) $args);
                if (ExpressionScanner::isStringLiteral($methodArg) || preg_match('/^[A-Za-z]+$/', $methodArg) === 1) {
                    $m = htmlspecialchars(strtoupper(trim($methodArg, '\'"')), Escaper::FLAGS, 'UTF-8');
                    return '<input type="hidden" name="_method" value="' . $m . '">';
                }
                return '<input type="hidden" name="_method" value="<?php echo \\Luany\\Lte\\Escaper::escape(strtoupper((string)('
                    . $methodArg . '))); ?>">';

            // ── Auth guards ───────────────────────────────────────────────────
            case 'auth':    return "<?php if(isset(\$_SESSION['user_id'])): ?>";
            case 'endauth': return '<?php endif; ?>';
            case 'guest':   return "<?php if(!isset(\$_SESSION['user_id'])): ?>";
            case 'endguest':return '<?php endif; ?>';

            // ── Layout system ─────────────────────────────────────────────────
            case 'extends':
                $layout = $this->nameExpr($args);
                return "<?php \\Luany\\Lte\\SectionStack::setLayout({$layout}); ?>";

            case 'section':
                if ($args === null) return '';
                if ($this->hasInlineValue($args)) {
                    [$sectionName, $value] = $this->parseSectionArgs($args);
                    return "<?php \\Luany\\Lte\\SectionStack::set({$sectionName}, \\Luany\\Lte\\Escaper::escape({$value})); ?>";
                }
                $sectionName = $this->nameExpr($args);
                return "<?php \\Luany\\Lte\\SectionStack::start({$sectionName}); ?>";

            case 'endsection':
            case 'stop':
                return '<?php \\Luany\\Lte\\SectionStack::end(); ?>';

            case 'yield':
                $yieldArgs   = $this->parseYieldArgs($args);
                $sectionName = $yieldArgs[0];
                $default     = $yieldArgs[1] ?? "''";
                return "<?php echo \\Luany\\Lte\\SectionStack::get({$sectionName}, \\Luany\\Lte\\Escaper::escape({$default})); ?>";

            // ── Include ───────────────────────────────────────────────────────
            case 'include':
                $parentVars = "array_filter(get_defined_vars(), "
                            . "function(\$k) { return !str_starts_with(\$k, '__'); }, "
                            . "ARRAY_FILTER_USE_KEY)";

                if ($args !== null && $this->hasInlineValue($args)) {
                    [$viewName, $extraData] = $this->parseSectionArgs($args);
                    $data     = "array_merge({$parentVars}, {$extraData})";
                } else {
                    $viewName = $this->nameExpr($args);
                    $data     = $parentVars;
                }

                return "<?php echo \$__engine->render({$viewName}, {$data}); ?>";

            // ── Asset directives ──────────────────────────────────────────────
            //
            // @style/@endstyle and @script/@endscript register blocks into
            // AssetStack immediately when the template is evaluated.
            //
            // @styles and @scripts, however, must NOT render the collected
            // assets inline — they only know about assets registered UP TO
            // that point in the evaluation order. Because @include directives
            // inside the layout run AFTER the layout's <head> section, any
            // component @style blocks would be missed if we called
            // AssetStack::renderStyles() directly here.
            //
            // Fix: emit an HTML comment placeholder. The Engine replaces it
            // with the actual rendered output AFTER the entire layout (and all
            // its @include'd components) finishes evaluating.
            // See Engine::resolveAssetPlaceholders().
            //
            case 'style':
                return '<?php \Luany\Lte\AssetStack::startStyle(' . $this->parseArgs($args) . '); ?>';
            case 'endstyle':
                return '<?php \Luany\Lte\AssetStack::endStyle(); ?>';
            case 'script':
                return '<?php \Luany\Lte\AssetStack::startScript(' . $this->parseArgs($args) . '); ?>';
            case 'endscript':
                return '<?php \Luany\Lte\AssetStack::endScript(); ?>';

            case 'styles':
                // Deferred placeholder — resolved by Engine::resolveAssetPlaceholders()
                // after the complete render cycle, so @include'd component styles
                // are guaranteed to be registered before this is replaced.
                return "<?php echo '<!--__LTE_STYLES__-->'; ?>";

            case 'scripts':
                // Deferred placeholder — resolved by Engine::resolveAssetPlaceholders()
                // after the complete render cycle, so @include'd component scripts
                // are guaranteed to be registered before this is replaced.
                return "<?php echo '<!--__LTE_SCRIPTS__-->'; ?>";

            // ── Stack directives ──────────────────────────────────────────────
            case 'push':
                $stackName = $this->nameExpr($args);
                return "<?php \Luany\Lte\SectionStack::startPush({$stackName}); ?>";
            case 'endpush':
                return '<?php \Luany\Lte\SectionStack::endPush(); ?>';
            case 'stack':
                $stackName = $this->nameExpr($args);
                return "<?php echo \Luany\Lte\SectionStack::getStack({$stackName}); ?>";

            // ── @json (Phase 5) ───────────────────────────────────────────────
            //
            // Outputs PHP data as safe JSON for use in JavaScript contexts.
            //
            // HTML-entity encoding flags are always applied to prevent XSS when
            // the JSON is embedded inline in a page:
            //   JSON_HEX_TAG    — encode < and > as \u003C / \u003E
            //   JSON_HEX_AMP    — encode & as \u0026
            //   JSON_HEX_APOS   — encode ' as \u0027
            //   JSON_HEX_QUOT   — encode " as \u0022
            //   JSON_UNESCAPED_UNICODE — keep Unicode chars readable
            //
            // Usage:
            //   @json($data)                    — safe defaults
            //   @json($data, JSON_PRETTY_PRINT) — custom flags OR-ed with defaults
            //
            case 'json':
                $safeFlags = 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE';
                if ($args !== null && $this->hasInlineValue($args)) {
                    // @json($data, CUSTOM_FLAGS) — two arguments
                    $jsonArgs = ExpressionScanner::split($args);
                    $expr     = $jsonArgs[0];
                    $flags    = '(' . $jsonArgs[1] . ') | ' . $safeFlags;
                    return "<?php echo json_encode({$expr}, {$flags}); ?>";
                }
                // @json($data) — single argument
                return "<?php echo json_encode({$args}, {$safeFlags}); ?>";

            // ── @class (Phase 5) ──────────────────────────────────────────────
            //
            // Conditionally builds a CSS class string from an array.
            //
            // Usage:
            //   <div @class(['btn', 'btn-primary' => $isPrimary, 'btn-disabled' => !$active])>
            //
            // Compiled:
            //   <div class="[output of ClassHelper::attr([...])]">
            //
            // The array expression is passed verbatim to ClassHelper::attr()
            // which evaluates conditions at runtime and wraps in class="...".
            //
            case 'class':
                return '<?php echo Luany\Lte\ClassHelper::attr(' . $args . '); ?>';

            // ── @component / @slot / @endslot / @endcomponent (Phase 5) ──────
            //
            // Component system with default slot and named slots.
            //
            // Usage (parent view):
            //   @component('components.alert', ['type' => 'error'])
            //       @slot('title')Error Title@endslot
            //       This is the default slot content.
            //   @endcomponent
            //
            // Component file (components/alert.lte):
            //   <div class="alert alert-{{ $type }}">
            //       <strong>{{ $title }}</strong>
            //       <p>{{ $slot }}</p>
            //   </div>
            //
            case 'component':
                [$viewName, $dataExpr] = $this->parseComponentArgs($args);
                return "<?php \\Luany\\Lte\\ComponentStack::startComponent(\$__engine, {$viewName}, {$dataExpr}); ?>";

            case 'slot':
                $slotName = $args !== null && trim($args) !== '' ? $this->nameExpr($args) : "'__default'";
                return "<?php \\Luany\\Lte\\ComponentStack::startSlot({$slotName}); ?>";

            case 'endslot':
                return '<?php \\Luany\\Lte\\ComponentStack::endSlot(); ?>';

            case 'endcomponent':
                return '<?php echo \\Luany\\Lte\\ComponentStack::endComponent(); ?>';

            // ── Debug helpers ─────────────────────────────────────────────────
            case 'dump':
                return "<?php var_dump({$args}); ?>";
            case 'dd':
                return "<?php var_dump({$args}); die; ?>";

            // ── @isset / @endisset ────────────────────────────────────────────
            case 'isset':
                return "<?php if(isset({$args})): ?>";
            case 'endisset':
                return '<?php endif; ?>';

            // ── @ifempty / @endifempty ────────────────────────────────────────
            case 'ifempty':
                return "<?php if(empty({$args})): ?>";
            case 'endifempty':
                return '<?php endif; ?>';

            default:
                return "@{$name}" . ($args !== null ? "({$args})" : '');
        }
    }

    // ── Argument parsing helpers ───────────────────────────────────────────────

    private function parseArgs(?string $args): string
    {
        $parts = ExpressionScanner::split($args);
        $parts = array_values(array_filter(
            array_map(fn (string $p): string => trim($p, '\'" '), $parts),
            fn (string $p): bool => $p !== ''
        ));

        if ($parts === []) {
            return '[]';
        }

        return '[' . implode(',', array_map(fn (string $p): string => var_export($p, true), $parts)) . ']';
    }

    private function hasInlineValue(string $args): bool
    {
        return count(ExpressionScanner::split($args)) > 1;
    }

    /**
     * Turn a view/section/stack name argument into a PHP expression.
     *
     *   'layouts.main' / "layouts.main" / layouts.main  → 'layouts.main'
     *   $layout, $prefix . '.card', "x.$y"             → passed through unchanged
     */
    private function nameExpr(?string $arg): string
    {
        $arg = trim((string) $arg);

        if ($arg === '') {
            return "''";
        }

        $bare = '/^[A-Za-z0-9_.\-\/:]+$/';

        if (ExpressionScanner::isStringLiteral($arg)) {
            $inner = substr($arg, 1, -1);
            return preg_match($bare, $inner) === 1 ? var_export($inner, true) : $arg;
        }

        // Legacy unquoted names: @extends(layouts.main)
        if (preg_match($bare, $arg) === 1 && !is_numeric($arg)) {
            return var_export($arg, true);
        }

        return $arg;
    }

    /**
     * Split "name, value" at the first top-level comma (strings and nested
     * brackets aware). The name becomes a PHP expression (see nameExpr());
     * the value is returned verbatim.
     *
     * @return array{0: string, 1: string}
     */
    private function parseSectionArgs(string $args): array
    {
        $parts = ExpressionScanner::split($args);
        if (count($parts) < 2) {
            return [$this->nameExpr($args), "''"];
        }

        $name  = $this->nameExpr(array_shift($parts));
        $value = implode(', ', $parts);
        return [$name, $value];
    }

    /** @return array{0: string, 1: string} */
    private function parseYieldArgs(?string $args): array
    {
        if ($args === null) return ["''", "''"];
        return $this->parseSectionArgs($args);
    }

    /**
     * Parse @component arguments: 'view.name' or 'view.name', ['key' => 'val'].
     *
     * @return array{0: string, 1: string}  [viewName, dataExpression]
     */
    private function parseComponentArgs(?string $args): array
    {
        if ($args === null || trim($args) === '') {
            return ["''", '[]'];
        }

        if ($this->hasInlineValue($args)) {
            return $this->parseSectionArgs($args);
        }

        return [$this->nameExpr($args), '[]'];
    }

    // ── Custom directives ──────────────────────────────────────────────────────

    public function directive(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    /**
     * Whether `@{$name}` is a directive (built-in or registered custom).
     * Any other `@word` in a template is left as literal text.
     */
    public function isDirective(string $name): bool
    {
        return isset($this->directives[$name]) || in_array($name, self::BUILTIN_DIRECTIVES, true);
    }

    private function registerDefaultDirectives(): void
    {
        // Reserved for future built-ins
    }
}