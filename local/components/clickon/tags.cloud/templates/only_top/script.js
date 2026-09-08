document.addEventListener("DOMContentLoaded", function (){
    $('.toggle-cloud-items').on('click', function (){
        $('.item.tire-2').slideToggle();
        var btnText = $(this).attr('data-toggle');
        $(this).attr('data-toggle', $(this).text());
        $(this).text(btnText);
        return false;
    })
})