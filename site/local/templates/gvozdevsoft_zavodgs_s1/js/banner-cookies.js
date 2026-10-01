(function ($) {
    'use strict';

    var storageKey = 'alymprofi_cookie_consent';

    function hasConsent() {
        try {
            if (window.localStorage.getItem(storageKey) === '1') {
                return true;
            }
        } catch (error) {
            // The cookie fallback below also works when storage is blocked.
        }

        return document.cookie.split(';').some(function (item) {
            return item.trim() === 'cookie_agree=1';
        });
    }

    function rememberConsent() {
        try {
            window.localStorage.setItem(storageKey, '1');
        } catch (error) {
            // A one-year cookie remains available as the fallback.
        }

        var cookie = 'cookie_agree=1; path=/; max-age=31536000; SameSite=Lax';
        if (window.location.protocol === 'https:') {
            cookie += '; Secure';
        }
        document.cookie = cookie;
    }

    var consentAlreadyGiven = hasConsent();

    if (consentAlreadyGiven) {
        var style = document.createElement('style');
        style.id = 'cookie-consent-style';
        style.textContent = '#banner_cookies{display:none!important;}';
        document.head.appendChild(style);
    }

    $(function () {
        var $banner = $('#banner_cookies');

        if (consentAlreadyGiven) {
            $banner.hide();
            return;
        }

        $('#cookies_agree_btn').on('click', function (event) {
            event.preventDefault();
            rememberConsent();
            $banner.fadeOut(250);
        });
    });
})(jQuery);
