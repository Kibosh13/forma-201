var cart_ajax = '/lib/cart/cart-ajax.php';
var cart_page = '/order/';
var alymCartPending = Promise.resolve();

function getCatalogCartInputStep(input) {
    return Math.max(1, parseInt($(input).attr('data-step'), 10) || 1);
}
function normalizeCatalogCartInputQuantity(quantity, step) {
    step = Math.max(1, step || 1);
    quantity = Math.ceil(Math.max(1, parseInt(quantity, 10) || step) / step) * step;
    return Math.min(quantity, Math.floor(1000 / step) * step);
}
function alymCartError(message) {
    var notice = $('#alym-cart-notice');
    if (!notice.length) notice = $('<div id="alym-cart-notice" class="alym-cart-notice" role="alert"/>').appendTo('body');
    notice.text(message).show();
    setTimeout(function () { notice.hide(); }, 7000);
}
function alymCartRequest(data) {
    alymCartPending = alymCartPending.catch(function () {}).then(function () {
        return $.ajax({method: 'POST', url: cart_ajax, data: data, dataType: 'json'}).then(function (cart) {
            applyCatalogCartState(cart);
            return cart;
        }, function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.error || 'Не удалось обновить корзину. Повторите действие.';
            alymCartError(message);
            throw new Error(message);
        });
    });
    // Keep the rejection available to checkout, without an unhandled promise.
    alymCartPending.catch(function () {});
    return alymCartPending;
}
function catalogCart() { return alymCartRequest({action: 'cart'}); }
function applyCatalogCartState(cart) {
    cart = cart || {};
    $('.catalog-cart-input-btn[data-product]').each(function () {
        var button = $(this), slug = button.attr('data-product');
        var input = button.closest('.catalog-cart-input').find('[name="quantity"]').first();
        input.prop('disabled', false);
        if (Object.prototype.hasOwnProperty.call(cart, slug)) {
            input.attr('data-product', slug).val(cart[slug]);
            button.removeClass('catalog-cart-add').addClass('catalog-cart-in-cart').html('<span aria-hidden="true">✓ </span>В корзине');
        } else {
            input.removeAttr('data-product');
            button.removeClass('catalog-cart-in-cart').addClass('catalog-cart-add').text('В корзину');
        }
    });
    $('.catalog-cart-link').remove();
    var count = Object.keys(cart).length;
    if (count && location.pathname !== cart_page) {
        $('<a class="catalog-cart-link" href="/order/" aria-label="Открыть корзину"/>')
            .append('<span class="catalog-cart-link-basket"><i class="fa fa-shopping-cart" aria-hidden="true"></i></span>')
            .append($('<span class="catalog-cart-link-count"/>').text(count)).appendTo('body');
    }
}
function alymCartTotals() {
    var total = 0;
    $('.alym-checkout__item').each(function () {
        var item = $(this), input = item.find('input[name="quantity"]');
        var sum = (parseFloat(input.attr('data-price')) || 0) * (parseInt(input.val(), 10) || 1);
        total += sum;
        item.find('[data-cell="summ"] span').text(sum.toLocaleString('ru-RU', {maximumFractionDigits: 2}));
    });
    $('.catalog-cart-table-summ span').text(total.toLocaleString('ru-RU', {maximumFractionDigits: 2}));
}
function alymPrepareProductOrder(button) {
    var card = $(button).closest('.catalog-section-tile__item, .catalog-section-list__item, .catalog-section-price__item');
    var input = card.length ? card.find('input[name="quantity"]').first() : $('.catalog-detail input[name="quantity"]').first();
    if (!input.length && !card.length) input = $('input[name="quantity"]').first();
    var quantity = normalizeCatalogCartInputQuantity(input.val(), getCatalogCartInputStep(input));
    if (input.length) input.val(quantity);
    var slug = $(button).attr('data-product') || (!card.length && $('#product-id').val()) || '';
    var name = $(button).attr('data-name') || (card.length ? card.find('.catalog-section-tile__title-link').text() : $('h1').first().text());
    $('[id="form-popup-catalog"] form').each(function () {
        var form = $(this);
        var quantityInput = form.find('[name="quantity"]');
        if (!quantityInput.length) {
            var label = $('<label class="alym-product-quantity"/>').text('Количество');
            quantityInput = $('<input type="number" name="quantity" class="form-popup-request__input" required min="1" max="1000"/>').appendTo(label);
            label.insertAfter(form.find('[name="form_catalog"]'));
        }
        quantityInput.attr({min: getCatalogCartInputStep(input), step: getCatalogCartInputStep(input)}).val(quantity);
        var productId = form.find('[name="product_id"]');
        if (!productId.length) productId = $('<input type="hidden" name="product_id"/>').appendTo(form);
        productId.val(slug);
        form.find('[name="form_catalog"]').val($.trim(name));
        form.find('.form-popup-catalog__product-name').text($.trim(name));
    });
}

