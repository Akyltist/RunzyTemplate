# 🚀 RunzyTemplate

[Читать на русском](README_RU.md)

RunzyTemplate is an extremely lightweight, fast, Standalone, and extensible PHP 7.1+ templating engine inspired by Laravel Blade syntax.

## ✨ Features

- **Zero Dependencies**  
  Built with pure PHP. Standalone. No heavy vendor folders or third-party packages required. Lightweight and easy to integrate into any project.

- **Blade-Inspired Syntax**  
  Write clean and expressive code using familiar directives like `@if`, `@foreach`, `@auth`, and `@forelse`. No more messy `<?php ?>` tags in your HTML.

- **Powerful Template Inheritance**  
  Organize your UI with a robust layout system. Use `@extends` to define your base structure, `@block` to fill it, and `@yield` to display content.

- **Dynamic Asset Management**  
  Manage your CSS and JS efficiently. Use `@push` to send scripts from nested views to a global `@stack` defined in your footer.

- **High Performance & Smart Caching**  
  Compiled templates are saved as plain PHP files. The engine automatically detects source file changes and recompiles only when necessary, ensuring near-native execution speed.

- **Infinite Extensibility**  
  Add your own logic with the `directive()` method. Create custom tags like `@markdown`, `@currency`, or `@datetime` in a single line of code.

- **Security by Default**  
  Protect your application from XSS attacks with automatic HTML escaping using `{{ }}`. Need raw output? Just use `{!! !!}`. Plus, built-in `@csrf` support for secure forms.

- **Flexible Authentication**  
  Easily toggle content visibility for authorized users or guests using `@auth` and `@guest` with support for custom logic via callbacks.


## Initialization

### Via Composer (Recommended)

Once the package is published on Packagist, you can install it via:

```bash
composer require akyltist/runzy-template
```

### Manual Installation (Development)

If you want to use the latest version directly from GitHub, add this to your composer.json:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com"
        }
    ],
    "require": {
        "akyltist/runzy-template": "dev-main"
    }
}
```

Then run:

```bash
composer update
```

## Requirements

PHP: 7.1 or higher
Extensions: mbstring (recommended for full UTF-8 support)

## 📦 Quick Start

Create a new instance of the `Template` engine. It requires paths to your views and a cache folder.

```php
use Akyltist\RunzyTemplate\Template;

$runzy = new Template(
    __DIR__ . '/views',  // Your .php templates directory
    __DIR__ . '/cache',  // Directory for compiled templates
    true                // Enable caching (strongly recommended for production)
);
```

### 2. Create a Base Layout

Define your application shell in views/layouts/main.php. Use @yield as a placeholder for content and @stack for scripts.

```html
<!-- views/layouts/main.php -->
<!DOCTYPE html>
<html>
<head>
    <title>Runzy Project</title>
</head>
<body>
    <div class="container">
        @yield('main_content')
    </div>

    @stack('footer_scripts')
</body>
</html>
```

### 3. Create a View

In views/welcome.php, extend the layout and fill the defined blocks.

```html
<!-- views/welcome.php -->
@extends('layouts.main')

@block('main_content')
    <h1>Welcome, {{ $username }}!</h1>
    
    @if($isAdmin)
        <p>You have administrative access.</p>
    @endif
@endblock

@push('footer_scripts')
    <script src="/js/welcome-alert.js"></script>
@endpush
```
### 4. Render the View

Now, simply call the render method in your entry point (e.g., index.php).

```php
echo $runzy->render('welcome', [
    'username' => 'Alex',
    'isAdmin'  => true
]);
```

**Syntax Highlights**

**Smart Comments**
  Use special comment tags that are completely stripped out during compilation. Unlike standard HTML comments, these will never be visible in the browser's "View Source".
  ```html
  {{-- This is a private note for developers and won't appear in HTML --}}
  <p>This content is public.</p>