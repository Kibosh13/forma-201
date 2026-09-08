if(!('Ultra' in window)){
    window.Ultra = {};
}
Ultra.FormHandler = {
    submitForm: function(form, selectorToReplace, bindInputsMask = {}, callback = function($newBlock){}){

        var $form = $(form);
        var $blockToReplace = $form;
        if(!selectorToReplace){
            selectorToReplace = '#' + form.id;
        }else{
            $blockToReplace = $(selectorToReplace);
        }
        if(!selectorToReplace){
            console.error('У формы должен быть ID');
            return;
        }
        $form.addClass('disabled op-50');
        var self = this;

        this.sendQuery($form.attr('action') || location.href, $form.attr('method') || 'post', new FormData(form))
            .then(function(res){
                var $res;
                try{
                    // var obRes = JSON.parse(res);
                    if(res.data && res.data.data && res.data.data.html){
                        $res = $(res.data.data.html);
                    }
                }
                catch (err){
                    $res = $(res);
                }
                if($res === undefined)
                {
                    $res = $(res);
                }
                //$res = $(res);
                var $newBlock = $res.find(selectorToReplace);
                if(!$newBlock.length){
                    $newBlock = $res.filter(selectorToReplace);
                }

                if(Object.keys(bindInputsMask).length){
                    self.bindInputsMask($newBlock, bindInputsMask);
                }
                if(typeof callback === 'function'){
                    callback($newBlock);
                }
                $blockToReplace.replaceWith($newBlock);
            })
            .catch()
            .then(function(){
                $form.removeClass('disabled op-50');

            })
    },
    sendQuery: function(url, type, data,  contentType = false, processData = false){
        return new Promise(function(resolve, reject){
            $.ajax({
                url: url,
                type: type,
                data: data,
                processData: processData,
                contentType: contentType,
                success: function(res){
                    resolve(res);
                },
                error: function(){
                    reject(['Ошибка обработки запроса']);
                }
            });
        });
    },
    bindInputsMask($form, inputsObj){
        if(!$().mask){
            console.log('haven\'t mask');
            return false;
        }
        for (var prop in inputsObj){
            switch (prop){
                case 'phone':
                    var $inputPhone = $form.find('[name="' + inputsObj[prop] + '"]');
                    if($inputPhone.length > 0){
                        $inputPhone.mask('+7 (000) 000-00-00');
                        $inputPhone.on("input", function () {
                            if ($(this).val() == '+') {
                                $(this).val('+7 (');
                            }

                            if ($(this).val() == '9') {
                                $(this).val('+7 (9');
                            }

                            if ($(this).val() == '+7 (8') {
                                $(this).val('+7 (');
                            }
                            if ($(this).val() == '+7 (87') {
                                $(this).val('+7 (');
                            }

                            if($(this).val() != ''){
                                var tmp = $(this).val().match(/\d/g)[1];
                                if(tmp == '8' || tmp == '7'){
                                    $(this).val('');
                                }
                            }
                        });
                    }
                    break;
                case 'inn':
                    $form.find('[name="' + inputsObj[prop] + '"]').mask('000000000000');
                    break;
                case 'zip':
                    $form.find('[name="' + inputsObj[prop] + '"]').mask('000000');
                    break;
                default:
                    break;
            }
        }
    },
};
