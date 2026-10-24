<?php
// studio/api/tools.php — что помощник AI-Acrobat знает о себе и какие действия ему разрешены.
//
// Инструменты выполняет БРАУЗЕР (studio.js): только он видит запущенный
// редактор. Сервер лишь объявляет модели, какие инструменты есть, и решает,
// какие из них доступны в текущем режиме.

/** Инструменты, которые ничего не меняют в документе. Доступны в обоих режимах. */
function studio_read_tools(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'find_commands',
                'description' => 'Найти команды редактора PDF по смыслу (по-русски или по-английски): id, русское название, путь в меню, '
                    . 'доступна ли сейчас. Вызывай перед run_command, если не знаешь точный id: id нельзя угадывать.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Что нужно сделать, 1–4 слова: «повернуть», «удалить страницы», «водяной знак», «сжать», «пароль», «rotate».'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_state',
                'description' => 'Текущее состояние: открытые документы (имя, страниц, есть ли несохранённые изменения), активный документ, '
                    . 'текущая страница, выделенные страницы, масштаб, режим, открытое окно, последнее сообщение.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'read_pages',
                'description' => 'Прочитать текст страниц активного документа (до 30 страниц за раз). У сканов текста нет — тогда look_at_screen.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'integer', 'description' => 'С какой страницы (с 1), по умолчанию текущая'],
                        'to' => ['type' => 'integer', 'description' => 'По какую страницу включительно'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'look_at_screen',
                'description' => 'Сделать снимок экрана редактора и посмотреть на него: страница, сканы, результат действий.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ],
    ];
}

/** Инструменты, которые меняют документ или вид. Только в режиме «Сделай». */
function studio_act_tools(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'select_pages',
                'description' => 'Выделить страницы: команды страниц (повернуть, удалить, извлечь…) действуют на выделенные, а без выделения — на текущую.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pages' => ['type' => 'string', 'description' => 'Номера страниц: «1,3-5,8»; пустая строка — снять выделение'],
                    ],
                    'required' => ['pages'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'go_to_page',
                'description' => 'Перейти к странице.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['page' => ['type' => 'integer', 'description' => 'Номер страницы с 1']],
                    'required' => ['page'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'run_command',
                'description' => 'Выполнить команду редактора так, как будто пользователь выбрал её в меню. У команд нет параметров: '
                    . 'страничные действуют на выделенные страницы (select_pages) или текущую. Одни команды делают дело сразу '
                    . '(повернуть, удалить страницы, сжать), другие открывают окно с настройками — тогда скажи пользователю, что выбрать в нём.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'id команды, например page.rotate, page.delete, optimize.reduce, edit.watermark'],
                    ],
                    'required' => ['id'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'open_dialog',
                'description' => 'Открыть окно настроек, чтобы пользователь сам выбрал параметры: watermark (водяной знак), header-footer (колонтитулы), '
                    . 'number-pages (нумерация страниц), background (фон), protect (пароль), security, optimize (уменьшить размер, подробно), '
                    . 'split (разделить), export-image (страницы в картинки), export-text (в текст), recognize-text (распознать текст), '
                    . 'signature (подпись), properties (свойства документа), page-boxes (обрезка), compare-files (сравнить).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'Имя окна из списка выше']],
                    'required' => ['name'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'undo',
                'description' => 'Отменить последние действия (как Ctrl+Z).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'steps' => ['type' => 'integer', 'description' => 'Сколько шагов отменить, по умолчанию 1', 'minimum' => 1, 'maximum' => 20],
                    ],
                ],
            ],
        ],
    ];
}

