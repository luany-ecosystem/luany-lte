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
    private static array   $sections = [];
    private static ?string $layout   = null;

    // ── Push/Stack (v0.2) ──────────────────────────────────────────────────────
    /** @var array<string, string> */
    private static array $stacks = [];

    /**
     * Open @section / @push blocks, innermost last. Each entry remembers the
     * output-buffer level it opened, so a block is only ever closed by the
     * buffer it owns.
     *
     * @var list<array{type: string, name: string, level: int}>
     */
    private static array $open = [];

    // ── Reset ─────────────────────────────────────────────────────────────────

    /**
     * Reset all state — called before each top-level render.
     */
    public static function reset(): void
    {
        self::$sections = [];
        self::$layout   = null;
        self::$stacks   = [];
        self::$open     = [];
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
     * Begin capturing a section. Sections may be nested.
     */
    public static function start(string $name): void
    {
        self::open('section', $name);
    }

    /**
     * End the innermost open section.
     *
     * @throws TemplateSyntaxException On a stray @endsection/@stop, or when the
     *         innermost open block is a @push.
     */
    public static function end(): void
    {
        self::$sections[self::close('section', '@endsection')] = (string) ob_get_clean();
    }

    public static function set(string $name, string $value): void
    {
        self::$sections[$name] = $value;
    }

    public static function get(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    public static function has(string $name): bool
    {
        return isset(self::$sections[$name]);
    }

    // ── Push / Stack ──────────────────────────────────────────────────────────

    /**
     * Begin pushing content onto a named stack.
     */
    public static function startPush(string $name): void
    {
        self::open('push', $name);
    }

    /**
     * End the innermost open push and append its content to the stack.
     *
     * @throws TemplateSyntaxException On a stray @endpush, or when the
     *         innermost open block is a @section.
     */
    public static function endPush(): void
    {
        $name    = self::close('push', '@endpush');
        $content = (string) ob_get_clean();

        self::$stacks[$name] = (self::$stacks[$name] ?? '') . $content;
    }

    /**
     * Render all content pushed onto a named stack.
     */
    public static function getStack(string $name): string
    {
        return self::$stacks[$name] ?? '';
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function open(string $type, string $name): void
    {
        ob_start();
        self::$open[] = ['type' => $type, 'name' => $name, 'level' => ob_get_level()];
    }

    /**
     * Validate and pop the innermost open block. The caller then owns the
     * current output buffer and must close it.
     *
     * @return string  The block's name
     */
    private static function close(string $type, string $directive): string
    {
        $top = end(self::$open);

        if ($top === false) {
            throw new TemplateSyntaxException(
                "Cannot use {$directive} without a matching @{$type}."
            );
        }

        if ($top['type'] !== $type) {
            throw new TemplateSyntaxException(
                "Cannot use {$directive} while @{$top['type']}('{$top['name']}') is still open."
            );
        }

        if (ob_get_level() !== $top['level']) {
            throw new TemplateSyntaxException(
                "Cannot use {$directive} for @{$type}('{$top['name']}'): an output buffer opened "
                . 'inside it was not closed (unbalanced @component/@style/@script?).'
            );
        }

        array_pop(self::$open);

        return $top['name'];
    }
}
