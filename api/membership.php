<?php
/**
 * Приём заявления на членство NRC-EBF (форма /new-church-nrc-ebf/).
 *
 * Что делает:
 *   1. проверяет данные и файлы (логотип, устав), отсекает спам (скрытое поле, слишком быстрая отправка, лимит по IP);
 *   2. сохраняет заявку ВНЕ веб-корня: …/vhosts/nrc-ebf.eu/private/membership/<номер>/
 *      application.csv, application.pdf и загруженные файлы; строка добавляется и в общий applications.csv;
 *   3. отправляет письмо на info@nrc-ebf.eu (Reply-To — заявитель): PDF и CSV во вложении, файлы — вложением,
 *      если помещаются в лимит письма, и всегда — защищённой ссылкой на скачивание (membership-file.php);
 *   4. отправляет заявителю автоответ «Ваше заявление получено».
 *
 * Библиотеки (TCPDF, PHPMailer) ставятся composer'ом в …/private/membership-lib — см. deploy/README.md.
 * Проверка без писем с сервера: NRC_FORM_TEST=1 php membership.php  (см. функцию cli_fixture()).
 */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

const MAIL_TO        = 'info@nrc-ebf.eu';
const MAIL_FROM      = 'info@nrc-ebf.eu';
const MAIL_FROM_NAME = 'NRC-EBF';
const FORM_URL       = '/new-church-nrc-ebf/';
const THANKS_URL     = '/new-church-nrc-ebf/spasibo/';
const MAX_LOGO       = 5 * 1024 * 1024;
const MAX_CHARTER    = 20 * 1024 * 1024;
// postfix на сервере принимает письма до ~10 МБ, а вложения при отправке растут на треть (base64):
// всё, что не помещается в этот запас, в письмо не прикладывается — только ссылкой.
const ATTACH_BUDGET  = 6 * 1024 * 1024;
const LINK_TTL       = 180 * 86400;   // ссылки на файлы в письме действуют 180 дней
const SITE_HOSTS     = ['nrc-ebf.eu', 'www.nrc-ebf.eu', 'new.nrc-ebf.eu'];
const RATE_LIMIT     = 5;      // заявлений
const RATE_WINDOW    = 3600;   // за час с одного IP
const MIN_FILL_MS    = 4000;   // быстрее 4 секунд форму заполняет только бот

const FIELDS = [
    // ключ => [подпись, обязательное, макс. длина, раздел]
    'church_name'       => ['Название церкви', true, 200, 'Церковь'],
    'church_name_local' => ['Название на национальном языке', false, 200, 'Церковь'],
    'denomination'      => ['Деноминация', true, 120, 'Церковь'],
    'members_count'     => ['Количество членов церкви', true, 10, 'Церковь'],
    'website'           => ['Веб-сайт', false, 300, 'Церковь'],
    'description'       => ['Описание, история и развитие церкви', true, 5000, 'Церковь'],
    'country'           => ['Страна', true, 80, 'Адрес и служения'],
    'city'              => ['Город', true, 120, 'Адрес и служения'],
    'address'           => ['Адрес, где проходят служения', true, 300, 'Адрес и служения'],
    'postal_code'       => ['Индекс', false, 20, 'Адрес и служения'],
    'region'            => ['Регион', false, 120, 'Адрес и служения'],
    'schedule'          => ['Расписание служений', true, 2000, 'Адрес и служения'],
    'pastor_first_name' => ['Имя служителя', true, 80, 'Ответственный служитель'],
    'pastor_last_name'  => ['Фамилия служителя', true, 80, 'Ответственный служитель'],
    'phone'             => ['Телефон', true, 40, 'Ответственный служитель'],
    'email'             => ['Эл. почта', true, 200, 'Ответственный служитель'],
];

