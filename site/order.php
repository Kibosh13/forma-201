<?php
declare(strict_types=1);
require __DIR__ . '/lib/cart/cart-core.php';
require __DIR__ . '/admin/public-content.php';
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
$catalog = load_catalog();
$items = cart_items($catalog);
$token = $_SESSION['order_token'];
session_write_close();
$total = array_sum(array_column($items, 'total'));
ob_start();
?>
<div class="alym-checkout__heading"><h1>Корзина и оформление заказа</h1><a href="/catalog/">Продолжить покупки</a></div>
<?php if (!$items): ?>
<p>Ваша корзина пока пуста.</p><a class="btn-link" href="/catalog/">Перейти в каталог</a>
<?php else: ?>
<p>Оформить заказ могут частные покупатели и организации.</p>
<div class="alym-checkout__items">
<?php foreach ($items as $item): ?>
<article class="catalog-cart-table-tr alym-checkout__item" data-product="<?=h($item['slug'])?>">
    <a href="<?=h($item['route'])?>" class="alym-checkout__image"><img src="<?=h($item['image'])?>" alt="<?=h($item['name'])?>"></a>
    <div class="alym-checkout__product"><a href="<?=h($item['route'])?>"><?=h($item['name'])?></a><?php if ($item['article']): ?><small>Арт. <?=h($item['article'])?></small><?php endif; ?><small><?=cart_money($item['price'])?> <?=h($item['unit'])?></small></div>
    <div class="alym-checkout__quantity"><label for="qty-<?=h($item['slug'])?>">Количество</label><div class="catalog-cart-input-wrap"><button type="button" class="catalog-cart-input-minus" aria-label="Уменьшить количество">−</button><input id="qty-<?=h($item['slug'])?>" type="number" name="quantity" min="<?=$item['step']?>" max="1000" step="<?=$item['step']?>" value="<?=$item['quantity']?>" data-step="<?=$item['step']?>" data-product="<?=h($item['slug'])?>" data-price="<?=$item['price']?>"><button type="button" class="catalog-cart-input-plus" aria-label="Увеличить количество">+</button></div></div>
    <strong class="alym-checkout__line-total" data-cell="summ"><span><?=cart_money($item['total'])?></span> руб.</strong>
    <button type="button" class="catalog-cart-delete alym-checkout__remove" data-product="<?=h($item['slug'])?>" aria-label="Удалить <?=h($item['name'])?>">Удалить</button>
</article>
<?php endforeach; ?>
</div>
<div class="alym-checkout__summary"><button type="button" class="catalog-cart-clear">Очистить корзину</button><strong class="catalog-cart-table-summ">Итого: <span><?=cart_money($total)?></span> руб.</strong></div>
<form class="catalog-cart-form alym-checkout-form" method="post" action="/lib/feedback/mail-form.php">
    <h2>Контактные данные</h2>
    <div class="alym-checkout__fields">
        <label>Ваше имя<input name="form_name" autocomplete="name" required maxlength="100"></label>
        <label>Телефон<input name="form_phone" type="tel" autocomplete="tel" required maxlength="50"></label>
        <label>Электронная почта<input name="form_email" type="email" autocomplete="email" maxlength="150"></label>
        <label>Компания, если заказываете от организации<input name="form_company" maxlength="200"></label>
        <label>Получение заказа<select name="form_delivery"><option value="self">Самовывоз</option><option value="delivery">Доставка</option></select></label>
        <label class="alym-checkout__address" hidden>Адрес доставки<textarea name="form_address" autocomplete="street-address" maxlength="1000" disabled></textarea></label>
        <label class="alym-checkout__wide">Комментарий к заказу<textarea name="form_message" maxlength="2000"></textarea></label>
    </div>
    <p><a href="/delivery/">Условия оплаты и доставки</a>. Менеджер свяжется с вами для подтверждения заказа.</p>
    <label class="alym-checkout__consent"><input type="checkbox" name="policy_read" value="1" required> <span>Я ознакомлен(а) с <a href="/kontakty/politika-konfidentsialnosti/" target="_blank" rel="noopener">Политикой в отношении обработки персональных данных</a></span></label>
    <label class="alym-checkout__consent"><input type="checkbox" name="policy_agree" value="1" required> <span>Я согласен(а) <a href="/kontakty/soglasie/" target="_blank" rel="noopener">на обработку персональных данных</a></span></label>
    <input type="hidden" name="id_form" value="checkout"><input type="hidden" name="name_form" value="Заказ из корзины"><input type="hidden" name="order_token" value="<?=h($token)?>">
    <div class="feedback-garant__mess-error" role="alert"></div>
    <button class="btn-button" type="submit">Оформить заказ</button>
    <div class="alym-checkout__result" role="status"></div>
</form>
<?php endif;
$content = (string)ob_get_clean();
[$dom, $xpath] = load_dom_file('catalog/index.html');
$box = first_node($xpath, class_query('content-box'));
if (!$box) { http_response_code(503); exit('Не удалось загрузить корзину.'); }
$sidebar = first_node($xpath, '//*[@id="sidebar"]');
$sidebar?->parentNode?->removeChild($sidebar);
$box->setAttribute('class', 'content-box alym-checkout');
set_inner_html($box, $content);
set_title($xpath, $dom, 'Корзина и оформление заказа — АлюмПрофи');
set_meta($xpath, $dom, 'robots', 'noindex,nofollow');
foreach ($xpath->query('//link[@rel="canonical"]') ?: array() as $canonical) $canonical->setAttribute('href', 'https://alymprofi.ru/order/');
$head = first_node($xpath, '//head');
$style = $dom->createElement('link');
$style->setAttribute('rel', 'stylesheet');
$style->setAttribute('href', '/lib/cart/checkout.css?v=' . filemtime(__DIR__ . '/lib/cart/checkout.css'));
$head?->appendChild($style);
public_version_design_assets($dom, $xpath);
echo preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $dom->saveHTML());
