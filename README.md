# LTE — Luany Template Engine

> AST-based template engine for PHP. Compiler-grade templating with zero regex parsing.

LTE can be used **independently** of the Luany framework — drop it into any PHP project.

## Why LTE?

Most PHP template engines compile templates using regex. LTE uses a real **Parser → AST → Compiler** pipeline:

- Predictable, deterministic output
- Zero regex in the entire pipeline — character-by-character parsing throughout
- Integrated asset lifecycle — styles and scripts as first-class template citizens
- Automatic CSS/JS deduplication — include a component 10× and its assets appear once
- Cache-aware compilation
- First-class tooling support (VS Code extension available)
- **Truly standalone** — zero external dependencies, works in any PHP 8.1+ project

## Installation

```bash
composer require luany/lte
```

## Basic Usage

```php
use Luany\Lte\Engine;

$engine = new Engine(
    viewsPath:  '/path/to/views',
    cachePath:  '/path/to/cache',
    autoReload: true
);

echo $engine->render('pages.dashboard', ['user' => $user]);
```

---

## Template Syntax

### Output

```lte
{{ $variable }}       {{-- escaped output --}}
{!! $variable !!}     {{-- raw output --}}
{{-- comment --}}     {{-- never rendered --}}
```

### Conditionals

```lte
@if($condition)
    ...
@elseif($other)
    ...
@else
    ...
@endif

@unless($condition)
    ...
@endunless
```

### Loops

```lte
@foreach($items as $item)
    <li>{{ $item }}</li>
@endforeach

@forelse($items as $item)
    <li>{{ $item }}</li>
@empty
    <p>No items found.</p>
@endforelse

@for($i = 0; $i < 10; $i++)
    {{ $i }}
@endfor
```

### Layout System

```lte
{{-- child view --}}
@extends('layouts.main')

@section('title', 'My Page')

@section('content')
    <h1>Hello World</h1>
@endsection
```

```lte
{{-- layouts/main.lte --}}
<html>
<head>
    <title>@yield('title', 'Default')</title>
</head>
<body>
    @yield('content')
</body>
</html>
```

### Include

```lte
@include('components.navbar')
@include('components.card', ['title' => 'Hello', 'body' => 'World'])
```

### Security

```lte
@csrf
@method('PUT')
```

### Auth Guards

```lte
@auth
    <a href="/dashboard">Dashboard</a>
@endauth

@guest
    <a href="/auth">Login</a>
@endguest
```

---

## Asset Directives (v0.2)

LTE treats inline CSS and JS as **first-class template citizens**.  
Styles and scripts defined anywhere in views or components are automatically accumulated, deduplicated, and rendered at the correct position in the layout.

### How it works

```
View / Component render:
  @style   → captured by AssetStack
  @script  → captured by AssetStack

Layout render:
  @styles  → outputs all captured <style> blocks  (place in <head>)
  @scripts → outputs all captured <script> blocks (place before </body>)
```

**Deduplication is automatic.** If the same component is `@include`'d 10 times, its `@style` and `@script` blocks appear exactly **once** in the final HTML — deduplicated by content hash.

### @style / @endstyle

```lte
@style
    .card {
        padding: 1rem;
        border-radius: .5rem;
    }
@endstyle
```

### @script / @endscript

```lte
@script(defer)
    document.querySelectorAll('.card').forEach(c => {
        c.addEventListener('click', () => console.log('clicked'));
    });
@endscript
```

Supported option: `defer`

### @styles and @scripts in layout

```lte
<head>
    <link rel="stylesheet" href="/assets/css/app.css">
    @styles      {{-- all accumulated <style> blocks render here --}}
    @stack('head')
</head>
<body>
    @yield('content')
    <script src="/assets/js/app.js"></script>
    @scripts     {{-- all accumulated <script> blocks render here --}}
    @stack('scripts')
</body>
```

---

## Stack Directives (v0.2)

Push content into named stacks from **page views**. Multiple pushes to the same stack accumulate — they never overwrite each other.

> **Important:** `@push` has no deduplication — if used inside a component that is `@include`'d multiple times, the content will appear multiple times. Use `@style` / `@script` inside components instead — they deduplicate automatically.

```lte
{{-- in a page view --}}
@push('head')
    <meta name="description" content="My page">
@endpush

{{-- in the layout --}}
@stack('head')
```

---

## Render Flow

How LTE assembles the final HTML — validated by real test output:

```
Component (card.lte, alert.lte, ...)
  │  @style   ──────────────────────────► AssetStack (styles[])
  │  @script  ──────────────────────────► AssetStack (scripts[])
  │  HTML     ──────────────────────────► rendered inline
  │
  ▼ @include
Page View (pages/dashboard.lte)
  │  @push('head') ────────────────────► SectionStack (stacks['head'])
  │  @style        ────────────────────► AssetStack (styles[])
  │  @section('content') ──────────────► SectionStack (sections['content'])
  │  @script       ────────────────────► AssetStack (scripts[])
  │
  ▼ @extends
Layout (layouts/main.lte)
  │  @styles       ◄───── AssetStack::renderStyles()   → <style> in <head>
  │  @stack('head')◄───── SectionStack::getStack()     → <meta>, links in <head>
  │  @yield('content')◄── SectionStack::get()          → HTML in <main>
  │  @scripts      ◄───── AssetStack::renderScripts()  → <script> before </body>
```

