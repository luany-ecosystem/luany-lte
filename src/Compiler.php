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
 */
class Compiler
{
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
            $php .= $this->compileNode($node);
        }
        return $php;
    }

    /** @param array<string, mixed> $node */
    private function compileNode(array $node): string
    {
        $line   = $node['line'] ?? null;
        $marker = ($line !== null) ? "<?php /* @lte:{$line} */ ?>" : '';

        switch ($node['type']) {
            case 'text':
                // Text nodes: emit the line marker only if there is actual content
                return $marker . $node['content'];

            case 'echo':
                return $marker
                    . "<?php echo htmlspecialchars((string)({$node['expression']} ?? ''), ENT_QUOTES, 'UTF-8'); ?>";

            case 'raw_echo':
                return $marker . "<?php echo {$node['expression']}; ?>";

            case 'php_block':
                return $marker . "<?php\n{$node['content']}\n?>";

            case 'directive':
                return $marker . $this->compileDirective($node);

            default:
                return '';
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
                $m = strtoupper(trim($args, '\'"'));
                return '<input type="hidden" name="_method" value="' . $m . '">';

            // ── Auth guards ───────────────────────────────────────────────────
            case 'auth':    return "<?php if(isset(\$_SESSION['user_id'])): ?>";
            case 'endauth': return '<?php endif; ?>';
            case 'guest':   return "<?php if(!isset(\$_SESSION['user_id'])): ?>";
            case 'endguest':return '<?php endif; ?>';

            // ── Layout system ─────────────────────────────────────────────────
            case 'extends':
                $layout = trim($args, '\'"');
                return "<?php \\Luany\\Lte\\SectionStack::setLayout('{$layout}'); ?>";

            case 'section':
                if ($args === null) return '';
                if ($this->hasInlineValue($args)) {
                    [$sectionName, $value] = $this->parseSectionArgs($args);
                    return "<?php \\Luany\\Lte\\SectionStack::set('{$sectionName}', {$value}); ?>";
                }
                $sectionName = trim($args, '\'"');
                return "<?php \\Luany\\Lte\\SectionStack::start('{$sectionName}'); ?>";

            case 'endsection':
            case 'stop':
                return '<?php \\Luany\\Lte\\SectionStack::end(); ?>';

            case 'yield':
                $yieldArgs   = $this->parseYieldArgs($args);
                $sectionName = $yieldArgs[0];
                $default     = $yieldArgs[1] ?? "''";
                return "<?php echo \\Luany\\Lte\\SectionStack::get('{$sectionName}', {$default}); ?>";

            // ── Include ───────────────────────────────────────────────────────
            case 'include':
                $parentVars = "array_filter(get_defined_vars(), "
                            . "function(\$k) { return !str_starts_with(\$k, '__'); }, "
                            . "ARRAY_FILTER_USE_KEY)";

                if ($args !== null && $this->hasInlineValue($args)) {
                    [$rawView, $extraData] = $this->parseSectionArgs($args);
                    $viewName = trim($rawView, '\'"');
                    $data     = "array_merge({$parentVars}, {$extraData})";
                } else {
                    $viewName = trim((string) $args, '\'"');
                    $data     = $parentVars;
                }

                return "<?php echo \$__engine->render('{$viewName}', {$data}); ?>";

            // ── Asset directives ──────────────────────────────────────────────
            case 'style':
                return '<?php \Luany\Lte\AssetStack::startStyle(' . $this->parseArgs($args) . '); ?>';
            case 'endstyle':
                return '<?php \Luany\Lte\AssetStack::endStyle(); ?>';
            case 'script':
                return '<?php \Luany\Lte\AssetStack::startScript(' . $this->parseArgs($args) . '); ?>';
            case 'endscript':
                return '<?php \Luany\Lte\AssetStack::endScript(); ?>';
            case 'styles':
                return '<?php echo \Luany\Lte\AssetStack::renderStyles(); ?>';
            case 'scripts':
                return '<?php echo \Luany\Lte\AssetStack::renderScripts(); ?>';

            // ── Stack directives ──────────────────────────────────────────────
            case 'push':
                $stackName = trim($args, '\'"');
                return "<?php \Luany\Lte\SectionStack::startPush('{$stackName}'); ?>";
            case 'endpush':
                return '<?php \Luany\Lte\SectionStack::endPush(); ?>';
            case 'stack':
                $stackName = trim($args, '\'"');
                return "<?php echo \Luany\Lte\SectionStack::getStack('{$stackName}'); ?>";

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
                    [$exprPart, $flagsPart] = $this->parseSectionArgs($args);
                    $expr  = trim($exprPart);
                    $flags = trim($flagsPart) . ' | ' . $safeFlags;
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
            //   <div class="[output of ClassHelper::compile([...])]">
            //
            // The array expression is passed verbatim to ClassHelper::compile()
            // which evaluates conditions at runtime.
            //
            case 'class':
                return '<?php echo \'class="\' . Luany\Lte\ClassHelper::compile(' . $args . ') . \'"\'; ?>';

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
                return "<?php \\Luany\\Lte\\ComponentStack::startComponent(\$__engine, '{$viewName}', {$dataExpr}); ?>";

            case 'slot':
                $slotName = $args !== null ? trim($args, '\'"') : '__default';
                return "<?php \\Luany\\Lte\\ComponentStack::startSlot('{$slotName}'); ?>";

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
        if ($args === null || trim($args) === '') {
            return '[]';
        }

        $args  = trim($args, '() ');
        $parts = [];
        $token = '';
        $inS   = false;
        $inD   = false;

        for ($i = 0, $len = strlen($args); $i < $len; $i++) {
            $ch = $args[$i];

            if ($ch === "'" && !$inD) { $inS = !$inS; $token .= $ch; continue; }
            if ($ch === '"' && !$inS) { $inD = !$inD; $token .= $ch; continue; }

            if ($ch === ',' && !$inS && !$inD) {
                $parts[] = trim($token, '\'" ');
                $token   = '';
                continue;
            }

            $token .= $ch;
        }

        if (trim($token) !== '') {
            $parts[] = trim($token, '\'" ');
        }

        if (empty($parts)) {
            return '[]';
        }

        return '[' . implode(',', array_map(fn($p) => "'{$p}'", $parts)) . ']';
    }

    private function hasInlineValue(string $args): bool
    {
        $inSingle = $inDouble = false;
        for ($i = 0, $len = strlen($args); $i < $len; $i++) {
            $ch = $args[$i];
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; continue; }
            if ($ch === ',' && !$inSingle && !$inDouble) return true;
        }
        return false;
    }

    /** @return array{0: string, 1: string|null} */
    private function parseSectionArgs(string $args): array
    {
        $inSingle = $inDouble = false;
        $splitAt  = -1;
        for ($i = 0, $len = strlen($args); $i < $len; $i++) {
            $ch = $args[$i];
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; continue; }
            if ($ch === ',' && !$inSingle && !$inDouble) { $splitAt = $i; break; }
        }
        if ($splitAt === -1) {
            return [trim($args, '\'" '), "''"];
        }
        $name  = trim(substr($args, 0, $splitAt), '\'" ');
        $value = trim(substr($args, $splitAt + 1));
        return [$name, $value];
    }

    /** @return array{0: string, 1: string|null} */
    private function parseYieldArgs(?string $args): array
    {
        if ($args === null) return ['', "''"];
        if ($this->hasInlineValue($args)) {
            return $this->parseSectionArgs($args);
        }
        return [trim($args, '\'"'), "''"];
    }

    /**
     * Parse @component arguments: 'view.name' or 'view.name', ['key' => 'val'].
     *
     * @return array{0: string, 1: string}  [viewName, dataExpression]
     */
    private function parseComponentArgs(?string $args): array
    {
        if ($args === null || trim($args) === '') {
            return ['', '[]'];
        }

        if ($this->hasInlineValue($args)) {
            // Two arguments: 'view.name', [...]
            $depth   = 0;
            $splitAt = -1;
            $inS = $inD = false;

            for ($i = 0, $len = strlen($args); $i < $len; $i++) {
                $ch = $args[$i];
                if ($ch === "'" && !$inD) { $inS = !$inS; continue; }
                if ($ch === '"' && !$inS) { $inD = !$inD; continue; }
                if (!$inS && !$inD) {
                    if ($ch === '[' || $ch === '(') $depth++;
                    elseif ($ch === ']' || $ch === ')') $depth--;
                    elseif ($ch === ',' && $depth === 0) { $splitAt = $i; break; }
                }
            }

            if ($splitAt !== -1) {
                $viewPart = trim(substr($args, 0, $splitAt), '\'" ');
                $dataPart = trim(substr($args, $splitAt + 1));
                return [$viewPart, $dataPart];
            }
        }

        // Single argument: just 'view.name'
        return [trim($args, '\'" '), '[]'];
    }

    // ── Custom directives ──────────────────────────────────────────────────────

    public function directive(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    private function registerDefaultDirectives(): void
    {
        // Reserved for future built-ins
    }
}