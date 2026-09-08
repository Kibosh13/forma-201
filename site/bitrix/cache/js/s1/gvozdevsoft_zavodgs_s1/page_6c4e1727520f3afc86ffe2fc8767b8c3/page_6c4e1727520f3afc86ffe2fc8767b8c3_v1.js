
; /* Start:"a:4:{s:4:"full";s:72:"/local/components/clickon/kp/templates/.default/script.js?17769444404737";s:6:"source";s:57:"/local/components/clickon/kp/templates/.default/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
class commercialOffer {
    
    product = {};

    showHideElements(id) {
        const table = $('#' + id);
        if (table.hasClass('hide')) {
            table.removeClass('hide');
        } else {
            table.addClass('hide');
        }
    }

    addQuantityProduct(id) {
        const quantityEl = $('#quantity-' + id);
        const button = $('#button-' + id);
        if (quantityEl[0].value > 0) {
            const name = $('#name-' + id)[0].textContent;
            this.product[id] = quantityEl[0].value;
            button[0].innerText = '<';
            button[0].classList.add('success');
        } else if (button[0].classList.contains('success')) {
            delete this.product[id];
            button[0].classList.remove('success');
            button[0].innerText = '+';
        }
    }

    isValidPhoneNumber(phone) {
        phone.value = phone.value.replace(/[^\d]/g, '');
        if (phone.value == '9') {
            phone.value = '79';
        }
        $(phone)[0].value = "+" + phone.value;
        if (!/^\+7\d+$/.test(phone.value) || /^\+78\d*$/.test(phone.value)) {
            $(phone)[0].value = '+7';
            return false;
        } else {
            if (!/^\+79\d*$/.test(phone.value) || /^\+79\d{10}$/.test(phone.value)) {
                $(phone)[0].value = phone.value.slice(0, -1);
                return false;
            }
        }
        return true;
    }

    intersection (a, b) {
        for(let i = 0; i < b.length; i++) {

            if(a.filter(av => av.indexOf(b[i]) !== -1).length === 0) {
                return false;
            }
        }
        return true;
    }

    cleanQuery(query) {
        if(typeof query !== 'string') {
            query = '';
        }
        return query.replaceAll(/\s+/g, ' ').split(' ').filter(Boolean).map(r => r.toLowerCase());
    }

    doesSatisfySearch(chunks, $el) {
        const text = $el.text();


        const targetChunks = [
            ...kp.cleanQuery(text),
        ];
        return kp.intersection(targetChunks, chunks);
        
    }
}

const kp = new commercialOffer;

$(function(){
    $('#kp-form').on('submit', function(event) {
        event.preventDefault();
        const form = $('#kp-form');
        const name = form.find('[name="fio"]')[0].value;
        const phone = form.find('[name="phone"]')[0].value;
        const email = form.find('[name="email"]')[0].value;
        const inn = form.find('[name="inn"]')[0].value;
        const ogrn = form.find('[name="ogrn"]')[0].value;
        const address = form.find('[name="address"]')[0].value;
        if (Object.keys(kp.product).length) {
            BX.ajax.runComponentAction('clickon:kp', 'create', {
                mode: 'ajax',
                data: {
                    name: name,
                    phone: phone,
                    email: email,
                    inn: inn,
                    ogrn: ogrn,
                    address: address,
                    product: kp.product
                }
            }).then(function (response) {
                if (response.data.status == 'success') {
                    const formblock = $('.form-block')[0];
                    form[0].style = 'display:none';
                    formblock.append(response.data.message);
                }
            })
        } else {
            
        }
    });
    $('#query-price-input').keyup(function () {
        let query = $(this).val();
        let queryChunks = kp.cleanQuery(query);
        

        const $title = $('.s-item__title');

        $title.each(function(){
            const $this = $(this);
            const $parent = $this.closest('.s-item');
            if(kp.doesSatisfySearch(queryChunks, $this)) {
                $parent.attr('data-show', 1).show();
            } else {
                $parent.attr('data-show', 0).hide();
            }
        });
        


        $('.price-order__item').each(function(){
            const $this = $(this);
            const $services = $this.find('.price-order__item__list .s-item[data-show="1"]');
            
            $services.length > 0
                ? $this.show()
                : $this.hide()
        });
        return;
    })

    $('.remove-quantity').on('click', function() {
        const quantity = $('#' + this.dataset.quantity)[0];
        if (parseInt(quantity.value) > 0) {
            quantity.value = parseInt(quantity.value) - 1;
        }
        
    })

    $('.add-quantity').on('click', function() {
        const quantity = $('#' + this.dataset.quantity)[0];
        if (parseInt(quantity.value) > 0) {
            quantity.value = parseInt(quantity.value) + 1;
        } else {
            quantity.value = 1;
        }
    })
})

/* End */
;; /* /local/components/clickon/kp/templates/.default/script.js?17769444404737*/
