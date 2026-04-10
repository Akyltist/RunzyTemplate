<?php

namespace Akyltist\RunzyTemplate;

class Template {
    /** @var string Путь к папке с шаблонами */
    private $templateDir;

    /** @var string Путь к папке для кеша */
    private $cacheDir;

    /** @var bool Флаг включения/выключения кеширования */
    private $cacheEnabled;

    /** @var array Хранилище содержимого блоков */
    private $blocks = [];

    /** @var string|null Имя родительского макета */
    private $layout;

    /** @var array Глобальные переменные, доступные во всех шаблонах */
    private $shared = [];

    /** @var callable|null Функция для проверки авторизации */
    private $authChecker;

    /** @var callable|null Функция для получения CSRF-токена */
    private $csrfProvider;

    /** @var array Пользовательские директивы */
    private $customDirectives = [];

    /** @var array Хранилище для стеков (скрипты, стили и т.д.) */
    private $stacks = [];

    /** @var array Порядок выполнения компиляторов (Pipeline) */
    private $compilers = [
        'compileComments',          // 1.  Удаление комментариев {{-- --}} (чтобы не обрабатывать скрытый код)
        'compileIncludes',          // 2.  Рекурсивное включение файлов @include
        'compileExtends',           // 3.  Определение родительского макета @extends
        'compileBlocks',            // 4.  Сбор контента блоков @block
        'compileBlockConditionals', // 5.  Проверка наличия контента в блоках @hasblock
        'compileYields',            // 6.  Определение мест вставки контента @yield
        'compileStacks',            // 7.  Определение мест вывода стеков @stack
        'compilePush',              // 8.  Наполнение стеков контентом @push
        'compilePrepend',           // 9.  Добавление контента в начало стеков @prepend
        'compileCustomDirectives',  // 10. Обработка пользовательских алиасов/директив
        'compilePHP',               // 11. Обработка блоков чистого PHP @php
        'compileCsrf',              // 12. Генерация и вставка CSRF-защиты @csrf
        'compileAuth',              // 13. Условие для авторизованных пользователей @auth
        'compileGuest',             // 14. Условие для гостей @guest
        'compileIf',                // 15. Управляющая конструкция @if
        'compileElseIf',            // 16. Управляющая конструкция @elseif
        'compileElse',              // 17. Управляющая конструкция @else
        'compileEndIf',             // 18. Закрытие условий @endif
        'compileForeach',           // 19. Стандартный цикл @foreach / @endforeach
        'compileForelse',           // 20. Расширенный цикл с проверкой на пустоту @forelse / @empty
        'compileEchoes',            // 21. Вывод сырых данных {!! !!} (Raw output)
        'compileEscapedEchoes'      // 22. Безопасный вывод данных {{ }} (HTML Escape)
    ];

    /**
     * @param string $templateDir Путь к шаблонам
     * @param string $cacheDir    Путь к кешу
     * @param bool   $cacheEnabled Использовать кеширование
     */
    public function __construct(
        string $templateDir,
        string $cacheDir = 'cache',
        bool $cacheEnabled = true
    ) {
        $this->templateDir = rtrim($templateDir, '/') . '/';
        $this->cacheDir = rtrim($cacheDir, '/') . '/';
        $this->cacheEnabled = $cacheEnabled;

        // Создаем папку только если кеш включен и папки еще нет
        if ($this->cacheEnabled && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Рендерит шаблон, используя систему кеширования.
     * 
     * @param string $view Имя шаблона (поддерживает точечную нотацию: 'auth.login')
     * @param array $data Данные для шаблона
     * @return string
     * @throws \Exception
     */
    public function render(string $view, array $data = []): string
    {
        $viewPath = $this->templateDir . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewPath)) {
            throw new \Exception("Template not found: {$viewPath}");
        }

        $compiledContent = $this->compile(file_get_contents($viewPath));
        $safeName = str_replace(['/', '.'], '_', $view);
        $cacheFile = $this->cacheDir . $safeName . '_' . md5($view) . '.php';
        file_put_contents($cacheFile, $compiledContent);

        ob_start();
        extract(array_merge($this->shared, $data), EXTR_SKIP);
        require $cacheFile;
        $content = ob_get_clean();

        if ($this->layout) {
            $layoutName = is_array($this->layout) ? $this->layout[1] : $this->layout;
            $this->layout = null;
            return $this->render($layoutName, $data);
        }

        return $content;
    }

    /**
     * Прогоняет содержимое шаблона через все компиляторы
     */
    private function compile(string $template): string
    {
        foreach ($this->compilers as $compiler) {
            $template = $this->$compiler($template);
        }

        return $template;
    }

    /**
     * Проверяет статус авторизации.
     * Позволяет использовать кастомную логику, если она задана.
     */
    protected function isAuth(): bool
    {
        // Если пользователь передал свою функцию — вызываем её
        if (is_callable($this->authChecker)) {
            return ($this->authChecker)();
        }

        // Стандартная логика (по умолчанию)
        return isset($_SESSION['user']);
    }