$(function () {
    catalogCart();
    $('body').on('click', '.catalog-cart-add', function () {
        var button = $(this), input = button.closest('.catalog-cart-input').find('[name="quantity"]').first();
        var quantity = normalizeCatalogCartInputQuantity(input.val(), getCatalogCartInputStep(input));
        input.val(quantity);
        button.attr('aria-busy', 'true');
        alymCartRequest({action: 'add', product: button.attr('data-product'), quantity: quantity})
            .then(function () { button.removeAttr('aria-busy'); }, function () { button.removeAttr('aria-busy'); });
        return false;
    });
    $('body').on('click', '.catalog-cart-link, .catalog-cart-in-cart', function () {
        alymCartPending.then(function () { location.href = cart_page; }).catch(function () {});
        return false;
    });
    $('body').on('click', '.catalog-cart-input-plus, .catalog-cart-input-minus', function () {
        var input = $(this).closest('.catalog-cart-input-wrap').find('input');
        if (!$(this).attr('onclick')) {
            var step = getCatalogCartInputStep(input);
            var value = (parseInt(input.val(), 10) || step) + ($(this).hasClass('catalog-cart-input-plus') ? step : -step);
            input.val(normalizeCatalogCartInputQuantity(value, step));
        }
        input.trigger('change');
        return false;
    });
    $('body').on('change', '.catalog-cart-input-wrap input[name="quantity"]', function () {
        var input = $(this), quantity = normalizeCatalogCartInputQuantity(input.val(), getCatalogCartInputStep(input));
        input.val(quantity);
        alymCartTotals();
        if (input.attr('data-product')) alymCartRequest({action: 'update', product: input.attr('data-product'), quantity: quantity});
    });
    $('body').on('click', '.catalog-cart-delete', function () {
        var item = $(this).closest('.alym-checkout__item');
        alymCartRequest({action: 'remove', product: $(this).attr('data-product')}).then(function (cart) {
            item.remove(); alymCartTotals();
            if (!Object.keys(cart).length) location.reload();
        }).catch(function () {});
        return false;
    });
    $('body').on('click', '.catalog-cart-clear', function () {
        alymCartRequest({action: 'clear'}).then(function () { location.href = cart_page; }).catch(function () {});
        return false;
    });
    $('body').on('click', '.btn-order, [data-src="#form-popup-catalog"]', function () { alymPrepareProductOrder(this); });
    $('body').on('change', '.catalog-cart-form [name="form_delivery"]', function () {
        var delivered = $(this).val() === 'delivery', address = $(this).closest('form').find('[name="form_address"]');
        address.prop('disabled', !delivered).closest('label').prop('hidden', !delivered);
    });
    $('body').on('submit', '.alym-checkout-form', function (event) {
        event.preventDefault();
        var form = $(this), button = form.find('button[type="submit"]'), error = form.find('[role="alert"]');
        button.prop('disabled', true); error.text('');
        alymCartPending.then(function () {
            return $.ajax({method: 'POST', url: form.attr('action'), data: new FormData(form[0]), processData: false, contentType: false, dataType: 'text'});
        }).then(function (response) {
            var parts = $.trim(response).split('#');
            if (parts[0] !== 'success') throw new Error(parts[1] || 'Не удалось оформить заказ. Повторите попытку.');
            $('.alym-checkout__item input, .alym-checkout__item button, .catalog-cart-clear').prop('disabled', true);
            form.empty().append($('<h2/>').text('Заказ принят'))
                .append($('<p/>').text('Номер заказа: ' + parts[2] + '. Менеджер свяжется с вами для подтверждения.'))
                .append($('<a class="btn-link" href="/catalog/"/>').text('Продолжить покупки'));
        }).catch(function (failure) {
            error.text(failure.message || 'Не удалось отправить заказ. Проверьте соединение и повторите попытку.');
            button.prop('disabled', false);
        });
    });
});
