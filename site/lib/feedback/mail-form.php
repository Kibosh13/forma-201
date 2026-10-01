<?php
declare(strict_types=1);

const ALYM_STORAGE_DIR = __DIR__ . '/../../admin/storage';
require __DIR__ . '/../../admin/leads.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('error#Допустима только отправка формы.');
}

function clean_form_value(mixed $value): string {
    if (is_array($value)) {
        return implode(', ', array_map('clean_form_value', $value));
    }
    $value = trim(strip_tags((string)$value));
    return mb_substr($value, 0, 5000);
}

function uploaded_files(): array {
    $result = array();
    foreach ($_FILES as $group) {
        $names = is_array($group['name'] ?? null) ? $group['name'] : array($group['name'] ?? '');
        $temps = is_array($group['tmp_name'] ?? null) ? $group['tmp_name'] : array($group['tmp_name'] ?? '');
        $errors = is_array($group['error'] ?? null) ? $group['error'] : array($group['error'] ?? UPLOAD_ERR_NO_FILE);
        $sizes = is_array($group['size'] ?? null) ? $group['size'] : array($group['size'] ?? 0);
        foreach ($names as $index => $name) {
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if (($sizes[$index] ?? 0) > 10 * 1024 * 1024) continue;
            $result[] = array('name' => (string)$name, 'tmp_name' => (string)($temps[$index] ?? ''), 'size' => (int)($sizes[$index] ?? 0));
        }
    }
    return $result;
}

$fields = array();
foreach ($_POST as $key => $value) {
    if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', (string)$key)) continue;
    $fields[(string)$key] = clean_form_value($value);
}

if (isset($fields['form_phone']) && $fields['form_phone'] === '') {
    exit('empty_field#form_phone');
}

$meaningful = array_filter($fields, fn(string $value, string $key): bool => $value !== '' && !in_array($key, array('id_form', 'name_form'), true), ARRAY_FILTER_USE_BOTH);
if (!$meaningful) {
    exit('error#Заполните поля формы.');
}

$id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$savedFiles = array();
$fileDirectory = ALYM_STORAGE_DIR . '/lead-files/' . $id;
foreach (uploaded_files() as $index => $file) {
    if (!is_uploaded_file($file['tmp_name'])) continue;
    if (!is_dir($fileDirectory)) mkdir($fileDirectory, 0770, true);
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) ? '.' . $extension : '';
    $safeName = preg_replace('/[^\pL\pN._-]+/u', '-', pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'file';
    $storedName = ($index + 1) . '-' . mb_substr($safeName, 0, 80) . $extension;
    $absolute = $fileDirectory . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $absolute)) continue;
    chmod($absolute, 0640);
    $savedFiles[] = array('name' => $file['name'], 'path' => 'lead-files/' . $id . '/' . $storedName, 'size' => $file['size']);
}

$settingsPath = ALYM_STORAGE_DIR . '/settings.json';
$settings = is_file($settingsPath) ? json_decode((string)file_get_contents($settingsPath), true) : array();
$mailTo = filter_var($settings['lead_email'] ?? $settings['email'] ?? 'info@alymprofi.ru', FILTER_VALIDATE_EMAIL) ?: 'info@alymprofi.ru';
$formType = $fields['id_form'] ?? $fields['name_form'] ?? 'Форма с сайта';
$lines = array('Новая заявка с сайта alymprofi.ru', 'Номер: ' . $id, 'Форма: ' . $formType, 'Страница: ' . ($_SERVER['HTTP_REFERER'] ?? ''), '');
foreach ($fields as $key => $value) {
    if ($value !== '') $lines[] = lead_field_label($key) . ': ' . $value;
}
if ($savedFiles) $lines[] = 'Прикреплённые файлы доступны в административной панели.';
$subject = 'Новая заявка с alymprofi.ru: ' . $formType;
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$headers = "From: alymprofi.ru <noreply@alymprofi.ru>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
$mailSent = @mail($mailTo, $encodedSubject, implode("\n", $lines), $headers);

add_lead(array(
    'id' => $id,
    'created_at' => date(DATE_ATOM),
    'status' => 'new',
    'form' => $formType,
    'page' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
    'fields' => $fields,
    'files' => $savedFiles,
    'mail_to' => $mailTo,
    'mail_sent' => $mailSent,
    'ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
));

echo 'success#Спасибо! Заявка отправлена.#' . $id;
