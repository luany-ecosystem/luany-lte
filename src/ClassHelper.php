<?php

namespace Luany\Lte;

/**
 * ClassHelper
 *
 * Runtime helper for the @class directive.
 *
 * @class compiles to a call to ClassHelper::compile() at runtime.
 * This keeps the Compiler simple (it just passes the raw array expression
 * through) and defers the conditional logic to PHP execution time.
 *
 * Supported array formats:
 *
 *   // Unconditional (integer key):
 *   ['btn', 'btn-lg']
 *   → 'btn btn-lg'
 *
 *   // Conditional (string key → bool expression):
 *   ['btn', 'btn-primary' => $isPrimary, 'btn-disabled' => !$active]
 *   → 'btn btn-primary'  (when $isPrimary=true, $active=true)
 *   → 'btn btn-disabled' (when $isPrimary=false, $active=false)
 *
 *   // Mixed:
 *   ['flex', 'items-center', 'justify-between' => $centered]
 *
 * Usage in templates:
 *
 *   <div @class(['btn', 'btn-primary' => $isPrimary, 'btn-disabled' => !$active])>
 *       Click me
 *   </div>
 *
 * Compiled output:
 *
 *   <div class="<?php echo \Luany\Lte\ClassHelper::compile(['btn', 'btn-primary' => $isPrimary]); ?>">
 */
final class ClassHelper
{
    /**
     * Evaluate a conditional class array and return the compiled class string.
     *
     * Rules:
     *   - Integer-keyed entries are ALWAYS included (unconditional)
     *   - String-keyed entries are included only when the value is truthy
     *   - Empty string entries are silently skipped
     *   - Result is imploded with a single space
     *
     * @param array<int|string, mixed> $classes
     */
    public static function compile(array $classes): string
    {
        $result = [];

        foreach ($classes as $class => $condition) {
            if (is_int($class)) {
                // Unconditional class — the value IS the class name
                $className = (string) $condition;
                if ($className !== '') {
                    $result[] = $className;
                }
            } else {
                // Conditional class — the key IS the class name, value is the condition
                if ($condition && $class !== '') {
                    $result[] = $class;
                }
            }
        }

        return implode(' ', $result);
    }

    /**
     * Build a complete class="..." HTML attribute string.
     *
     * Convenience wrapper over compile() used by the @class directive.
     * Keeps the generated PHP template free of any string-literal quoting.
     *
     * @param array<int|string, mixed> $classes
     */
    public static function attr(array $classes): string
    {
        return 'class="' . self::compile($classes) . '"';
    }
}