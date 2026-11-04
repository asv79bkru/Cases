<?php

declare(strict_types=1);

/**
 * Веб-интерфейс разметки кейсов (:8081, рядом с upload.php) — здесь теги и метки «не кейс»
 * живут в базе (catalog.sqlite), а не в заметках докладчика Google Slides/pptx. Файл исходника
 * после этого не содержит служебных пометок вообще.
 *
 * Разметка — списком: каждая карточка — слайд; слева содержимое слайда (превью, заголовок,
 * текст, комментарии рецензентов — служебные пометки, в презентацию клиенту не попадают),
 * справа — утверждённые теги, предложенные (словарь/LLM,
 * принять/отклонить) и добавление новых тегов с автодополнением по справочнику. Инверсная
 * метка: по умолчанию слайд — кейс; эксперт отмечает только «не кейс» (обложка, о компании,
 * контакты). Вкладка «Теги» — правка справочника: переименование/слияние дублей вида
 * «авто»/«автопром», счётчики покрытия кейсов.
 *
 * Изменения сохраняются сразу по действию (fetch, POST + JSON). Доступ защищён тем же
 * HTTP Basic Auth, что и загрузчик (UPLOAD_USERNAME/UPLOAD_PASSWORD в .env) — без обоих
 * значений страница отказывает в доступе.
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Catalog\CatalogRepository;
use CasesBot\Catalog\TagTaxonomy;

$envPath = __DIR__ . '/../.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value));
        }
    }
}

$config = require __DIR__ . '/../config/config.php';

function requireAuth(array $config): void
{
    $username = $config['upload']['username'];
    $password = $config['upload']['password'];

    if ($username === '' || $password === '') {
        http_response_code(500);
        echo 'Веб-интерфейс разметки не настроен: задайте UPLOAD_USERNAME и UPLOAD_PASSWORD в .env.';
        exit;
    }

    $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $givenPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (!hash_equals($username, $givenUser) || !hash_equals($password, $givenPass)) {
        header('WWW-Authenticate: Basic realm="CasesBot"');
        http_response_code(401);
        echo 'Требуется авторизация.';
        exit;
    }
}

requireAuth($config);

$catalog = new CatalogRepository(
    $config['catalog']['storage_path'],
    __DIR__ . '/../storage/catalog/schema.sql',
    $config['catalog']['images_path']
);
$taxonomy = new TagTaxonomy($config['tags_taxonomy_path'], $config['tag_relations_path'] ?? null);

/** JSON-ответ и завершение (все POST-действия интерфейса). */
function jsonOut(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Состояние карточки слайда после мутации — ровно то, что перерисовывает JS. */
function cardState(CatalogRepository $catalog, int $caseId): array
{
    $case = $catalog->caseById($caseId);
    if ($case === null) {
        return ['ok' => false, 'error' => "Кейс #{$caseId} не найден (слайд удалён из исходника?)"];
    }

    return [
        'ok' => true,
        'case' => [
            'id' => (int) $case['id'],
            'slide_number' => (int) $case['slide_number'],
            'tags' => $case['tags'] !== null ? array_map('trim', explode(', ', $case['tags'])) : [],
            'reviewed' => (int) $case['tags_reviewed'] === 1,
            'is_not_case' => (int) $case['is_not_case'] === 1,
            'suggestions' => $catalog->pendingSuggestionsForCase($caseId),
        ],
    ];
}

// Превью слайда: картинки лежат в storage/catalog/images/ — каталог не отдан по HTTP,
// файл отдаём этим экшеном (basename-фильтр — только имя, без обхода путей).
if (($_GET['action'] ?? '') === 'image') {
    $imagesDir = rtrim((string) $config['catalog']['images_path'], '/\\');
    $file = basename((string) ($_GET['file'] ?? ''));
    $path = $imagesDir . '/' . $file;

    if ($file === '' || !is_file($path)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- POST-действия
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $caseId = (int) ($_POST['case_id'] ?? 0);

    switch ($action) {
        case 'save_tags':
            $tags = json_decode((string) ($_POST['tags'] ?? '[]'), true);
            if (!is_array($tags)) {
                jsonOut(['ok' => false, 'error' => 'Некорректный список тегов.']);
            }

            // Нормализация: «1с erp» -> «erp», регистр/пробелы — к канонической форме справочника.
            $tags = $taxonomy->normalizeTags(array_map('strval', $tags));

            $catalog->setCaseTags($caseId, $tags);

            // Предложения, совпавшие с сохранённым набором, — приняты, очередь по кейсу чистится.
            foreach ($catalog->pendingSuggestionsForCase($caseId) as $suggestion) {
                if (in_array(mb_strtolower($suggestion['tag']), array_map('mb_strtolower', $tags), true)) {
                    $catalog->resolveSuggestion($suggestion['id'], true);
                }
            }

            jsonOut(cardState($catalog, $caseId));

        case 'accept_suggestion':
            $catalog->resolveSuggestion((int) ($_POST['suggestion_id'] ?? 0), true);
            jsonOut(cardState($catalog, $caseId));

        case 'reject_suggestion':
            $catalog->resolveSuggestion((int) ($_POST['suggestion_id'] ?? 0), false);
            jsonOut(cardState($catalog, $caseId));

        case 'accept_all_suggestions':
            $catalog->acceptAllSuggestions($caseId);
            jsonOut(cardState($catalog, $caseId));

        case 'set_not_case':
            $catalog->setNotCase($caseId, ($_POST['value'] ?? '0') === '1');
            jsonOut(cardState($catalog, $caseId));

        case 'rename_tag':
            $tagId = (int) ($_POST['tag_id'] ?? 0);
            $newName = trim((string) ($_POST['name'] ?? ''));
            // Каноническая форма, если новое имя — известный тег/синоним.
            $newName = $taxonomy->normalize($newName) ?? $newName;

            try {
                $outcome = $catalog->renameTag($tagId, $newName);
            } catch (\InvalidArgumentException $e) {
                jsonOut(['ok' => false, 'error' => $e->getMessage()]);
            }

            jsonOut(['ok' => true, 'outcome' => $outcome, 'tags' => $catalog->allTagsWithCounts()]);

        case 'delete_tag':
            $catalog->deleteTag((int) ($_POST['tag_id'] ?? 0));
            jsonOut(['ok' => true, 'tags' => $catalog->allTagsWithCounts()]);

        default:
            jsonOut(['ok' => false, 'error' => 'Неизвестное действие.']);
    }
}

// ---------------------------------------------------------------- GET: страница
$presentations = $catalog->presentationsInCatalog();

$currentFile = (string) ($_GET['file'] ?? '');
if ($currentFile === '' || !in_array($currentFile, array_column($presentations, 'source_file_id'), true)) {
    $currentFile = $presentations[0]['source_file_id'] ?? '';
}

$cases = $currentFile !== '' ? $catalog->casesForFile($currentFile) : [];
$pendingByCase = $catalog->pendingSuggestionsForCases(array_map(
    static fn (array $case): int => (int) $case['id'],
    $cases
));

// Справочник для автодополнения: канонические теги config/tags.php + теги из базы.
$knownTags = array_values(array_unique(array_merge(
    $taxonomy->canonicalTags(),
    array_map(static fn (array $row): string => $row['name'], $catalog->allTagsWithCounts())
)));
sort($knownTags, SORT_NATURAL | SORT_FLAG_CASE);

$imageByCase = [];
foreach ($cases as $case) {
    $images = $catalog->imagesForCase((int) $case['id']);
    if ($images !== []) {
        $imageByCase[(int) $case['id']] = basename($images[0]);
    }
}

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Разметка кейсов — CasesBot</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1100px; margin: 1.5rem auto; padding: 0 1rem; color: #222; }
  h1 { font-size: 1.3rem; }
  h2 { font-size: 1.1rem; }
  .nav { margin-bottom: 1rem; }
  .card { display: flex; gap: 1rem; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 0.8rem 0; }
  .card.not-case { opacity: 0.55; background: #f5f5f5; }
  .card-left { flex: 1 1 55%; min-width: 0; }
  .card-right { flex: 1 1 45%; min-width: 0; }
  .card-left img.preview { max-width: 100%; max-height: 260px; display: block; margin-bottom: 0.5rem; border: 1px solid #eee; }
  .card-title { font-weight: 600; margin-bottom: 0.3rem; }
  .slide-text { white-space: pre-wrap; font-size: 0.85rem; max-height: 140px; overflow: hidden; cursor: pointer; }
  .slide-text.expanded { max-height: none; }
  .reviewer-comments { margin-top: 0.5rem; padding: 0.5rem; background: #fff8e1; border: 1px solid #f0e0a0; border-radius: 6px; font-size: 0.8rem; white-space: pre-wrap; }
  .badge { display: inline-block; font-size: 0.7rem; padding: 0.1rem 0.5rem; border-radius: 10px; margin-left: 0.4rem; vertical-align: middle; }
  .badge.ok { background: #eaf7ea; border: 1px solid #b6dfb6; }
  .badge.warn { background: #fdf1d7; border: 1px solid #ecd28a; }
  .badge.dim { background: #eee; border: 1px solid #ccc; }
  .tag-zone h3 { font-size: 0.8rem; margin: 0.5rem 0 0.3rem; color: #555; text-transform: uppercase; }
  .chips { display: flex; flex-wrap: wrap; gap: 0.3rem; }
  .chip { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.15rem 0.5rem; border-radius: 12px; font-size: 0.85rem; }
  .chip.approved { background: #eaf2fb; border: 1px solid #b3cbe8; }
  .chip.suggested { background: #fdf6e3; border: 1px dashed #d9c07a; }
  .chip button {
    border: none; background: none; cursor: pointer;
    padding: 0.15rem 0.45rem; font-size: 1rem; line-height: 1;
    min-width: 1.6rem; min-height: 1.6rem;
  }
  .chip button.accept { color: #1a7f2e; font-weight: 700; }
  .chip button.reject { color: #b02a2a; }
  .add-tag { display: flex; gap: 0.4rem; margin-top: 0.6rem; }
  .add-tag input { flex: 1; padding: 0.25rem 0.5rem; }
  .row-actions { margin-top: 0.6rem; display: flex; gap: 0.6rem; align-items: center; font-size: 0.85rem; }
  .filters { display: flex; gap: 1rem; align-items: center; margin: 0.5rem 0 1rem; font-size: 0.9rem; }
  select, button { padding: 0.3rem 0.6rem; }
  button { cursor: pointer; }
  .muted { color: #777; font-size: 0.85rem; }
  table { border-collapse: collapse; width: 100%; margin-top: 0.8rem; }
  th, td { text-align: left; padding: 0.3rem 0.6rem; border-bottom: 1px solid #eee; font-size: 0.9rem; }
  td form, td .inline { display: flex; gap: 0.4rem; margin: 0; padding: 0; }
  td input[type="text"] { padding: 0.15rem 0.4rem; }
</style>
</head>
<body>

<h1>Разметка кейсов</h1>
<div class="nav">
  <a href="?tab=cases<?= $currentFile !== '' ? '&file=' . urlencode($currentFile) : '' ?>">Разметка</a> ·
  <a href="?tab=tags">Справочник тегов</a> ·
  <a href="/upload.php" target="_blank">Загрузка презентаций</a>
</div>

<?php if (($_GET['tab'] ?? 'cases') === 'tags'): ?>

<h2>Справочник тегов</h2>
<p class="muted">
  Частоты показывают покрытие: тег с одним-двумя кейсами — кандидат в синонимы соседнего
  (слейте их переименованием в существующее имя). Переименование в имя существующего тега
  выполняет слияние: все кейсы переезжают на целевой тег.
</p>
<table id="tag-table">
  <tr><th>Тег</th><th>Кейсов</th><th>Действия</th></tr>
  <?php foreach ($catalog->allTagsWithCounts() as $tag): ?>
  <tr data-tag-id="<?= (int) $tag['id'] ?>">
    <td class="tag-name"><?= htmlspecialchars((string) $tag['name'], ENT_QUOTES) ?></td>
    <td class="tag-count"><?= (int) $tag['case_count'] ?></td>
    <td>
      <span class="inline">
        <input type="text" class="rename-input" placeholder="новое имя или имя существующего">
        <button onclick="renameTag(this)">Переименовать/слить</button>
        <button onclick="deleteTag(this)">Удалить</button>
      </span>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="muted" id="tag-message"></p>

<script>
function tagApi(action, data) {
  const form = new FormData();
  form.append('action', action);
  for (const [key, value] of Object.entries(data)) {
    form.append(key, value);
  }
  return fetch('', { method: 'POST', body: form }).then((response) => response.json());
}

function renameTag(button) {
  const row = button.closest('tr');
  const name = row.querySelector('.rename-input').value.trim();
  if (!name) {
    document.getElementById('tag-message').textContent = 'Введите новое имя тега.';
    return;
  }
  tagApi('rename_tag', { tag_id: row.dataset.tagId, name })
    .then((result) => {
      if (!result.ok) {
        document.getElementById('tag-message').textContent = result.error;
        return;
      }
      document.getElementById('tag-message').textContent = result.outcome === 'merged'
        ? 'Тег с таким именем уже существовал — кейсы переехали на него (слияние).'
        : 'Тег переименован.';
      setTimeout(() => location.reload(), 800);
    });
}

function deleteTag(button) {
  const row = button.closest('tr');
  if (!confirm('Удалить тег? Кейсы потеряют эту пометку.')) {
    return;
  }
  tagApi('delete_tag', { tag_id: row.dataset.tagId }).then(() => location.reload());
}
</script>

<?php else: ?>

<?php if ($presentations === []): ?>
<p>Каталог пуст — загрузите презентацию и запустите переиндексацию на странице загрузки.</p>
<?php else: ?>

<div class="filters">
  <label>Презентация:
    <select onchange="location.href = '?tab=cases&file=' + encodeURIComponent(this.value)">
      <?php foreach ($presentations as $p): ?>
      <option value="<?= htmlspecialchars($p['source_file_id'], ENT_QUOTES) ?>"
        <?= $p['source_file_id'] === $currentFile ? 'selected' : '' ?>>
        <?= htmlspecialchars($p['source_file_name'], ENT_QUOTES) ?>
        (<?= $p['total'] ?> сл., размечено <?= $p['reviewed'] ?><?= $p['pending'] > 0 ? ', предложений ' . $p['pending'] : '' ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><input type="checkbox" id="hide-reviewed"> скрыть размеченные</label>
  <label><input type="checkbox" id="show-not-case" checked> показывать «не кейс»</label>
</div>

<div id="cards">
<?php foreach ($cases as $case): ?>
  <?php
    $caseId = (int) $case['id'];
    $tags = $case['tags'] !== null ? array_map('trim', explode(', ', $case['tags'])) : [];
    $suggestions = $pendingByCase[$caseId] ?? [];
    $notCase = (int) $case['is_not_case'] === 1;
    $reviewed = (int) $case['tags_reviewed'] === 1;
  ?>
  <div class="card<?= $notCase ? ' not-case' : '' ?>" id="card-<?= $caseId ?>"
       data-id="<?= $caseId ?>"
       data-tags='<?= json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
       data-suggestions='<?= json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
       data-not-case="<?= $notCase ? '1' : '0' ?>" data-reviewed="<?= $reviewed ? '1' : '0' ?>">
    <div class="card-left">
      <div class="card-title">
        Слайд <?= (int) $case['slide_number'] ?>
        <?php if ($reviewed): ?><span class="badge ok">размечено</span>
        <?php elseif ($suggestions !== []): ?><span class="badge warn">есть предложения</span>
        <?php else: ?><span class="badge dim">не размечено</span><?php endif; ?>
        <?php if ($notCase): ?><span class="badge dim">не кейс</span><?php endif; ?>
      </div>
      <?php if (isset($imageByCase[$caseId])): ?>
      <img class="preview" src="?action=image&file=<?= urlencode($imageByCase[$caseId]) ?>" alt="превью слайда">
      <?php endif; ?>
      <?php if (($case['title'] ?? '') !== ''): ?>
      <div class="muted">Заголовок: <?= htmlspecialchars((string) $case['title'], ENT_QUOTES) ?></div>
      <?php endif; ?>
      <div class="slide-text" onclick="this.classList.toggle('expanded')"><?= htmlspecialchars((string) ($case['content'] ?? ''), ENT_QUOTES) ?></div>
      <?php if (($case['reviewer_comments'] ?? '') !== ''): ?>
      <div class="reviewer-comments"><b>Комментарии рецензентов</b> <span class="muted">(служебный комментарий, в презентацию клиенту не попадает):</span>
<?= htmlspecialchars((string) $case['reviewer_comments'], ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
    <div class="card-right">
      <div class="tag-zone">
        <h3>Утверждённые теги</h3>
        <div class="chips approved-chips"></div>
        <h3>Предложенные (словарь / LLM)</h3>
        <div class="chips suggested-chips"></div>
        <div class="add-tag">
          <input type="text" class="new-tag" list="known-tags" placeholder="добавить тег…"
                 onkeydown="if (event.key === 'Enter') addTag(this)">
          <button onclick="addTag(this.previousElementSibling)">+</button>
        </div>
        <div class="row-actions">
          <button onclick="acceptAll(this)">Принять все предложения</button>
          <label><input type="checkbox" class="not-case-toggle" onchange="setNotCase(this)"
            <?= $notCase ? 'checked' : '' ?>> это НЕ кейс</label>
        </div>
        <p class="muted status" style="margin-bottom:0"></p>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<datalist id="known-tags">
  <?php foreach ($knownTags as $tag): ?>
  <option value="<?= htmlspecialchars($tag, ENT_QUOTES) ?>">
  <?php endforeach; ?>
</datalist>

<script>
const KNOWN_TAGS = <?= json_encode($knownTags, JSON_UNESCAPED_UNICODE) ?>;

function api(action, data) {
  const form = new FormData();
  form.append('action', action);
  for (const [key, value] of Object.entries(data)) {
    form.append(key, value);
  }
  return fetch('', { method: 'POST', body: form })
    .then((response) => response.json());
}

function renderCard(card) {
  const tags = JSON.parse(card.dataset.tags);
  const suggestions = JSON.parse(card.dataset.suggestions);
  const notCase = card.dataset.notCase === '1';

  card.classList.toggle('not-case', notCase);

  const approved = card.querySelector('.approved-chips');
  approved.innerHTML = '';
  for (const tag of tags) {
    const chip = document.createElement('span');
    chip.className = 'chip approved';
    chip.append(tag);
    const remove = document.createElement('button');
    remove.textContent = '×';
    remove.title = 'убрать тег';
    remove.onclick = () => {
      card.dataset.tags = JSON.stringify(tags.filter((t) => t !== tag));
      saveTags(card);
    };
    chip.append(remove);
    approved.append(chip);
  }
  if (tags.length === 0) {
    approved.innerHTML = '<span class="muted">нет</span>';
  }

  const suggested = card.querySelector('.suggested-chips');
  suggested.innerHTML = '';
  for (const s of suggestions) {
    const chip = document.createElement('span');
    chip.className = 'chip suggested';
    const label = document.createElement('span');
    label.textContent = s.tag;
    label.title = 'источник: ' + (s.source === 'llm' ? 'LLM' : 'словарь');
    chip.append(label);
    const accept = document.createElement('button');
    accept.textContent = '+';
    accept.className = 'accept';
    accept.title = 'принять';
    accept.onclick = () => api('accept_suggestion', { case_id: card.dataset.id, suggestion_id: s.id })
      .then((result) => applyState(card, result));
    const reject = document.createElement('button');
    reject.textContent = '×';
    reject.className = 'reject';
    reject.title = 'отклонить';
    reject.onclick = () => api('reject_suggestion', { case_id: card.dataset.id, suggestion_id: s.id })
      .then((result) => applyState(card, result));
    chip.append(accept, reject);
    suggested.append(chip);
  }
  if (suggestions.length === 0) {
    suggested.innerHTML = '<span class="muted">нет</span>';
  }

  const toggle = card.querySelector('.not-case-toggle');
  if (toggle) {
    toggle.checked = notCase;
  }

  // Фильтры видимости
  const hideReviewed = document.getElementById('hide-reviewed');
  const showNotCase = document.getElementById('show-not-case');
  const reviewed = card.dataset.reviewed === '1';
  card.style.display = ((hideReviewed && hideReviewed.checked && reviewed) || (!showNotCase || !showNotCase.checked) && notCase)
    ? 'none' : '';
}

function applyState(card, result) {
  if (!result.ok) {
    card.querySelector('.status').textContent = result.error || 'ошибка';
    return;
  }
  const c = result.case;
  card.dataset.tags = JSON.stringify(c.tags);
  card.dataset.suggestions = JSON.stringify(c.suggestions);
  card.dataset.notCase = c.is_not_case ? '1' : '0';
  card.dataset.reviewed = c.reviewed ? '1' : '0';
  renderCard(card);
}

function saveTags(card) {
  api('save_tags', { case_id: card.dataset.id, tags: card.dataset.tags })
    .then((result) => applyState(card, result));
}

function levenshtein(a, b) {
  const m = a.length, n = b.length;
  if (!m) return n;
  if (!n) return m;
  let prev = Array.from({ length: n + 1 }, (_, i) => i);
  for (let i = 1; i <= m; i++) {
    const curr = [i];
    for (let j = 1; j <= n; j++) {
      curr[j] = Math.min(
        prev[j] + 1,
        curr[j - 1] + 1,
        prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)
      );
    }
    prev = curr;
  }
  return prev[n];
}

function findSimilar(tag) {
  const t = tag.toLowerCase();
  let best = null, bestScore = 0;
  for (const known of KNOWN_TAGS) {
    const k = known.toLowerCase();
    if (k === t) continue;
    let score;
    if (k.includes(t) || t.includes(k)) {
      score = 0.9;
    } else {
      score = 1 - levenshtein(t, k) / Math.max(t.length, k.length);
    }
    if (score > bestScore) { bestScore = score; best = known; }
  }
  return bestScore >= 0.8 ? best : null;
}

function addTag(input) {
  const value = input.value.trim();
  if (!value) return;

  const card = input.closest('.card');
  const tags = JSON.parse(card.dataset.tags);

  const exists = tags.find((t) => t.toLowerCase() === value.toLowerCase());
  if (exists) {
    input.value = '';
    return;
  }

  const similar = findSimilar(value);
  const finalTag = (similar && confirm('Похоже на существующий тег «' + similar + '». Использовать его?'))
    ? similar
    : value;

  tags.push(finalTag);
  card.dataset.tags = JSON.stringify(tags);
  input.value = '';
  saveTags(card);
}

function acceptAll(button) {
  const card = button.closest('.card');
  api('accept_all_suggestions', { case_id: card.dataset.id })
    .then((result) => applyState(card, result));
}

function setNotCase(checkbox) {
  const card = checkbox.closest('.card');
  api('set_not_case', { case_id: card.dataset.id, value: checkbox.checked ? '1' : '0' })
    .then((result) => applyState(card, result));
}

document.getElementById('hide-reviewed').addEventListener('change', () => {
  document.querySelectorAll('.card').forEach(renderCard);
});
document.getElementById('show-not-case').addEventListener('change', () => {
  document.querySelectorAll('.card').forEach(renderCard);
});

document.querySelectorAll('.card').forEach(renderCard);
</script>

<?php endif; ?>
<?php endif; ?>

</body>
</html>