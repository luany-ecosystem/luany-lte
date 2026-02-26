<?php

namespace Luany\Lte;

/**
 * LTE AssetStack
 *
 * Manages inline styles and scripts defined inside .lte views/components.
 * Accumulates them during render and outputs at the correct place in the layout.
 *
 * Usage in templates:
 *
 *   @style                          — open inline style block
 *     .card { padding: 1rem; }
 *   @endstyle
 *
 *   @script(defer)                  — open inline script block (optional: defer)
 *     document.querySelector('.card').addEventListener('click', fn);
 *   @endscript
 *
 *   @styles                         — render all accumulated styles (use in <head>)
 *   @scripts                        — render all accumulated scripts (use before </body>)
 *
 * Deduplication:
 *   If the same component is @include'd multiple times, its styles/scripts
 *   are only rendered once — deduplication is automatic via content hash.
 *
 * Separation of concerns:
 *   AssetStack → inline resource semantics
 *   SectionStack → layout semantics
 *   These two never interact.
 */
class AssetStack
{
    private static array $styles  = [];
    private static array $scripts = [];

    private static array $styleHashes  = [];
    private static array $scriptHashes = [];

    private static bool  $capturingStyle  = false;
    private static bool  $capturingScript = false;
    private static array $pendingOptions  = [];

    // ── Reset ─────────────────────────────────────────────────────────────────

    /**
     * Called by Engine at the start of every root render.
     * Guarantees zero state leakage between requests.
     */
    public static function reset(): void
    {
        self::$styles         = [];
        self::$scripts        = [];
        self::$styleHashes    = [];
        self::$scriptHashes   = [];
        self::$capturingStyle  = false;
        self::$capturingScript = false;
        self::$pendingOptions  = [];
    }

    // ── Style ─────────────────────────────────────────────────────────────────

    /**
     * Begin capturing an inline style block.
     *
     * @param array $options  Supported: ['scoped'] — reserved for v0.3
     */
    public static function startStyle(array $options = []): void
    {
        self::$capturingStyle = true;
        self::$pendingOptions = $options;
        ob_start();
    }

    /**
     * End capturing. Stores the block if not already seen (deduplication).
     */
    public static function endStyle(): void
    {
        if (!self::$capturingStyle) {
            return;
        }

        $content = ob_get_clean();
        $hash    = md5($content);

        if (!isset(self::$styleHashes[$hash])) {
            self::$styleHashes[$hash] = true;
            self::$styles[] = [
                'content' => trim($content),
                'options' => self::$pendingOptions,
            ];
        }

        self::$capturingStyle = false;
        self::$pendingOptions = [];
    }

    // ── Script ────────────────────────────────────────────────────────────────

    /**
     * Begin capturing an inline script block.
     *
     * @param array $options  Supported: ['defer']
     */
    public static function startScript(array $options = []): void
    {
        self::$capturingScript = true;
        self::$pendingOptions  = $options;
        ob_start();
    }

    /**
     * End capturing. Stores the block if not already seen (deduplication).
     */
    public static function endScript(): void
    {
        if (!self::$capturingScript) {
            return;
        }

        $content = ob_get_clean();
        $hash    = md5($content);

        if (!isset(self::$scriptHashes[$hash])) {
            self::$scriptHashes[$hash] = true;
            self::$scripts[] = [
                'content' => trim($content),
                'options' => self::$pendingOptions,
            ];
        }

        self::$capturingScript = false;
        self::$pendingOptions  = [];
    }

    // ── Render ────────────────────────────────────────────────────────────────

    /**
     * Render all accumulated <style> blocks.
     * Place @styles inside <head> in your layout.
     */
    public static function renderStyles(): string
    {
        if (empty(self::$styles)) {
            return '';
        }

        $output = '';
        foreach (self::$styles as $style) {
            $output .= "<style>\n{$style['content']}\n</style>\n";
        }

        return $output;
    }

    /**
     * Render all accumulated <script> blocks.
     * Place @scripts before </body> in your layout.
     */
    public static function renderScripts(): string
    {
        if (empty(self::$scripts)) {
            return '';
        }

        $output = '';
        foreach (self::$scripts as $script) {
            $defer = in_array('defer', $script['options']) ? ' defer' : '';
            $output .= "<script{$defer}>\n{$script['content']}\n</script>\n";
        }

        return $output;
    }

    // ── Introspection (for testing) ───────────────────────────────────────────

    public static function getStyleCount(): int
    {
        return count(self::$styles);
    }

    public static function getScriptCount(): int
    {
        return count(self::$scripts);
    }
}