    /**
     * Позволяет задать свою логику проверки авторизации.
     */
    public function setAuthChecker(callable $callback)
    {
        $this->authChecker = $callback;
        return $this;
    }

    /**
     * Компилирует @auth ... @endauth
     */
    protected function compileAuth($template)
    {
        $template = preg_replace('/@auth/i', '<?php if($this->isAuth()): ?>', $template);
        return preg_replace('/@endauth/i', '<?php endif; ?>', $template);
    }

    /**
     * Компилирует @guest ... @endguest
     */
    protected function compileGuest($template)
    {
        $template = preg_replace('/@guest/i', '<?php if(!$this->isAuth()): ?>', $template);
        return preg_replace('/@endguest/i', '<?php endif; ?>', $template);
    }

    /**
     * Устанавливает пользовательский обработчик для генерации CSRF-токена.
     * Позволяет интегрировать шаблонизатор с любой существующей системой безопасности.
     * 
     * @param callable $callback Функция, возвращающая строку токена
     * @return $this
     */
    public function setCsrfProvider(callable $callback)
    {
        $this->csrfProvider = $callback;
        return $this;
    }

    /**
     * Извлекает CSRF-токен для вставки в форму.
     * Сначала проверяет наличие кастомного провайдера, иначе использует стандартную сессию.
     * 
     * @return string Текущий защитный токен
     */
    protected function getCsrfToken(): string
    {
        // Если пользователь задал свою логику через setCsrfProvider
        if (is_callable($this->csrfProvider)) {
            return ($this->csrfProvider)();
        }

        // Стандартная логика: работа с нативной сессией PHP
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_token'];
    }

    /**
     * Компилирует директиву @csrf в скрытый input.
     */
    protected function compileCsrf($template)
    {
        return preg_replace('/@csrf/i', '<input type="hidden" name="_token" value="<?php echo $this->getCsrfToken(); ?>">', $template);
    }

    /**
     * Определяет родительский макет: @extends('layout')
     */
    protected function compileExtends($template) 
    {
        // Используем хеш-разделитель (#) для удобства и флаги 'is' для многострочности
        $pattern = '#@extends\s*\(\s*[\'"](.*?)[\'"]\s*\)#is';
            
        if (preg_match($pattern, $template, $matches)) {
            // Сохраняем имя родительского макета из первой группы захвата
            $this->layout = $matches[1]; 
        }
            
        // Удаляем директиву из тела шаблона, чтобы она не выводилась в финальном HTML
        return preg_replace($pattern, '', $template);
    }

    /**
     * Компилирует @include('имя.файла')
     * Рекурсивно подтягивает содержимое других шаблонов
     */
    protected function compileIncludes($template)
    {
        return preg_replace_callback('/@include\s*\(\s*[\'"](.*?)[\'"]\s*\)/i', function ($matches) {
            $viewName = $matches[1];
            $filePath = $this->templateDir . str_replace('.', '/', $viewName) . '.php';

            if (!file_exists($filePath)) {
                return "<!-- RunzyTemplate Error: Include '{$viewName}' not found -->";
            }

            $includeContent = file_get_contents($filePath);
            
            // Рекурсивно компилируем содержимое подключенного файла
            return $this->compile($includeContent);
        }, $template);
    }

