<?php

namespace Luany\Lte;

/**
 * LTE Engine — Orchestrator
 *
 * Render flow:
 *   1. SectionStack::reset() + AssetStack::reset()
 *   2. Compile + evaluate child view  (populates sections/assets, sets layout)
 *   3. If @extends was used → compile + evaluate layout (consumes sections, renders assets)
 *   4. Return final HTML string
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
     */
    public function render(string $view, array $data = []): string
    {
        $isRoot = (self::$renderDepth === 0);

        if ($isRoot) {
            SectionStack::reset();
            AssetStack::reset();
        }

        self::$renderDepth++;

        try {
            $viewPath = $this->findView($view);
            if (!$viewPath) {
                throw new \Exception("View [{$view}] not found in [{$this->viewsPath}]");
            }

            $cachedPath = $this->getCachedPath($viewPath);
            if ($this->needsRecompile($viewPath, $cachedPath)) {
                $this->compile($viewPath, $cachedPath);
            }

            $output = $this->evaluate($cachedPath, $data);

            // Only the root render resolves layouts
            if ($isRoot) {
                $layout = SectionStack::getLayout();
                if ($layout !== null) {
                    $layoutPath = $this->findView($layout);
                    if (!$layoutPath) {
                        throw new \Exception("Layout [{$layout}] not found in [{$this->viewsPath}]");
                    }
                    $layoutCached = $this->getCachedPath($layoutPath);
                    if ($this->needsRecompile($layoutPath, $layoutCached)) {
                        $this->compile($layoutPath, $layoutCached);
                    }
                    return $this->evaluate($layoutCached, $data);
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
     * Resolve view name to absolute filesystem path.
     * Supports dot notation: 'pages.home' → 'pages/home'
     * Tries .lte first, then .php for backward compatibility.
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

    private function compile(string $viewPath, string $cachedPath): void
    {
        $source = file_get_contents($viewPath);
        $ast    = $this->parser->parse($source);
        $php    = $this->compiler->compile($ast);
        file_put_contents($cachedPath, $php);
    }

    /**
     * Execute compiled PHP file in isolated scope, return output as string.
     *
     * Scope isolation — variables visible inside the compiled view:
     *   - All keys from $data (user-supplied variables)        ✅
     *   - $__engine (filtered by __ prefix in @include)        ✅
     *   - $__lte_path (replaces $path — stays internal)        ✅
     *
     * Variables intentionally removed before require:
     *   - $data  → unset() — prevents leaking the raw array
     *   - $path  → renamed to $__lte_path, then unset()
     *     Both would otherwise appear in get_defined_vars() inside
     *     the compiled view and leak into @include children.
     */
    private function evaluate(string $path, array $data): string
    {
        $data['__engine'] = $this;
        extract($data, EXTR_SKIP);

        // Remove $data and $path from scope before executing the view.
        // Without this, get_defined_vars() inside the compiled template
        // would expose these internal variables to @include children.
        unset($data);
        $__lte_path = $path;
        unset($path);

        ob_start();
        try {
            require $__lte_path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return ob_get_clean();
    }
}