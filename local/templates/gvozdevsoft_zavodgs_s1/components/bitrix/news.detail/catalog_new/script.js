$(function () {
    $('.popup-with-form-ral').click(function(){
        $.magnificPopup.open({
            items: {
                src: $(this).data('href')
            },
            type: 'ajax',
            focus: '#name',
            closeOnBgClick: false,
            callbacks: {
                beforeOpen: function() {
                    if($(window).width() < 700) {
                        this.st.focus = false;
                    } else {
                        this.st.focus = '#name';
                    }
                },
                ajaxContentAdded: function() {
                    ralInput();
                    $('.color-ral-btn').off('click')
                }
            }
        });
        return false;
    });

    $('.ral-block').find('.price').hide()

    product.id = $('#product-id')[0].value;
    product.name = $('h1')[0].textContent;
    product.price = $('.pricespace')[0].textContent;
    product.quantity = $('input[name="quantity"]')[0].value;
    updateTestPriceTotal();

    $('.catalog-cart-input-detail-minus, .catalog-cart-input-detail-plus').on('click', function () {
        setTimeout(updateTestPriceTotal, 0);
    });
    $('input[name="quantity"]').on('input change', updateTestPriceTotal);
})

function ralInput(){

    $('.color-ral').on('click',function(){

        $('.color-ral-btn').attr('data-href',"/ajax/ral_form/?offerId="+$(this).attr('data-offerId')+"&offerName="+$(this).attr('data-offerName')+"&ralValue="+$(this).attr('data-title')+"&ralColor="+$(this).attr('data-rgb'));
        $('.color-ral-btn').text('Заказать');
        $('.color-ral-btn').attr('data-title',$(this).attr('data-title'))
        $('.color-ral-btn').attr('data-rgb',$(this).attr('data-rgb'))
        $.magnificPopup.close();

        $('.ral-name').text($(this).attr('data-title'));
        $('.ral-description').css({'background':$(this).attr('data-rgb')})

        $('.color-ral-btn').attr('onclick',"return false;")

        $('.color-ral-btn').on('click',function () {
            $.magnificPopup.open({
                items: {
                    src: $(this).data('href')
                },
                type: 'ajax',
                focus: '#name',
                closeOnBgClick: false,
                callbacks: {
                    beforeOpen: function() {
                        if($(window).width() < 700) {
                            this.st.focus = false;
                        } else {
                            this.st.focus = '#name';
                        }
                    },
                    ajaxContentAdded: function () {
                        console.log( $('.color-ral-btn').attr('data-title'))
                        $('.ral-value').text( $('.color-ral-btn').attr('data-title'))
                        $('.ral-description').css({'background':$('.color-ral-btn').attr('data-rgb')})
                        $('#ral').val($('.color-ral-btn').attr('data-title'));
                    }
                }
            });
        })
    })

}

class Product {
    id = 0;
    name = '';
    price = 0;
    quantity = 0;
    brand = 'Производитель алюминиевого профиля';
}

const product = new Product();

function formatPriceValue(value) {
    return Number(value).toLocaleString('ru-RU', {
        minimumFractionDigits: value % 1 === 0 ? 0 : 2,
        maximumFractionDigits: 2
    });
}

function updateTestPriceTotal() {
    const total = $('[data-test-price-total]')[0];
    const quantity = $('input[name="quantity"]')[0];
    if (!total || !quantity) {
        return;
    }

    const retailPrice = parseFloat(total.dataset.retailPrice) || 0;
    const smallPrice = parseFloat(total.dataset.smallPrice) || 0;
    const bigPrice = parseFloat(total.dataset.bigPrice) || 0;
    const smallMin = parseFloat(total.dataset.smallMin) || 0;
    const bigMin = parseFloat(total.dataset.bigMin) || 0;
    const quantityValue = parseInt(quantity.value, 10) || 0;
    const retailTotal = quantityValue * retailPrice;
    let unitPrice = retailPrice;
    let priceType = 'Розничная цена';

    if (bigMin > 0 && retailTotal >= bigMin && bigPrice > 0) {
        unitPrice = bigPrice;
        priceType = 'Крупный опт';
    } else if (smallMin > 0 && retailTotal >= smallMin && smallPrice > 0) {
        unitPrice = smallPrice;
        priceType = 'Мелкий опт';
    }

    total.querySelector('[data-test-price-total-sum]').textContent = formatPriceValue(quantityValue * unitPrice);
    total.querySelector('[data-test-price-total-unit]').textContent = formatPriceValue(unitPrice);
    total.querySelector('[data-test-price-total-type]').textContent = priceType;
    product.price = unitPrice;
}

function changeQuantity(inc) {
    const quantity = $('input[name="quantity"]')[0];
    const value = parseInt(quantity.value);
    const step = parseInt(quantity.dataset.step) > 0 ? parseInt(quantity.dataset.step) : 1;
    if (inc === '+' && quantity.value < 1000) {
        quantity.value = (value > 0 ? Math.floor(value / step) * step : 0) + step;
    } else if(inc === '-' && value !== step) {
        quantity.value = (value > 0 ? Math.ceil(value / step) * step : step) - step;
        if (quantity.value < step) {
            quantity.value = step;
        }
    } else if(inc === 'change') {
        if (value < step) {
            quantity.value = step;
        } else if (value > 1000) {
            quantity.value = 1000;
        } else {
            quantity.value = Math.ceil(value / step) * step;
        }
    }
    const inputName = $('#form-popup-catalog').find('input[name="form_catalog"]')[0];
    const textName = $('#form-popup-catalog').find('.form-popup-catalog__product-name')[0];
    inputName.value = inputName.value.replace(/;\s*\d+\s*шт/gi, '') + '; ' + quantity.value + ' шт';
    textName.textContent = textName.textContent.replace(/;\s*\d+\s*шт/gi, '') + '; ' + quantity.value + ' шт';
    product.id = $('#product-id')[0].value;
    product.name = $('h1')[0].textContent;
    product.price = $('.pricespace')[0].textContent;
    product.quantity = quantity.value;
    updateTestPriceTotal();
}