const UPLOADS = [
    'logo'    => ['Логотип церкви', MAX_LOGO, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']],
    'charter' => ['Конституция / устав церкви', MAX_CHARTER, [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/rtf' => 'rtf', 'text/rtf' => 'rtf', 'text/plain' => 'txt',
    ]],
];

// Word/ODT libmagic иногда определяет лишь как «zip» или «двоичный файл» — тогда решает расширение имени.
const GENERIC_MIME = ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'];
const DOC_EXT      = ['doc', 'docx', 'odt', 'rtf'];

$CLI     = PHP_SAPI === 'cli';
$PRIVATE = dirname(__DIR__, 2) . '/private';          // …/vhosts/nrc-ebf.eu/private
$DATA    = $PRIVATE . '/membership';
require $PRIVATE . '/membership-lib/vendor/autoload.php';

// ---------------------------------------------------------------- вывод

function page(int $code, string $title, string $html): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $t = htmlspecialchars($title);
    echo <<<HTML
    <!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$t} · NRC-EBF</title><meta name="robots" content="noindex">
    <style>body{margin:0;background:#f4f6f9;color:#141922;font:17px/1.55 -apple-system,'Segoe UI',system-ui,sans-serif}
    main{max-width:640px;margin:0 auto;padding:40px 20px}h1{font-size:26px;line-height:1.2;margin:0 0 12px}
    a{color:#1f4f9a}.btn{display:inline-block;margin-top:16px;padding:14px 22px;border-radius:14px;background:#1f4f9a;color:#fff;text-decoration:none;font-weight:600}</style>
    </head><body><main><h1>{$t}</h1>{$html}</main></body></html>
    HTML;
    exit;
}

function fail(string $message): never
{
    page(422, 'Заявление не отправлено', '<p>' . $message . '</p><a class="btn" href="' . FORM_URL . '">Вернуться к форме</a>'
        . '<p>Если не получается, напишите нам: <a href="mailto:' . MAIL_TO . '">' . MAIL_TO . '</a>.</p>');
}

function done(): never
{
    global $CLI;
    if ($CLI) {
        exit(0);
    }
    header('Location: ' . THANKS_URL, true, 303);
    exit;
}

function log_line(string $file, string $line): void
{
    global $DATA;
    @file_put_contents("$DATA/$file", date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------- проверки

function clean(string $v, int $max): string
{
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[^\P{C}\n\t]/u', '', $v) ?? '';   // управляющие символы, кроме переноса строки
    return mb_substr(trim($v), 0, $max);
}

function rate_limited(string $ip): bool
{
    global $DATA;
    $dir = "$DATA/.rate";
    @mkdir($dir, 0750, true);
    $file = $dir . '/' . sha1($ip);
    $now = time();
    $hits = array_filter(json_decode((string) @file_get_contents($file), true) ?: [], fn($t) => $t > $now - RATE_WINDOW);
    if (count($hits) >= RATE_LIMIT) {
        return true;
    }
    $hits[] = $now;
    file_put_contents($file, json_encode(array_values($hits)), LOCK_EX);
    return false;
}

function csv_cell(string $v): string
{
    return preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v;   // защита от формул в Excel
}

function write_csv(string $path, array $header, array $row, bool $append): void
{
    $new = !$append || !file_exists($path);
    $fh = fopen($path, $append ? 'ab' : 'wb');
    flock($fh, LOCK_EX);
    if ($new) {
        fwrite($fh, "\xEF\xBB\xBF");                        // BOM — чтобы Excel открыл кириллицу
        fputcsv($fh, $header, ';', '"', '');
    }
    fputcsv($fh, array_map('csv_cell', $row), ';', '"', '');
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ---------------------------------------------------------------- ссылки на файлы

/** Секрет для подписи ссылок: создаётся один раз, лежит вне веб-корня, в репозиторий не попадает. */
function link_secret(): string
{
    global $PRIVATE;
    $file = "$PRIVATE/membership-secret";
    if (!is_file($file)) {
        file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        chmod($file, 0600);
    }
    return trim((string) file_get_contents($file));
}

function file_link(string $id, string $name): string
{
    global $CLI;
    $host = $CLI ? 'nrc-ebf.eu' : strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!in_array($host, SITE_HOSTS, true)) {
        $host = 'nrc-ebf.eu';
    }
    $exp = time() + LINK_TTL;
    $sig = hash_hmac('sha256', "$id|$name|$exp", link_secret());
    return "https://$host/api/membership-file.php?" . http_build_query(['id' => $id, 'f' => $name, 'e' => $exp, 's' => $sig]);
}

function human_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' МБ' : max(1, (int) round($bytes / 1024)) . ' КБ';
}

// ---------------------------------------------------------------- PDF

function make_pdf(string $path, string $id, string $when, array $values, array $saved): void
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('nrc-ebf.eu');
    $pdf->SetTitle('Заявление на членство NRC-EBF № ' . $id);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(18, 16, 18);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    if (isset($saved['logo']) && in_array(pathinfo($saved['logo'], PATHINFO_EXTENSION), ['jpg', 'png', 'gif'], true)) {
        $pdf->Image($saved['logo'], 157, 14, 35, 0, '', '', '', true, 150, '', false, false, 0, 'RT');
    }
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->MultiCell(135, 0, 'Заявление на членство в сети NRC-EBF', 0, 'L');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetTextColor(90, 98, 110);
    $pdf->MultiCell(135, 0, "№ $id · $when\nСеть русскоговорящих церквей при Европейской Баптистской Федерации", 0, 'L');
    $pdf->SetTextColor(20, 25, 34);
    $pdf->Ln(6);

    $html = '';
    $section = '';
    foreach (FIELDS as $key => [$label, , , $sec]) {
        if ($sec !== $section) {
            $section = $sec;
            $html .= '<tr><td colspan="2" style="font-size:12pt;font-weight:bold;color:#1f4f9a;border-bottom:0.3mm solid #1f4f9a;">'
                . htmlspecialchars($sec) . '</td></tr>';
        }
        $v = $values[$key] !== '' ? nl2br(htmlspecialchars($values[$key])) : '<span style="color:#9aa3b2;">—</span>';
        $html .= '<tr><td width="34%" style="color:#4b5563;">' . htmlspecialchars($label) . '</td><td width="66%">' . $v . '</td></tr>';
    }
    $html .= '<tr><td colspan="2" style="font-size:12pt;font-weight:bold;color:#1f4f9a;border-bottom:0.3mm solid #1f4f9a;">Файлы и согласия</td></tr>';
    foreach (UPLOADS as $key => [$label]) {
        $html .= '<tr><td width="34%" style="color:#4b5563;">' . $label . '</td><td width="66%">'
            . (isset($saved[$key]) ? htmlspecialchars(basename($saved[$key])) . ' (' . human_size((int) filesize($saved[$key])) . ')' : 'не приложен') . '</td></tr>';
    }
    $html .= '<tr><td width="34%" style="color:#4b5563;">Конституция NRC-EBF</td><td width="66%">Ознакомились, принимают и поддерживают Конституцию и Основы вероучения</td></tr>';
    $html .= '<tr><td width="34%" style="color:#4b5563;">Обработка данных</td><td width="66%">Согласие дано</td></tr>';

    $pdf->writeHTML('<table cellpadding="5" cellspacing="0">' . $html . '</table>', true, false, true, false, '');
    $pdf->Output($path, 'F');
}

// ---------------------------------------------------------------- почта

function mailer(): PHPMailer
{
    $m = new PHPMailer(true);
    $m->isSendmail();                 // локальный postfix Plesk, подпись DKIM ставит сервер
    $m->CharSet = PHPMailer::CHARSET_UTF8;
    $m->Encoding = PHPMailer::ENCODING_BASE64;
    $m->Sender = MAIL_FROM;           // envelope-from
    return $m;
}

// ---------------------------------------------------------------- тест из консоли

function cli_fixture(): array
{
    $post = [
        'church_name' => 'Тестовая церковь (проверка формы)', 'church_name_local' => 'Testgemeinde', 'denomination' => 'ЕХБ',
        'members_count' => '25', 'website' => 'https://example.org', 'description' => "Проверка обработчика.\nВторая строка.",
        'country' => 'Чехия', 'city' => 'Прага', 'address' => 'Testovací 1', 'postal_code' => '110 00', 'region' => '',
        'schedule' => "Воскресенье 10:00 — богослужение", 'pastor_first_name' => 'Иван', 'pastor_last_name' => 'Тестов',
        'phone' => '+420 000 000 000', 'email' => getenv('NRC_FORM_TEST_EMAIL') ?: 'test@example.org',
        'accept_constitution' => '1', 'accept_privacy' => '1', 'started' => (string) ((time() - 60) * 1000),
    ];
    $files = [];
    foreach (['logo' => 'NRC_FORM_TEST_LOGO', 'charter' => 'NRC_FORM_TEST_CHARTER'] as $key => $env) {
        if ($path = getenv($env)) {
            $files[$key] = ['name' => basename($path), 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
        }
    }
    return [$post, $files];
}

// ================================================================= обработка

if ($CLI) {
    if (getenv('NRC_FORM_TEST') !== '1') {
        fwrite(STDERR, "Запуск из консоли только в режиме проверки: NRC_FORM_TEST=1\n");
        exit(1);
    }
    [$_POST, $_FILES] = cli_fixture();
    $ip = 'cli';
} else {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        page(405, 'Форма заявления', '<p>Заявление подаётся через форму на сайте.</p><a class="btn" href="' . FORM_URL . '">Открыть форму</a>');
    }
    // запрос больше post_max_size PHP отбрасывает целиком — $_POST приходит пустым
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('Файлы слишком большие. Устав — до 20 МБ, логотип — до 5 МБ.');
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// спам: скрытое поле заполнено или форма отправлена мгновенно — делаем вид, что всё хорошо
$started = (int) ($_POST['started'] ?? 0);
if (trim((string) ($_POST['company_url'] ?? '')) !== '' || ($started > 0 && (int) (microtime(true) * 1000) - $started < MIN_FILL_MS)) {
    log_line('spam.log', $ip);
    done();
}
if (!$CLI && rate_limited($ip)) {
    fail('Слишком много заявлений с одного адреса за короткое время. Попробуйте позже.');
}

$values = [];
$errors = [];
foreach (FIELDS as $key => [$label, $required, $max]) {
    $values[$key] = clean((string) ($_POST[$key] ?? ''), $max);
    if ($required && $values[$key] === '') {
        $errors[] = "не заполнено поле «{$label}»";
    }
}
if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'адрес эл. почты указан с ошибкой';
}
if ($values['members_count'] !== '' && (!ctype_digit($values['members_count']) || (int) $values['members_count'] < 1)) {
    $errors[] = 'количество членов церкви должно быть числом';
}
if ($values['website'] !== '' && !preg_match('~^https?://~i', $values['website'])) {
    $values['website'] = 'https://' . $values['website'];
}
if (empty($_POST['accept_constitution'])) {
    $errors[] = 'нужно подтвердить принятие Конституции NRC-EBF';
}
if (empty($_POST['accept_privacy'])) {
    $errors[] = 'нужно согласие на обработку данных';
}

// файлы
$finfo = new finfo(FILEINFO_MIME_TYPE);
$uploads = [];
foreach (UPLOADS as $key => [$label, $max, $types]) {
    $f = $_FILES[$key] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        continue;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "файл «{$label}» не загрузился (возможно, больше " . ($max >> 20) . ' МБ)';
        continue;
    }
    if ($f['size'] > $max) {
        $errors[] = "файл «{$label}» больше " . ($max >> 20) . ' МБ';
        continue;
    }
    $mime = $finfo->file($f['tmp_name']) ?: '';
    $ext  = $types[$mime] ?? null;
    $orig = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === null && $key === 'charter' && in_array($mime, GENERIC_MIME, true) && in_array($orig, DOC_EXT, true)) {
        $ext = $orig;
    }
    if ($ext === null) {
        $errors[] = "файл «{$label}» неподходящего формата";
        continue;
    }
    $uploads[$key] = [$f['tmp_name'], $ext];
}

