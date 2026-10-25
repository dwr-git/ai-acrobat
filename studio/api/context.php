<?php
// studio/api/context.php — ИИ-помощник у выделения («ИИ» возле выделенного текста и
// «ИИ-помощник…» в контекстном меню). Подключается из assistant.php для запросов
// {mode: "text", op, custom?, extra?, selection, document}.
//
// Модель работает в контексте всего документа, а меняет только выделенный фрагмент.
// Большой документ передаётся не целиком: начало, оглавление (короткие строки-заголовки)
// и окно вокруг выделения.

declare(strict_types=1);

/** Сколько токенов документа (≈ символов / 3,5) отдавать целиком. */
const STUDIO_CONTEXT_TOKENS = 16000;
/** Сколько символов брать по обе стороны от выделения в большом документе. */
const STUDIO_CONTEXT_WINDOW = 3000;
/** Больше этого текст документа не принимаем вовсе. */
const STUDIO_CONTEXT_MAX_CHARS = 2000000;

/** Задания операций меню. Ключи — id пунктов меню в studio.js. */
function studio_context_prompt(string $op): ?string
{
    $only = ' Верни ТОЛЬКО результат, без вступлений и пояснений.';
    $p = [
        'improve' => 'Улучши текст: сделай его ясным и выразительным без потери смысла. Сохрани терминологию, факты и логику.' . $only,
        'rephrase' => 'Переформулируй текст другими словами, полностью сохранив смысл.' . $only,
        'simplify' => 'Упрости текст: короткие предложения, понятные слова, без потери сути.' . $only,
        'shorten' => 'Сократи текст на 30–50 %, сохранив ключевые мысли.' . $only,
        'expand' => 'Разверни текст: добавь подробностей, пояснений и уместных примеров. Не выдумывай фактов.' . $only,
        'fix_grammar' => 'Исправь орфографические, пунктуационные и грамматические ошибки. Больше ничего не меняй.' . $only,
        'formalize' => 'Перепиши в официально-деловом стиле (как в служебных документах).' . $only,
        'casualize' => 'Перепиши живым разговорным языком.' . $only,
        'academic' => 'Перепиши в научном стиле.' . $only,
        'marketing' => 'Перепиши как рекламный текст: выгоды, эмоции, призыв к действию.' . $only,
        'summarize' => 'Составь краткое содержание: 4–8 пунктов с главными мыслями, маркированным списком Markdown.' . $only,
        'tldr' => 'Перескажи суть в 2–3 предложениях.' . $only,
        'explain' => 'Объясни этот текст как хороший учитель: простыми словами, с примерами и аналогиями, по шагам.',
        'atomic_meaning' => 'Сформулируй главный смысл текста одним ясным предложением.' . $only,
        'key_points' => 'Выдели ключевые тезисы: 3–7 пунктов маркированным списком Markdown.' . $only,
        'questions' => 'Составь 5–10 содержательных вопросов по тексту, нумерованным списком Markdown.' . $only,
        'check_spelling' => 'Проверь орфографию и пунктуацию. Перечисли ошибки списком Markdown: «было → стало» и коротко почему. Если ошибок нет, напиши «Ошибок не найдено».',
        'check_style' => 'Сделай стилистический разбор: тавтологии, канцелярит, слабые слова, повторы. Для каждого — фрагмент и предлагаемая правка, списком Markdown.',
        'check_logic' => 'Найди логические противоречия и пробелы в рассуждении. Для каждого — фрагмент и суть проблемы. Если их нет, напиши «Противоречий не найдено».',
        'check_cohesion' => 'Оцени связность текста по шкале от 1 до 10, объясни оценку и дай конкретные советы.',
        'check_facts' => 'Отметь утверждения, которые стоит проверить: для каждого — почему и что именно проверить. Списком Markdown.',
        'to_list' => 'Преобразуй текст в маркированный список Markdown (строки вида «- пункт»).' . $only,
        'to_table' => 'Преобразуй текст в таблицу Markdown с заголовками столбцов.' . $only,
        'steps' => 'Разложи текст на пронумерованные шаги (1. 2. 3.).' . $only,
        'fix_format' => 'Улучши структуру: абзацы, подзаголовки (## Заголовок), списки. Используй Markdown.' . $only,
        'continue' => 'Напиши естественное продолжение текста в том же стиле: 1–3 абзаца.' . $only,
        'counterargument' => 'Сформулируй аргументированный контраргумент в уважительном тоне.',
        'examples' => 'Приведи 3–5 конкретных примеров, иллюстрирующих текст, маркированным списком Markdown.' . $only,
        'title' => 'Предложи 5 вариантов заголовка для этого текста, нумерованным списком.' . $only,
    ];
    $langs = ['translate_ru' => 'русский', 'translate_en' => 'английский', 'translate_be' => 'белорусский',
        'translate_de' => 'немецкий', 'translate_fr' => 'французский', 'translate_es' => 'испанский', 'translate_zh' => 'китайский'];
    if (isset($langs[$op])) {
        return "Переведи текст на {$langs[$op]} язык. Сохрани форматирование, термины и стиль." . $only;
    }
    return $p[$op] ?? null;
}

