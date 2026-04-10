<?php

namespace Akyltist\RunzyTemplate;

class Template {
    /** @var string Путь к папке с шаблонами */
    private $templateDir;

    /** @var string Путь к папке для кеша */
    private $cacheDir;

    /** @var bool Флаг включения/выключения кеширования */
    private $cacheEnabled;

    /**
     * Список методов для обработки синтаксиса шаблона
     */
    private $compilers = [
        'compileComments',
        'compileIncludes',
        'compileBlocks',
        'compileBlockConditionals',
        'compileYields',
        'compileEscapedEchoes',
        'compileEchoes',
        'compilePHP',
        'compileIf',
        'compileElseIf',
        'compileElse',
        'compileEndIf',
        'compileForeach'
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
            throw new \Exception("Шаблон не найден: {$viewPath}");
        }

        // Путь к скомпилированному файлу в кеше
        $cacheFile = $this->cacheDir . md5($view) . '.php';

        // Логика компиляции: если кеш выключен или устарел
        if (!$this->cacheEnabled || !file_exists($cacheFile) || filemtime($viewPath) > filemtime($cacheFile)) {
            $content = file_get_contents($viewPath);
            $compiledContent = $this->compile($content);
            
            if (!file_put_contents($cacheFile, $compiledContent)) {
                throw new \Exception("Не удалось записать файл кеша: {$cacheFile}");
            }
        }

        // Рендеринг в песочнице
        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $cacheFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean();
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
    
    protected function compileBlocks($template) { return $template; }
    
    protected function compileBlockConditionals($template) { return $template; }
    
    protected function compileYields($template) { return $template; }
    
    /**
     * Сырой вывод: {!! $var !!} -> <?php echo $var; ?>
     */
    protected function compileEchoes($template)
    {
        return preg_replace('/\{!!\s*(.+?)\s*!!\}/is', '<?php echo $1; ?>', $template);
    }
    
    /**
     * Безопасный вывод: {{ $var }} -> <?php echo htmlspecialchars(...); ?>
     */
    protected function compileEscapedEchoes($template)
    {
        return preg_replace('/\{\{\s*(.+?)\s*\}\}/is', '<?php echo htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>', $template);
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