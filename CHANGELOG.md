# Changelog — luany/lte

All notable changes to this package are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased] — next/v1

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