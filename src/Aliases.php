<?php

namespace Luany\Lte;

/**
 * Directive aliases for a template syntax ("Luany em Português", layer L1).
 *
 * With the syntax set to "pt", `@se … @senao … @fimse` compiles exactly like
 * `@if … @else … @endif`. The English names keep working, and the two can be
 * mixed in one template. Aliases are matched as written: they are lowercase,
 * like the English directives (`@Se` stays text). Accented forms and other
 * spellings are accepted only when listed as variants (`@seção` = `@secao`).
 *
 * The table lives in resources/aliases.{syntax}.json:
 *   { "en": "section", "pt": "secao", "variants": ["seccao", "seção", "secção"], ... }
 */
final class Aliases
{
    public const SYNTAXES = ['en', 'pt'];

    /** @var array<string, string> written alias => English directive */
    private array $toEnglish = [];

    /** @var array<string, string> English directive => canonical alias */
    private array $canonical = [];

    private function __construct(public readonly string $syntax)
    {
    }

    /** No aliases: plain English LTE. */
    public static function none(): self
    {
        return new self('en');
    }

    /**
     * @throws \InvalidArgumentException unknown syntax
     */
    public static function for(string $syntax): self
    {
        $syntax = strtolower(trim($syntax));
        if (!in_array($syntax, self::SYNTAXES, true)) {
            throw new \InvalidArgumentException(
                "Unknown LTE syntax [{$syntax}]: expected " . implode(' or ', self::SYNTAXES) . '.'
            );
        }
        if ($syntax === 'en') {
            return self::none();
        }

        $aliases = new self($syntax);
        foreach (self::entries($syntax) as $entry) {
            $en = (string) $entry['en'];
            $aliases->canonical[$en] = (string) $entry[$syntax];
            foreach ([$entry[$syntax], ...($entry['variants'] ?? [])] as $written) {
                $aliases->toEnglish[(string) $written] = $en;
            }
        }

        return $aliases;
    }

    public function isEmpty(): bool
    {
        return $this->toEnglish === [];
    }

    /** English directive for a written alias, or null when it is not an alias. */
    public function english(string $written): ?string
    {
        return $this->toEnglish[$written] ?? null;
    }

    /** Canonical alias for an English directive (`endif` → `fimse`), or the English name. */
    public function canonical(string $english): string
    {
        return $this->canonical[$english] ?? $english;
    }

    /** @return array<string, string> written alias => English directive */
    public function all(): array
    {
        return $this->toEnglish;
    }

    /**
     * Raw table entries for a syntax (used by tooling: docs, editor grammar).
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(string $syntax): array
    {
        static $cache = [];
        if (isset($cache[$syntax])) {
            return $cache[$syntax];
        }

        $file = dirname(__DIR__) . "/resources/aliases.{$syntax}.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || !is_array($data['entries'] ?? null)) {
            throw new \RuntimeException("LTE alias table is missing or invalid: {$file}");
        }

        return $cache[$syntax] = array_values(array_filter(
            $data['entries'],
            static fn ($e): bool => is_array($e) && ($e['kind'] ?? null) === 'directive'
        ));
    }
}
