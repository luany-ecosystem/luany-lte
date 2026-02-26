<?php

namespace Luany\Lte;

/**
 * LTE Compiler
 *
 * Compiles AST nodes produced by Parser into executable PHP code.
 *
 * Key directives and their compiled output:
 *
 *   @extends('layouts.main')
 *     → <?php \Luany\Lte\SectionStack::setLayout('layouts.main'); ?>
 *
 *   @section('title', 'My Page')
 *     → <?php \Luany\Lte\SectionStack::set('title', 'My Page'); ?>
 *
 *   @section('content')
 *     → <?php \Luany\Lte\SectionStack::start('content'); ?>
 *
 *   @endsection
 *     → <?php \Luany\Lte\SectionStack::end(); ?>
 *
 *   @yield('content')
 *     → <?php echo \Luany\Lte\SectionStack::get('content'); ?>
 *
 *   @include('components.navbar')
 *     → <?php echo $__engine->render('components.navbar', [...vars...]); ?>
 *
 *   — Asset directives (v0.2) ————————————————————————————————————————
 *
 *   @style / @endstyle
 *     → Captures inline CSS block → AssetStack
 *
 *   @style(scoped) / @endstyle
 *     → Scoped CSS block (reserved for v0.3)
 *
 *   @script / @endscript
 *     → Captures inline JS block → AssetStack
 *
 *   @script(defer) / @endscript
 *     → Deferred inline JS block
 *
 *   @styles
 *     → <?php echo \Luany\Lte\AssetStack::renderStyles(); ?>
 *
 *   @scripts
 *     → <?php echo \Luany\Lte\AssetStack::renderScripts(); ?>
 *
 *   — Stack directives (v0.2) ————————————————————————————————————————
 *
 *   @push('head') / @endpush
 *     → Pushes content into a named stack (accumulative)
 *
 *   @stack('head')
 *     → Renders all content pushed to that stack
 */
class Compiler
{
    private array $directives = [];

    public function __construct()
    {
        $this->registerDefaultDirectives();
    }

    /**
     * Compile AST to PHP
     */
    public function compile(array $ast): string
    {
        $php = '';
        foreach ($ast as $node) {
            $php .= $this->compileNode($node);
        }
        return $php;
    }

    private function compileNode(array $node): string
    {
        switch ($node['type']) {
            case 'text':
                return $node['content'];
            case 'echo':
                return "<?php echo htmlspecialchars((string)({$node['expression']} ?? ''), ENT_QUOTES, 'UTF-8'); ?>";
            case 'raw_echo':
                return "<?php echo {$node['expression']}; ?>";
            case 'directive':
                return $this->compileDirective($node);
            default:
                return '';
        }
    }

    private function compileDirective(array $node): string
    {
        $name = $node['name'];
        $args = $node['args'];

        if (isset($this->directives[$name])) {
            return call_user_func($this->directives[$name], $args);
        }

        switch ($name) {

            // Conditionals
            case 'if':        return "<?php if({$args}): ?>";
            case 'elseif':    return "<?php elseif({$args}): ?>";
            case 'else':      return '<?php else: ?>';
            case 'endif':     return '<?php endif; ?>';
            case 'unless':    return "<?php if(!({$args})): ?>";
            case 'endunless': return '<?php endif; ?>';

            // Loops
            case 'foreach':    return "<?php foreach({$args}): ?>";
            case 'endforeach': return '<?php endforeach; ?>';
            case 'for':        return "<?php for({$args}): ?>";
            case 'endfor':     return '<?php endfor; ?>';
            case 'while':      return "<?php while({$args}): ?>";
            case 'endwhile':   return '<?php endwhile; ?>';

            // Inline PHP
            case 'php':
                return $args !== null ? "<?php {$args}; ?>" : '<?php ';
            case 'endphp':
                return ' ?>';

            // Security
            case 'csrf':
                // CSRF token — uses session directly, no external helper required
                return '<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)(isset($_SESSION[\'csrf_token\']) ? $_SESSION[\'csrf_token\'] : (($_SESSION[\'csrf_token\'] = bin2hex(random_bytes(32))))) , ENT_QUOTES, \'UTF-8\'); ?>">';
            case 'method':
                $m = strtoupper(trim($args, '\'"'));
                return "<input type=\"hidden\" name=\"_method\" value=\"{$m}\">";

            // Auth guards
            case 'auth':
                return "<?php if(isset(\$_SESSION['user_id'])): ?>";
            case 'endauth':
                return '<?php endif; ?>';
            case 'guest':
                return "<?php if(!isset(\$_SESSION['user_id'])): ?>";
            case 'endguest':
                return '<?php endif; ?>';

            // Layout system
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

            // Include — delegates to $__engine->render() — zero external dependencies
            case 'include':
                // Supports both forms:
                //   @include('view.name')
                //   @include('view.name', ['key' => 'value'])
                //
                // Uses character-by-character parsing (zero regex — identity preserved).
                // Reuses hasInlineValue() + parseSectionArgs() already in this Compiler.
                $parentVars = "array_filter(get_defined_vars(), function(\$k) { return !str_starts_with(\$k, '__'); }, ARRAY_FILTER_USE_KEY)";

                if ($args !== null && $this->hasInlineValue($args)) {
                    [$rawView, $extraData] = $this->parseSectionArgs($args);
                    $viewName = trim($rawView, '\'"');
                    $data     = "array_merge({$parentVars}, {$extraData})";
                } else {
                    $viewName = trim((string) $args, '\'"');
                    $data     = $parentVars;
                }

                return "<?php echo \$__engine->render('{$viewName}', {$data}); ?>";

                        // ── Asset directives (v0.2) ────────────────────────────────────────

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

            // ── Stack directives (v0.2) ────────────────────────────────────────

            case 'push':
                $stackName = trim($args, '\'"');
                return "<?php \Luany\Lte\SectionStack::startPush('{$stackName}'); ?>";

            case 'endpush':
                return '<?php \Luany\Lte\SectionStack::endPush(); ?>';

            case 'stack':
                $stackName = trim($args, '\'"');
                return "<?php echo \Luany\Lte\SectionStack::getStack('{$stackName}'); ?>";

            default:
                return "@{$name}" . ($args !== null ? "({$args})" : '');
        }
    }

    // ── Argument parsing helpers ───────────────────────────────────────────────

    /**
     * Parse directive args into a PHP array literal for AssetStack calls.
     *
     * @style(defer, scoped)  →  ['defer','scoped']
     * @style                 →  []
     *
     * Uses character-by-character parsing to handle quoted strings with commas.
     */
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

    private function parseYieldArgs(?string $args): array
    {
        if ($args === null) return ['', "''"];
        if ($this->hasInlineValue($args)) {
            return $this->parseSectionArgs($args);
        }
        return [trim($args, '\'"'), "''"];
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