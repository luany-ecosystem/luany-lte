<?php

namespace Luany\Lte;

/**
 * LTE Engine — Orchestrator
 *
 * Render flow:
 *   1. SectionStack::reset() + AssetStack::reset() + ComponentStack::reset() (root only)
 *   2. Compile + evaluate child view  (populates sections/assets, sets layout)
 *   3. If @extends was used → compile + evaluate layout (consumes sections, renders assets)
 *   4. Return final HTML string
 *
 * Phase 5 additions:
 *   - ComponentStack::reset() called at root render start
 *   - evaluate() now catches Throwable and enriches error messages with
 *     the .lte source line number (extracted from @lte:{N} PHP comments
 *     embedded by the Compiler)
 *   - compile() wraps parser/compiler errors with view name context
 */
class Engine
{
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
        $this->cachePath  = $cachePath !== null
            ? rtrim($cachePath, '/')
            : sys_get_temp_dir() . '/lte_cache';
        $this->autoReload = $autoReload;

        $this->parser   = new Parser();
        $this->compiler = new Compiler();

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Render a view and return the final HTML string.
     *
     * @param string $view  Dot-notation view name (e.g. 'pages.home')
     * @param array  $data  Variables to pass into the view
     * @throws \Exception If the view or layout cannot be found, or compilation fails
     */
    public function render(string $view, array $data = []): string
    {
        $isRoot = (self::$renderDepth === 0);

        if ($isRoot) {
            SectionStack::reset();
            AssetStack::reset();
            ComponentStack::reset();
        }

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

            $output = $this->evaluate($cachedPath, $data, $viewPath);

            if ($isRoot) {
                $layout = SectionStack::getLayout();
                if ($layout !== null) {
                    $layoutPath = $this->findView($layout);
                    if (!$layoutPath) {
                        throw new \Exception("Layout [{$layout}] not found in [{$this->viewsPath}]");
                    }
                    $layoutCached = $this->getCachedPath($layoutPath);
                    if ($this->needsRecompile($layoutPath, $layoutCached)) {
                        $this->compile($layoutPath, $layoutCached, $layout);
                    }
                    return $this->evaluate($layoutCached, $data, $layoutPath);
                }
            }

            return $output;

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
        $files = glob($this->cachePath . '/*.php');
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
    public function findView(string $view): ?string
    {
        $path = str_replace('.', '/', $view);

        $ltePath = $this->viewsPath . '/' . $path . '.lte';
        if (file_exists($ltePath)) return $ltePath;

        $phpPath = $this->viewsPath . '/' . $path . '.php';
        if (file_exists($phpPath)) return $phpPath;

        return null;
    }

    private function getCachedPath(string $viewPath): string
    {
        $hash = md5($viewPath);
        return $this->cachePath . '/' . $hash . '.php';
    }

    private function needsRecompile(string $viewPath, string $cachedPath): bool
    {
        if ($this->autoReload) return true;
        if (!file_exists($cachedPath)) return true;
        return filemtime($viewPath) > filemtime($cachedPath);
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
            $ast = $this->parser->parse($source);
            $php = $this->compiler->compile($ast);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "LTE compilation error in view [{$viewName}]: " . $e->getMessage(),
                0,
                $e
            );
        }

        file_put_contents($cachedPath, $php);
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
     * @param string $path       Absolute path to the compiled cache file
     * @param array  $data       Variables to extract into the view scope
     * @param string $sourcePath Original .lte file path (for error messages)
     */
    private function evaluate(string $path, array $data, string $sourcePath = ''): string
    {
        $data['__engine'] = $this;
        extract($data, EXTR_SKIP);

        unset($data);
        $__lte_path   = $path;
        $__lte_source = $sourcePath;
        unset($path);

        ob_start();
        try {
            require $__lte_path;
        } catch (\Throwable $e) {
            ob_end_clean();

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
        return ob_get_clean();
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
