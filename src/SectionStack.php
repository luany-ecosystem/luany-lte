<?php

namespace Luany\Lte;

/**
 * LTE SectionStack
 *
 * Manages the global state of @section/@yield/@extends during template rendering.
 * Works as a static registry so that child views can declare sections
 * and parent layouts can consume them — even across separate evaluate() calls.
 *
 * Lifecycle:
 *   1. Engine::render() calls SectionStack::reset()
 *   2. Child view is evaluated:
 *        @extends → SectionStack::setLayout('layouts.main')
 *        @section('title', 'Home') → SectionStack::set('title', 'Home')
 *        @section('content') → SectionStack::start('content')  [ob_start]
 *        @endsection          → SectionStack::end()             [ob_get_clean → store]
 *   3. Engine::render() checks SectionStack::getLayout()
 *   4. Layout is evaluated:
 *        @yield('title')   → SectionStack::get('title')
 *        @yield('content') → SectionStack::get('content')
 */
class SectionStack
{
    private static array  $sections       = [];
    private static ?string $currentSection = null;
    private static ?string $layout         = null;

    /**
     * Reset all state — called before each top-level render
     */
    public static function reset(): void
    {
        self::$sections       = [];
        self::$currentSection = null;
        self::$layout         = null;
    }

    // ─── Layout ────────────────────────────────────────────────────────────────

    public static function setLayout(string $layout): void
    {
        self::$layout = $layout;
    }

    public static function getLayout(): ?string
    {
        return self::$layout;
    }

    // ─── Sections ──────────────────────────────────────────────────────────────

    /**
     * Start capturing a block section (@section without inline value)
     */
    public static function start(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    /**
     * End current block section and store its content
     */
    public static function end(): void
    {
        if (self::$currentSection === null) {
            // Safety: discard orphan buffer
            ob_end_clean();
            return;
        }
        self::$sections[self::$currentSection] = ob_get_clean();
        self::$currentSection = null;
    }

    /**
     * Set an inline section value (@section('name', 'value'))
     */
    public static function set(string $name, string $value): void
    {
        self::$sections[$name] = $value;
    }

    /**
     * Get a section's content for @yield
     */
    public static function get(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    /**
     * Check if a section has been defined
     */
    public static function has(string $name): bool
    {
        return isset(self::$sections[$name]);
    }
}