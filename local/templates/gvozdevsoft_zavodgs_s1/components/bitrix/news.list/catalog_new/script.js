BX.ready(function() {
    $('.btn-order').on('click', function() {
        let name = $(this).data().name;
        let price = $(this).data().price;
        $('.form-popup-catalog__product-name').text(name);
        $('input[name="form_catalog"]').val(name);
        product.name = name;
        product.price = price;
    })
})

class Product {
    name = '';
    price = 0;
    brand = 'dial';
}

const product = new Product();