    /**
     * Делится переменной со всеми шаблонами.
     * 
     * @param string|array $key Имя переменной или массив данных
     * @param mixed $value Значение (если $key — строка)
     * @return $this
     */
    public function share($key, $value = null)
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
        } else {
            $this->shared[$key] = $value;
        }
        return $this;
    }
    
    /**
     * Компилирует блоки контента: @block('name')...@endblock
     */
    protected function compileBlocks($template) {
        // Важно: захватываем имя блока корректно
        return preg_replace('/@block\s*\(\s*[\'"](.*?)[\'"]\s*\)(.*?)@endblock/is', '<?php $this->blocks[\'$1\'] = \'$2\'; ?>', $template);
    }

    /**
     * Компилирует проверку существования блока: @hasblock('name') ... @endhasblock
     */
    protected function compileBlockConditionals($template)
    {
        $template = preg_replace('/@hasblock\s*\(\s*\'(.*?)\'\s*\)/i', '<?php if(isset($this->blocks[\'$1\'])): ?>', $template);
        return preg_replace('/@endhasblock/i', '<?php endif; ?>', $template);
    }
    
    /**
     * Компилирует места вставки: @yield('name')
     */
    protected function compileYields($template) {
        // Поддержка @yield('name') и @yield("name")
        return preg_replace('/@yield\s*\(\s*[\'"](.*?)[\'"]\s*\)/i', '<?php echo $this->blocks[\'$1\'] ?? \'\'; ?>', $template);
    }
    
    /**
     * Сырой вывод: {!! $var !!} -> <?php echo $var; ?>
     */
    protected function compileEchoes($template) {
        // Используем нежадный поиск (.*?) и флаг s для многострочности
        return preg_replace('/\{!!\s*(.*?)\s*!!\}/is', '<?php echo $1; ?>', $template);
    }

    /**
     * Экранирует данные для безопасного вывода в HTML.
     * 
     * @param mixed $value
     * @return string
     */
    public function e($value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Безопасный вывод: {{ $var }} -> <?php echo $this->e($var); ?>
     */
    protected function compileEscapedEchoes($template) {
        // Добавляем $this->e() для безопасности
        return preg_replace('/\{\{\s*(.*?)\s*\}\}/is', '<?php echo $this->e($1); ?>', $template);
    }
    
    /**
     * Компилирует блоки чистого PHP: @php ... @endphp
     */
    protected function compilePHP($template)
    {
        return preg_replace('/@php(.*?)@endphp/is', '<?php $1; ?>', $template);
    }
    
    /**
     * Компилирует @if(...)
     */
    protected function compileIf($template)
    {
        return preg_replace('/@if\s*\((.*)\)/i', '<?php if($1): ?>', $template);
    }

    /**
     * Компилирует @elseif(...)
     */
    protected function compileElseIf($template)
    {
        return preg_replace('/@elseif\s*\((.*)\)/i', '<?php elseif($1): ?>', $template);
    }

    /**
     * Компилирует @else
     */
    protected function compileElse($template)
    {
        return preg_replace('/@else/i', '<?php else: ?>', $template);
    }

    /**
     * Компилирует @endif. 
     */
    protected function compileEndIf($template)
    {
        return preg_replace('/@endif/i', '<?php endif; ?>', $template);
    }
    
    /**
     * Компилирует цикл @foreach
     */
    protected function compileForeach($t) {
        // Добавляем флаг 's' и 'm' для корректной обработки сложных строк
        $t = preg_replace('/@foreach\s*\((.*?)\)/is', '<?php foreach($1): ?>', $t);
        return preg_replace('/@endforeach/is', '<?php endforeach; ?>', $t);
    }

    /**
     * Компилирует цикл @forelse
     */
    protected function compileForelse($template)
    {
        // Заменяем @forelse($users as $user) на проверку и начало цикла
        $template = preg_replace('/@forelse\s*\(\s*(.+?)\s*as\s*(.+?)\s*\)/i', '<?php if(!empty($1)): foreach($1 as $2): ?>', $template);

        // Заменяем @empty на переход к блоку else
        $template = preg_replace('/@empty/i', '<?php endforeach; else: ?>', $template);

        // Заменяем @endforelse на закрытие условия
        $template = preg_replace('/@endforelse/i', '<?php endif; ?>', $template);

        return $template;
    }
    
    /**
     * Компилирует комментарии: {{-- текст --}} -> удаляет их из вывода
     */
    protected function compileComments($template)
    {
        return preg_replace('/\{\{--\s*(.+?)\s*--\}\}/is', '', $template);
    }

    /**
     * Регистрирует новую кастомную директиву.
     * 
     * @param string $name Название директивы (без @)
     * @param callable $handler Функция, принимающая выражение и возвращающая PHP-код
     * @return $this
     */
    public function directive(string $name, callable $handler)
    {
        $this->customDirectives[$name] = $handler;
        return $this;
    }
    
    /**
     * Компилирует пользовательские директивы.
     */
    protected function compileCustomDirectives($template)
    {
        foreach ($this->customDirectives as $name => $handler) {
            // Регулярка ищет @название(...)
            $template = preg_replace_callback('/@'.$name.'\s*\((.*?)\)/is', function ($matches) use ($handler) {
                // Передаем содержимое скобок в обработчик пользователя
                return $handler($matches[1]);
            }, $template);
        }

        return $template;
    }

    /**
     * Компилирует вставку стека: @stack('name')
     */
    /*
    protected function compileStacks($template)
    {
        return preg_replace('/@stack\s*\(\s*\'(.*?)\'\s*\)/i', '<?php echo implode(PHP_EOL, $this->stacks[\'$1\'] ?? []); ?>', $template);
    }*/
    protected function compileStacks($t) {
    // Поддержка @stack('name') и @stack("name")
    return preg_replace('/@stack\s*\(\s*[\'"](.*?)[\'"]\s*\)/i', '<?php echo implode(PHP_EOL, $this->stacks[\'$1\'] ?? []); ?>', $t);
}

    /**
     * Компилирует начало наполнения стека: @push('name')
     */
    /*
    protected function compilePush($template)
    {
        return preg_replace('/@push\s*\(\s*\'(.*?)\'\s*\)(.*?)@endpush/is', '<?php $this->stacks[\'$1\'][] = \'$2\'; ?>', $template);
    }*/
    protected function compilePush($t) {
    return preg_replace('/@push\s*\(\s*[\'"](.*?)[\'"]\s*\)(.*?)@endpush/is', '<?php $this->stacks[\'$1\'][] = \'$2\'; ?>', $t);
}

    /**
     * Компилирует @prepend('name') — добавляет в начало стека (иногда нужно для приоритетных стилей)
     */
    protected function compilePrepend($template)
    {
        return preg_replace('/@prepend\s*\(\s*\'(.*?)\'\s*\)(.*?)@endprepend/is', '<?php array_unshift($this->stacks[\'$1\'] = $this->stacks[\'$1\'] ?? [], \'$2\'); ?>', $template);
    }
}