if ($errors) {
    fail('Пожалуйста, исправьте: ' . htmlspecialchars(implode('; ', $errors)) . '.');
}

// сохранение
$id   = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
$when = date('d.m.Y H:i');
$dir  = "$DATA/$id";
if (!mkdir($dir, 0750, true)) {
    log_line('errors.log', "$id mkdir failed");
    fail('Не удалось сохранить заявление на сервере.');
}
$saved = [];
foreach ($uploads as $key => [$tmp, $ext]) {
    $dest = "$dir/$key.$ext";
    $ok = $CLI ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest);
    if ($ok) {
        $saved[$key] = $dest;
    }
}

$header = array_merge(['Номер', 'Дата'], array_map(fn($f) => $f[0], FIELDS), ['Логотип', 'Устав', 'IP']);
$row    = array_merge([$id, $when], array_values($values), [isset($saved['logo']) ? basename($saved['logo']) : '', isset($saved['charter']) ? basename($saved['charter']) : '', $ip]);
write_csv("$dir/application.csv", $header, $row, false);
write_csv("$DATA/applications.csv", $header, $row, true);

try {
    make_pdf("$dir/application.pdf", $id, $when, $values, $saved);
} catch (Throwable $e) {
    log_line('errors.log', "$id pdf: " . $e->getMessage());
}

