# 🚀 RunzyTemplate

[Читать на русском](README_RU.md)

RunzyTemplate is an extremely lightweight (18 kB), fast, Standalone, and extensible PHP 7.1+ templating engine inspired by Laravel Blade syntax.

## ✨ Features

- **Zero Dependencies**  
  Built with pure PHP. Standalone. No heavy vendor folders or third-party packages required. Lightweight and easy to integrate into any project.

- **Blade-Inspired Syntax**  
  Write clean and expressive code using familiar directives like `@if`, `@foreach`, `@auth`, and `@forelse`. No more messy `<?php ?>` tags in your HTML.

- **AI-Friendly Design**  
  The engine uses a predictable, standardized syntax that is easily understood by LLMs (ChatGPT, Claude). Includes a ready-to-use prompt context for faster AI-assisted development.

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

## Syntax Highlights

### Smart Comments
Use special comment tags that are completely stripped out during compilation. Unlike standard HTML comments, these will never be visible in the browser's "View Source".

```html
{{-- This is a private note for developers and won't appear in HTML --}}
<p>This content is public.</p>
```


### Embedded PHP Blocks
When you need to execute raw logic, use the `@php` directive. This keeps your templates clean while allowing full access to PHP power when necessary.
```html
@php
    $status = $user->isActive() ? 'online' : 'offline';
    $labelClass = $status === 'online' ? 'bg-green' : 'bg-gray';
@endphp

<span class="status-{{ $labelClass }}">{{ $status }}</span>
```


### Built-in CSRF Protection
Protect your application from Cross-Site Request Forgery attacks with a single directive. It automatically generates a secure token and injects a hidden input into your forms.
```html
<form action="/update" method="POST">
    @csrf
    <input type="text" name="display_name">
    <button type="submit">Save Changes</button>
</form>
```

Server-side validation (Example):
```php
if ($_POST['_token'] !== $_SESSION['_token']) {
    die('CSRF token mismatch!');
}
```


### Authentication & Guest Directives
Easily toggle the visibility of UI elements based on the user's authentication status. By default, it checks `$_SESSION['user']`, but you can fully customize this logic.

Template:
```html
@auth
    <p>Welcome back, {{ $user->name }}!</p>
    <a href="/logout">Logout</a>
@endauth

@guest
    <p>Hello, stranger! Please sign in.</p>
    <a href="/login">Login</a>
@guest
```

Customizing Logic (PHP):
```php
// Optional: Define your own authentication check
$runzy->setAuthChecker(function() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
});
```


### Conditional Logic (@if, @elseif, @else)
Control your template flow with clean, readable directives. No more nested brackets or messy PHP tags. It supports any valid PHP expression inside the conditions.

Template:
```html
@if($user->role === 'admin')
    <div class="badge-admin">Administrator</div>
@elseif($user->role === 'editor')
    <div class="badge-editor">Editor</div>
@else
    <div class="badge-user">Standard User</div>
@endif
```

PHP Logic (behind the scenes):
The engine converts these into high-performance alternative PHP syntax:
```php
<?php if($user->role === 'admin'): ?>
    <div class="badge-admin">Administrator</div>
<?php elseif($user->role === 'editor'): ?>
    ...
<?php endif; ?>
```


### Advanced Loops (@foreach, @forelse)
Iterate through data with ease. Use `@foreach` for standard loops or the powerful `@forelse` to handle empty states without extra `if` statements.

Standard Loop:
```html
@foreach($users as $user)
    <li>{{ $user->name }}</li>
@endforeach
```

Loop with Empty State (@forelse):
This directive checks if the collection is empty and displays the @empty block automatically.
```html
<ul>
    @forelse($news as $article)
        <li>{{ $article->title }}</li>
    @empty
        <li>No news available at the moment.</li>
    @endforelse
</ul>
```

PHP Data Example:
```php
echo $runzy->render('news_list', [
    'news' => $database->getLatestArticles() // Works with arrays or objects
]);
```


### Asset Management (@stack, @push)
The perfect way to manage JavaScript and CSS dependencies. Define a placeholder in your main layout and "push" content into it from any nested child view or partial.

Base Layout (`layout.php`):
```html
  <html>
  <body>
      @yield('content')

      <!-- Placeholder for scripts -->
      @stack('scripts')
  </body>
  </html>
```

Child View:
You can push multiple scripts into the same stack from different files.
```html
@extends('layout')

@block('content')
    <h1>Dashboard</h1>
@endblock

@push('scripts')
    <script src="https://cdn.com"></script>
    <script src="/js/dashboard-charts.js"></script>
@endpush
```

Advanced Usage:
Use @prepend to add content to the beginning of the stack (useful for high-priority libraries).
```html
@prepend('scripts')
    <script src="/js/jquery.min.js"></script>
@endprepend
```


### Custom Directives (Extensibility)
Extend the engine with your own syntax in just one line. Use the `directive()` method to create custom aliases that compile into reusable PHP code.

Registering a Directive (PHP):
```php
  // Custom date formatter directive
  $runzy->directive('datetime', function($expression) {
      return "<?php echo date($expression); ?>";
  });

  // Custom YouTube embed directive
  $runzy->directive('youtube', function($id) {
      return '<iframe src="https://youtube.com' . $id . '"></iframe>';
  });

  // Custom var dump directive
  $runzy->directive('dump', function($expression) {
    return "<?php var_dump($expression); ?>";
});
```

Using in Templates:
```html
<div class="meta">
    Published on: @datetime('d.m.Y', $post->created_at)
</div>

<div class="video">
    @youtube($post->video_id)
</div>

<pre>
    @dump($user)
</pre>
```

Why it's cool:
It keeps your views clean and allows you to abstract complex HTML or PHP logic into simple, readable tags.


## 🤖 AI Context & Prompting

If you are using AI (ChatGPT, Claude, etc.) to help you write templates or extend this engine, you can provide the following context to help it understand **RunzyTemplate** without sharing the entire source code.

### Reference Prompt

> I am using **RunzyTemplate**, a lightweight PHP template engine. Please follow these rules:
> - **Syntax**: Blade-like directives.
> - **Variables**: `{{ $var }}` (escaped), `{!! $var !!}` (raw), `{{-- comment --}}`.
> - **Control Structures**: `@if`, `@elseif`, `@else`, `@endif`, `@foreach`, `@endforeach`.
> - **Empty States**: `@forelse($a as $b)`, `@empty`, `@endforelse`.
> - **Layout System**: `@extends('name')`, `@yield('name')`, `@block('name')/@endblock`, `@hasblock('name')`.
> - **Partials**: `@include('name')`.
> - **Asset Stacks**: `@push('name')/@endpush`, `@prepend('name')/@endprepend`, `@stack('name')`.
> - **Security & Auth**: `@csrf` (inserts hidden input), `@auth`, `@guest`.
> - **Customization**: Use `@php ... @endphp` for raw code and `$engine->directive(name, callback)` for custom aliases.
> Please generate code or templates strictly according to this API.