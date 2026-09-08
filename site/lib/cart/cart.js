var cart_ajax = "/lib/cart/cart-ajax.php";
var cart_page = "/order/";

function getCatalogCartInputStep(input)
{
	var step = parseInt($(input).attr("data-step"));
	return step > 0 ? step : 1;
}

function normalizeCatalogCartInputQuantity(quantity, step)
{
	quantity = parseInt(quantity);
	step = step > 0 ? step : 1;
	if (!(quantity > 0)) {
		return step;
	}
	return Math.ceil(quantity / step) * step;
}

$(document).ready(function()
{
	catalogCart();
	
	$("body").on("click", ".catalog-cart-add", function()
	{
		var _this = $(this);
		var input = $(this).parent().find("[name='quantity']");
		var quantity = normalizeCatalogCartInputQuantity(input.val(), getCatalogCartInputStep(input));
		input.val(quantity);
		$.ajax({method: "POST", url: cart_ajax, data: {action: "add", product: $(this).attr("data-product"), quantity: quantity}}).done(function(msg)
		{
			catalogCart();
		});
		return false;
	});
	
	$("body").on("input keydown", ".catalog-cart-input-wrap input", function()
	{
		var step = getCatalogCartInputStep(this);
		if (!/^\d*$/.test($(this).val())) {
			$(this).val(step);
		}
		else
		{
			if ($(this).val() !== "" && parseInt($(this).val()) < 1) $(this).val(step);
		}
	});
	
	$("body").on("click", ".catalog-cart-input-plus", function()
	{
		if ($(this).closest(".catalog-detail-btns").length > 0) return false;

		var input = $(this).parent().find("input");
		var step = getCatalogCartInputStep(input);
		var currentValue = parseInt(input.val());
		var v = (currentValue > 0 ? Math.floor(currentValue / step) * step : 0) + step;
		input.filter(":enabled").val(v).change();
		return false;
	});
	
	$("body").on("click", ".catalog-cart-input-minus", function()
	{
		if ($(this).closest(".catalog-detail-btns").length > 0) return false;

		var input = $(this).parent().find("input");
		var step = getCatalogCartInputStep(input);
		var currentValue = parseInt(input.val());
		var v = (currentValue > 0 ? Math.ceil(currentValue / step) * step : step) - step;
		if (v < step) v = step;
		input.filter(":enabled").val(v).change();
		return false;
	});
	
	$("body").on("click", ".catalog-cart-clear", function()
	{
		$.ajax({method: "POST", url: cart_ajax, data: {action: "clear"}}).done(function(msg)
		{
			document.location = cart_page;
		});
		return false;
	});
	
	$("body").on("click", ".catalog-cart-delete", function()
	{
		var product_id = $(this).attr("data-product");
		$.ajax({method: "POST", url: cart_ajax, data: {action: "remove", product: product_id}}).done(function(msg)
		{
			var cart = $.parseJSON(msg);
			var cartSumm = 0;
			
			for (var i in cart)
			{
				var price = parseFloat($(".catalog-cart-table-tr[data-product='"+i+"'] input[name^='quantity']").attr("data-price"));
				
				cartSumm += price * cart[i];
				
				$(".catalog-cart-table-tr[data-product='"+i+"'] .catalog-cart-table-td[data-cell='summ'] span").html(cart[i] * price);
			}
			
			if (cartSumm == 0)
			{
				document.location = cart_page;
			}
			else
			{
				$(".catalog-cart-table-tr[data-product='"+product_id+"']").remove();
				$(".catalog-cart-table-summ span").html(cartSumm);
			}
		});
		return false;
	});
	
	$("body").on("change", ".catalog-cart-table .catalog-cart-input-wrap input", function()
	{
		var quantity = normalizeCatalogCartInputQuantity($(this).val(), getCatalogCartInputStep(this));
		$(this).val(quantity);
		$.ajax({method: "POST", url: cart_ajax, data: {action: "update", product: $(this).attr("data-product"), quantity: quantity}}).done(function(msg)
		{
			var cart = $.parseJSON(msg);
			var cartSumm = 0;
			
			for (var i in cart)
			{
				var price = parseFloat($(".catalog-cart-table-tr[data-product='"+i+"'] input[name^='quantity']").attr("data-price"));
				
				cartSumm += price * cart[i];
				
				$(".catalog-cart-table-tr[data-product='"+i+"'] .catalog-cart-table-td[data-cell='summ'] span").html(cart[i] * price);
			}
			
			$(".catalog-cart-table-summ span").html(cartSumm);
		});
	});

	$("body").on("change", ".catalog-cart-input-wrap input[data-product]", function()
	{
		if ($(this).closest(".catalog-cart-table").length > 0) {
			return;
		}

		var input = $(this);
		var quantity = normalizeCatalogCartInputQuantity(input.val(), getCatalogCartInputStep(this));
		input.val(quantity);

		$.ajax({method: "POST", url: cart_ajax, data: {action: "update", product: input.attr("data-product"), quantity: quantity}}).done(function(msg)
		{
			applyCatalogCartState($.parseJSON(msg));
		});
	});
	
	$("body").on("change", ".catalog-cart-form select[name='form_delivery']", function()
	{
		if ($(this).val() == "self")
		{
			$(".catalog-cart-form textarea[name='form_address']").prop("disabled", true).hide();
		}
		else
		{
			$(".catalog-cart-form textarea[name='form_address']").prop("disabled", false).show();
		}
	});
});

function catalogCart()
{
	$.ajax({method: "POST", url: cart_ajax, data: {action: "cart"}}).done(function(msg)
	{
		applyCatalogCartState($.parseJSON(msg));
	});
}

function applyCatalogCartState(cart)
{
	cart = cart || {};

	for (var i in cart)
	{
		if (cart.hasOwnProperty(i))
		{
			var productButtons = $(".catalog-cart-input-btn[data-product='"+i+"']");
			productButtons.each(function()
			{
				var button = $(this);
				var input = button.closest(".catalog-cart-input").find("[name='quantity']").first();
				input.prop("disabled", false).attr("data-product", i).val(cart[i]);
				button.removeClass("catalog-cart-add").addClass("catalog-cart-in-cart").html("<span style='padding-right: 5px;'><i class='fa fa-check' aria-hidden='true'></i></span>В корзине");
			});
		}
	}

	var count = Object.keys(cart).length;
	$(".catalog-cart-link").remove();

	if (count > 0 && document.location.pathname != cart_page)
	{
		$("body").append("<a class='catalog-cart-link' href='"+cart_page+"'><span class='catalog-cart-link-basket'><i class='fa fa-shopping-cart' aria-hidden='true'></i></span><span class='catalog-cart-link-count'>"+count+"</span></a>");
	}
}
