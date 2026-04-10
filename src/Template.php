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

    /**
     * Список методов для обработки синтаксиса шаблона
     */
    private $compilers = [
        'compileComments',          // 1. Удаляем лишнее сразу
        'compileIncludes',          // 2. Собираем файлы в один (рекурсия)
        'compileBlocks',            // 3. Собираем контент блоков
        'compileBlockConditionals', // 4. Проверяем наличие блоков
        'compileYields',            // 5. Вставляем блоки в макет
        'compilePHP',               // 6. Чистый PHP
        'compileIf',                // 7. Логика (If/Else)
        'compileElseIf',
        'compileElse',
        'compileEndIf',             // 
        'compileForeach',           // 8. Циклы
        'compileEchoes',            // 9. Сырой вывод {!! !!} (Сначала специфичный синтаксис)
        'compileEscapedEchoes'      // 10. Безопасный вывод {{ }} (Потом общий синтаксис)
    ];

    /**
     * @param string $templateDir Путь к шаблонам
     * @param string $cacheDir    Путь к кешу
     * @param bool   $cacheEnabled Использовать кеширование
     */
    public function __construct(
        string $templateDir,
        string $cacheDir = 'cache',
        bool $cacheEnabled = false
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
        // 1. Сбрасываем макет перед каждым рендерингом, чтобы старые вызовы не влияли на новые
        $this->layout = null;

        // 2. Формируем путь к файлу шаблона
        $viewPath = $this->templateDir . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewPath)) {
            throw new \Exception("Шаблон не найден: {$viewPath}");
        }

        // 3. Компилируем основной шаблон (в процессе сработает compileExtends и заполнит $this->layout)
        $compiledContent = $this->compile(file_get_contents($viewPath));

        // 4. Сохраняем скомпилированный код во временный (или кеш) файл для исполнения
        $cacheFile = $this->cacheDir . md5($view) . '.php';
        file_put_contents($cacheFile, $compiledContent);

        // 5. Рендерим дочерний шаблон. В процессе выполнения заполнятся блоки $this->blocks
        ob_start();
        extract($data, EXTR_SKIP);
        require $cacheFile;
        $content = ob_get_clean();

        // 6. Если в шаблоне была директива @extends, то $this->layout теперь не пуст
        if ($this->layout) {
            // Запоминаем имя макета и очищаем свойство, чтобы избежать рекурсии
            $layoutName = $this->layout;
            $this->layout = null;

            // Рекурсивно рендерим макет. 
            // Внутри макета сработают @yield, которые вытянут данные из $this->blocks
            return $this->render($layoutName, $data);
        }

        // 7. Если наследования нет, просто возвращаем контент
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
     * Определяет родительский макет: @extends('layout')
     */
    protected function compileExtends($template)
    {
        // Ищем @extends и сохраняем имя макета в свойство класса
        if (preg_match('/@extends\s*\(\s*\'(.*?)\'\s*\)/i', $template, $matches)) {
            $this->layout = $matches[1];
        }

        // Удаляем директиву из текста
        return preg_replace('/@extends\s*\(\s*\'(.*?)\'\s*\)/i', '', $template);
    }

    /**
     * Компилирует @include('имя.файла')
     * Рекурсивно подтягивает содержимое других шаблонов
     */
    protected function compileIncludes($template)
    {
        return preg_replace_callback('/@include\s*\(\s*\'(.*?)\'\s*\)/i', function ($matches) {
            $path = $this->templateDir . str_replace('.', '/', $matches[1]) . '.php';

            if (!file_exists($path)) {
                return "<!-- RunzyTemplate Error: Include '$matches[1]' not found -->";
            }

            // Читаем содержимое и рекурсивно прогоняем через этот же метод
            $includedContent = file_get_contents($path);
            return $this->compileIncludes($includedContent);
        }, $template);
    }
    
    /**
     * Компилирует блоки контента: @block('name')...@endblock
     */
    protected function compileBlocks($template)
    {
        return preg_replace('/@block\s*\(\s*\'(.*?)\'\s*\)(.*?)@endblock/is', '<?php $this->blocks[\'$1\'] = \'$2\'; ?>', $template);
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
    protected function compileYields($template)
    {
        return preg_replace('/@yield\s*\(\s*\'(.*?)\'\s*\)/i', '<?php echo $this->blocks[\'$1\'] ?? \'\'; ?>', $template);
    }
    
    /**
     * Сырой вывод: {!! $var !!} -> <?php echo $var; ?>
     */
    protected function compileEchoes($template)
    {
        return preg_replace('/\{!!\s*(.+?)\s*!!\}/is', '<?php echo $1; ?>', $template);
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
    protected function compileEscapedEchoes($template)
    {
        return preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/is', 
            '<?php echo $this->e($1); ?>', 
            $template
        );
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
    protected function compileForeach($template)
    {
        $template = preg_replace('/@foreach\s*\((.*)\)/i', '<?php foreach($1): ?>', $template);
        return preg_replace('/@endforeach/i', '<?php endforeach; ?>', $template);
    }
    
    /**
     * Компилирует комментарии: {{-- текст --}} -> удаляет их из вывода
     */
    protected function compileComments($template)
    {
        return preg_replace('/\{\{--\s*(.+?)\s*--\}\}/is', '', $template);
    }

}