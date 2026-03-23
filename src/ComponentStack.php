<?php

namespace Luany\Lte;

/*
 * ComponentStack
 *
 * Manages the rendering context for @component/@slot/@endslot/@endcomponent directives.
 *
 * Components are a higher-level abstraction than @include:
 *   - They have a default slot (content between @component and @endcomponent
 *     that is not inside a @slot block)
 *   - They support named slots (@slot('title') ... @endslot)
 *   - The component view receives all slots as PHP variables:
 *       $slot   → default slot content (string)
 *       $title  → named slot 'title' content (string)
 *       + any explicit $data passed to @component
 *
 * Usage in a parent view:
 *
 * ```lte
 * @component('components.alert', ['type' => 'error'])
 *     @slot('title')
 *         Error!
 *     @endslot
 *     Something went wrong. Please try again.
 * @endcomponent
 * ```
 *
 * Component file (components/alert.lte):
 *
 *   <div class="alert alert-{{ $type }}">
 *       <strong>{{ $title }}</strong>
 *       {{ $slot }}
 *   </div>
 *
 * Implementation — stack-based (supports nested components):
 *
 *   startComponent() → push context, ob_start() to capture default slot
 *   startSlot()      → ob_get_clean() (save current default content), ob_start() for named slot
 *   endSlot()        → ob_get_clean() → store in context['slots'][name], ob_start() resume default
 *   endComponent()   → ob_get_clean() → append to default slot, render component, pop context
 */
class ComponentStack
{
    /**
     * Stack of active component contexts.
     * Each frame:
     *   engine   → Engine instance for rendering
     *   view     → component view name (dot notation)
     *   data     → explicit data passed via @component second arg
     *   slots    → ['slotName' => 'content', ...]
     *   default  → accumulated default slot content so far
     *   active   → name of currently-open named slot (or null = capturing default)
     *
     * @var array<int, array{engine: Engine, view: string, data: array, slots: array, default: string, active: string|null}>
     */
    /** @var array<int, array<string, mixed>> */
    private static array $stack = [];

    /**
     * Reset all state.
     * Called by Engine::render() at root level alongside SectionStack::reset().
     */
    public static function reset(): void
    {
        // If there are open output buffers from abandoned components, clean them
        foreach (self::$stack as $_) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        self::$stack = [];
    }

    /**
     * Whether there are any active component frames on the stack.
     * Used by Engine::render() to avoid resetting mid-resolution state.
     */
    public static function isActive(): bool
    {
        return !empty(self::$stack);
    }

    /**
     * Begin a component context.
     *
     * Called by compiled @component directive:
     *   <?php ComponentStack::startComponent($__engine, 'components.alert', ['type' => 'error']); ?>
     *
     * @param Engine $engine   The current rendering engine instance
     * @param string $view     Dot-notation view name of the component
     * @param array<string, mixed>  $data  Explicit data to pass to the component view
     */
    public static function startComponent(Engine $engine, string $view, array $data = []): void
    {
        self::$stack[] = [
            'engine'  => $engine,
            'view'    => $view,
            'data'    => $data,
            'slots'   => [],
            'default' => '',
            'active'  => null,
        ];

        ob_start(); // Begin capturing default slot content
    }

    /**
     * Begin capturing a named slot.
     *
     * Saves any default-slot content captured so far, then starts a fresh
     * output buffer for the named slot.
     *
     * @param string $name  Slot name (e.g. 'title', 'footer')
     * @throws \RuntimeException If called outside a @component block
     */
    public static function startSlot(string $name): void
    {
        $frame = &self::currentFrame();

        // Save content captured for default slot so far
        $frame['default'] .= ob_get_clean();
        $frame['active']   = $name;

        ob_start(); // Begin capturing this named slot
    }

    /**
     * End a named slot capture and resume capturing the default slot.
     *
     * @throws \RuntimeException If called outside a @component block
     */
    public static function endSlot(): void
    {
        $frame = &self::currentFrame();

        if ($frame['active'] === null) {
            // Orphan @endslot — no named slot is open, nothing to close.
            // Do NOT call ob_end_clean() here: the active ob belongs to the
            // surrounding @component default-slot capture, not to this slot.
            return;
        }

        // Store named slot content
        $frame['slots'][$frame['active']] = ob_get_clean();
        $frame['active']                  = null;

        ob_start(); // Resume capturing default slot
    }

    /**
     * Finalise the component and return the rendered HTML.
     *
     * Captures remaining default slot content, merges all slots into the
     * component data, renders the component view, and pops the context frame.
     *
     * @throws \RuntimeException If called outside a @component block
     */
    public static function endComponent(): string
    {
        $frame = array_pop(self::$stack);

        if ($frame === null) {
            throw new \RuntimeException(
                '@endcomponent called without a matching @component.'
            );
        }

        // Capture any remaining default slot content
        $frame['default'] .= ob_get_clean();

        // Build the data array passed to the component view:
        //   1. Explicit data from @component second arg  (lowest priority)
        //   2. Named slots as variables ($title, $footer, ...)
        //   3. $slot → default slot content             (always present)
        $viewData = array_merge(
            $frame['data'],
            $frame['slots'],
            ['slot' => trim($frame['default'])],
        );

        return $frame['engine']->render($frame['view'], $viewData);
    }

    /**
     * Get a reference to the current (top) frame, or throw if stack is empty.
     *
     * @return array Reference to the current frame
     * @throws \RuntimeException If called outside a @component block
     */
    /** @return array<string, mixed> */
    private static function &currentFrame(): array
    {
        $last = count(self::$stack) - 1;
        if ($last < 0) {
            throw new \RuntimeException(
                '@slot / @endslot called outside a @component block.'
            );
        }
        return self::$stack[$last];
    }
}