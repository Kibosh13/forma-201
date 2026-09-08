
; /* Start:"a:4:{s:4:"full";s:123:"/forma-201/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/catalog.smart.filter/filter_catalog_top/script.js?178704644228534";s:6:"source";s:107:"/forma-201/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/catalog.smart.filter/filter_catalog_top/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
function JCSmartFilter(ajaxURL, viewMode)
{
	this.ajaxURL = ajaxURL;
	this.form = null;
	this.timer = null;
	this.cacheKey = '';
	this.cache = [];
	this.viewMode = viewMode;
}

JCSmartFilter.prototype.keyup = function(input)
{
	if(!!this.timer)
	{
		clearTimeout(this.timer);
	}
	this.timer = setTimeout(BX.delegate(function(){
		this.reload(input);
	}, this), 500);
};

JCSmartFilter.prototype.click = function(checkbox)
{
	if(!!this.timer)
	{
		clearTimeout(this.timer);
	}

	this.timer = setTimeout(BX.delegate(function(){
		this.reload(checkbox);
	}, this), 500);
};

JCSmartFilter.prototype.reload = function(input)
{
	if (this.cacheKey !== '')
	{
		//Postprone backend query
		if(!!this.timer)
		{
			clearTimeout(this.timer);
		}
		this.timer = setTimeout(BX.delegate(function(){
			this.reload(input);
		}, this), 1000);
		return;
	}
	this.cacheKey = '|';

	this.position = BX.pos(input, true);
	this.form = BX.findParent(input, {'tag':'form'});
	if (this.form)
	{
		var values = [];
		values[0] = {name: 'ajax', value: 'y'};
		this.gatherInputsValues(values, BX.findChildren(this.form, {'tag': new RegExp('^(input|select)$', 'i')}, true));

		for (var i = 0; i < values.length; i++)
			this.cacheKey += values[i].name + ':' + values[i].value + '|';

		if (this.cache[this.cacheKey])
		{
			this.curFilterinput = input;
			this.postHandler(this.cache[this.cacheKey], true);
		}
		else
		{
			this.curFilterinput = input;
			BX.ajax.loadJSON(
				this.ajaxURL,
				this.values2post(values),
				BX.delegate(this.postHandler, this)
			);
		}
	}
};

JCSmartFilter.prototype.updateItem = function (PID, arItem)
{
	if (arItem.PROPERTY_TYPE === 'N' || arItem.PRICE)
	{
		var trackBar = window['trackBar' + PID];
		if (!trackBar && arItem.ENCODED_ID)
			trackBar = window['trackBar' + arItem.ENCODED_ID];

		if (trackBar && arItem.VALUES)
		{
			if (arItem.VALUES.MIN && arItem.VALUES.MIN.FILTERED_VALUE)
			{
				trackBar.setMinFilteredValue(arItem.VALUES.MIN.FILTERED_VALUE);
			}

			if (arItem.VALUES.MAX && arItem.VALUES.MAX.FILTERED_VALUE)
			{
				trackBar.setMaxFilteredValue(arItem.VALUES.MAX.FILTERED_VALUE);
			}
		}
	}
	else if (arItem.VALUES)
	{
		for (var i in arItem.VALUES)
		{
			if (arItem.VALUES.hasOwnProperty(i))
			{
				var value = arItem.VALUES[i];
				var control = BX(value.CONTROL_ID);

				if (!!control)
				{
					var label = document.querySelector('[for="'+value.CONTROL_ID+'"]');
					if (value.DISABLED)
					{
						if (label)
							BX.addClass(label, 'disabled');
						else
							BX.addClass(control.parentNode, 'disabled');
					}
					else
					{
						if (label)
							BX.removeClass(label, 'disabled');
						else
							BX.removeClass(control.parentNode, 'disabled');
					}

					if (value.hasOwnProperty('ELEMENT_COUNT'))
					{
						label = document.querySelector('[data-role="count_'+value.CONTROL_ID+'"]');
						if (label)
							label.innerHTML = value.ELEMENT_COUNT;
					}
				}
			}
		}
	}
};

JCSmartFilter.prototype.postHandler = function (result, fromCache)
{
	var hrefFILTER, url, curProp;
	var modef = BX('modef');
	var modef_num = BX('modef_num');

	if (!!result && !!result.ITEMS)
	{
		for(var PID in result.ITEMS)
		{
			if (result.ITEMS.hasOwnProperty(PID))
			{
				this.updateItem(PID, result.ITEMS[PID]);
			}
		}

		if (!!modef && !!modef_num)
		{
			modef_num.innerHTML = result.ELEMENT_COUNT;
			// hrefFILTER = BX.findChildren(modef, {tag: 'A'}, true);

			// if (result.FILTER_URL && hrefFILTER)
			// {
			// 	hrefFILTER[0].href = BX.util.htmlspecialcharsback(result.FILTER_URL);
			// }

			// if (result.FILTER_AJAX_URL && result.COMPONENT_CONTAINER_ID)
			// {
			// 	BX.bind(hrefFILTER[0], 'click', function(e)
			// 	{
			// 		url = BX.util.htmlspecialcharsback(result.FILTER_AJAX_URL);
			// 		BX.ajax.insertToNode(url, result.COMPONENT_CONTAINER_ID);
			// 		return BX.PreventDefault(e);
			// 	});
			// }

			if (result.INSTANT_RELOAD && result.COMPONENT_CONTAINER_ID)
			{
				url = BX.util.htmlspecialcharsback(result.FILTER_AJAX_URL);
				BX.ajax.insertToNode(url, result.COMPONENT_CONTAINER_ID);
			}
			else
			{
				if (modef.style.display === 'none')
				{
					modef.style.display = 'inline-block';
				}
				if (this.viewMode == "vertical")
				{
					curProp = BX.findChild(BX.findParent(this.curFilterinput, {'class':'bx_filter_parameters_box'}), {'class':'bx_filter_container_modef'}, true, false);
					curProp.appendChild(modef);
				}
			}
		}

	}

	if (!fromCache && this.cacheKey !== '')
	{
		this.cache[this.cacheKey] = result;
	}
	this.cacheKey = '';
};

JCSmartFilter.prototype.gatherInputsValues = function (values, elements)
{
	if(elements)
	{
		for(var i = 0; i < elements.length; i++)
		{
			var el = elements[i];
			if (el.disabled || !el.type)
				continue;

			switch(el.type.toLowerCase())
			{
				case 'text':
				case 'textarea':
				case 'password':
				case 'hidden':
				case 'select-one':
					if(el.value.length)
						values[values.length] = {name : el.name, value : el.value};
					break;
				case 'radio':
				case 'checkbox':
					if(el.checked)
						values[values.length] = {name : el.name, value : el.value};
					break;
				case 'select-multiple':
					for (var j = 0; j < el.options.length; j++)
					{
						if (el.options[j].selected)
							values[values.length] = {name : el.name, value : el.options[j].value};
					}
					break;
				default:
					break;
			}
		}
	}
};

JCSmartFilter.prototype.values2post = function (values)
{
	var post = [];
	var current = post;
	var i = 0;

	while(i < values.length)
	{
		var p = values[i].name.indexOf('[');
		if(p == -1)
		{
			current[values[i].name] = values[i].value;
			current = post;
			i++;
		}
		else
		{
			var name = values[i].name.substring(0, p);
			var rest = values[i].name.substring(p+1);
			if(!current[name])
				current[name] = [];

			var pp = rest.indexOf(']');
			if(pp == -1)
			{
				//Error - not balanced brackets
				current = post;
				i++;
			}
			else if(pp == 0)
			{
				//No index specified - so take the next integer
				current = current[name];
				values[i].name = '' + current.length;
			}
			else
			{
				//Now index name becomes and name and we go deeper into the array
				current = current[name];
				values[i].name = rest.substring(0, pp) + rest.substring(pp+1);
			}
		}
	}
	return post;
};

JCSmartFilter.prototype.hideFilterProps = function(element)
{
	var easing;
	var obj = element.parentNode;
	var filterBlock = BX.findChild(obj, {className:"bx_filter_block"}, true, false);

	if(BX.hasClass(obj, "active"))
	{
		easing = new BX.easing({
			duration : 300,
			start : { opacity: 1,  height: filterBlock.offsetHeight },
			finish : { opacity: 0, height:0 },
			transition : BX.easing.transitions.quart,
			step : function(state){
				filterBlock.style.opacity = state.opacity;
				filterBlock.style.height = state.height + "px";
			},
			complete : function() {
				filterBlock.setAttribute("style", "");
				BX.removeClass(obj, "active");
			}
		});
		easing.animate();
	}
	else
	{
		filterBlock.style.display = "block";
		filterBlock.style.opacity = 0;
		filterBlock.style.height = "auto";

		var obj_children_height = filterBlock.offsetHeight;
		filterBlock.style.height = 0;

		easing = new BX.easing({
			duration : 300,
			start : { opacity: 0,  height: 0 },
			finish : { opacity: 1, height: obj_children_height },
			transition : BX.easing.transitions.quart,
			step : function(state){
				filterBlock.style.opacity = state.opacity;
				filterBlock.style.height = state.height + "px";
			},
			complete : function() {
			}
		});
		easing.animate();
		BX.addClass(obj, "active");
	}
};

JCSmartFilter.prototype.showDropDownPopup = function(element, popupId)
{
	var contentNode = element.querySelector('[data-role="dropdownContent"]');
	BX.PopupWindowManager.create("smartFilterDropDown"+popupId, element, {
		autoHide: true,
		offsetLeft: 0,
		offsetTop: 3,
		overlay : false,
		draggable: {restrict:true},
		closeByEsc: true,
		content: contentNode
	}).show();
};

JCSmartFilter.prototype.selectDropDownItem = function(element, controlId)
{
	this.keyup(BX(controlId));

	var wrapContainer = BX.findParent(BX(controlId), {className:"bx_filter_select_container"}, false);

	var currentOption = wrapContainer.querySelector('[data-role="currentOption"]');
	currentOption.innerHTML = element.innerHTML;
	BX.PopupWindowManager.getCurrentPopup().close();
};

BX.namespace("BX.Iblock.SmartFilter");
BX.Iblock.SmartFilter = (function()
{
	var SmartFilter = function(arParams)
	{
		if (typeof arParams === 'object')
		{
			this.leftSlider = BX(arParams.leftSlider);
			this.rightSlider = BX(arParams.rightSlider);
			this.tracker = BX(arParams.tracker);
			this.trackerWrap = BX(arParams.trackerWrap);

			this.minInput = BX(arParams.minInputId);
			this.maxInput = BX(arParams.maxInputId);

			this.minPrice = parseFloat(arParams.minPrice);
			this.maxPrice = parseFloat(arParams.maxPrice);

			this.curMinPrice = parseFloat(arParams.curMinPrice);
			this.curMaxPrice = parseFloat(arParams.curMaxPrice);

			this.fltMinPrice = arParams.fltMinPrice ? parseFloat(arParams.fltMinPrice) : parseFloat(arParams.curMinPrice);
			this.fltMaxPrice = arParams.fltMaxPrice ? parseFloat(arParams.fltMaxPrice) : parseFloat(arParams.curMaxPrice);

			this.precision = arParams.precision || 0;

			this.priceDiff = this.maxPrice - this.minPrice;

			this.leftPercent = 0;
			this.rightPercent = 0;

			this.fltMinPercent = 0;
			this.fltMaxPercent = 0;

			this.colorUnavailableActive = BX(arParams.colorUnavailableActive);//gray
			this.colorAvailableActive = BX(arParams.colorAvailableActive);//blue
			this.colorAvailableInactive = BX(arParams.colorAvailableInactive);//light blue

			this.isTouch = false;

			this.init();

			if ('ontouchstart' in document.documentElement)
			{
				this.isTouch = true;

				BX.bind(this.leftSlider, "touchstart", BX.proxy(function(event){
					this.onMoveLeftSlider(event)
				}, this));

				BX.bind(this.rightSlider, "touchstart", BX.proxy(function(event){
					this.onMoveRightSlider(event)
				}, this));
			}
			else
			{
				BX.bind(this.leftSlider, "mousedown", BX.proxy(function(event){
					this.onMoveLeftSlider(event)
				}, this));

				BX.bind(this.rightSlider, "mousedown", BX.proxy(function(event){
					this.onMoveRightSlider(event)
				}, this));
			}

			BX.bind(this.minInput, "keyup", BX.proxy(function(event){
				this.onInputChange();
			}, this));

			BX.bind(this.maxInput, "keyup", BX.proxy(function(event){
				this.onInputChange();
			}, this));
		}
	};

	SmartFilter.prototype.init = function()
	{
		var priceDiff;

		if (this.curMinPrice > this.minPrice)
		{
			priceDiff = this.curMinPrice - this.minPrice;
			this.leftPercent = (priceDiff*100)/this.priceDiff;

			this.leftSlider.style.left = this.leftPercent + "%";
			this.colorUnavailableActive.style.left = this.leftPercent + "%";
		}

		this.setMinFilteredValue(this.fltMinPrice);

		if (this.curMaxPrice < this.maxPrice)
		{
			priceDiff = this.maxPrice - this.curMaxPrice;
			this.rightPercent = (priceDiff*100)/this.priceDiff;

			this.rightSlider.style.right = this.rightPercent + "%";
			this.colorUnavailableActive.style.right = this.rightPercent + "%";
		}

		this.setMaxFilteredValue(this.fltMaxPrice);
	};

	SmartFilter.prototype.setMinFilteredValue = function (fltMinPrice)
	{
		this.fltMinPrice = parseFloat(fltMinPrice);
		if (this.fltMinPrice >= this.minPrice)
		{
			var priceDiff = this.fltMinPrice - this.minPrice;
			this.fltMinPercent = (priceDiff*100)/this.priceDiff;

			if (this.leftPercent > this.fltMinPercent)
				this.colorAvailableActive.style.left = this.leftPercent + "%";
			else
				this.colorAvailableActive.style.left = this.fltMinPercent + "%";

			this.colorAvailableInactive.style.left = this.fltMinPercent + "%";
		}
		else
		{
			this.colorAvailableActive.style.left = "0%";
			this.colorAvailableInactive.style.left = "0%";
		}
	};

	SmartFilter.prototype.setMaxFilteredValue = function (fltMaxPrice)
	{
		this.fltMaxPrice = parseFloat(fltMaxPrice);
		if (this.fltMaxPrice <= this.maxPrice)
		{
			var priceDiff = this.maxPrice - this.fltMaxPrice;
			this.fltMaxPercent = (priceDiff*100)/this.priceDiff;

			if (this.rightPercent > this.fltMaxPercent)
				this.colorAvailableActive.style.right = this.rightPercent + "%";
			else
				this.colorAvailableActive.style.right = this.fltMaxPercent + "%";

			this.colorAvailableInactive.style.right = this.fltMaxPercent + "%";
		}
		else
		{
			this.colorAvailableActive.style.right = "0%";
			this.colorAvailableInactive.style.right = "0%";
		}
	};

	SmartFilter.prototype.getXCoord = function(elem)
	{
		var box = elem.getBoundingClientRect();
		var body = document.body;
		var docElem = document.documentElement;

		var scrollLeft = window.pageXOffset || docElem.scrollLeft || body.scrollLeft;
		var clientLeft = docElem.clientLeft || body.clientLeft || 0;
		var left = box.left + scrollLeft - clientLeft;

		return Math.round(left);
	};

	SmartFilter.prototype.getPageX = function(e)
	{
		e = e || window.event;
		var pageX = null;

		if (this.isTouch && event.targetTouches[0] != null)
		{
			pageX = e.targetTouches[0].pageX;
		}
		else if (e.pageX != null)
		{
			pageX = e.pageX;
		}
		else if (e.clientX != null)
		{
			var html = document.documentElement;
			var body = document.body;

			pageX = e.clientX + (html.scrollLeft || body && body.scrollLeft || 0);
			pageX -= html.clientLeft || 0;
		}

		return pageX;
	};

	SmartFilter.prototype.recountMinPrice = function()
	{
		var newMinPrice = (this.priceDiff*this.leftPercent)/100;
		newMinPrice = (this.minPrice + newMinPrice).toFixed(this.precision);

		if (newMinPrice != this.minPrice)
			this.minInput.value = newMinPrice;
		else
			this.minInput.value = "";
		renderSelectedFilterProperties();
		smartFilter.keyup(this.minInput);
	};

	SmartFilter.prototype.recountMaxPrice = function()
	{
		var newMaxPrice = (this.priceDiff*this.rightPercent)/100;
		newMaxPrice = (this.maxPrice - newMaxPrice).toFixed(this.precision);

		if (newMaxPrice != this.maxPrice)
			this.maxInput.value = newMaxPrice;
		else
			this.maxInput.value = "";
		renderSelectedFilterProperties();
		smartFilter.keyup(this.maxInput);
	};

	SmartFilter.prototype.onInputChange = function ()
	{
		var priceDiff;
		if (this.minInput.value)
		{
			var leftInputValue = this.minInput.value;
			if (leftInputValue < this.minPrice)
				leftInputValue = this.minPrice;

			if (leftInputValue > this.maxPrice)
				leftInputValue = this.maxPrice;

			priceDiff = leftInputValue - this.minPrice;
			this.leftPercent = (priceDiff*100)/this.priceDiff;

			this.makeLeftSliderMove(false);
		}

		if (this.maxInput.value)
		{
			var rightInputValue = this.maxInput.value;
			if (rightInputValue < this.minPrice)
				rightInputValue = this.minPrice;

			if (rightInputValue > this.maxPrice)
				rightInputValue = this.maxPrice;

			priceDiff = this.maxPrice - rightInputValue;
			this.rightPercent = (priceDiff*100)/this.priceDiff;

			this.makeRightSliderMove(false);
		}
	};

	SmartFilter.prototype.makeLeftSliderMove = function(recountPrice)
	{
		recountPrice = (recountPrice === false) ? false : true;

		this.leftSlider.style.left = this.leftPercent + "%";
		this.colorUnavailableActive.style.left = this.leftPercent + "%";

		var areBothSlidersMoving = false;
		if (this.leftPercent + this.rightPercent >= 100)
		{
			areBothSlidersMoving = true;
			this.rightPercent = 100 - this.leftPercent;
			this.rightSlider.style.right = this.rightPercent + "%";
			this.colorUnavailableActive.style.right = this.rightPercent + "%";
		}

		if (this.leftPercent >= this.fltMinPercent && this.leftPercent <= (100-this.fltMaxPercent))
		{
			this.colorAvailableActive.style.left = this.leftPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.right = 100 - this.leftPercent + "%";
			}
		}
		else if(this.leftPercent <= this.fltMinPercent)
		{
			this.colorAvailableActive.style.left = this.fltMinPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.right = 100 - this.fltMinPercent + "%";
			}
		}
		else if(this.leftPercent >= this.fltMaxPercent)
		{
			this.colorAvailableActive.style.left = 100-this.fltMaxPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.right = this.fltMaxPercent + "%";
			}
		}

		if (recountPrice)
		{
			this.recountMinPrice();
			if (areBothSlidersMoving)
				this.recountMaxPrice();
		}
	};

	SmartFilter.prototype.countNewLeft = function(event)
	{
		var pageX = this.getPageX(event);

		var trackerXCoord = this.getXCoord(this.trackerWrap);
		var rightEdge = this.trackerWrap.offsetWidth;

		var newLeft = pageX - trackerXCoord;

		if (newLeft < 0)
			newLeft = 0;
		else if (newLeft > rightEdge)
			newLeft = rightEdge;

		return newLeft;
	};

	SmartFilter.prototype.onMoveLeftSlider = function(e)
	{
		if (!this.isTouch)
		{
			this.leftSlider.ondragstart = function() {
				return false;
			};
		}

		if (!this.isTouch)
		{
			document.onmousemove = BX.proxy(function(event) {
				this.leftPercent = ((this.countNewLeft(event)*100)/this.trackerWrap.offsetWidth);
				this.makeLeftSliderMove();
			}, this);

			document.onmouseup = function() {
				document.onmousemove = document.onmouseup = null;
			};
		}
		else
		{
			document.ontouchmove = BX.proxy(function(event) {
				this.leftPercent = ((this.countNewLeft(event)*100)/this.trackerWrap.offsetWidth);
				this.makeLeftSliderMove();
			}, this);

			document.ontouchend = function() {
				document.ontouchmove = document.touchend = null;
			};
		}

		return false;
	};

	SmartFilter.prototype.makeRightSliderMove = function(recountPrice)
	{
		recountPrice = (recountPrice === false) ? false : true;

		this.rightSlider.style.right = this.rightPercent + "%";
		this.colorUnavailableActive.style.right = this.rightPercent + "%";

		var areBothSlidersMoving = false;
		if (this.leftPercent + this.rightPercent >= 100)
		{
			areBothSlidersMoving = true;
			this.leftPercent = 100 - this.rightPercent;
			this.leftSlider.style.left = this.leftPercent + "%";
			this.colorUnavailableActive.style.left = this.leftPercent + "%";
		}

		if ((100-this.rightPercent) >= this.fltMinPercent && this.rightPercent >= this.fltMaxPercent)
		{
			this.colorAvailableActive.style.right = this.rightPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.left = 100 - this.rightPercent + "%";
			}
		}
		else if(this.rightPercent <= this.fltMaxPercent)
		{
			this.colorAvailableActive.style.right = this.fltMaxPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.left = 100 - this.fltMaxPercent + "%";
			}
		}
		else if((100-this.rightPercent) <= this.fltMinPercent)
		{
			this.colorAvailableActive.style.right = 100-this.fltMinPercent + "%";
			if (areBothSlidersMoving)
			{
				this.colorAvailableActive.style.left = this.fltMinPercent + "%";
			}
		}

		if (recountPrice)
		{
			this.recountMaxPrice();
			if (areBothSlidersMoving)
				this.recountMinPrice();
		}
	};

	SmartFilter.prototype.onMoveRightSlider = function(e)
	{
		if (!this.isTouch)
		{
			this.rightSlider.ondragstart = function() {
				return false;
			};
		}

		if (!this.isTouch)
		{
			document.onmousemove = BX.proxy(function(event) {
				this.rightPercent = 100-(((this.countNewLeft(event))*100)/(this.trackerWrap.offsetWidth));
				this.makeRightSliderMove();
			}, this);

			document.onmouseup = function() {
				document.onmousemove = document.onmouseup = null;
			};
		}
		else
		{
			document.ontouchmove = BX.proxy(function(event) {
				this.rightPercent = 100-(((this.countNewLeft(event))*100)/(this.trackerWrap.offsetWidth));
				this.makeRightSliderMove();
			}, this);

			document.ontouchend = function() {
				document.ontouchmove = document.ontouchend = null;
			};
		}

		return false;
	};

	return SmartFilter;
})();

function toggleDropdown($id) {
	const dropdown = document.getElementById($id);
	const cur = dropdown.closest('.multiselect').querySelector('.cur');
	dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
	cur.classList.contains('cur-transorm') === true ? cur.classList.remove('cur-transorm') : cur.classList.add('cur-transorm');
}

let appliedSelectedFilterProperties = [];

function collectSelectedFilterProperties(filterForm) {
	const selectedProperties = [];
	const filterItems = filterForm.querySelectorAll('[data-filter-name]');

	filterItems.forEach(function(item) {
		const filterName = item.getAttribute('data-filter-name');
		const selectedValues = [];
		const minInput = item.querySelector('input[data-filter-bound="min"]');
		const maxInput = item.querySelector('input[data-filter-bound="max"]');

		if (minInput && minInput.value.trim() !== '') {
			selectedValues.push({
				label: 'от ' + minInput.value.trim(),
				clear: function() {
					minInput.value = '';
				},
			});
		}

		if (maxInput && maxInput.value.trim() !== '') {
			selectedValues.push({
				label: 'до ' + maxInput.value.trim(),
				clear: function() {
					maxInput.value = '';
				},
			});
		}

		item.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').forEach(function(input) {
			if (!input.value || input.name === 'Очистить' || input.value === 'Очистить') {
				return;
			}

			const filterValue = input.getAttribute('data-filter-value') || input.getAttribute('attr-value');
			if (filterValue) {
				selectedValues.push({
					label: filterValue,
					clear: function() {
						input.checked = false;
					},
				});
			}
		});

		if (filterName && selectedValues.length > 0) {
			selectedProperties.push({
				name: filterName,
				values: selectedValues,
			});
		}
	});

	return selectedProperties;
}

function submitSelectedFilterChange(filterForm) {
	const setFilterButton = filterForm.querySelector('[name="set_filter"]');

	if (filterForm.requestSubmit && setFilterButton) {
		filterForm.requestSubmit(setFilterButton);
		return;
	}

	if (!filterForm.querySelector('input[type="hidden"][name="set_filter"]')) {
		const hiddenSetFilter = document.createElement('input');
		hiddenSetFilter.type = 'hidden';
		hiddenSetFilter.name = 'set_filter';
		hiddenSetFilter.value = 'Y';
		filterForm.appendChild(hiddenSetFilter);
	}

	filterForm.submit();
}

function renderSelectedFilterProperties(options) {
	options = options || {};

	const selectedContainer = document.querySelector('.catalog-filter-popup #catalog-filter .catalog-filter__selected');
	const selectedChipsContainer = document.querySelector('.catalog-section__selected-filters');
	const filterForm = document.querySelector('#catalog-filter .smartfilter');

	if (!filterForm) {
		return;
	}

	const selectedProperties = collectSelectedFilterProperties(filterForm);

	if (selectedContainer) {
		renderSelectedFilterPopup(selectedContainer, selectedProperties);
	}

	if (options.renderAppliedChips && selectedChipsContainer) {
		renderSelectedFilterChips(selectedChipsContainer, appliedSelectedFilterProperties, filterForm);
	}
}

function renderSelectedFilterPopup(selectedContainer, selectedProperties) {
	selectedContainer.innerHTML = '';
	selectedContainer.classList.toggle('catalog-filter__selected_empty', selectedProperties.length === 0);

	selectedProperties.forEach(function(property) {
		const item = document.createElement('div');
		const name = document.createElement('div');
		const values = document.createElement('div');

		item.className = 'catalog-filter__selected-item';
		name.className = 'catalog-filter__selected-name';
		values.className = 'catalog-filter__selected-values';
		name.textContent = property.name;

		property.values.forEach(function(propertyValue) {
			const value = document.createElement('span');
			value.className = 'catalog-filter__selected-value';
			value.textContent = propertyValue.label;
			values.appendChild(value);
		});

		item.appendChild(name);
		item.appendChild(values);
		selectedContainer.appendChild(item);
	});
}

function renderSelectedFilterChips(selectedChipsContainer, selectedProperties, filterForm) {
	selectedChipsContainer.innerHTML = '';
	selectedChipsContainer.classList.toggle('catalog-section__selected-filters_empty', selectedProperties.length === 0);

	selectedProperties.forEach(function(property) {
		property.values.forEach(function(propertyValue) {
			const chip = document.createElement('button');
			const chipText = document.createElement('span');
			const chipRemove = document.createElement('span');

			chip.type = 'button';
			chip.className = 'catalog-section__selected-filter';
			chip.setAttribute('aria-label', 'Удалить фильтр ' + property.name + ': ' + propertyValue.label);
			chipText.className = 'catalog-section__selected-filter-text';
			chipRemove.className = 'catalog-section__selected-filter-remove';
			chipText.textContent = property.name + ': ' + propertyValue.label;
			chipRemove.setAttribute('aria-hidden', 'true');

			chip.appendChild(chipText);
			chip.appendChild(chipRemove);
			chip.addEventListener('click', function() {
				propertyValue.clear();
				renderSelectedFilterProperties();
				submitSelectedFilterChange(filterForm);
			});

			selectedChipsContainer.appendChild(chip);
		});
	});
}

function check(e, clear = false) {
	const multi = e.parentElement.parentElement.parentElement;
	const header = multi.querySelector('.select-header');
	const checked = multi.querySelectorAll('input[type="checkbox"]:checked');
	
	if (checked.length > 0 && clear === false) {
		header.textContent = header.getAttribute('attr-name') + ': ';
		checked.forEach((value, index, array) => {
			if (header.textContent != header.getAttribute('attr-name') + ': ') {
				header.textContent += ', ';
			}
			header.textContent += value.getAttribute('attr-value');
		})
	} else {
		header.textContent = header.getAttribute('attr-name');
	}
	if (clear) {
		checked.forEach((value, index) => {
			value.checked = false;
		})
	}
	renderSelectedFilterProperties();
}
function checkCheckbox() {
	const multiselect = document.querySelectorAll('.multiselect');
	multiselect.forEach((multi, index, array) => {
		const header = multi.querySelector('.select-header');
		const checked = multi.querySelectorAll('input[type="checkbox"]:checked');
		if (checked.length > 0) {
			header.textContent = header.getAttribute('attr-name') + ': ';
			checked.forEach((value, index, array) => {
				if (header.textContent != header.getAttribute('attr-name') + ': ') {
					header.textContent += ', ';
				}
				header.textContent += value.getAttribute('attr-value');
			})
		}
	})	
}
function priceChange(self) {
	console.log(self);
}
document.addEventListener('DOMContentLoaded', () => {
	checkCheckbox();
	const filterForm = document.querySelector('#catalog-filter .smartfilter');
	if (filterForm) {
		appliedSelectedFilterProperties = collectSelectedFilterProperties(filterForm);
	}
	renderSelectedFilterProperties({
		renderAppliedChips: true,
	});
})
document.addEventListener('input', (e) => {
	if (e.target.closest('#catalog-filter')) {
		renderSelectedFilterProperties();
	}
});
document.addEventListener('change', (e) => {
	if (e.target.closest('#catalog-filter')) {
		renderSelectedFilterProperties();
	}
});
document.addEventListener('click', (e) => {
	$('.multiselect').each((id, element) => {
			if (!element.contains(e.target)) {
				const dropdown = document.getElementById(element.querySelector('.select-options').id);
				const cur = dropdown.closest('.multiselect').querySelector('.cur');
				dropdown.style.display = 'none';
				 cur.classList.remove('cur-transorm');
			}
		}
	)
});

function showFilters() {
	const filterToggle = $('#show_filters_catalog')[0];
	const filterToggleText = filterToggle.querySelector('.cur-filter__text');

	if (filterToggle.classList.contains('cur-transorm')) {
		let i = 0;
		$('.filter-flex-element').each((id, element) => {
			if (i > 2 && !element.classList.contains('filter-flex-element_active')) {
				element.classList.add('hide');
			} 
			i++;
		})
		filterToggle.classList.remove('cur-transorm');
		if (filterToggleText) {
			filterToggleText.textContent = 'Раскрыть';
		}
	} else {
		$('.filter-flex-element.hide').each((id, element) => {
			element.classList.remove('hide');
		})
		filterToggle.classList.add('cur-transorm');
		if (filterToggleText) {
			filterToggleText.textContent = 'Скрыть';
		}
	}
}

$(function(){
	if ($('.bx_filter_parameters_box.filter-flex-element').length > 3) {
		$('#show_filters_catalog')[0].classList.add('show-button-show-filter-catalog');
	}
});
/* End */
;
; /* Start:"a:4:{s:4:"full";s:79:"/forma-201/local/components/clickon/tags.cloud/templates/only_top/script.js?1779710366320";s:6:"source";s:65:"/forma-201/local/components/clickon/tags.cloud/templates/only_top/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
document.addEventListener("DOMContentLoaded", function (){
    $('.toggle-cloud-items').on('click', function (){
        $('.item.tire-2').slideToggle();
        var btnText = $(this).attr('data-toggle');
        $(this).attr('data-toggle', $(this).text());
        $(this).text(btnText);
        return false;
    })
})
/* End */
;
; /* Start:"a:4:{s:4:"full";s:103:"/forma-201/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js?1780584785431";s:6:"source";s:89:"/forma-201/local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js";s:3:"min";s:0:"";s:3:"map";s:0:"";}"*/
BX.ready(function() {
    $('.btn-order').on('click', function() {
        let name = $(this).data().name;
        let price = $(this).data().price;
        $('.form-popup-catalog__product-name').text(name);
        $('input[name="form_catalog"]').val(name);
        product.name = name;
        product.price = price;
    })
})

class Product {
    name = '';
    price = 0;
    brand = 'dial';
}

const product = new Product();


/* End */
;; /* /local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/catalog.smart.filter/filter_catalog_top/script.js?178704644228534*/
; /* /local/components/clickon/tags.cloud/templates/only_top/script.js?1779710366320*/
; /* /local/templates/gvozdevsoft_zavodgs_s1/components/bitrix/news.list/catalog_new/script.js?1780584785431*/
