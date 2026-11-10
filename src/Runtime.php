<?php

namespace Luany\Lte;

/**
 * Runtime
 *
 * Small set of hooks used by compiled templates, so applications can plug
 * their own environment and authentication into built-in directives.
 *
 *   Runtime::setDebug(false);                               // @dump/@dd become no-ops
 *   Runtime::setAuthResolver(fn () => auth()->check());     // drives @auth / @guest
 *
 * Defaults keep backward compatibility: debug output enabled, and
 * "authenticated" means $_SESSION['user_id'] is set.
 */
final class Runtime
{
    private static bool $debug = true;

    /** @var (callable(): bool)|null */
    private static $authResolver = null;

    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }

    public static function debug(): bool
    {
        return self::$debug;
    }

    /**
     * @param (callable(): bool)|null $resolver
     */
    public static function setAuthResolver(?callable $resolver): void
    {
        self::$authResolver = $resolver;
    }

    public static function check(): bool
    {
        if (self::$authResolver !== null) {
            return (bool) (self::$authResolver)();
        }

        return isset($_SESSION['user_id']);
    }

    /**
     * Restore defaults (useful in tests and long-running workers).
     */
    public static function reset(): void
    {
        self::$debug        = true;
        self::$authResolver = null;
    }
}
