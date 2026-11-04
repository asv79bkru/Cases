<?php

declare(strict_types=1);

/**
 * Веб-загрузчик презентаций (§5.1.8 ТЗ v1.2) — простая страница для эксперта: загрузить .pptx
 * в presentations/ (та же презентация под тем же именем = новая версия, перезаписывает старую)
 * и кнопкой запустить переиндексацию (Indexer::runAutomatic — без ручного подтверждения по
 * каждому слайду, источник тегов теперь заметки докладчика, см. Indexer, ТЗ §5.1.8).
 *
 * Отдельный процесс php -S (см. docker/entrypoint.sh, :8081) — presentations/ на :8080 отдаёт
 * только статику для прямых ссылок из чата, этот файл живёт в public/.
 *
 * Защищено HTTP Basic Auth (UPLOAD_USERNAME/UPLOAD_PASSWORD в .env) — без них страница отказывает
 * в доступе: без пароля сюда мог бы зайти кто угодно, кто достучится до порта, и подменить любую
 * презентацию или запустить переиндексацию.
 */

require __DIR__ . '/../vendor/autoload.php';

use CasesBot\Presentation\PublicCopyBuilder;
use CasesBot\Presentation\SlideCloner;
use CasesBot\Storage\LocalPresentationsClient;

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
        echo 'Веб-загрузчик не настроен: задайте UPLOAD_USERNAME и UPLOAD_PASSWORD в .env.';
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

$presentations = new LocalPresentationsClient($config['presentations']['folder_path'], '', $config['presentations']['public_name_overrides'] ?? []);

/** @return array{ok: bool, message: string} */
function handleUpload(array $config): array
{
    if (!isset($_FILES['presentation']) || $_FILES['presentation']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['presentation']['error'] ?? UPLOAD_ERR_NO_FILE;

        return ['ok' => false, 'message' => "Файл не загружен (код ошибки {$code})."];
    }

    $originalName = basename($_FILES['presentation']['name']);
    if (!str_ends_with(strtolower($originalName), '.pptx')) {
        return ['ok' => false, 'message' => 'Ожидается файл .pptx.'];
    }

    $destination = rtrim($config['presentations']['folder_path'], '/\\') . '/' . $originalName;
    $isNewVersion = is_file($destination);

    if (!move_uploaded_file($_FILES['presentation']['tmp_name'], $destination)) {
        return ['ok' => false, 'message' => "Не удалось сохранить файл «{$originalName}»."];
    }

    $verb = $isNewVersion ? 'обновлена новой версией' : 'загружена';

    return ['ok' => true, 'message' => "Презентация «{$originalName}» {$verb}. Теперь можно запустить переиндексацию."];
}

/** @return array{ok: bool, message: string} */
function handleReindex(array $config): array
{
    $report = \CasesBot\Catalog\Reindexer::fromConfig($config)->runAutomatic();
    $indexed = $report['indexed'];
    $llmErrors = $report['llmErrors'];

    // Пересборка «парадных» копий (без заметок/комментариев) — они отдаются по :8080 как ссылки
    // из чата; сырой файл с пометками наружу не выходит (см. PublicCopyBuilder).
    $publicErrors = updatePublicCopies($config);

    $statusLabel = [
        'new' => 'новый',
        'moved' => 'переставлен',
        'updated' => 'обновлён',
        'unchanged' => 'без изменений',
    ];

    $lines = array_map(
        static function (array $row): string {
            $tags = $row['tags'] !== [] ? implode(', ', $row['tags']) : '—';
            $suggestions = $row['suggestions'] > 0 ? " (+{$row['suggestions']} предл.)" : '';
            $status = $statusLabel[$row['status']] ?? $row['status'];

            return htmlspecialchars(
                "{$row['file']}, слайд {$row['slide']} [{$status}]: "
                    . ($row['title'] !== '' ? $row['title'] : '(без названия)')
                    . " [теги: {$tags}]{$suggestions}",
                ENT_QUOTES
            );
        },
        $indexed
    );

    $message = 'Переиндексация завершена: обработано ' . count($indexed) . " слайд(ов).\n" . implode("\n", $lines);
    $pendingTotal = array_sum(array_map(static fn (array $row): int => $row['suggestions'], $indexed));
    if ($pendingTotal > 0) {
        $message .= "\n\nНовые предложения тегов ждут подтверждения в интерфейсе разметки (теги, порт :8081).";
    }
    if ($llmErrors !== []) {
        $message .= "\n\nLLM не смогла предложить теги для некоторых слайдов:\n" . implode("\n", array_unique($llmErrors));
    }
    if ($publicErrors !== []) {
        $message .= "\n\nНе получилось пересобрать публичные копии (ссылку в чате мог получить незачищенный файл — проверьте):\n"
            . implode("\n", $publicErrors);
    }

    return ['ok' => true, 'message' => $message];
}

