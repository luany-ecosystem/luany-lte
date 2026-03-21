<?php

namespace Luany\Lte;

/*
 * LTE SectionStack
 *
 * Manages the global state of @section/@yield/@extends/@push/@stack during rendering.
 * Works as a static registry so that child views can declare sections
 * and parent layouts can consume them — even across separate evaluate() calls.
 *
 * Lifecycle:
 *   1. Engine::render() calls SectionStack::reset()
 *   2. Child view is evaluated — LTE directives map to these calls:
 *
 * ```lte
 * @extends('layouts.main')           → SectionStack::setLayout('layouts.main')
 * @section('title', 'Home')          → SectionStack::set('title', 'Home')
 * @section('content') @endsection    → SectionStack::start/end()   [ob_start/get_clean]
 * @push('head') @endpush             → SectionStack::startPush/endPush()
 * ```
 *
 *   3. Engine::render() checks SectionStack::getLayout()
 *   4. Layout is evaluated:
 *
 * ```lte
 * @yield('title')    → SectionStack::get('title')
 * @yield('content')  → SectionStack::get('content')
 * @stack('head')     → SectionStack::getStack('head')
 * ```
 */
class SectionStack
{
    /** @var array<string, string> */
    private static array   $sections       = [];
    private static ?string $currentSection = null;
    private static ?string $layout         = null;

    // ── Push/Stack (v0.2) ──────────────────────────────────────────────────────
    /** @var array<string, array<int, string>> */
    private static array   $stacks       = [];
    private static ?string $currentStack = null;

    // ── Reset ─────────────────────────────────────────────────────────────────

    /**
     * Reset all state — called before each top-level render.
     */
    public static function reset(): void
    {
        self::$sections       = [];
        self::$currentSection = null;
        self::$layout         = null;
        self::$stacks         = [];
        self::$currentStack   = null;
    }

    // ── Layout ────────────────────────────────────────────────────────────────

    public static function setLayout(string $layout): void
    {
        self::$layout = $layout;
    }

    public static function getLayout(): ?string
    {
        return self::$layout;
    }

    // ── Sections ──────────────────────────────────────────────────────────────

    /**
     * Start capturing a block section (@section without inline value).
     */
    public static function start(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    /**
     * End current block section and store its content.
     *
     * Safety: checks ob_get_level() before calling ob_end_clean() on the
     * orphan path — prevents accidentally popping a foreign output buffer
     * when @endsection is called without a matching @section.
     */
    public static function end(): void
    {
        if (self::$currentSection === null) {
            // Orphan @endsection — only clean a buffer if one is open.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return;
        }

        self::$sections[self::$currentSection] = ob_get_clean();
        self::$currentSection = null;
    }

    /**
     * Set an inline section value (@section('name', 'value')).
     */
    public static function set(string $name, string $value): void
    {
        self::$sections[$name] = $value;
    }

    /**
     * Get a section's content for @yield.
     */
    public static function get(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    /**
     * Check if a section has been defined.
     */
    public static function has(string $name): bool
    {
        return isset(self::$sections[$name]);
    }

    // ── Push / Stack (v0.2) ───────────────────────────────────────────────────

    /*
     * Begin capturing content to push into a named stack.
     * Multiple @push calls to the same stack are accumulated — never replaced.
     *
     * Example:
     *   @push('head')
     *       <meta name="description" content="...">
     *   @endpush
     */
    public static function startPush(string $name): void
    {
        self::$currentStack = $name;
        ob_start();
    }

    /**
     * End push capture and append content to the named stack.
     *
     * Safety: checks ob_get_level() before ob_end_clean() on the
     * orphan path — same rationale as SectionStack::end().
     */
    public static function endPush(): void
    {
        if (self::$currentStack === null) {
            // Orphan @endpush — only clean a buffer if one is open.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return;
        }

        $content = ob_get_clean();

        if (!isset(self::$stacks[self::$currentStack])) {
            self::$stacks[self::$currentStack] = '';
        }

        self::$stacks[self::$currentStack] .= $content;
        self::$currentStack = null;
    }

    /*
     * Render all content pushed to a named stack.
     *
     * Example:
     *
     * ```lte
     * @stack('head')
     * ```
     */
    public static function getStack(string $name): string
    {
        return self::$stacks[$name] ?? '';
    }
}