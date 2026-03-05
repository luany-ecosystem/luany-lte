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
 *   @forelse($items as $item) / @empty / @endforelse
 *     → foreach with empty-state fallback via $__lte_fe{n} temp variable
 *
 *   — Asset directives (v0.2) ————————————————————————————————————————
 *
 *   @style / @endstyle      → Captures inline CSS block → AssetStack
 *   @script / @endscript    → Captures inline JS block  → AssetStack
 *   @styles                 → Renders accumulated styles
 *   @scripts                → Renders accumulated scripts
 *
 *   — Stack directives (v0.2) ————————————————————————————————————————
 *
 *   @push('head') / @endpush   → Pushes content into a named stack
 *   @stack('head')             → Renders named stack
 *
 *   — @php usage ————————————————————————————————————————————————————
 *
 *   Inline (no @endphp):   @php($x = 1)  →  <?php $x = 1; ?>
 *   Block  (needs @endphp): @php ... @endphp
 */
class Compiler
{
    private array $directives = [];

    /**
     * Counter for unique @forelse variable names.
     * Incremented per @forelse so nested @forelse loops never collide.
     */
    private int $forelseDepth = 0;

    public function __construct()
    {
        $this->registerDefaultDirectives();
    }

    /**
     * Compile AST to PHP.
     */
    public function compile(array $ast): string
    {
        $this->forelseDepth = 0;

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
            case 'php_block':
                return "<?php\n{$node['content']}\n?>";    
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
            //
            // Compiled output for @forelse($users as $user):
            //
            //   [php] $__lte_fe1 = $users; if (!empty($__lte_fe1)): [/php]
            //   [php] foreach ($__lte_fe1 as $user): [/php]
            //     ... loop body ...
            //   [php] endforeach; [/php][php] else: [/php]
            //     ... @empty body ...
            //   [php] endif; [/php]
            //
            // The $forelseDepth counter ensures nested @forelse directives
            // each get a unique temp variable (__lte_fe1, __lte_fe2, ...).

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
                // Split into two separate PHP tags so that the token 'else:'
                // never appears bare inside a switch block (PHP parser edge case).
                return '<?php endforeach; ?>' . '<?php else: ?>';

            case 'endforelse':
                $this->forelseDepth = max(0, $this->forelseDepth - 1);
                return '<?php endif; ?>';

            // ── Inline PHP ────────────────────────────────────────────────────
            //
            // Two distinct forms — must NOT be mixed:
            //   Inline:  @php($x = 1)   → self-closing, no @endphp
            //   Block:   @php / @endphp → opens/closes raw PHP block
            //
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
                return "<input type=\"hidden\" name=\"_method\" value=\"{$m}\">";

            // ── Auth guards ───────────────────────────────────────────────────
            case 'auth':
                return "<?php if(isset(\$_SESSION['user_id'])): ?>";
            case 'endauth':
                return '<?php endif; ?>';
            case 'guest':
                return "<?php if(!isset(\$_SESSION['user_id'])): ?>";
            case 'endguest':
                return '<?php endif; ?>';

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

            // ── Asset directives (v0.2) ───────────────────────────────────────
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

            // ── Stack directives (v0.2) ───────────────────────────────────────
            case 'push':
                $stackName = trim($args, '\'"');
                return "<?php \Luany\Lte\SectionStack::startPush('{$stackName}'); ?>";

            case 'endpush':
                return '<?php \Luany\Lte\SectionStack::endPush(); ?>';

            case 'stack':
                $stackName = trim($args, '\'"');
                return "<?php echo \Luany\Lte\SectionStack::getStack('{$stackName}'); ?>";

            // ── Debug helpers ─────────────────────────────────────────────────────────
            case 'dump':
                return "<?php var_dump({$args}); ?>";

            case 'dd':
                return "<?php var_dump({$args}); die; ?>";

            // ── @isset / @endisset ────────────────────────────────────────────────────
            case 'isset':
                return "<?php if(isset({$args})): ?>";

            case 'endisset':
                return '<?php endif; ?>';

            // ── @ifempty / @endifempty ────────────────────────────────────────────────
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