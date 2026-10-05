<?php
declare(strict_types=1);

const ALYM_SITE_ROOT = __DIR__ . '/..';
const ALYM_ADMIN_DIR = __DIR__;
const ALYM_STORAGE_DIR = __DIR__ . '/storage';

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_dir(ALYM_STORAGE_DIR)) {
    mkdir(ALYM_STORAGE_DIR, 0770, true);
}

$localConfig = __DIR__ . '/config.local.php';
$config = is_file($localConfig) ? require $localConfig : array();
$config += array('user' => '', 'password_hash' => '');

session_name('alym_admin');
session_set_cookie_params(array(
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/admin/',
));
session_start();

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function is_logged_in(): bool {
    return !empty($_SESSION['alym_admin_logged_in']);
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect('/admin/?login=1');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['alym_csrf'])) {
        $_SESSION['alym_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['alym_csrf'];
}

function check_csrf(): void {
    $provided = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Сессия формы истекла. Обновите страницу и повторите действие.');
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['alym_flash'] = array('message' => $message, 'type' => $type);
}

function take_flash(): ?array {
    $flash = $_SESSION['alym_flash'] ?? null;
    unset($_SESSION['alym_flash']);
    return $flash;
}

function clean_relative_path(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        throw new RuntimeException('Недопустимый путь.');
    }
    return $path;
}

function site_path(string $relative): string {
    return ALYM_SITE_ROOT . '/' . clean_relative_path($relative);
}

function load_catalog(): array {
    $working = ALYM_STORAGE_DIR . '/catalog.json';
    $seed = ALYM_ADMIN_DIR . '/data/catalog.json';
    if (!is_file($working)) {
        if (!is_file($seed)) {
            throw new RuntimeException('Индекс каталога не найден.');
        }
        copy($seed, $working);
    }
    $data = json_decode((string)file_get_contents($working), true);
    if (!is_array($data)) {
        throw new RuntimeException('Индекс каталога повреждён.');
    }
    return $data;
}

function save_catalog(array $data): void {
    $path = ALYM_STORAGE_DIR . '/catalog.json';
    $temp = $path . '.tmp';
    file_put_contents($temp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($temp, $path);
}

function catalog_write_lock() {
    $lock = fopen(ALYM_STORAGE_DIR . '/catalog-write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Не удалось заблокировать каталог для сохранения. Повторите попытку.');
    }
    return $lock;
}

function backup_file(string $relative): void {
    $relative = clean_relative_path($relative);
    $source = site_path($relative);
    if (!is_file($source)) {
        return;
    }
    $destination = ALYM_STORAGE_DIR . '/backups/' . date('Y-m-d_His') . '/' . $relative;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0770, true);
    }
    copy($source, $destination);
}

function load_dom_file(string $relative): array {
    $path = site_path($relative);
    if (!is_file($path)) {
        throw new RuntimeException('HTML-файл не найден: ' . $relative);
    }
    $source = (string)file_get_contents($path);
    // libxml's HTML4 parser treats closing tags inside JavaScript strings as
    // HTML. Protect raw script bodies before parsing, then restore text nodes.
    // Without this, saving a card can turn popular-product scripts into text.
    $scripts = array();
    $protected = preg_replace_callback('~(<script\b[^>]*>)(.*?)(</script\s*>)~is', static function (array $match) use (&$scripts): string {
        $key = 'ALYM_RAW_SCRIPT_' . count($scripts) . '_' . bin2hex(random_bytes(8));
        $scripts[$key] = $match[2];
        return $match[1] . $key . $match[3];
    }, $source) ?? $source;
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $protected, LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('script') as $script) {
        $key = trim($script->textContent);
        if (array_key_exists($key, $scripts)) $script->textContent = $scripts[$key];
    }
    return array($dom, new DOMXPath($dom), $source);
}

function save_dom_file(string $relative, DOMDocument $dom): void {
    backup_file($relative);
    $html = $dom->saveHTML();
    $html = preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $html) ?? $html;
    $path = site_path($relative);
    $temp = $path . '.tmp';
    file_put_contents($temp, $html, LOCK_EX);
    chmod($temp, 0640);
    rename($temp, $path);
}

