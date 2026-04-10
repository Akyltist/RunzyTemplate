<?php

namespace Akyltist\RunzyTemplate\Tests;

use PHPUnit\Framework\TestCase;
use Akyltist\RunzyTemplate\Template;

class TemplateTest extends TestCase
{
    private $templateDir;
    private $cacheDir;
    private $engine;

    /**
     * Подготовка окружения перед каждым тестом
     */
    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/temp_views';
        $this->cacheDir = __DIR__ . '/temp_cache';

        if (!is_dir($this->templateDir)) mkdir($this->templateDir, 0777, true);
        if (!is_dir($this->cacheDir)) mkdir($this->cacheDir, 0777, true);

        $this->engine = new Template($this->templateDir, $this->cacheDir, false);
    }

    /**
     * Очистка после каждого теста
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->templateDir);
        $this->removeDirectory($this->cacheDir);
    }

    private function removeDirectory($path)
    {
        if (!is_dir($path)) return;
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    private function createView($name, $content)
    {
        $path = $this->templateDir . '/' . $name . '.php';
        file_put_contents($path, $content);
    }

    // --- ТЕСТЫ ---

    public function test_escaped_and_raw_echoes()
    {
        $this->createView('echo', 'Safe: {{ $name }}, Raw: {!! $html !!}');
        $output = $this->engine->render('echo', [
            'name' => '<b>Alex</b>',
            'html' => '<b>Bold</b>'
        ]);

        $this->assertEquals('Safe: &lt;b&gt;Alex&lt;/b&gt;, Raw: <b>Bold</b>', $output);
    }

    public function test_conditional_logic()
    {
        $this->createView('if', '@if($show)Showed@elseIgnored@endif');
        
        $this->assertEquals('Showed', $this->engine->render('if', ['show' => true]));
        $this->assertEquals('Ignored', $this->engine->render('if', ['show' => false]));
    }

    public function test_forelse_loop()
    {
        $template = '@forelse($items as $i){{ $i }}@emptyEmpty@endforelse';
        $this->createView('forelse', $template);

        $this->assertEquals('123', $this->engine->render('forelse', ['items' => [1, 2, 3]]));
        $this->assertEquals('Empty', $this->engine->render('forelse', ['items' => []]));
    }

    public function test_template_inheritance()
    {
        // Создаем макет
        $this->createView('layout', 'Header @yield("content") Footer');

        // Создаем дочерний шаблон с переносом строки
        $this->createView('child', "@extends('layout')\n @block('content')Body@endblock");

        $output = $this->engine->render('child');

        // Очищаем результат от лишних пробелов и переносов для сравнения
        $result = trim(preg_replace('/\s+/', ' ', $output));

        $this->assertEquals('Header Body Footer', $result);
    }

    public function test_nested_loops()
    {
        // Используем HEREDOC, чтобы кавычки внутри не конфликтовали
        $template = <<<'EOT'
    @foreach($categories as $category)[{{ $category['name'] }}:@foreach($category['items'] as $item) {{ $item }}@endforeach]@endforeach
    EOT;

        $this->createView('nested', $template);

        $data = [
            'categories' => [
                [
                    'name' => 'Fruits',
                    'items' => ['Apple', 'Banana']
                ],
                [
                    'name' => 'Vegetables',
                    'items' => ['Carrot']
                ]
            ]
        ];

        $output = $this->engine->render('nested', $data);
        $result = trim(preg_replace('/\s+/', ' ', $output));

        $this->assertEquals('[Fruits: Apple Banana][Vegetables: Carrot]', $result);
    }

    public function test_mixed_nested_loops()
    {
        $template = <<<'EOT'
    @foreach($groups as $group)
        {{ $group['name'] }}:
        @forelse($group['items'] as $item)
            {{ $item }}
        @empty
            No items
        @endforelse
    @endforeach
    EOT;
    
        $this->createView('mixed_loops', $template);
    
        $data = [
            'groups' => [
                [
                    'name' => 'Full',
                    'items' => ['A', 'B']
                ],
                [
                    'name' => 'Empty',
                    'items' => [] // Здесь должен сработать @empty
                ]
            ]
        ];
    
        $output = $this->engine->render('mixed_loops', $data);
        
        // Очищаем результат от лишних пробелов и переносов
        $result = trim(preg_replace('/\s+/', ' ', $output));
    
        $this->assertEquals('Full: A B Empty: No items', $result);
    }

    public function test_stacks_and_push()
    {
        $this->createView('master', '@stack("js")');
        $this->createView('page', '@extends("master") @push("js")script1@endpush @push("js")script2@endpush');

        $output = $this->engine->render('page');
        $this->assertStringContainsString('script1', $output);
        $this->assertStringContainsString('script2', $output);
    }

    public function test_auth_directives()
    {
        $this->createView('auth', '@auth Yes @endauth @guest No @endguest');

        // Тест для гостя
        $this->engine->setAuthChecker(function() { return false; });
        $this->assertStringContainsString('No', $this->engine->render('auth'));

        // Тест для авторизованного
        $this->engine->setAuthChecker(function() { return true; });
        $this->assertStringContainsString('Yes', $this->engine->render('auth'));
    }

    public function test_custom_directives()
    {
        $this->engine->directive('upper', function($m) {
            return "<?php echo strtoupper($m); ?>";
        });

        $this->createView('custom', '@upper("hello")');
        $this->assertEquals('HELLO', $this->engine->render('custom'));
    }

    public function test_csrf_directive()
    {
        $this->engine->setCsrfProvider(function() { return 'test_token'; });
        $this->createView('csrf', '@csrf');
        
        $output = $this->engine->render('csrf');
        $this->assertStringContainsString('value="test_token"', $output);
        $this->assertStringContainsString('name="_token"', $output);
    }
}