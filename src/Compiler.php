<?php

namespace Luany\Lte;

/**
 * LTE Compiler
 *
 * Compiles AST nodes produced by Parser into executable PHP strings.
 */
class Compiler
{
    private array $directives   = [];
    private int   $forelseDepth = 0;

    public function __construct()
    {
        $this->registerDefaultDirectives();
    }

    public function compile(array $ast): string
    {
        $this->forelseDepth = 0;
        $output = '';
        foreach ($ast as $node) {
            $output .= $this->compileNode($node);
        }
        return $output;
    }

    public function directive(string $name, callable $handler): void
    {
        $this->directives[$name] = $handler;
    }

    private function compileNode(array $node): string
    {
        $line   = $node['line'] ?? null;
        $marker = ($line !== null) ? "<?php /* @lte:{$line} */ ?>" : '';

        switch ($node['type']) {
            case 'text':
                return $marker . $node['content'];
            case 'echo':
                $expr = $node['expression'];
                return $marker . "<?php echo htmlspecialchars((string)({$expr} ?? ''), ENT_QUOTES, 'UTF-8'); ?>";
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

    private function compileDirective(array $node): string
    {
        $name = $node['name'];
        $args = $node['args'];

        if (isset($this->directives[$name])) {
            return call_user_func($this->directives[$name], $args);
        }

        switch ($name) {

            case 'if':        return "<?php if({$args}): ?>";
            case 'elseif':    return "<?php elseif({$args}): ?>";
            case 'else':      return '<?php else: ?>';
            case 'endif':     return '<?php endif; ?>';
            case 'unless':    return "<?php if(!({$args})): ?>";
            case 'endunless': return '<?php endif; ?>';

            case 'foreach':    return "<?php foreach({$args}): ?>";
            case 'endforeach': return '<?php endforeach; ?>';
            case 'for':        return "<?php for({$args}): ?>";
            case 'endfor':     return '<?php endfor; ?>';
            case 'while':      return "<?php while({$args}): ?>";
            case 'endwhile':   return '<?php endwhile; ?>';

            case 'forelse':
                $this->forelseDepth++;
                $tmp   = '__lte_fe' . $this->forelseDepth;
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

            case 'php':
                return $args !== null ? "<?php {$args}; ?>" : '<?php ';
            case 'endphp':
                return ' ?>';

            case 'csrf':
                $token = "htmlspecialchars((string)(isset(\$_SESSION['csrf_token'])"
                       . " ? \$_SESSION['csrf_token']"
                       . " : (\$_SESSION['csrf_token'] = bin2hex(random_bytes(32)))),"
                       . " ENT_QUOTES, 'UTF-8')";
                return '<input type="hidden" name="csrf_token" value="<?php echo ' . $token . '; ?>">';

            case 'method':
                $m = strtoupper(trim($args, '\'"'));
                return '<input type="hidden" name="_method" value="' . $m . '">';

            case 'auth':     return "<?php if(isset(\$_SESSION['user_id'])): ?>";
            case 'endauth':  return '<?php endif; ?>';
            case 'guest':    return "<?php if(!isset(\$_SESSION['user_id'])): ?>";
            case 'endguest': return '<?php endif; ?>';

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
                $ya          = $this->parseYieldArgs($args);
                $sectionName = $ya[0];
                $default     = $ya[1] ?? "''";
                return "<?php echo \\Luany\\Lte\\SectionStack::get('{$sectionName}', {$default}); ?>";

            case 'include':
                $parentVars = "array_filter(get_defined_vars(),"
                            . " function(\$k) { return !str_starts_with(\$k, '__'); },"
                            . " ARRAY_FILTER_USE_KEY)";
                if ($args !== null && $this->hasInlineValue($args)) {
                    [$rawView, $extraData] = $this->parseSectionArgs($args);
                    $viewName = trim($rawView, '\'"');
                    $data     = "array_merge({$parentVars}, {$extraData})";
                } else {
                    $viewName = trim((string) $args, '\'"');
                    $data     = $parentVars;
                }
                return "<?php echo \$__engine->render('{$viewName}', {$data}); ?>";

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

            case 'push':
                $stackName = trim($args, '\'"');
                return "<?php \Luany\Lte\SectionStack::startPush('{$stackName}'); ?>";
            case 'endpush':
                return '<?php \Luany\Lte\SectionStack::endPush(); ?>';
            case 'stack':
                $stackName = trim($args, '\'"');
                return "<?php echo \Luany\Lte\SectionStack::getStack('{$stackName}'); ?>";

            case 'json':
                $safe = 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE';
                if ($args !== null && $this->hasInlineValue($args)) {
                    [$exprPart, $flagsPart] = $this->parseSectionArgs($args);
                    $expr  = trim($exprPart);
                    $flags = trim($flagsPart) . ' | ' . $safe;
                    return "<?php echo json_encode({$expr}, {$flags}); ?>";
                }
                return "<?php echo json_encode({$args}, {$safe}); ?>";

            case 'class':
                // Delegates to ClassHelper::attr() which wraps the result in class="..."
                // Generated PHP: ClassHelper::attr(ARGS) wrapped in class=attr
                return '<?php echo \Luany\Lte\ClassHelper::attr(' . $args . '); ?>';


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

            case 'dump':
                return "<?php var_dump({$args}); ?>";
            case 'dd':
                return "<?php var_dump({$args}); die; ?>";

            case 'isset':
                return "<?php if(isset({$args})): ?>";
            case 'endisset':
                return '<?php endif; ?>';

            case 'ifempty':
                return "<?php if(empty({$args})): ?>";
            case 'endifempty':
                return '<?php endif; ?>';

            default:
                return "@{$name}" . ($args !== null ? "({$args})" : '');
        }
    }

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
        $inSingle = false;
        $inDouble = false;
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
        $inSingle = false;
        $inDouble = false;
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
        if ($this->hasInlineValue($args)) return $this->parseSectionArgs($args);
        return [trim($args, '\'"'), "''"];
    }

    private function parseComponentArgs(?string $args): array
    {
        if ($args === null || trim($args) === '') {
            return ['', '[]'];
        }
        if ($this->hasInlineValue($args)) {
            $depth   = 0;
            $splitAt = -1;
            $inS     = false;
            $inD     = false;
            for ($i = 0, $len = strlen($args); $i < $len; $i++) {
                $ch = $args[$i];
                if ($ch === "'" && !$inD) { $inS = !$inS; continue; }
                if ($ch === '"' && !$inS) { $inD = !$inD; continue; }
                if (!$inS && !$inD) {
                    if ($ch === '[' || $ch === '(') { $depth++; }
                    elseif ($ch === ']' || $ch === ')') { $depth--; }
                    elseif ($ch === ',' && $depth === 0) { $splitAt = $i; break; }
                }
            }
            if ($splitAt !== -1) {
                $viewPart = trim(substr($args, 0, $splitAt), '\'" ');
                $dataPart = trim(substr($args, $splitAt + 1));
                return [$viewPart, $dataPart];
            }
        }
        return [trim($args, '\'" '), '[]'];
    }

    private function registerDefaultDirectives(): void {}
}