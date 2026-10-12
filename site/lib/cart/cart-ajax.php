<?php
declare(strict_types=1);
require __DIR__ . '/cart-core.php';
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{}'); }
try {
    cart_check_origin();
    cart_session();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'cart';
    $slug = is_string($_POST['product'] ?? null) ? $_POST['product'] : '';
    if ($action === 'clear') $_SESSION['cart'] = array();
    elseif ($action === 'remove') unset($_SESSION['cart'][$slug]);
    elseif (in_array($action, array('add', 'update'), true)) {
        $product = cart_product($slug, load_catalog());
        $quantity = cart_quantity($_POST['quantity'] ?? 1, $product['step']);
        if (!isset($_SESSION['cart'][$slug]) && count($_SESSION['cart']) >= 100) throw new RuntimeException('В корзине может быть до 100 разных товаров.');
        $_SESSION['cart'][$slug] = $quantity;
    } elseif ($action !== 'cart') throw new RuntimeException('Неизвестное действие корзины.');
    cart_items(load_catalog());
    echo json_encode((object)$_SESSION['cart'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    session_write_close();
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(array('error' => $error->getMessage()), JSON_UNESCAPED_UNICODE);
}
