<?php

namespace Luany\Lte;

/**
 * LTE Engine — Orchestrator
 *
 * Render flow:
 *   1. SectionStack::reset() + AssetStack::reset() + ComponentStack::reset() (root only)
 *   2. Compile + evaluate child view  (populates sections/assets, sets layout)
 *   3. If @extends was used → compile + evaluate layout (consumes sections, renders assets)
 *   4. Resolve asset placeholders → replace <!--__LTE_STYLES__--> / <!--__LTE_SCRIPTS__-->
 *      with the fully-collected AssetStack output (see resolveAssetPlaceholders())
 *   5. Return final HTML string
 *
 * Phase 5 additions:
 *   - ComponentStack::reset() called at root render start
 *   - evaluate() now catches Throwable and enriches error messages with
 *     the .lte source line number (extracted from @lte:{N} PHP comments
 *     embedded by the Compiler)
 *   - compile() wraps parser/compiler errors with view name context
 *
 * Asset placeholder resolution (Bug fix — render order):
 *   The Compiler emits HTML comment placeholders for @styles and @scripts
 *   instead of calling AssetStack::render*() directly. This decouples the
 *   point where the placeholder appears in the HTML from the point where
 *   the assets are collected. resolveAssetPlaceholders() is called once,
 *   after the complete layout evaluation, performing a single str_replace
 *   over the final HTML string. This guarantees that @style/@script blocks
 *   registered by any @include'd component — regardless of its position in
 *   the layout — are present in the AssetStack before they are flushed.
 *
 *   Without this fix, placing @styles in <head> would output an empty or
 *   partial <style> block because @include'd component styles had not yet
 *   been registered at that point in the linear evaluation order.
 */
class Engine
{
    /**
     * Engine version. Part of the compiled-cache key, so upgrading LTE never
     * reuses templates compiled by an older compiler.
     */
    public const VERSION = '1.2.0';

    private Parser   $parser;
    private Compiler $compiler;
    private string   $viewsPath;
    private string   $cachePath;
    private bool     $autoReload;
    private static int $renderDepth = 0;

    public function __construct(
        string  $viewsPath,
        ?string $cachePath  = null,
        bool    $autoReload = false
    ) {
        $this->viewsPath  = rtrim($viewsPath, '/');
        $this->autoReload = $autoReload;

        $this->compiler = new Compiler();
        $compiler       = $this->compiler;
        $this->parser   = new Parser(static fn (string $name): bool => $compiler->isDirective($name));

        if ($cachePath !== null) {
            $this->cachePath = rtrim($cachePath, '/\\');
            self::ensureDirectory($this->cachePath, 0755);
        } else {
            // No shared, predictable /tmp/lte_cache: use a per-user directory
            // created 0700 and refuse it if another user owns or can write it.
            // Passing an explicit, project-local $cachePath is recommended.
            $this->cachePath = self::defaultCachePath();
            self::ensureDirectory($this->cachePath, 0700);
            self::assertPrivateDirectory($this->cachePath);
        }
    }

    /**
     * Render a view and return the final HTML string.
     *
     * @param string               $view  Dot-notation view name (e.g. 'pages.home')
     * @param array<string, mixed> $data  Variables to expose in the view
     * @throws \Exception If the view or layout cannot be found, or compilation fails
     */
    public function render(string $view, array $data = []): string
    {
        $isRoot = (self::$renderDepth === 0);

        if ($isRoot) {
            SectionStack::reset();
            AssetStack::reset();
            // Only reset ComponentStack if no component resolution is in progress.
            // endComponent() calls render() at depth 0 — resetting here would
            // destroy outer component frames that are still on the stack.
            if (!ComponentStack::isActive()) {
                ComponentStack::reset();
            }
        }

        // Snapshot of the global render state, restored if this render fails so
        // that a throwing view/component cannot leak frames or buffers into the
        // next render (long-running workers: RoadRunner, Swoole, FrankenPHP).
        $obLevel        = ob_get_level();
        $componentDepth = ComponentStack::depth();
        $sectionDepth   = SectionStack::openDepth();

        self::$renderDepth++;

        try {
            $viewPath = $this->findView($view);
            if (!$viewPath) {
                throw new \Exception("View [{$view}] not found in [{$this->viewsPath}]");
            }

            $cachedPath = $this->getCachedPath($viewPath);
            if ($this->needsRecompile($viewPath, $cachedPath)) {
                $this->compile($viewPath, $cachedPath, $view);
            }

            // @extends is only honoured for the top-level view: a partial or
            // component that declares a layout must not hijack the page's.
            $outerLayout = SectionStack::getLayout();
            $output      = $this->evaluate($cachedPath, $data, $viewPath);
            if (!$isRoot) {
                SectionStack::setLayout($outerLayout);
            }

            if ($isRoot) {
                // Resolve the layout chain: child → layout → parent layout ...
                // Each level may @extends another; sections defined at a lower
                // level (closer to the child) take precedence.
                $seen = [];
                while (($layout = SectionStack::getLayout()) !== null) {
                    if (isset($seen[$layout])) {
                        throw new TemplateSyntaxException(
                            'Circular @extends detected: ' . implode(' → ', array_keys($seen)) . " → {$layout}"
                        );
                    }
                    $seen[$layout] = true;

                    $layoutPath = $this->findView($layout);
                    if (!$layoutPath) {
                        throw new \Exception("Layout [{$layout}] not found in [{$this->viewsPath}]");
                    }
                    $layoutCached = $this->getCachedPath($layoutPath);
                    if ($this->needsRecompile($layoutPath, $layoutCached)) {
                        $this->compile($layoutPath, $layoutCached, $layout);
                    }

                    SectionStack::setLayout(null);
                    SectionStack::enterLayout();

                    // Evaluate the layout — this runs all @include directives,
                    // registering every component's @style/@script blocks into AssetStack.
                    $output = $this->evaluate($layoutCached, $data, $layoutPath);
                }

                // Only AFTER the outermost layout returns do we replace the
                // placeholders emitted by @styles and @scripts, ensuring a
                // complete asset collection.
                return $this->resolveAssetPlaceholders($output);
            }

            // Non-root renders (depth > 0): return raw output; the root caller
            // is responsible for resolving placeholders after the full cycle.
            return $output;

        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            ComponentStack::unwindTo($componentDepth);
            SectionStack::unwindTo($sectionDepth);
            if ($isRoot) {
                AssetStack::reset();
            }
            throw $e;
        } finally {
            self::$renderDepth--;
        }
    }

