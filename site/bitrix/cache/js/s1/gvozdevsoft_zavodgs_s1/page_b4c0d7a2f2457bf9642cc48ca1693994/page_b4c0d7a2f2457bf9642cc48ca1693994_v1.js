
; /* Start:"a:4:{s:4:"full";s:118:"/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/iblock.element.add.form/form-review/script.js?17588097974386";s:6:"source";s:103:"/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/iblock.element.add.form/form-review/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
$(document).ready(function(){
    $("#review-add-button").on('click', function(event) {
        event.preventDefault();
        const formData = new FormData($("#review-add")[0]);
        if ($('.success-send-review').length > 0) {
            $('.success-send-review').remove();
        }
        if (formData.get('name').length == 0) {
            $("#review-add").find('#name').addClass('error-form-review');
        } else {
            $("#review-add").find('#name').removeClass('error-form-review');
        }
        if (formData.get('phone').length == 0) {
            $("#review-add").find('#phone').addClass('error-form-review');
        } else {
            $("#review-add").find('#phone').removeClass('error-form-review');
        }
        if (formData.get('review').length == 0) {
            $("#review-add").find('#review').addClass('error-form-review');
        } else {
            $("#review-add").find('#review').removeClass('error-form-review');
        }
        if (!$('#pd')[0].checked) {
            $('#pd').parent().addClass('error-form-review');
        } else {
            $('#pd').parent().removeClass('error-form-review');
        }
    
        if (!$('#pd-access')[0].checked) {
            $('#pd-access').parent().addClass('error-form-review');
        } else {
            $('#pd-access').parent().removeClass('error-form-review');
        }
    
        if ($('.rating-form')[0].getAttribute('data-value') < 1) {
             $('#rating-form').parent().find('.rating-form').addClass('error-form-review-rating');
        } else {
            $('.error-form-review-rating').removeClass('error-form-review-rating');
        }
    
        if (($('.error-form-review-checkbox').length + $('.error-form-review-rating').length) === 0) {
            $.ajax({
                type: "POST",
                url: "/local/ajax/review/",
                data: formData,
                contentType: false,
                processData: false,
                dataType: "json",
                success: function(data) {
                    if (data.error) {
                        for (const key in data.error) {
                            if (key === 'pd' || key === 'pd-access') {
                                $('#' + key).parent().find('.cus-check').addClass('error-form-review-checkbox');
                            } else if (key === 'rating') {
                                $('#rating-form').parent().find('.rating-form').addClass('error-form-review-rating');
                            }
                        }
                    } else if (data.success) {
                        $('#review-add')[0].reset();
                        $('#pd-access')[0].removeAttribute('checked');
                        $('#pd')[0].removeAttribute('checked');
                        $('.act-check').removeClass('act-check');
                        $('.rating-form')[0].setAttribute('data-value', '0');
                        document.querySelectorAll('.rating-form').forEach(dom => new Rating(dom));
                        $('#review-add')[0].insertAdjacentHTML('beforebegin', '<div class="success-send-review">Отзыв отправлен<div>');
                    }
                },
            });
        } else {
            return false;
        }
    });
    
    class RatingAdd {
        constructor(dom) {
            dom.innerHTML = '<svg width="110" height="20"></svg>';
            this.svg = dom.querySelector('svg');
            for(var i = 0; i < 5; i++)
            this.svg.innerHTML += `<polygon data-value="${i+1}"
                transform="translate(${i*22},0)" 
                points="10,1 4,19.8 19,7.8 1,7.8 16,19.8">`;
            this.svg.onclick = e => this.change(e);
            this.render();
        }
    
        change(e) {
            let value = e.target.dataset.value;
            value && (this.svg.parentNode.dataset.value = value); 
            $('#rating-form')[0].value = value;
            this.render();
        }
    
        render() {
            this.svg.querySelectorAll('polygon').forEach(star => {
            let on = +this.svg.parentNode.dataset.value >= +star.dataset.value;
            star.classList.toggle('active', on);
            });
        }
    }
    
    document.querySelectorAll('.rating-form').forEach(dom => new RatingAdd(dom));

    $('#phone').mask('+7 (000) 000-00-00');
});
/* End */
;; /* /local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/iblock.element.add.form/form-review/script.js?17588097974386*/