$church  = $values['church_name'];
$pastor  = trim($values['pastor_first_name'] . ' ' . $values['pastor_last_name']);
$summary = '';
foreach (FIELDS as $key => [$label]) {
    $summary .= $label . ': ' . ($values[$key] !== '' ? $values[$key] : '—') . "\n";
}

// что прикладываем к письму: PDF и CSV всегда, файлы — пока помещаются в запас письма; ссылки — на всё
$files = ['application.pdf' => 'Заявление (PDF)', 'application.csv' => 'Заявление (CSV)'];
foreach ($saved as $key => $path) {
    $files[basename($path)] = UPLOADS[$key][0];
}
$budget   = ATTACH_BUDGET;
$attach   = [];
$fileList = '';
foreach ($files as $name => $label) {
    $path = "$dir/$name";
    if (!is_file($path)) {
        continue;
    }
    $size = (int) filesize($path);
    $fits = $size <= $budget;
    if ($fits) {
        $budget -= $size;
        $attach[$name] = $path;
    }
    $fileList .= "- {$label}, " . human_size($size) . ($fits ? ' — во вложении' : ' — слишком велик для письма, скачать по ссылке') . "\n  " . file_link($id, $name) . "\n";
}

if ($CLI && getenv('NRC_FORM_TEST_MAIL') !== '1') {
    echo "OK $id — файлы: " . implode(', ', array_map('basename', glob("$dir/*"))) . " (письма не отправлялись)\n"
        . "во вложение пошли бы: " . implode(', ', array_keys($attach)) . "\n{$fileList}";
    exit(0);
}

