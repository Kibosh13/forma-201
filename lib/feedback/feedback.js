$(document).ready(function(){
    
    $('.feedback-file__box').click(function(){
        $(this).closest('form').find('input[type=file]').click();
    });

    $('input[type=file]').change(function(){
        var form = $(this).closest('form');
        var count = form.find("input:file")[0].files.length;
        form.find('.feedback-file__text').text('Выбрано '+count+' файл(ов)');
    });

    $('input[name="form_link_user"]').on('focus', function(){
        let $this = $(this);
        $this.attr('data-placeholder', $this.attr('placeholder'));
        $this.attr('placeholder', '/forma-201/lib/feedback/feedback.js')
    }).on('focusout', function(){
        let $this = $(this);
        $this.attr('placeholder', $this.attr('data-placeholder'))
    });

    /* FORM */

    $(".feedback").submit(function(event){
        event.preventDefault();
        var fb = $(this);
        
        fb.find('button[type="submit"]').prop('disabled', true);
        fb.find("input, textarea").removeClass('error');
        if(fb.find('.feedback-garant__checkbox').prop('checked')){
            var formData = new FormData(this);
            $.ajax({
                url: "/lib/feedback/mail-form.php",
                type: "post",
                data: formData,
                success: function(data) {
                    // console.log(data);
                    data = data.trim();
                    data = data.split("#");
                    if (data[0] == "error" || data[0] == "empty_field") {
                        if (data[0] == "error") {
                            fb.find(".feedback-garant__mess-error").html(data[1]);
                        }
                        if (data[0] == "empty_field") {
                            var nameField = data[1];
                            fb.find("input[name='"+nameField+"'], textarea[name='"+nameField+"']").addClass('error');
                        }
                        fb.find('button[type="submit"]').removeAttr('disabled');
                    } else {
                        let arClasses = fb[0].className.split(' ');
                        if(arClasses.length > 0){
                            let arRes = arClasses.filter(function (item,index,array) {
                                if(item.includes("__form")){
                                    return true;
                                }
                            });
                            switch (arRes[0]) {
                                case 'form-popup-callback__form':
                                    ym(97698578,'reachGoal','zakazZvonok')
                                    break;
                                case 'form-popup-found-cheaper__form':
                                    ym(97698578,'reachGoal','nashliDeshewle')
                                    break;
                                case 'form-slider-main__form':
                                    ym(97698578,'reachGoal','zakazZvonok')
                                    break;
                                case 'form-popup-request__form':
                                    let parentForm = fb.parent();
                                    let id = parentForm.attr("id");
                                    if(id === 'form-popup-catalog')
                                    {
                                        ym(97698578,'reachGoal','zaprosStoimost');
                                        if (typeof product != 'undefined') {
                                            if (product.quantity) {
                                                window.dataLayer.push({
                                                    "ecommerce": {
                                                        "currencyCode": "RUB",
                                                        "purchase": {
                                                            "actionField": {
                                                                "id": data[2]
                                                            },
                                                            "products": [
                                                                {
                                                                    "id": product.id,
                                                                    "name": product.name,
                                                                    "price": parseFloat(product.price),
                                                                    "brand": "Производитель алюминиевого профиля",
                                                                    "quantity": parseInt(product.quantity),
                                                                    "position": 1
                                                                }
                                                            ]
                                                        }
                                                    }
                                                });
                                            } else {
                                                window.dataLayer.push({
                                                    "ecommerce": {
                                                        "currencyCode": "RUB",
                                                        "purchase": {
                                                            "actionField": {
                                                                "id": data[2]
                                                            },
                                                            "products": [
                                                                {
                                                                    "id": product.id,
                                                                    "name": product.name,
                                                                    "price": parseFloat(product.price),
                                                                    "brand": "Производитель алюминиевого профиля",
                                                                    "position": 1
                                                                }
                                                            ]
                                                        }
                                                    }
                                                });
                                            }
                                        } else {
                                            console.log('Error! typeof product = ' + typeof product);
                                        }
                                    }else{
                                        ym(97698578,'reachGoal','napisatNam');
                                    }
                                    break;
                                case 'form-popup-estimate__form':
                                    console.log(arRes[0])
                                    console.log('estimate__form')
                                    r = 'estimate__form';
                                    break;
                                case 'form-service-bottom__form':
                                    ym(97698578,'reachGoal','consult')
                                    break;
                                case 'form-service-top__form':
                                    ym(97698578,'reachGoal','zakazUsluga')
                                    break;
                            }
                        }
                        fb.find('.feedback-garant__box').css('display', 'none');
                        fb.find('.feedback-garant').append('<div class="feedback-send-message">'+data[1]+'</div>');
                        
                        setTimeout(() => {
                            $.fancybox.close();
                            if (fb.find('.form-popup-request__textarea')[0]) {
                                fb.find('.form-popup-request__textarea')[0].value = '';
                            }
                            if (fb.find('.form-contacts__textarea')[0]) {
                                fb.find('.form-contacts__textarea')[0].value = '';
                            }
                            if (fb.find('input[name="form_name"]')[0]) {
                                fb.find('input[name="form_name"]')[0].value = '';
                            }
                            if (fb.find('input[name="form_phone"]')[0]) {
                                fb.find('input[name="form_phone"]')[0].value = '';
                            }
                            fb.find('.feedback-garant__checkbox')[0].checked = false;
                            fb.find('.feedback-garant__checkbox')[1].checked = false;
                            fb.find('.feedback-garant__box').css('display', 'block');
                            fb.find('.feedback-send-message').remove();
                            fb.find('.form-popup-request__btn').removeAttr('disabled');
                        }, 5000);
                    }
                },
                error: function(){
                    alert("Ваша заявка не отправлена! Попробуйте еще раз");
                    fb.find('button[type="submit"]').prop('disabled', false);
                },
                cache: false,
                contentType: false,
                processData: false
            });
            $(this).find(".feedback-garant__mess-error").html("");
        } else {
            $(this).find(".feedback-garant__mess-error").html("Необходимо дать согласие");
        }
    });
    $("[data-fancybox]").fancybox({
		beforeShow: function( instance, slide ) {
            if (slide) {
                if (slide.src.startsWith("#")) {
                    if ($(slide.src).find('input[name="form_name"]')[0]) {
                        $(slide.src).find('input[name="form_name"]')[0].value = '';
                    }
                    if ($(slide.src).find('input[name="form_phone"]')[0]) {
                        $(slide.src).find('input[name="form_phone"]')[0].value = '';
                    }
                    if ($(slide.src).find('.form-popup-request__textarea')[0]) {
                        $(slide.src).find('.form-popup-request__textarea')[0].value = '';
                    }
                    if ($(slide.src).find('.feedback-garant__checkbox')[0]) {
                        $(slide.src).find('.feedback-garant__checkbox')[0].checked = false;
                    }
                    if ($(slide.src).find('.feedback-garant__checkbox')[1]) {
                        $(slide.src).find('.feedback-garant__checkbox')[1].checked = false;
                    }
                }
            }
        }
    });
});
