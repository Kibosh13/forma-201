$(function(){
    $('#cookies_agree_btn').on('click', function (){
        let date = new Date(Date.now() + (86400e3 * 30));
        date = date.toUTCString();
        document.cookie = "cookie_agree=1; path=/; expires=" + date;
        $('#banner_cookies').fadeOut(400);
        return false;
    });
});