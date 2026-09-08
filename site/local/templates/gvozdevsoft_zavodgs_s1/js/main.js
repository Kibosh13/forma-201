$(document).ready(function(){

	$(".mygallery").justifiedGallery({
		rowHeight : 150,
		lastRow : 'nojustify',
		captions : false,
		border : 0,
		margins : 7
	});

	$(".mygallery2").justifiedGallery({
		rowHeight : 200,
		lastRow : 'nojustify',
		captions : false,
		border : 0,
		margins : 7
	});

	$(".mygallery-catalog").justifiedGallery({
		rowHeight : 70,
		lastRow : 'nojustify',
		captions : false,
		border : 0,
		margins : 7
	});

	//Slider main page
	$(".header-slider").owlCarousel({
		items:1,
		loop:true,
		margin:0,
		nav:false,
		autoplay:true,
		autoplayTimeout:6000,
		smartSpeed:750,
		autoplayHoverPause:true
	});

	//Slider section main page
	$(".section-slider").owlCarousel({
		items:4,
		loop:true,
		margin:30,
		nav:true,
		dots: false,
		autoplay:false,
		smartSpeed:750,
		autoplayHoverPause:false,
		responsive:{
			0:{
				items:1
			},
			768:{
				items:2
			},
			992:{
				items:4
			}
		}
	});

	//Slider section main page
	$(".section-slider2").owlCarousel({
		items:3,
		loop:true,
		margin:30,
		nav:true,
		dots: false,
		autoplay:false,
		smartSpeed:750,
		autoplayHoverPause:false,
		responsive:{
			0:{
				items:1
			},
			768:{
				items:2
			},
			992:{
				items:3
			}
		}
	});

	//Slider section main page partners
	$(".section-slider-partners").owlCarousel({
		items:6,
		loop:true,
		margin:30,
		nav:false,
		dots: false,
		autoplay:true,
		smartSpeed:750,
		autoplayHoverPause:false,
		responsive:{
			0:{
				items:2
			},
			768:{
				items:3
			},
			992:{
				items:5
			}
		}
	});

	$(".section-slider .owl-next, .section-slider2 .owl-next").html("<i class='fa fa-angle-right nextarr' aria-hidden='true'></i>");
	$(".section-slider .owl-prev, .section-slider2 .owl-prev").html("<i class='fa fa-angle-left prevarr' aria-hidden='true'></i>");

	// Fixed - sidebar
	$('#sidebar_fixed').sticky({topSpacing:70});

	// Action - sidebar
	$(".sidebar-action").owlCarousel({
		items:1,
		loop:true,
		margin:0,
		nav:true,
		dots: false,
		autoplay:true,
		smartSpeed:750,
		autoplayHoverPause:false,
	});

	// Action - sidebar - ajax
	$actionSidebarAjax = $(".sidebar-action-ajax");
	$actionSidebarAjax.owlCarousel({
		items:1,
		loop:true,
		margin:0,
		nav:true,
		dots: false,
		autoplay:true,
		smartSpeed:750,
		autoplayHoverPause:false,
		onInitialized: fetchPromo,
	});

	// $actionSidebarAjax.on('drag.owl.carousel', fetchPromo);
	// $(".sidebar-action-ajax button").on('click', fetchPromo);

	// Review, portfolio - sidebar
	$(".sidebar-review, .sidebar-portfolio").owlCarousel({
		items:1,
		loop:true,
		margin:0,
		nav:true,
		dots: false,
		autoplay:false,
		smartSpeed:750,
		autoplayHoverPause:false,
	});

	$("#sidebar .owl-next").html("<i class='fa fa-arrow-circle-right nextarr' aria-hidden='true'></i>");
	$("#sidebar .owl-prev").html("<i class='fa fa-arrow-circle-left prevarr' aria-hidden='true'></i>");

	// Taby - calc
	$(".tabs").lightTabs();

	//Maska nomer phone
	$(".inputmask").inputmask(typeof window.inputMaskPattern !== 'undefined'?window.inputMaskPattern:"+7 (999) 999-99-99");

	// Spaces in a prices
	$(".pricespace").each(function(){
		var price = $(this).html();
		var parts = (price + '').split('.');
		var main = parts[0];
		var len = main.length;
		var pricetxt = '';
		var i = len - 1;
		while(i >= 0) {
			pricetxt = main.charAt(i) + pricetxt;
			if ((len - i) % 3 === 0 && i > 0) {
				pricetxt = ' ' + pricetxt;
			}
			--i;
		}
		if (parts.length > 1) { pricetxt += '.' + parts[1]; }
		$(this).html(pricetxt);
	});


	// $(document).on('click', '.menu-section-active2', function (){
	$(document).on('click', '.menu-section-active2 .menu-name-active2, .menu-element-items a.parent', function (){
		$(this).parent().toggleClass('opened');
		return false;
	})

	$(window).scroll(function(){
		var aTop = $('#header').height();
		if($(this).scrollTop()>=aTop){
			$('body').addClass('fixed-top');
		} else {
			$('body').removeClass('fixed-top');
		}
	});

	
	//Закрытие при клике за пределами
	$(document).click(function (e) {
		var container = $('.phone-menu, .minmenu, .search-menu, #horizontal-multilevel-menu');
		console.log(container.has(e.target).length === 0);
		console.log($(e.target).closest('body'));
		if (container.has(e.target).length === 0 && ($(e.target).closest('body').length > 0)) {
			$('.feedback-block').hide();
			if (window.innerWidth < 770) {
				$('#horizontal-multilevel-menu').hide();
			}
			if(!$('.mob-search').hasClass('hide')){
				$('.mob-search').addClass('hide');
			}
		};
	});


	$('.catalog-cart-add').on('mouseover', function(){
		let $this = $(this);
		$this.append('<div class="text-hover-buy-btn">Оформление товара, только для юридических лиц</div>')
	}).on('mouseout', function(){
		let $this = $(this);
		$('.text-hover-buy-btn').remove();
	});
	
});

function fetchPromo(){
	BX.ajax.runAction('dial:utils.promotionsidebarcontroller.fetchPromotionSidebar').then(function (response){
		$owlSlide = $(response.data.html).children();
		$actionSidebarAjax.trigger("remove.owl.carousel", [1]);
		if( $owlSlide[0].classList.value === 'sidebar-action owl-carousel')
		{
			$($($owlSlide[0]).children()).each(function (i,e) {
				if(i === 0 ){
					return true;
				}
				$actionSidebarAjax.trigger('add.owl.carousel', [e])
			})
		}else{
			$owlSlide.each(function (i,e) {
			// $($($owlSlide[0]).children()).each(function (i,e) {
				if(i === 0 ){
					return true;
				}
				$actionSidebarAjax.trigger('add.owl.carousel', [e])
			})
		}
		$actionSidebarAjax.trigger("refresh.owl.carousel");
		$actionSidebarAjax.trigger("to.owl.carousel", [1]);

	});
	// $(".sidebar-action-ajax button").off('click',fetchPromo);
	// $actionSidebarAjax.off('drag.owl.carousel', fetchPromo);
}
