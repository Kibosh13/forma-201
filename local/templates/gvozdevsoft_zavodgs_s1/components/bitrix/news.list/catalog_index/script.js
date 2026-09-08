BX.ready(function() {
    $('.btn-order').on('click', function() {
        let name = $(this).data().name;
        $('.form-popup-catalog__product-name').text(name);
        $('input[name="form_catalog"]').val(name)
    })
})