    /**
     * Access the compiler (for registering custom directives).
     */
    public function getCompiler(): Compiler
    {
        return $this->compiler;
    }

    /**
     * Clear all cached compiled views.
     */
    public function clearCache(): void
    {
        $files = array_merge(
            glob($this->cachePath . '/*.php') ?: [],
            glob($this->cachePath . '/*.tmp') ?: []
        );
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
        }
    }

    public function setAutoReload(bool $autoReload): void
    {
        $this->autoReload = $autoReload;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Resolve a dot-notation view name to an absolute filesystem path.
     * Tries .lte first, then .php for backward-compatibility.
     */
    /**
     * Parse and validate a view into its syntax tree without rendering it
     * (for tooling: linters, editor integrations, `luany view:check`).
     *
     * @throws TemplateSyntaxException
     * @throws \RuntimeException When the view does not exist
     */
    public function parseView(string $view): Ast\Template
    {
        $path = $this->findView($view);

        if ($path === null) {
            throw new \RuntimeException("View [{$view}] not found.");
        }

        try {
            return $this->compiler->buildTree($this->parser->parse((string) file_get_contents($path)));
        } catch (TemplateSyntaxException $e) {
            throw new TemplateSyntaxException(
                "In view [{$view}]: " . $e->getMessage(),
                0,
                $e,
                $e->templateLine,
                $e->templateColumn,
                $view,
            );
        }
    }

    public function findView(string $view): ?string
    {
        $path = str_replace('.', '/', $view);

        $ltePath = $this->viewsPath . '/' . $path . '.lte';
        if (file_exists($ltePath)) return $ltePath;

        $phpPath = $this->viewsPath . '/' . $path . '.php';
        if (file_exists($phpPath)) return $phpPath;

        return null;
    }

    /**
     * Absolute path of the compiled-template cache directory.
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * Cache file for a view. The key covers the source path, the engine
     * version and the set of registered custom directives, so neither an
     * upgrade nor registering a directive reuses stale compiled code.
     */
    private function getCachedPath(string $viewPath): string
    {
        $hash = hash('sha256', self::VERSION . "\0" . $this->compiler->signature() . "\0" . $viewPath);
        return $this->cachePath . '/' . $hash . '.php';
    }

    private function needsRecompile(string $viewPath, string $cachedPath): bool
    {
        if ($this->autoReload) return true;
        if (!file_exists($cachedPath)) return true;
        // '>=' so that an edit in the same second as the last compile is not
        // missed (see writeCompiled() for how this avoids endless recompiles).
        return filemtime($viewPath) >= filemtime($cachedPath);
    }

    /**
     * Atomically write compiled PHP: write a unique temp file in the cache
     * directory, then rename() it over the target. Concurrent requests see
     * either the old or the new file, never a partially written one.
     *
     * mtime: when the source was modified in the current second, the cache
     * file is back-dated to before the source so the next request recompiles
     * (an edit later in this same second must not be missed). Otherwise the
     * cache keeps "now", which is strictly newer than the source.
     */
    private function writeCompiled(string $viewPath, string $cachedPath, string $php): void
    {
        $tmp = $cachedPath . '.' . bin2hex(random_bytes(8)) . '.tmp';

        if (@file_put_contents($tmp, $php) !== strlen($php)) {
            @unlink($tmp);
            throw new \RuntimeException("LTE could not write compiled template to [{$tmp}].");
        }

        clearstatcache(true, $viewPath);
        $sourceMtime = filemtime($viewPath);
        if ($sourceMtime !== false && $sourceMtime >= time()) {
            @touch($tmp, $sourceMtime - 1);
        }

        if (!@rename($tmp, $cachedPath)) {
            @unlink($tmp);
            throw new \RuntimeException("LTE could not move compiled template into [{$cachedPath}].");
        }

        clearstatcache(true, $cachedPath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cachedPath, true);
        }
    }

    private static function defaultCachePath(): string
    {
        $user = function_exists('posix_geteuid')
            ? (string) posix_geteuid()
            : substr(hash('sha256', get_current_user() . "\0" . __DIR__), 0, 16);

        return rtrim(sys_get_temp_dir(), '/\\') . '/lte_cache_' . $user;
    }

    private static function ensureDirectory(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new \RuntimeException("LTE could not create cache directory [{$dir}].");
        }

        // mkdir() is subject to the umask; enforce the intended mode.
        @chmod($dir, $mode);
    }

    /**
     * Refuse a cache directory that another user could have planted or can
     * write to: it must be a real directory (not a symlink) owned by the
     * current user; group/other write permission is removed (or refused).
     */
    private static function assertPrivateDirectory(string $dir): void
    {
        clearstatcache(true, $dir);

        if (is_link($dir) || !is_dir($dir)) {
            throw new \RuntimeException("LTE refuses cache directory [{$dir}]: not a real directory.");
        }

        if (!function_exists('posix_geteuid')) {
            return; // ownership cannot be verified (e.g. Windows)
        }

        if (fileowner($dir) !== posix_geteuid()) {
            throw new \RuntimeException(
                "LTE refuses cache directory [{$dir}]: it is owned by another user. "
                . 'Pass an explicit $cachePath to Engine.'
            );
        }

        $perms = fileperms($dir);
        if ($perms !== false && ($perms & 0022) !== 0 && !@chmod($dir, 0700)) {
            throw new \RuntimeException(
                "LTE refuses cache directory [{$dir}]: it is writable by group or others. "
                . 'Pass an explicit $cachePath to Engine.'
            );
        }
    }

    /**
     * Compile a .lte source file to a cached PHP file.
     *
     * Wraps parse/compile errors with the view name for easier debugging.
     *
     * @param string $viewPath    Absolute path to the .lte source file
     * @param string $cachedPath  Absolute path to write the compiled PHP
     * @param string $viewName    Dot-notation view name (for error messages)
     */
    private function compile(string $viewPath, string $cachedPath, string $viewName): void
    {
        $source = file_get_contents($viewPath);

        try {
            $tree = $this->compiler->buildTree($this->parser->parse($source));
            $php  = $this->compiler->compileTree($tree);
        } catch (TemplateSyntaxException $e) {
            throw new TemplateSyntaxException(
                "LTE compilation error in view [{$viewName}]: " . $e->getMessage(),
                0,
                $e,
                $e->templateLine,
                $e->templateColumn,
                $viewName,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("LTE compilation error in view [{$viewName}]: " . $e->getMessage(), 0, $e);
        }

        $this->writeCompiled($viewPath, $cachedPath, $php);
    }

    /**
     * Execute a compiled PHP file in isolated scope and return output.
     *
     * Error enrichment:
     *   The Compiler embeds `<?php /* @lte:{LINE} *\/ ?>` markers in the
     *   generated PHP. If an exception is thrown during evaluation, this
     *   method reads the compiled file and scans backwards from the error
     *   line to find the nearest @lte:{N} marker, then re-throws with the
     *   original .lte line number included in the message.
     *
     * Scope isolation:
     *   - $data    → unset before require (prevents leaking the raw array)
     *   - $path    → renamed to $__lte_path (prevents leaking into child views)
     *   - $__engine → injected for @include / @component
     *   Internal variables prefixed __ are excluded by @include's filter.
     *
     * @param string               $path        Absolute path to the compiled cache file
     * @param array<string, mixed> $data        Variables to extract into the view scope
     * @param string               $sourcePath  Original .lte file path (for error messages)
     */
    private function evaluate(string $path, array $data, string $sourcePath = ''): string
    {
        // Move the internal $data parameter into a __ prefixed variable and
        // unset it BEFORE calling extract(). This prevents a name collision:
        // if the caller passed ['data' => ...], extract() would create $data
        // in scope, but then unset($data) below would destroy it.
        $__lte_vars             = $data;
        $__lte_vars['__engine'] = $this;
        unset($data); // remove parameter from scope before extract

        extract($__lte_vars, EXTR_SKIP);
        unset($__lte_vars); // remove internal copy — user's $data (if any) remains
        $__lte_path   = $path;
        $__lte_source = $sourcePath;
        unset($path);

        // Record the output buffer depth before we open ours.
        // In the error path we restore to exactly this level — no more, no less.
        // This prevents PHPUnit 11 from warning about "closed output buffers
        // other than its own" when tested code deliberately triggers errors.
        $__lte_ob_level = ob_get_level();
        ob_start();
        try {
            require $__lte_path;
        } catch (\Throwable $e) {
            // Clean only the buffers we opened, leaving PHPUnit's own untouched
            while (ob_get_level() > $__lte_ob_level) {
                ob_end_clean();
            }

            // Try to map the PHP error line back to an .lte source line
            $lteLine = $this->resolveLteLine($__lte_path, $e->getLine());

            $viewLabel = $__lte_source !== ''
                ? basename($__lte_source)
                : basename($__lte_path);

            $location = $lteLine !== null
                ? "[{$viewLabel} line {$lteLine}]"
                : "[{$viewLabel}]";

            throw new \RuntimeException(
                "LTE render error in {$location}: " . $e->getMessage(),
                0,
                $e
            );
        }

        // A view must leave exactly the one buffer we opened. Anything else means
        // an unbalanced block (@section/@push/@component/@style) and returning
        // ob_get_clean() would hand back somebody else's buffer.
        if (ob_get_level() !== $__lte_ob_level + 1) {
            while (ob_get_level() > $__lte_ob_level) {
                ob_end_clean();
            }
            $viewLabel = basename($__lte_source !== '' ? $__lte_source : $__lte_path);
            throw new TemplateSyntaxException(
                "LTE render error in [{$viewLabel}]: unbalanced output buffers — "
                . 'a @section, @push, @component, @style or @script block was not closed.'
            );
        }

        return (string) ob_get_clean();
    }

    /**
     * Replace @styles and @scripts placeholders with the actual rendered
     * asset blocks collected by AssetStack during the full render cycle.
     *
     * Design rationale:
     *   The Compiler emits HTML comment markers — not direct PHP calls — for
     *   @styles and @scripts. These markers survive ob_start/ob_get_clean
     *   intact and are batch-replaced here via str_replace, which is O(n)
     *   over the final HTML string and performs a single pass over it.
     *
     *   This method is called exclusively at the root render level, AFTER:
     *     (a) the child view has been fully evaluated, AND
     *     (b) the layout (including all its @include'd components) has been
     *         fully evaluated.
     *   At that point AssetStack holds the complete, deduplicated set of all
     *   @style and @script blocks from every view in the render tree.
     *
     *   Calling renderStyles() / renderScripts() here — rather than inline
     *   during layout evaluation — is what guarantees that component styles
     *   are present even when @styles appears in <head>, before the component
     *   @include directives have run.
     *
     * Placeholder strings:
     *   <!--__LTE_STYLES__-->   → AssetStack::renderStyles()
     *   <!--__LTE_SCRIPTS__-->  → AssetStack::renderScripts()
     *
     * Edge cases:
     *   - @styles / @scripts may appear zero, one, or multiple times in the
     *     layout. str_replace handles all occurrences in one call.
     *   - Non-root renders (depth > 0) bypass this method; they return their
     *     raw output including any placeholders, which the root caller resolves.
     *
     * @param string $html  The fully-evaluated HTML string from the root render
     * @return string       HTML with asset placeholders replaced by actual output
     */
    private function resolveAssetPlaceholders(string $html): string
    {
        return str_replace(
            ['<!--__LTE_STYLES__-->', '<!--__LTE_SCRIPTS__-->'],
            [AssetStack::renderStyles(), AssetStack::renderScripts()],
            $html
        );
    }

    /**
     * Scan a compiled PHP cache file to find the nearest @lte:{N} line marker
     * at or before $phpLine.
     *
     * The Compiler embeds these as: `<?php /* @lte:42 *\/ ?>`
     * They appear on the compiled line that corresponds to the .lte source line.
     *
     * @param  string  $compiledPath  Absolute path to the compiled cache file
     * @param  int     $phpLine       The line number from the PHP exception
     * @return int|null  The .lte source line, or null if no marker found
     */
    private function resolveLteLine(string $compiledPath, int $phpLine): ?int
    {
        if (!file_exists($compiledPath)) {
            return null;
        }

        $lines = file($compiledPath);
        if ($lines === false) {
            return null;
        }

        // Scan backwards from the error line to find the nearest marker
        $limit = min($phpLine - 1, count($lines) - 1);

        for ($i = $limit; $i >= 0; $i--) {
            if (preg_match('/@lte:(\d+)/', $lines[$i], $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }
}