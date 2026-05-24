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

    public function test_js_frameworks_compatibility()
    {
        $template = <<<'EOT'
        <div x-data="{ allTasks: {!! json_encode($tasks) !!}, loading: false }">
            <template x-if="isAdmin && isActive === true">
                <button @click="tasks = tasks.filter(t => t.id !== 1)">Filter</button>
            </template>
        </div>
        EOT;

        $this->createView('js_test', $template);

        $data = [
            'tasks' => [['id' => 1, 'name' => 'Task 1']],
        ];

        $output = $this->engine->render('js_test', $data);

        // Проверяем, что json_encode отрендерился правильно внутри x-data
        $this->assertStringContainsString('allTasks: [{"id":1,"name":"Task 1"}]', $output);

        // Проверяем, что амперсанды && не превратились в &amp;&amp;
        $this->assertStringContainsString('x-if="isAdmin && isActive === true"', $output);

        // Проверяем, что стрелочная функция => осталась целой
        $this->assertStringContainsString('t => t.id !== 1', $output);
    }

    public function test_js_frameworks_real_world_compatibility()
    {
        // Сценарии, которые ломают парсер Runzy в реальном проекте:
        // 1. JSON с апострофами во фреймворке (Экран ломает кавычки x-data)
        // 2. Сравнение с операторами < или > внутри стрелочной функции Alpine.js
        // 3. Динамический PHP-вывод прямо перед логическим '&&'
        $template = <<<'EOT'
        <?php $isAdminFlag = 1; ?>
        <div x-data="{ 
            staff: {!! json_encode($employees, JSON_UNESCAPED_UNICODE) !!}, 
            currentId: {{ $currentId }} 
        }">
            {{-- Стрелочная функция с оператором меньше/больше внутри тега --}}
            <button @click="staff = staff.filter(s => s.age > 18 && s.id !== currentId)">
                Filter
            </button>

            {{-- Динамический PHP-вывод вплотную к амперсандам --}}
            <template x-if="{{ $isAdminFlag }} && parseInt(member.is_active) === 1">
                <div class="admin-panel">Admin</div>
            </template>
        </div>
        EOT;

        $this->createView('js_real_world_test', $template);

        $data = [
            'currentId' => 6,
            // Критично: данные содержат одинарные кавычки/апострофы (O'Connor, ООО 'Вектор')
            'employees' => [
                ['id' => 7, 'name' => "Анастасия O'Connor", 'age' => 25]
            ]
        ];

        $output = $this->engine->render('js_real_world_test', $data);

        // ТЕСТ 1: Проверяем, что json_encode не разрушил HTML-атрибут из-за кавычки O'Connor
        $this->assertStringContainsString('staff: [{"id":7,"name":"Анастасия O\'Connor","age":25}]', $output);

        // ТЕСТ 2: Проверяем, что знак ">" в стрелочной функции (age > 18) не был воспринят как закрытие HTML-тега
        $this->assertStringContainsString('s => s.age > 18 && s.id !== currentId', $output);

        // ТЕСТ 3: Проверяем, что амперсанды после вывода переменной не превратились в &amp;&amp;
        // Ожидаем чистый JS: x-if="1 && parseInt(member.is_active) === 1"
        $this->assertStringContainsString('x-if="1 && parseInt(member.is_active) === 1"', $output);
    }


    public function test_template_include_context_isolation()
    {
        // 1. Создаем дочерний шаблон (parts/modal_staff_edit.php)
        // Физически создаем подпапку parts, чтобы шаблонизатор мог найти файл по пути parts/modal_staff_edit.php
        $partsDir = $this->templateDir . DIRECTORY_SEPARATOR . 'parts';
        if (!is_dir($partsDir)) {
            mkdir($partsDir, 0777, true);
        }

        $modalTemplate = <<<'EOT'
        <div class="modal">
            <h3>Редактирование: {{ $member->name }}</h3>
            <p>Должность: {{ $member->position }}</p>
        </div>
        EOT;
        // Записываем файл в подпапку parts/modal_staff_edit.php
        file_put_contents($partsDir . DIRECTORY_SEPARATOR . 'modal_staff_edit.php', $modalTemplate);

        // 2. Создаем родительский шаблон (crm/staff_index.php)
        // Здесь крутится цикл, где переменная ТОЖЕ называется $member
        $mainTemplate = <<<'EOT'
        <div class="staff-list">
            @foreach($staff as $member)
                <div class="card">{{ $member->name }}</div>
            @endforeach

            {{-- СЦЕНАРИЙ А: Стандартный @include теперь работает динамически и полностью изолирован! --}}
            <div id="standard-include">
                @include('parts.modal_staff_edit', ['member' => $selectedMember])
            </div>

            {{-- СЦЕНАРИЙ Б: Прямой вызов изолированного метода render --}}
            <div id="isolated-render">
                {!! $this->render('parts.modal_staff_edit', ['member' => $selectedMember]) !!}
            </div>
        </div>
        EOT;
        $this->createView('staff_index_test', $mainTemplate);

        // 3. Готовим синтетические данные
        $designer = (object)['name' => 'Анастасия', 'position' => 'Дизайнер'];
        $analyst  = (object)['name' => 'Сергей', 'position' => 'Аналитик'];
        $owner    = (object)['name' => 'Владелец Компании', 'position' => 'Директор'];

        $data = [
            'staff'          => [$designer, $analyst], // Переменные для цикла
            'selectedMember' => $owner                 // То, что МЫ ХОТИМ передать в модалку
        ];

        // 4. Рендерим страницу
        $output = $this->engine->render('staff_index_test', $data);

        // --- ПРОВЕРКА СЦЕНАРИЯ Б (Прямой изолированный рендер) ---
        $this->assertStringContainsString('<h3>Редактирование: Владелец Компании</h3>', $output);
        $this->assertStringContainsString('<p>Должность: Директор</p>', $output);

        // --- ПРОВЕРКА СЦЕНАРИЯ А (Стандартный @include из коробки) ---
        // Благодаря динамическому выходу в ядре, RunzyTemplate НЕ затирает $selectedMember элементом из цикла!
        $this->assertStringContainsString('<div id="standard-include">', $output);
        $this->assertStringContainsString('<h3>Редактирование: Владелец Компании</h3>', $output); 
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

public function test_template_conditional_empty_tasks_rendering()
{
    // 1. Создаем тестируемый шаблон с твоим условием ветвления
    $template = <<<'EOT'
    <div class="project-tasks-box">
        @if(empty($tasks))
            <div class="has-text-centered py-6 has-text-grey-light">
                <i class="fas fa-tasks fa-3x mb-3"></i>
                <p>В этом проекте еще нет задач</p>
            </div>
        @endif
        
        @foreach($tasks as $item)
            <div class="task-item">{{ $item->text }}</div>
        @endforeach
    </div>
    EOT;

    $this->createView('conditional_tasks_test', $template);

    // --- КЕЙС 1: Проект АБСОЛЮТНО ПУСТОЙ (задач нет) ---
    $emptyData = [
        'tasks' => []
    ];

    $outputEmpty = $this->engine->render('conditional_tasks_test', $emptyData);

    // Проверяем, что при пустом массиве заглушка КРАСИВО И ЧЕТКО СТЕРЛАСЬ И ОТРЕНДЕРИЛАСЬ
    $this->assertStringContainsString('В этом проекте еще нет задач', $outputEmpty);
    $this->assertStringContainsString('class="has-text-centered py-6 has-text-grey-light"', $outputEmpty);


    // --- КЕЙС 2: В проекте РЕАЛЬНО ЕСТЬ ЗАДАЧИ ---
    $activeData = [
        'tasks' => [
            (object)['id' => 1, 'text' => 'Разработать этикетку PROFLINE']
        ]
    ];

    $outputActive = $this->engine->render('conditional_tasks_test', $activeData);

    // КРИТИЧЕСКИЙ ТЕСТ: Проверяем, что Runzy не накосячил с empty()
    // Заглушки "В этом проекте еще нет задач" на экране БЫТЬ НЕ ДОЛЖНО!
    $this->assertStringNotContainsString('В этом проекте еще нет задач', $outputActive);
    
    // А вот сама задача должна успешно появиться
    $this->assertStringContainsString('Разработать этикетку PROFLINE', $outputActive);
}

    public function test_template_js_context_escaping_with_newlines_and_bullets()
{
    // Сценарий: Текст ТЗ содержит реальные переносы строк (\n) и маркеры списков.
    // Если шаблонизатор выведет его "как есть" или сделает только htmlspecialchars,
    // одинарные кавычки в JS сломаются, и браузер выдаст "Invalid or unexpected token".
    
    // Ожидаем от автора синтаксис специального JS-экранирования (например, модификатор :js или фильтр)
    $template = <<<'EOT'
    <div x-data="{ active: false }">
        <button @click="initTask('{{ $task->text|js }}')">Открыть задачу</button>
    </div>
    EOT;

    $this->createView('js_escaping_test', $template);

    $data = [
        'task' => (object)[
            'text' => "УТ-00015734 - КОМПЛЕКТ картриджей ITA.\n• Нужен макет.\n• Срочно!"
        ]
    ];

    $output = $this->engine->render('js_escaping_test', $data);

    // --- ПРОВЕРКА БЕЗОПАСНОСТИ ДЛЯ JAVASCRIPT ---
    
    // 1. Тест НЕ должен содержать реальных, сырых переносов строк внутри атрибута @click
    $this->assertStringNotContainsString("@click=\"initTask('УТ-00015734 - КОМПЛЕКТ картриджей ITA.\n", $output);

    // 2. Шаблонизатор должен автоматически экранировать перенос строки в безопасный для JS символ '\n'
    // или вернуть валидную JSON-строку
    $this->assertStringContainsString('\n• Нужен макет.', $output);
    $this->assertStringContainsString('\n• Срочно!', $output);
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