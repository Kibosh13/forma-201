<?php
declare(strict_types=1);

if (!defined('ALYM_PUBLIC_CONTENT')) define('ALYM_PUBLIC_CONTENT', true);
require_once __DIR__ . '/../../admin/bootstrap.php';
require_once __DIR__ . '/../../admin/editor.php';

function cart_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_name('alym_cart');
    session_set_cookie_params(array('lifetime' => 30 * 86400, 'path' => '/', 'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax'));
    session_start();
    $_SESSION['cart'] ??= array();
    $_SESSION['order_token'] ??= bin2hex(random_bytes(24));
}

function cart_product(string $slug, array $catalog): array {
    $entry = $catalog['products'][$slug] ?? null;
    if (!$entry || isset($catalog['deleted_products'][$entry['route']]) || !is_file(site_path($entry['path']))) {
        throw new RuntimeException('Товар больше не доступен. Обновите страницу каталога.');
    }
    $values = product_read($entry);
    $price = str_replace(array(' ', "\u{00a0}", ','), array('', '', '.'), (string)$values['price']);
    $source = (string)file_get_contents(site_path($entry['path']));
    $step = 1;
    if (preg_match('~<input\b[^>]*\bname=["\']quantity["\'][^>]*>~i', $source, $input)
        && preg_match('~\bdata-step=["\']([0-9]+)["\']~i', $input[0], $match)) $step = max(1, min(1000, (int)$match[1]));
    return array('slug' => $slug, 'route' => $entry['route'], 'name' => $values['name'],
        'article' => $values['article'] ?? '', 'image' => $values['image'] ?? $entry['image'] ?? '',
        'price' => is_numeric($price) ? max(0, round((float)$price, 2)) : 0,
        'unit' => $values['unit'] ?: 'р./шт.', 'step' => $step);
}

function cart_quantity(mixed $raw, int $step = 1): int {
    if (!is_scalar($raw) || !preg_match('/^[0-9]{1,4}$/', (string)$raw) || (int)$raw < 1 || (int)$raw > 1000) {
        throw new RuntimeException('Укажите количество от 1 до 1000.');
    }
    $quantity = (int)(ceil((int)$raw / $step) * $step);
    if ($quantity > 1000) throw new RuntimeException('Количество превышает 1000.');
    return $quantity;
}

function cart_items(array $catalog): array {
    cart_session();
    $items = array();
    foreach ($_SESSION['cart'] as $slug => $quantity) {
        try {
            $product = cart_product((string)$slug, $catalog);
            $product['quantity'] = cart_quantity($quantity, $product['step']);
            $product['total'] = round($product['price'] * $product['quantity'], 2);
            $items[] = $product;
            $_SESSION['cart'][$slug] = $product['quantity'];
        } catch (RuntimeException $error) { unset($_SESSION['cart'][$slug]); }
    }
    return $items;
}

function cart_money(float $value): string {
    return number_format($value, floor($value) === $value ? 0 : 2, ',', ' ');
}

function cart_order_fields(array $items): array {
    $lines = array();
    $total = 0;
    foreach ($items as $item) {
        $lines[] = $item['name'] . (empty($item['article']) ? '' : ' (арт. ' . $item['article'] . ')');
        $lines[] = 'Количество: ' . $item['quantity'] . '; цена: ' . cart_money($item['price']) . ' ' . $item['unit'] . '; сумма: ' . cart_money($item['total']) . ' руб.';
        $lines[] = 'Ссылка: https://alymprofi.ru' . $item['route'];
        $lines[] = '';
        $total += $item['total'];
    }
    return array('order_items' => trim(implode("\n", $lines)), 'order_total' => cart_money($total) . ' руб.');
}

function cart_check_origin(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        http_response_code(403);
        throw new RuntimeException('Обновите страницу сайта и повторите действие.');
    }
}
