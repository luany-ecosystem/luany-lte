# Changelog — luany/lte

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — 1.2.0

### Added
- **Real syntax tree** — `Ast\TreeBuilder` nests block directives into `Ast\BlockNode` (branches: `@if`/`@elseif`/`@else`, `@forelse`/`@empty`, …) under an `Ast\Template`; `Template::walk()`; `Compiler::buildTree()` / `compileTree()`; `Engine::parseView()` for tooling. (#13)
- **Compile-time validation** — unclosed, stray and crossed blocks; misplaced or duplicated `@else` / `@elseif` / `@empty`; directives missing required arguments. (#13)
- **PHP linting at compile time** — syntax errors in `{{ }}`, `{!! !!}`, `@php` and directive arguments are reported with the template line and column instead of failing when the page is rendered. (#13)
- Parser tokens carry a `column`; `TemplateSyntaxException` exposes `$view`, `$templateLine`, `$templateColumn`.
- Hint for closers glued to text (`Title@endslotBody` → `@endslot Body`).

### Changed
- Structural errors that previously slipped through to runtime (e.g. an `@endslot` glued to the following word, leaving the slot open) are now compile errors with a precise message.
- Error message wording for structure errors (now include column); `Compiler::validate()` delegates to the tree builder.
- `Engine::VERSION` 1.2.0 — compiled caches are rebuilt.

Validated against 177 real templates (skeleton, docs site, generated CRUD views, an application) with no false positives.

---

## [1.1.0] — 2026-11-10

### Security
- `@dump` / `@dd` are no-ops when `Runtime::setDebug(false)` (luany/framework wires this to `APP_DEBUG`). `@auth` / `@guest` go through `Runtime::check()`, configurable with `Runtime::setAuthResolver()` (default: `$_SESSION['user_id']`). (#14)
- `@csrf` emits the `_token` field and uses `csrf_token()` when available (session key `_csrf_token`), matching luany/framework.
- Inline `@section('name', $value)` values and `@yield('name', $default)` defaults are now HTML-escaped (block sections are unchanged). `@class` escapes its class list. All escaping uses `ENT_QUOTES | ENT_SUBSTITUTE`, so invalid UTF-8 no longer renders as an empty string. (#11)
- Compiled cache: writes are atomic (temp file + `rename()`); the default cache directory is now a per-user `sys_get_temp_dir()/lte_cache_<uid>` created `0700` instead of the shared `/tmp/lte_cache`, and is refused when it is a symlink or owned by another user. (#12)
- View/section names are compiled as PHP literals, so a quote in a name can no longer break out of the generated code. (#6)

### Added
- `Htmlable` interface and `HtmlString` for intentionally raw values; `Escaper::escape()`.
- `@@` renders a literal `@`; `@{{ ... }}` renders a literal `{{ ... }}`. (#4)
- `@empty($x) ... @endempty` outside `@forelse`. (#9)
- `TemplateSyntaxException` (extends `RuntimeException`) for malformed templates, with source line numbers.
- `Engine::VERSION`, `Engine::getCachePath()`, `Compiler::isDirective()`, `Compiler::validate()`, `Compiler::signature()`, optional directive predicate for `Parser`.
- Multi-level layouts: a layout may `@extends` another layout. (#7)

### Fixed
- Parser no longer hangs on `{!` not followed by `!`. (#1)
- Newlines after echoes, directives and comments are preserved exactly. (#2)
- Whitespace between a directive and `(` is accepted: `@if ($x)`. (#3)
- `@` in plain text (emails, CSS `@media`) is no longer parsed as a directive. (#4)
- `{{ }}`, `{!! !!}` and directive arguments are scanned string- and bracket-aware: `{{ '}}' }}`, `@json(['a' => 1, 'b' => 2])`, `@include('x', ['a' => 1, 'b' => 2])`. (#5)
- Dynamic names work: `@include($view)`, `@extends($layout)`, `@section($name)`, `@yield`, `@push`, `@stack`, `@component`, `@slot`. (#6)
- Nested sections; stray or mismatched `@endsection`/`@endpush` throw instead of closing foreign output buffers. (#8)
- `@forelse` shows `@empty` for empty `Traversable`s/generators; `@endforelse` without `@empty` is valid. (#9)
- Component/section/output-buffer state is unwound when a render throws. (#10)
- Cache key includes the engine version and registered custom directives; same-second edits are detected. (#12)
- `@extends` inside an included partial no longer hijacks the page layout. (#7)

### Changed (behaviour)
- Only built-in and registered directives are parsed; any other `@word` is output as text (previously echoed back almost verbatim). `@name` right after a letter/digit/underscore is text, except closers (`@end*`, `@else`, `@elseif`, `@stop`) and a directive directly following another directive.
- Templates whose output relied on newlines being swallowed after directives will now contain those newlines.
- Inline section values / yield defaults containing HTML are escaped; wrap trusted markup in `HtmlString`.
- When a child view and a layout define the same section, the child's wins (previously the layout's later definition overwrote it).
- Bare `@empty` outside `@forelse`, `@forelse` without `as`, and unbalanced `@section`/`@push` blocks now throw `TemplateSyntaxException`.
- Existing compiled caches are ignored (new key format); the old `/tmp/lte_cache` directory can be deleted.

---

## [1.0.0] — 2026-03-23

### Added
- `@json` directive — renders any PHP value as formatted JSON inside `<pre>` tags. Useful for debugging template variables.
- `@class` directive — conditionally builds a CSS class string: `@class(['active' => $isActive, 'disabled' => !$enabled])`.
- `@component` / `@slot` / `@endcomponent` — named slot component system. Components receive `$slot` (default content) and named slots via `@slot('name')...@endslot`.
- Line tracking — compiler preserves `#line N` markers in compiled PHP output. Stack traces in LTE templates now point to the correct source line in the `.lte` file.

### Fixed
- `@styles` placement documentation corrected. `@styles` must appear **after all `@include` calls** that contain `@style` blocks. If placed in `<head>` before layout includes (navbar, footer, flash), styles from those components are not yet buffered and are lost. The skeleton correctly places `@styles` in `<body>` after all includes.

---

## [0.1.0] — Phase 1 (initial implementation)

### Added
- `Engine` — AST-based template compiler and renderer. `render(string $name, array $data): string`. Auto-reload in development, cached compilation in production.
- `Compiler` — single-pass AST compiler. Zero regex in the render path. Emits optimised PHP.
- `Parser` — tokeniser that produces an AST from `.lte` source.

**Directives:**
- `{{ $var }}` — escaped output (`htmlspecialchars`)
- `{!! $var !!}` — raw unescaped output
- `@extends('layout')` — extend a layout file
- `@section('name') ... @endsection` — define a content section
- `@yield('name', 'default')` — render a section
- `@include('view.name')` — include a sub-view (passes all current variables)
- `@if / @elseif / @else / @endif`
- `@foreach / @endforeach`
- `@forelse / @empty / @endforelse` — foreach with empty-state fallback
- `@for / @endfor`
- `@while / @endwhile`
- `@php ... @endphp` — raw PHP block
- `@csrf` — renders `<input type="hidden" name="csrf_token" value="...">` using `csrf_token()`
- `@method('PUT')` — renders `<input type="hidden" name="_method" value="PUT">`
- `@push('stack') ... @endpush` — push content onto a named stack
- `@stack('stack')` — render all pushed content for a stack
- `@style ... @endstyle` — collect inline `<style>` blocks into `AssetStack`
- `@script ... @endscript` — collect inline `<script>` blocks into `AssetStack`; supports `@script(defer)` and `@script(async)`
- `@styles` — render all collected `<style>` blocks (deduplicated)
- `@scripts` — render all collected `<script>` blocks (deduplicated)
- `@dump($var)` — development helper, renders `<pre>var_export()</pre>`
- `@dd($var)` — dump and die
- `@isset($var) ... @endisset`
- `@empty($var) ... @endempty`
- `@switch / @case / @default / @endswitch`
- `@break` — break inside `@switch` or loops
- `@continue` — continue inside loops
- `@auth ... @endauth` — renders if `auth_user()` returns non-null
- `@guest ... @endguest` — renders if `auth_user()` returns null
- Comments: `{{-- ... --}}` — stripped from compiled output

**Asset deduplication:** `@styles` and `@scripts` automatically deduplicate blocks with identical content across multiple includes.

**Layout system:** Child views evaluated before layout; sections and stacks populated before `@yield` and `@stack` are resolved.