function first_node(DOMXPath $xpath, string $query): ?DOMElement {
    $node = $xpath->query($query)?->item(0);
    return $node instanceof DOMElement ? $node : null;
}

function class_query(string $class, string $suffix = ''): string {
    return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' . $suffix;
}

function inner_html(DOMNode $node): string {
    $result = '';
    foreach ($node->childNodes as $child) {
        $result .= $node->ownerDocument->saveHTML($child);
    }
    return $result;
}

function set_inner_html(DOMElement $node, string $html): void {
    while ($node->firstChild) {
        $node->removeChild($node->firstChild);
    }
    if (trim($html) === '') {
        return;
    }
    $fragmentDoc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $fragmentDoc->loadHTML('<?xml encoding="UTF-8"><div id="alym-fragment">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    $container = $fragmentDoc->getElementById('alym-fragment');
    if (!$container) {
        return;
    }
    foreach (iterator_to_array($container->childNodes) as $child) {
        $node->appendChild($node->ownerDocument->importNode($child, true));
    }
}

function set_meta(DOMXPath $xpath, DOMDocument $dom, string $name, string $content): void {
    $node = first_node($xpath, '//meta[@name="' . $name . '"]');
    if (!$node) {
        $head = first_node($xpath, '//head');
        if (!$head) return;
        $node = $dom->createElement('meta');
        $node->setAttribute('name', $name);
        $head->appendChild($node);
    }
    $node->setAttribute('content', $content);
}

function set_property_meta(DOMXPath $xpath, DOMDocument $dom, string $property, string $content): void {
    $node = first_node($xpath, '//meta[@property="' . $property . '"]');
    if (!$node) {
        $head = first_node($xpath, '//head');
        if (!$head) return;
        $node = $dom->createElement('meta');
        $node->setAttribute('property', $property);
        $head->appendChild($node);
    }
    $node->setAttribute('content', $content);
}

function set_title(DOMXPath $xpath, DOMDocument $dom, string $title): void {
    $node = first_node($xpath, '//title');
    if (!$node) {
        $head = first_node($xpath, '//head');
        if (!$head) return;
        $node = $dom->createElement('title');
        $head->appendChild($node);
    }
    $node->textContent = $title;
    set_property_meta($xpath, $dom, 'og:title', $title);
}

function store_uploaded_image(array $file, string $label = 'image'): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить изображение.');
    }
    if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new RuntimeException('Изображение больше 15 МБ.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Поддерживаются JPG, PNG, WebP и GIF.');
    }
    $directory = 'upload/admin/' . date('Y/m');
    $absoluteDirectory = site_path($directory);
    if (!is_dir($absoluteDirectory)) {
        mkdir($absoluteDirectory, 0770, true);
    }
    $safeLabel = preg_replace('/[^a-z0-9_-]+/i', '-', $label) ?: 'image';
    $filename = trim($safeLabel, '-') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $absolute = $absoluteDirectory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absolute)) {
        throw new RuntimeException('Не удалось сохранить изображение.');
    }
    chmod($absolute, 0640);
    return '/' . $directory . '/' . $filename;
}

function upload_image(string $field, string $label = 'image'): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    return store_uploaded_image($_FILES[$field], $label);
}

function upload_images(string $field, string $label = 'image'): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return array();
    }
    $upload = $_FILES[$field];
    if (!is_array($upload['name'] ?? null)) {
        $single = store_uploaded_image($upload, $label);
        return $single ? array($single) : array();
    }

    $images = array();
    foreach ($upload['name'] as $index => $name) {
        $file = array(
            'name' => $name,
            'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0,
        );
        $image = store_uploaded_image($file, $label . '-' . ($index + 1));
        if ($image) $images[] = $image;
    }
    return $images;
}

function slugify(string $value): string {
    $map = array(
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
    );
    $value = strtr(mb_strtolower(trim($value)), $map);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function unique_slug(string $requested, array $existing): string {
    $base = slugify($requested) ?: 'item';
    $slug = $base;
    $i = 2;
    while (isset($existing[$slug])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function remove_tree(string $path): void {
    if (!is_dir($path)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
