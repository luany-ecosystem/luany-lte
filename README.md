# LTE — Luany Template Engine

> AST-based template engine for PHP. Compiler-grade templating with zero regex parsing.

LTE can be used **independently** of the Luany framework — drop it into any PHP project.

## Why LTE?

Most PHP template engines (Blade, Twig) compile templates using regex. LTE uses a real **Parser → AST → Compiler** pipeline, which means:

- Predictable, deterministic output
- No regex edge cases
- First-class tooling support (VS Code extension available)
- Cache-aware compilation

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

echo $engine->render('pages.welcome', ['name' => 'World']);
```

## Template Syntax

```lte
@extends('layouts.main')

@section('title', 'Welcome')

@section('content')
    <h1>Hello, {{ $name }}!</h1>

    @foreach($items as $item)
        <li>{{ $item }}</li>
    @endforeach
@endsection
```

## Directives

| Directive | Description |
|---|---|
| `{{ $var }}` | Echo escaped |
| `{!! $var !!}` | Echo raw |
| `@extends` | Inherit layout |
| `@section` / `@endsection` | Define section |
| `@yield` | Output section |
| `@include` | Include partial |
| `@if` / `@elseif` / `@else` / `@endif` | Conditionals |
| `@foreach` / `@endforeach` | Loop |
| `@forelse` / `@empty` / `@endforelse` | Loop with empty state |
| `@for` / `@endfor` | For loop |
| `@while` / `@endwhile` | While loop |
| `@php` / `@endphp` | Raw PHP block |
| `@csrf` | CSRF token field |
| `@method` | HTTP method override |
| `@auth` / `@endauth` | Auth guard |
| `@guest` / `@endguest` | Guest guard |
| `{{-- comment --}}` | Template comment |

## VS Code Extension

First-class `.lte` file support available on the [VS Code Marketplace](https://marketplace.visualstudio.com/items?itemName=NgolaProgramador.luany-lte-vscode).

## Requirements

- PHP 8.1+

## License

MIT — see [LICENSE](LICENSE) for details.
