<?php
declare(strict_types=1);
define('ALYM_PUBLIC_CONTENT', true);
require __DIR__ . '/admin/bootstrap.php';
require __DIR__ . '/admin/editor.php';
require __DIR__ . '/admin/public-content.php';

header('Content-Type: text/html; charset=UTF-8');
$relative = (string)($_GET['alym_file'] ?? '');
try {
    $relative = clean_relative_path($relative);
    $path = realpath(site_path($relative));
    $root = realpath(ALYM_SITE_ROOT);
    if (!$path || !$root || !str_starts_with($path, $root . '/') || !preg_match('~\.(?:html|prod|tag)$~i', $relative) || str_starts_with($relative, 'admin/')) {
        http_response_code(404);
        exit('Страница не найдена.');
    }
    $catalog = load_catalog();
    if (isset($catalog['deleted_products']['/' . $relative])) {
        http_response_code(404);
        exit('Страница не найдена.');
    }
    echo public_content_html($relative, $catalog);
} catch (Throwable $error) {
    error_log('AlyumProfi content: ' . $error->getMessage());
    http_response_code(503);
    echo 'Не удалось загрузить страницу. Повторите попытку.';
}