// 1) координаторам
try {
    $m = mailer();
    $m->setFrom(MAIL_FROM, 'Сайт NRC-EBF');
    $m->addAddress(MAIL_TO);
    $m->addReplyTo($values['email'], $pastor);
    $m->Subject = "Заявление на членство: {$church} ({$values['city']}, {$values['country']})";
    $m->Body = "Новое заявление на членство в сети NRC-EBF\n№ {$id} от {$when}\n\n{$summary}\n"
        . "Файлы заявления (ссылки действуют " . (LINK_TTL / 86400) . " дней):\n{$fileList}\n"
        . "Копия хранится на сервере: private/membership/{$id}/\n"
        . "Ответить заявителю можно прямо на это письмо.\n";
    foreach ($attach as $name => $path) {
        $m->addAttachment($path, pathinfo($name, PATHINFO_FILENAME) . "-{$id}." . pathinfo($name, PATHINFO_EXTENSION));
    }
    $m->send();
} catch (Throwable $e) {
    log_line('errors.log', "$id mail to coordinators: " . $e->getMessage());
}

// 2) заявителю — «Ваше заявление получено»
try {
    $m = mailer();
    $m->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $m->addAddress($values['email'], $pastor);
    $m->addReplyTo(MAIL_TO, MAIL_FROM_NAME);
    $m->Subject = 'Ваше заявление получено — NRC-EBF';
    $m->Body = "Здравствуйте, {$values['pastor_first_name']}!\n\n"
        . "Спасибо! Мы получили заявление церкви «{$church}» на членство в сети NRC-EBF (№ {$id}).\n\n"
        . "Координатор рассмотрит его и свяжется с вами по этому адресу. Если у вас есть вопросы, просто ответьте на это письмо.\n\n"
        . "С уважением,\nNRC-EBF — Сеть русскоговорящих церквей при Европейской Баптистской Федерации\nhttps://nrc-ebf.eu\n";
    $m->send();
} catch (Throwable $e) {
    log_line('errors.log', "$id mail to applicant: " . $e->getMessage());
}

log_line('received.log', "$id {$church} <{$values['email']}>");
done();
