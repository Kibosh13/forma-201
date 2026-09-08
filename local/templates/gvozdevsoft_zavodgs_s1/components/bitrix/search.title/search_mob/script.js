window.addEventListener('load', function () {
    var searchGlass = $(".header-inner-sticky .search-glass");
    if(searchGlass){
        searchGlass.on("click", function (){
            var parSticky = $(this).closest(".header-inner-sticky");
            var parent = $(this).parent();
            // $(parent).toggleClass("show")
            parent[0].classList.toggle("show");

            parSticky[0].classList.toggle("show-search");
        });
    }
})


$(function(){
    $('.search-menu').on('click', function (e) {
        e.stopPropagation();
        const search = $('.mob-search');
        if (search.length > 0) {
            if (search[0].classList.contains('hide')) {
                search[0].classList.remove('hide');
                
                $('.feedback-block').hide();
                $('#horizontal-multilevel-menu').hide();
            } else {
                search[0].classList.add('hide');
            }
        }
        return false;
    })

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.mob-search')) {
            const search = $('.mob-search');
            if (search.length > 0 && !search[0].classList.contains('hide')) {
                search[0].classList.add('hide');
            }
        }
    });
})
