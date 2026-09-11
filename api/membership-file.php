<?php
/**
 * Скачивание файла заявления по подписанной ссылке из письма координаторам.
 * Ссылку формирует membership.php: ?id=<номер>&f=<файл>&e=<срок>&s=<HMAC-SHA256>.
 * Секрет — …/private/membership-secret (вне веб-корня). Без верной подписи и до истечения срока файл не отдаётся.
 */
declare(strict_types=1);

function deny(int $code, string $text): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo $text;
    exit;
}

$PRIVATE = dirname(__DIR__, 2) . '/private';
$id  = (string) ($_GET['id'] ?? '');
$f   = (string) ($_GET['f'] ?? '');
$exp = (string) ($_GET['e'] ?? '');
$sig = (string) ($_GET['s'] ?? '');

if (!preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/', $id)
    || !preg_match('/^(application\.(pdf|csv)|logo\.(jpg|png|webp|gif)|charter\.(pdf|jpg|png|doc|docx|odt|rtf|txt))$/', $f)
    || !ctype_digit($exp) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
    deny(400, 'Неверная ссылка.');
}
$secretFile = "$PRIVATE/membership-secret";
if (!is_file($secretFile)) {
    deny(404, 'Файл не найден.');
}
$expected = hash_hmac('sha256', "$id|$f|$exp", trim((string) file_get_contents($secretFile)));
if (!hash_equals($expected, $sig)) {
    deny(403, 'Ссылка недействительна.');
}
if ((int) $exp < time()) {
    deny(410, 'Срок действия ссылки истёк. Файл хранится на сервере: private/membership/' . $id . '/');
}
$path = "$PRIVATE/membership/$id/$f";
if (!is_file($path)) {
    deny(404, 'Файл не найден.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . pathinfo($f, PATHINFO_FILENAME) . '-' . $id . '.' . pathinfo($f, PATHINFO_EXTENSION) . '"');
header('X-Robots-Tag: noindex');
header('Cache-Control: private, no-store');
readfile($path);