/** Системная инструкция. $mode: 'advice' (Совет) или 'act' (Сделай). */
function studio_system_prompt(string $mode): string
{
    $available = $mode === 'act' ? array_merge(studio_read_tools(), studio_act_tools()) : studio_read_tools();
    $catalog = studio_tool_catalog($available);

    $common = <<<TXT
Ты — встроенный помощник AI-Acrobat, редактора PDF, устроенного как Adobe Acrobat: просмотр, комментарии, страницы
(повернуть, удалить, вставить, извлечь, разделить, объединить), водяные знаки, колонтитулы, нумерация, сжатие, пароль,
скрытие данных, формы, заполнение и подпись, экспорт в картинки и текст; интерфейс на русском.
Файлы пользователя хранятся на его сервере («Мои файлы»). Пользователь работает в редакторе прямо сейчас.
К его сообщению приложено состояние программы, а иногда снимок экрана.

Как отвечать:
- Всегда по-русски, коротко и по делу: 1–6 предложений или короткий нумерованный список шагов.
- Пункты меню пиши так: «Страницы › Повернуть». Горячие клавиши — в скобках: (Ctrl+S).
- Названия команд бери из find_commands, а не по памяти. Не выдумывай функций, которых нет.
- Можешь читать текст страниц (read_pages) и пересказывать, искать сроки, суммы, стороны, проверять договор.
  Если у страницы нет текста (скан), посмотри на неё (look_at_screen) и прочитай с картинки.
- Сохранение: Ctrl+S сохраняет документ в «Мои файлы» на сервере (значок папки в панели помощника).

Твои инструменты (других нет — ни computer_use, ни кликов по координатам):
{$catalog}
Вызывай их штатно. Если штатный вызов недоступен, напиши вызов текстом, по одному блоку на вызов:
<tool_call><function=ИМЯ><parameter=КЛЮЧ>ЗНАЧЕНИЕ</parameter></function></tool_call>
Значения-объекты и списки пиши в JSON. После блока ничего не пиши — дождись результата.
TXT;

    if ($mode === 'act') {
        return $common . "\n\n" . <<<TXT
Режим «Сделай»: ты сам выполняешь действия в редакторе с помощью инструментов.
Это главное правило: не объясняй пользователю, как сделать вручную, — сделай сам, а где нужно его решение (текст
водяного знака, пароль) — открой окно (open_dialog) и скажи, что в нём выбрать.
Каждое сообщение пользователя начинается с пометки текущего режима — верь ей.
Порядок работы:
1. Посмотри состояние (приложено; свежее — get_state): открыт ли документ, сколько страниц, какие выделены.
2. Страничные команды: сначала select_pages, потом run_command. Частые id:
   page.rotate (по часовой), page.rotate_ccw, page.delete, page.duplicate, page.reverse, page.insert_blank,
   page.extract (окно), page.split (окно), page.number (окно нумерации), page.organize (сетка страниц);
   optimize.reduce (уменьшить размер файла); edit.watermark, edit.header_footer, edit.background (окна);
   edit.watermark.remove, edit.header_footer.remove, edit.background.remove;
   protect.password (окно пароля), protect.remove; export.image, export.text, export.docx (окна экспорта);
   comment.flatten, form.flatten, form.clear; edit.undo, edit.redo.
   Если не знаешь id — find_commands.
3. Удаление страниц и снятие защиты — только если пользователь прямо просил; перед удалением назови номера страниц.
4. После действия коротко скажи, что сделал, и что Ctrl+Z отменяет.
Правила безопасности:
- Не закрывай документы и не сохраняй их сам, если пользователь не просил.
- Делай не больше 10 действий подряд; если задача больше — сделай часть и спроси, продолжать ли.
- Если команда вернула ошибку (например, «disabled right now») — прочитай её и объясни, что мешает (нет документа, нет выделения, документ защищён).
TXT;
    }

    return $common . "\n\n" . <<<TXT
Режим «Совет»: ты только объясняешь и подсказываешь, ничего не меняешь сам.
Можно искать команды (find_commands), читать текст (read_pages), смотреть состояние (get_state) и экран (look_at_screen).
Объясняй, куда нажать и что выставить. Можешь пересказать документ, найти в нём нужное, проверить договор на риски.
Если пользователь просит сделать что-то за него — скажи, что для этого нужно переключиться в режим «Сделай» (кнопка вверху панели).
TXT;
}

/**
 * Каталог инструментов текстом — для системной подсказки. Некоторые шлюзы (например, vLLM без
 * парсера вызовов) не передают модели поле tools; тогда модель узнаёт о наших инструментах
 * только отсюда и пишет вызовы текстом.
 */
function studio_tool_catalog(array $tools): string
{
    $lines = [];
    foreach ($tools as $t) {
        $f = $t['function'] ?? [];
        $name = (string)($f['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $params = [];
        foreach (($f['parameters']['properties'] ?? []) as $p => $spec) {
            $params[] = $p . ': ' . ($spec['type'] ?? 'string');
        }
        $lines[] = '- ' . $name . '(' . implode(', ', $params) . ') — ' . (string)($f['description'] ?? '');
    }
    return implode("\n", $lines);
}

/**
 * Вызовы инструментов, которые модель написала текстом, а не штатным полем tool_calls:
 *   <tool_call><function=ИМЯ><parameter=КЛЮЧ>ЗНАЧЕНИЕ</parameter>…</function></tool_call>  (Qwen)
 *   <tool_call>{"name": "ИМЯ", "arguments": {…}}</tool_call>                             (Hermes)
 * Возвращает [вызовы, текст без этих блоков].
 */
function studio_text_tool_calls(string $content): array
{
    $calls = [];
    $value = static function (string $raw) {
        $raw = trim($raw);
        $j = json_decode($raw, true);
        return ($j !== null || $raw === 'null') ? $j : $raw;
    };
    // Qwen XML: с обёрткой <tool_call> или без неё. Прокси с vLLM иногда «съедает» начало
    // вызова («<tool_call>\n<»), и приходит «function=run_command>…» — это тоже понимаем,
    // как и вызов без закрывающих тегов в конце ответа.
    if (preg_match_all('~(?:<tool_call>\s*)?<?function=([A-Za-z0-9_.\-]+)>(.*?)(?:</function>(?:\s*</tool_call>)?|(?=<?function=[A-Za-z0-9_.\-]+>)|\z)~s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $args = [];
            if (preg_match_all('~<?parameter=([A-Za-z0-9_.\-]+)>(.*?)(?:</parameter>|(?=<?parameter=[A-Za-z0-9_.\-]+>)|(?=</function>)|\z)~s', $one[2], $pm, PREG_SET_ORDER)) {
                foreach ($pm as $p) {
                    $args[$p[1]] = $value($p[2]);
                }
            }
            $calls[] = ['function' => ['name' => $one[1], 'arguments' => $args ?: new stdClass()]];
            $content = str_replace($one[0], '', $content);
        }
    }
    // Hermes: JSON внутри <tool_call>.
    if (preg_match_all('~<tool_call>\s*(\{.*?\})\s*</tool_call>~s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $j = json_decode($one[1], true);
            if (is_array($j) && isset($j['name'])) {
                $args = $j['arguments'] ?? ($j['parameters'] ?? []);
                if (is_string($args)) {
                    $args = json_decode($args, true) ?: [];
                }
                $calls[] = ['function' => ['name' => (string)$j['name'], 'arguments' => $args ?: new stdClass()]];
                $content = str_replace($one[0], '', $content);
            }
        }
    }
    // Рассуждения модели в ответ не нужны.
    $content = preg_replace('~<think>.*?</think>~s', '', $content) ?? $content;
    $content = trim(str_replace(['<tool_call>', '</tool_call>'], '', $content));
    return [$calls, $content];
}