**Deduplication proof** — from the real render test:

| Component | Included | CSS rendered | JS rendered |
|---|---|---|---|
| `card.lte` | 3× | 1× | 1× |
| `alert.lte` | 2× | 1× | — |
| `dashboard.lte` (page) | 1× | 1× | 1× |

---

## Official View Structure (v0.2)

```
@extends('layouts.main')               ← layout base

@section('title', 'Page Title')        ← page title

@push('head')                          ← head metadata (SEO, external links)
    <meta name="description" content="...">
@endpush

@style                                 ← page-level CSS (outside @section)
    .hero { padding: 4rem 0; }
@endstyle

@section('content')                    ← visible HTML content
    <h1>Hello</h1>
    @include('components.card', [...])
@endsection

@script(defer)                         ← page-level JS (outside @section)
    console.log('ready');
@endscript
```

### Page view vs Component

| Context | Pattern |
|---|---|
| **Page view** | `@style` and `@script` outside `@section`, at view level |
| **Component** | `@style` and `@script` collocated with the component HTML |

**Component example** — self-contained, owns its own CSS and JS:

```lte
{{-- components/card.lte --}}
@style
    .lte-card { border-radius: .75rem; padding: 1.5rem; }
@endstyle

<div class="lte-card">
    <h3>{{ $title }}</h3>
    <p>{{ $body }}</p>
</div>

@script(defer)
    document.querySelectorAll('.lte-card').forEach(c => {
        c.addEventListener('click', () => c.style.borderColor = '#6366f1');
    });
@endscript
```

---

## Directive Reference

| Directive | Description |
|---|---|
| `{{ $var }}` | Echo escaped |
| `{!! $var !!}` | Echo raw |
| `{{-- ... --}}` | Template comment |
| `@extends` | Inherit layout |
| `@section` / `@endsection` | Define section |
| `@yield` | Output section |
| `@include` | Include partial |
| `@if` / `@elseif` / `@else` / `@endif` | Conditionals |
| `@unless` / `@endunless` | Negated conditional |
| `@foreach` / `@endforeach` | Loop |
| `@forelse` / `@empty` / `@endforelse` | Loop with empty state |
| `@for` / `@endfor` | For loop |
| `@while` / `@endwhile` | While loop |
| `@php` / `@endphp` | Raw PHP block |
| `@csrf` | CSRF token field |
| `@method` | HTTP method override |
| `@auth` / `@endauth` | Auth guard |
| `@guest` / `@endguest` | Guest guard |
| `@style` / `@endstyle` | Inline CSS block (v0.2) |
| `@script` / `@endscript` | Inline JS block — option: `defer` (v0.2) |
| `@styles` | Render accumulated styles (v0.2) |
| `@scripts` | Render accumulated scripts (v0.2) |
| `@push` / `@endpush` | Push to named stack (v0.2) |
| `@stack` | Render named stack (v0.2) |

---

## Custom Directives

```php
$engine->getCompiler()->directive('datetime', function ($args) {
    return "<?php echo date('d/m/Y H:i', strtotime({$args})); ?>";
});
```

```lte
@datetime($post->created_at)
```

---

## VS Code Extension

First-class `.lte` file support on the [VS Code Marketplace](https://marketplace.visualstudio.com/items?itemName=luany.luany-lte).

---

## Changelog

### v0.2.1
- `@forelse` / `@empty` / `@endforelse` — loop with empty state (was documented but not implemented)
- Scope isolation in `Engine::evaluate()` — `$path` and `$data` no longer leak into included views
- `ob_get_level()` safety in `SectionStack` — prevents buffer corruption on orphan `@endsection`
- 96 unit tests — `ParserTest`, `CompilerTest`, `EngineTest` covering the full pipeline
- `phpunit.xml` configuration added

### v0.2.0
- `AssetStack` — inline CSS/JS with automatic deduplication by content hash
- `@style` / `@endstyle` — inline style blocks (page-level and component-level)
- `@script` / `@endscript` — inline script blocks (supports `defer`)
- `@styles` / `@scripts` — render accumulated assets in layout
- `@push` / `@endpush` / `@stack` — named accumulative stacks
- `parseArgs` — robust argument parser (handles quoted strings with commas)
- **Zero external dependencies** — `{{ $var }}` compiles to `htmlspecialchars()`, `@include` uses `$__engine->render()`, no framework helpers required
- **Standalone confirmed** — tested and validated independently of the Luany framework

### v0.1.0
- Initial release — Parser → AST → Compiler pipeline
- Layout system (`@extends`, `@section`, `@yield`)
- Full directive set (loops, conditionals, auth guards, CSRF)
- Cache-aware compilation with `autoReload`

---

## Requirements

- PHP 8.1+

## License

MIT — see [LICENSE](LICENSE) for details.