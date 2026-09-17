
; /* Start:"a:4:{s:4:"full";s:79:"/local/components/clickon/tags.cloud/templates/only_top/script.js?1779710366320";s:6:"source";s:65:"/local/components/clickon/tags.cloud/templates/only_top/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
document.addEventListener("DOMContentLoaded", function (){
    $('.toggle-cloud-items').on('click', function (){
        $('.item.tire-2').slideToggle();
        var btnText = $(this).attr('data-toggle');
        $(this).attr('data-toggle', $(this).text());
        $(this).text(btnText);
        return false;
    })
})
/* End */
;
; /* Start:"a:4:{s:4:"full";s:103:"/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js?1780584785431";s:6:"source";s:89:"/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
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
    brand = 'ИП Притуленко С. Д.';
}

const product = new Product();


/* End */
;; /* /local/components/clickon/tags.cloud/templates/only_top/script.js?1779710366320*/
; /* /local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js?1780584785431*/