/** Пересобирает публичные копии презентаций; строки ошибок — наружу в отчёт страницы. @return string[] */
function updatePublicCopies(array $config): array
{
    try {
        $builder = new PublicCopyBuilder(
            new SlideCloner($config['python']['bin'], $config['python']['slide_cloner']),
            $config['presentations']['folder_path'],
            $config['presentations']['public_folder_path']
        );

        return $builder->rebuildAll($config['presentations']['public_name_overrides'] ?? [])['errors'];
    } catch (\Throwable $e) {
        return ["{$e->getMessage()}"];
    }
}

/** @return array{ok: bool, message: string} */
function handleDelete(array $config): array
{
    $presentations = new LocalPresentationsClient($config['presentations']['folder_path'], '', $config['presentations']['public_name_overrides'] ?? []);
    $id = basename($_POST['file'] ?? '');

    if ($id === '') {
        return ['ok' => false, 'message' => 'Файл не указан.'];
    }

    try {
        $presentations->deleteFile($id);
        // Сырой файл удалён — публичная копия без пометок тоже больше не нужна.
        $publicErrors = updatePublicCopies($config);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => "Не удалось удалить «{$id}»: " . $e->getMessage()];
    }

    $message = "Презентация «{$id}» удалена.";
    if ($publicErrors !== []) {
        $message .= "\nНо не удалось обновить публичные копии:\n" . implode("\n", $publicErrors);
    }

    return ['ok' => true, 'message' => $message];
}

function formatFileSize(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' МБ';
    }

    return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $result = match ($action) {
        'upload' => handleUpload($config),
        'reindex' => handleReindex($config),
        'delete' => handleDelete($config),
        default => ['ok' => false, 'message' => 'Неизвестное действие.'],
    };
}

$files = $presentations->listPresentations();

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Загрузка презентаций — CasesBot</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #222; }
  h1 { font-size: 1.4rem; }
  form { margin: 1.5rem 0; padding: 1rem; border: 1px solid #ddd; border-radius: 8px; }
  .result { padding: 1rem; border-radius: 8px; white-space: pre-wrap; font-family: monospace; font-size: 0.85rem; }
  .result.ok { background: #eaf7ea; border: 1px solid #b6dfb6; }
  .result.error { background: #fbeaea; border: 1px solid #e3b6b6; }
  table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
  th, td { text-align: left; padding: 0.3rem 0.6rem; border-bottom: 1px solid #eee; font-size: 0.9rem; }
  td form { margin: 0; padding: 0; border: none; }
  button { padding: 0.5rem 1rem; cursor: pointer; }
  td button { padding: 0.2rem 0.6rem; font-size: 0.85rem; }
</style>
</head>
<body>

<h1>Загрузка презентаций CasesBot</h1>

<p>См. также: <a href="/tags.php">интерфейс разметки кейсов</a> — теги, метки «не кейс», предложения LLM.</p>

<?php if ($result !== null): ?>
<div class="result <?= $result['ok'] ? 'ok' : 'error' ?>"><?= htmlspecialchars($result['message'], ENT_QUOTES) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <p>Загрузить .pptx (то же имя файла = новая версия существующей презентации):</p>
  <input type="file" name="presentation" accept=".pptx" required>
  <button type="submit" name="action" value="upload">Загрузить</button>
</form>

<form method="post" onsubmit="return confirm('Переиндексировать все презентации? Это может занять некоторое время.');">
  <p>После загрузки новой версии запустите переиндексацию каталога:</p>
  <button type="submit" name="action" value="reindex">Запустить переиндексацию</button>
</form>

<h2>Презентации на сервере</h2>
<table>
<tr><th>Файл</th><th>Размер</th><th>Изменён</th><th></th></tr>
<?php foreach ($files as $file): ?>
<tr>
  <td><?= htmlspecialchars($file['name'], ENT_QUOTES) ?></td>
  <td><?= htmlspecialchars(formatFileSize($file['size']), ENT_QUOTES) ?></td>
  <td><?= htmlspecialchars($file['modifiedTime'], ENT_QUOTES) ?></td>
  <td>
    <form method="post" onsubmit="return confirm('Удалить презентацию «<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>»?');">
      <input type="hidden" name="file" value="<?= htmlspecialchars($file['name'], ENT_QUOTES) ?>">
      <button type="submit" name="action" value="delete">Удалить</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table>

<h2>Исходник презентации, в который вносим правки:</h2>
<a href="https://docs.google.com/presentation/d/1tZ_CMsn_hxzeL-ActyXWuFlTA_ZjONZ2Cewmz8UgbaA/edit?slide=id.g3d9787e8b57_0_7#slide=id.g3d9787e8b57_0_7" target="_blank">Открыть в Google Slides</a>

</body>
</html>
