# luany/lte

**AST-compiled template engine for PHP. Zero regex. Compiler-grade templating.**

**Version**: v1.0.0 &nbsp;|&nbsp; **PHP**: >= 8.2 &nbsp;|&nbsp; **License**: MIT
**Author**: António Ambrósio Ngola &nbsp;|&nbsp; **Org**: [luany-ecosystem](https://github.com/luany-ecosystem)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Installation](#2-installation)
3. [Basic Usage](#3-basic-usage)
4. [Output](#4-output)
5. [Conditionals](#5-conditionals)
6. [Loops](#6-loops)
7. [Layout System](#7-layout-system)
8. [Includes](#8-includes)
9. [Components & Slots](#9-components--slots)
10. [Asset Directives](#10-asset-directives)
11. [Stack Directives](#11-stack-directives)
12. [PHP Blocks](#12-php-blocks)
13. [Security Directives](#13-security-directives)
14. [Auth Guards](#14-auth-guards)
15. [Debug Helpers](#15-debug-helpers)
16. [JSON Output](#16-json-output)
17. [Conditional Classes](#17-conditional-classes)
18. [Custom Directives](#18-custom-directives)
19. [Error Reporting](#19-error-reporting)
20. [Changelog](#20-changelog)

---

## 1. Overview

LTE (Luany Template Engine) compiles `.lte` templates to PHP via a hand-written AST parser. There is **no regex** in the parsing pipeline — every token is identified character-by-character.

**Render pipeline:**

```
.lte source -> Parser (AST) -> Compiler (PHP string) -> cache file -> evaluate -> HTML
```

- `Parser` — tokenises source into AST nodes: `text`, `echo`, `raw_echo`, `php_block`, `directive`
- `Compiler` — translates AST nodes to PHP code strings
- `Engine` — orchestrates compile, cache, evaluate, resolves layouts and components

---

## 2. Installation

```bash
composer require luany/lte
```

---

## 3. Basic Usage

```php
use Luany\Lte\Engine;

$engine = new Engine(
    viewsPath:  '/path/to/views',
    cachePath:  '/path/to/storage/cache/views',
    autoReload: true,  // true = recompile every request (dev); false = use cache (production)
);

$html = $engine->render('pages.home', ['title' => 'Welcome', 'user' => $user]);
```

View files use the `.lte` extension and are resolved with dot notation:
`'pages.home'` resolves to `views/pages/home.lte`

---

## 4. Output

```html
{{-- HTML-escaped output --}}
<p>{{ $name }}</p>
<p>{{ $user->email }}</p>
<p>{{ strtoupper($text) }}</p>

{{-- Raw/unescaped output --}}
<p>{!! $trustedHtml !!}</p>

{{-- Comments are removed from compiled output --}} {{-- This will not appear in
the HTML --}}
```

---

## 5. Conditionals

```html
@if($user)
<p>Hello, {{ $user->name }}</p>
@elseif($guest)
<p>Welcome, guest</p>
@else
<p>Please login</p>
@endif @unless($banned)
<p>Welcome!</p>
@endunless @isset($title)
<h1>{{ $title }}</h1>
@endisset @ifempty($items)
<p>No items found.</p>
@endifempty
```

---

## 6. Loops

```html
@foreach($users as $user)
<li>{{ $user->name }}</li>
@endforeach @forelse($posts as $post)
<li>{{ $post->title }}</li>
@empty
<p>No posts yet.</p>
@endforelse {{-- Nested @forelse uses unique internal variables per level --}}
@forelse($categories as $category) @forelse($category->posts as $post)
<li>{{ $post->title }}</li>
@empty
<p>No posts in {{ $category->name }}</p>
@endforelse @empty
<p>No categories.</p>
@endforelse @for($i = 0; $i < 10; $i++)
<span>{{ $i }}</span>
@endfor @while($condition) ... @endwhile
```

---

## 7. Layout System

**Layout** (`views/layouts/main.lte`):

```html
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>@yield('title', 'My App')</title>
    @stack('head') @styles
  </head>
  <body>
    @yield('content') @scripts @stack('scripts')
  </body>
</html>
```

**Page** (`views/pages/home.lte`):

```html
@extends('layouts.main') @section('title', 'Home Page') @push('head')
<meta name="description" content="Welcome" />
@endpush @section('content')
<h1>Welcome</h1>
<p>{{ $message }}</p>
@endsection
```

| Directive                          | Description                    |
| ---------------------------------- | ------------------------------ |
| `@extends('layout')`               | Declare the parent layout      |
| `@section('name') ... @endsection` | Define a block section         |
| `@section('name', 'value')`        | Define an inline section       |
| `@yield('name')`                   | Output a section               |
| `@yield('name', 'default')`        | Output a section with fallback |
| `@stop`                            | Alias for `@endsection`        |

---

## 8. Includes

```html
{{-- Parent variables are passed automatically --}}
@include('components.navbar') {{-- With extra data merged in --}}
@include('components.card', ['title' => 'Hello', 'body' => $content])
```

---

## 9. Components & Slots

Components are reusable view fragments with named slots. The component view receives all slots as PHP variables.

**Component file** (`views/components/alert.lte`):

```html
<div class="alert alert-{{ $type }}">
  @isset($title)
  <strong>{!! $title !!}</strong>
  @endisset {!! $slot !!}
</div>
```

**Usage in a parent view:**

```html
@component('components.alert', ['type' => 'error']) @slot('title') Something
went wrong @endslot Please check your input and try again. @endcomponent
```

| Variable | Source                                                                            |
| -------- | --------------------------------------------------------------------------------- |
| `$slot`  | Default slot — content inside `@component...@endcomponent` not in a named `@slot` |
| `$title` | Named slot — content of `@slot('title')...@endslot`                               |
| `$type`  | Explicit data — second argument to `@component`                                   |

**Important:** Slot content is already-rendered HTML. Always use `{!! $slot !!}` and `{!! $namedSlot !!}` inside component views to avoid double-escaping.

Nested components are fully supported.

---

## 10. Asset Directives

Inline `<style>` and `<script>` blocks declared anywhere in the view tree are collected and rendered at a designated position in the layout. Duplicate blocks (same content) are automatically deduplicated.

```html
@style .card { padding: 1rem; border-radius: 4px; } @endstyle @script(defer)
document.querySelector('.card').addEventListener('click', fn); @endscript
```

In your layout:

```html
<head>
  @styles
</head>
<body>
  ... @scripts
</body>
```

`@script(defer)` adds the `defer` attribute to the rendered `<script>` tag.

---

## 11. Stack Directives

Named stacks accumulate content from multiple `@push` calls. Unlike `@section`, pushes never replace previous content — they always append.

```html
{{-- In any view or component --}} @push('head')
<link rel="stylesheet" href="/css/page.css" />
@endpush {{-- In layout --}} @stack('head')
```

---

## 12. PHP Blocks

```html
{{-- Inline (self-closing) --}} @php($count = count($items)) {{-- Block --}}
@php $grouped = []; foreach ($items as $item) { $grouped[$item->category][] =
$item; } @endphp
```

Content inside `@php ... @endphp` is never parsed for LTE directives or echo tags.

---

## 13. Security Directives

```html
<form method="POST" action="/users">@csrf @method('PUT') ...</form>
```

- `@csrf` — generates `<input type="hidden" name="csrf_token" value="...">` using `$_SESSION['csrf_token']` (auto-generated with `random_bytes(32)` if absent)
- `@method('PUT')` — generates `<input type="hidden" name="_method" value="PUT">` for HTML form method override

---

## 14. Auth Guards

```html
@auth
<p>Welcome, {{ $_SESSION['user_name'] }}</p>
@endauth @guest
<a href="/login">Login</a>
@endguest
```

Guards check `isset($_SESSION['user_id'])`. For custom auth logic, use `@if` or register a custom directive.

---

## 15. Debug Helpers

```html
@dump($variable) {{-- var_dump() --}} @dd($variable) {{-- var_dump() + die --}}
```

---

## 16. JSON Output

Safe JSON output for use in JavaScript contexts. Always applies XSS-safe encoding flags.

```html
<script>
  const config = @json($config);
  const users  = @json($users);
</script>

{{-- With additional flags (OR-ed with safe defaults) --}}
<script>
  const data = @json($data, JSON_PRETTY_PRINT);
</script>
```

Always applied: `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE`.
`<` and `>` are encoded as `\u003C` / `\u003E`, preventing XSS injection.

---

## 17. Conditional Classes

Builds a `class="..."` HTML attribute from a conditional array. Integer-keyed entries are always included; string-keyed entries are included only when their value is truthy.

```html
<button @class(['btn', 'btn-primary' => $isPrimary, 'btn-disabled' => !$active])>
    Click me
</button>
```

Given `$isPrimary = true`, `$active = true`:

```html
<button class="btn btn-primary"></button>
```

Given `$isPrimary = false`, `$active = false`:

```html
<button class="btn btn-disabled"></button>
```

Using `ClassHelper` directly in PHP:

```php
use Luany\Lte\ClassHelper;

// Compile to class string only
$classes = ClassHelper::compile(['flex', 'items-center', 'text-red-500' => $hasError]);
// -> 'flex items-center text-red-500'  (when $hasError = true)

// Compile to full class="..." attribute
$attr = ClassHelper::attr(['btn', 'active' => $isActive]);
// -> 'class="btn active"'
```

---

## 18. Custom Directives

```php
// Register before rendering (e.g. in a ServiceProvider boot method)
$engine->getCompiler()->directive('datetime', function (?string $args) {
    $format = $args ?: "'Y-m-d H:i'";
    return "<?php echo date({$format}); ?>";
});

$engine->getCompiler()->directive('money', function (?string $args) {
    return "<?php echo number_format((float)({$args}), 2, '.', ','); ?>";
});
```

Usage:

```html
<p>Posted: @datetime('d/m/Y H:i')</p>
<p>Price: @money($product->price)</p>
```

The handler receives the raw argument string (content inside the parentheses) and must return a valid PHP string.

---

## 19. Error Reporting

**Compilation errors** include the view name and source line:

```
LTE compilation error in view [pages.home]: Unclosed echo tag on line 12.
```

**Runtime errors** include the `.lte` source line number:

```
LTE render error in [pages.home.lte line 23]: Call to undefined method User::missing()
```

The Engine embeds `@lte:{N}` markers in compiled cache files and maps PHP runtime exceptions back to their original `.lte` source line.

---

## 20. Changelog

### next/v1 — Phase 5: Completion

**New — `src/ComponentStack.php`**

- Stack-based context manager for `@component` / `@slot` / `@endslot` / `@endcomponent`
- Supports nested components via frame stack
- `isActive(): bool` — guards Engine reset during component rendering

**New — `src/ClassHelper.php`**

- `compile(array $classes): string` — conditional class array to space-separated string
- `attr(array $classes): string` — returns full `class="..."` attribute string

**Modified — `src/Parser.php`**

- Every AST node now carries `'line' => N` (1-based source line)
- Error messages include source line number
- Line counter maintained accurately across all token types

**Modified — `src/Compiler.php`**

- `@json($data)` / `@json($data, FLAGS)` — XSS-safe JSON output
- `@class([...])` — conditional CSS class builder via `ClassHelper::attr()`
- `@component` / `@slot` / `@endslot` / `@endcomponent` — component system
- Every compiled node prefixed with `<?php /* @lte:{N} */ ?>` line marker

**Modified — `src/Engine.php`**

- `ComponentStack::reset()` called at root render start, guarded by `isActive()`
- Compilation errors wrapped with view name context
- `evaluate()` uses `ob_get_level()` guard for clean buffer restoration
- `resolveLteLine()` maps PHP exception line back to `.lte` source line

**Tests added:** `Phase5CompilerTest` (30), `Phase5EngineTest` (21), `ComponentStackTest` (11)

**Total: 170 tests, 252 assertions — all green, zero warnings.**

---

### v0.2.x baseline

AST parser, Compiler with built-in directives, Engine with layout/cache/include system, SectionStack, AssetStack.

Built-in directives: `@if`, `@elseif`, `@else`, `@endif`, `@unless`, `@endunless`, `@foreach`, `@endforeach`, `@for`, `@endfor`, `@while`, `@endwhile`, `@forelse`, `@empty`, `@endforelse`, `@php`, `@endphp`, `@csrf`, `@method`, `@auth`, `@endauth`, `@guest`, `@endguest`, `@extends`, `@section`, `@endsection`, `@stop`, `@yield`, `@include`, `@style`, `@endstyle`, `@script`, `@endscript`, `@styles`, `@scripts`, `@push`, `@endpush`, `@stack`, `@dump`, `@dd`, `@isset`, `@endisset`, `@ifempty`, `@endifempty`.