/** Операции, которым без выделения нужен весь документ. */
function studio_context_whole_doc_ops(): array
{
    return ['continue', 'summarize', 'tldr', 'key_points', 'check_logic', 'check_cohesion', 'check_facts',
        'check_spelling', 'check_style', 'questions', 'explain', 'atomic_meaning', 'fix_format', 'title'];
}

/** Документ целиком или (если велик) начало, оглавление и окно вокруг выделения. */
function studio_context_block(string $doc, string $selected): array
{
    $len = mb_strlen($doc, 'UTF-8');
    if ((int)($len / 3.5) <= STUDIO_CONTEXT_TOKENS) {
        return ['full', "=== ДОКУМЕНТ ЦЕЛИКОМ ===\n" . $doc . "\n=== КОНЕЦ ДОКУМЕНТА ===\n"];
    }
    $anchor = (int)($len / 2);
    if ($selected !== '') {
        $probe = mb_substr($selected, 0, 200, 'UTF-8');
        $p = mb_strpos($doc, $probe, 0, 'UTF-8');
        if ($p !== false) {
            $anchor = $p + (int)(mb_strlen($selected, 'UTF-8') / 2);
        }
    }
    $start = max(0, $anchor - STUDIO_CONTEXT_WINDOW);
    $end = min($len, $anchor + STUDIO_CONTEXT_WINDOW);
    $toc = [];
    foreach (preg_split('/\R/u', $doc) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || mb_strlen($line, 'UTF-8') >= 100) {
            continue;
        }
        if (mb_strtoupper($line, 'UTF-8') === $line || !preg_match('/[.!?;:,]$/u', $line)) {
            $toc[] = '• ' . $line;
        }
        if (count($toc) >= 40) {
            break;
        }
    }
    $block = "=== ДОКУМЕНТ БОЛЬШОЙ: ПОКАЗАНЫ НАЧАЛО, ОГЛАВЛЕНИЕ И ФРАГМЕНТ ВОКРУГ ВЫДЕЛЕНИЯ ===\n"
        . "--- Начало ---\n" . mb_substr($doc, 0, 800, 'UTF-8') . "\n"
        . "--- Оглавление ---\n" . implode("\n", $toc) . "\n"
        . "--- Фрагмент (символы {$start}–{$end} из {$len}) ---\n" . mb_substr($doc, $start, $end - $start, 'UTF-8') . "\n=== КОНЕЦ ===\n";
    return ['windowed', $block];
}

/**
 * Системная инструкция и сообщения для модели по запросу панели.
 * Возвращает [system, messages, contextMode]; при ошибке завершает запрос через studio_fail.
 */
function studio_context_request(array $in): array
{
    $op = is_string($in['op'] ?? null) ? trim($in['op']) : '';
    $custom = is_string($in['custom'] ?? null) ? trim(mb_substr($in['custom'], 0, 4000, 'UTF-8')) : '';
    $extra = is_string($in['extra'] ?? null) ? trim(mb_substr($in['extra'], 0, 2000, 'UTF-8')) : '';
    $selected = is_string($in['selection'] ?? null) ? $in['selection'] : '';
    $doc = is_string($in['document'] ?? null) ? $in['document'] : '';
    if (mb_strlen($doc, 'UTF-8') > STUDIO_CONTEXT_MAX_CHARS) {
        $doc = mb_substr($doc, 0, STUDIO_CONTEXT_MAX_CHARS, 'UTF-8');
    }
    $selected = mb_substr($selected, 0, 60000, 'UTF-8');

    if ($custom !== '') {
        $task = $custom;
    } else {
        $task = studio_context_prompt($op);
        if ($task === null) {
            studio_fail(400, 'Неизвестная операция: ' . $op);
        }
    }
    if ($extra !== '') {
        $task .= "\n\nДополнительное пожелание: " . $extra;
    }

    $system = implode("\n", [
        'Ты — ИИ-помощник, встроенный в программу для PDF AI-Acrobat (по возможностям как Adobe Acrobat).',
        'Ты видишь текст PDF-документа целиком (или его часть), но работаешь только с указанным фрагментом, если он задан. Текст извлечён из PDF: переносы строк могут быть случайными.',
        'Отвечай без вступлений вроде «Вот ваш текст:», без извинений и комментариев о себе.',
        'Сохраняй язык исходного текста, если задача — не перевод.',
        'Для списков, таблиц и подзаголовков используй Markdown; обычный текст пиши обычными абзацами.',
    ]);
    [$mode, $context] = studio_context_block($doc, $selected);
    if ($selected !== '') {
        $target = "=== ФРАГМЕНТ, С КОТОРЫМ РАБОТАЕМ ===\n" . $selected . "\n=== КОНЕЦ ФРАГМЕНТА ===\n\n"
            . 'Выполни задачу ТОЛЬКО для этого фрагмента; документ выше — контекст.';
    } elseif (in_array($op, studio_context_whole_doc_ops(), true) || $custom !== '') {
        $target = 'Выделения нет: работай с документом целиком (или с показанным фрагментом).';
    } else {
        $target = 'Выделения нет: выполни задачу для показанного текста.';
    }
    $user = ($doc !== '' ? $context . "\n" : '') . $target . "\n\n=== ЗАДАЧА ===\n" . $task;
    return [$system, [['role' => 'user', 'content' => $user]], $mode];
}
