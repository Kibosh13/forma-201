#!/usr/bin/env python3
from pathlib import Path
from PIL import Image, ImageOps
import html
import re
import shutil

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / 'site'
CAT = SITE / 'catalog'
TEXT_SUFFIXES = {'.html', '.prod', '.tag'}

PRICE_MAP = {
    # Silver matt inner/outer corners: mirror white-corner prices by size.
    'vneshniy-ugol-40-serebristyy.prod': 150,
    'vneshniy-ugol-60-serebristyy.prod': 170,
    'vneshniy-ugol-80-serebristyy.prod': 192,
    'vneshniy-ugol-100-serebristyy.prod': 230,
    'vnutrenniy-ugol-40-serebristyy.prod': 150,
    'vnutrenniy-ugol-60-serebristyy.prod': 170,
    'vnutrenniy-ugol-80-serebristyy.prod': 192,
    'vnutrenniy-ugol-100-serebristyy.prod': 230,
    # Radius hidden skirtings.
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-radiusnye-6m-ral.prod': 2207,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-radiusnye-belye-6m.prod': 2207,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-radiusnye-serebro-6m.prod': 2207,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-radiusnye-shampan-6m.prod': 2207,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-radiusnye-zoloto-6m.prod': 2207,
    'skrytye-plintusy-dlya-podsvetki-chyernyy-6m.prod': 2207,
    # Hidden 49.5 x 12.4 illuminated skirtings.
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-belye.prod': 1406,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-ral.prod': 1406,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-serebro.prod': 1406,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-shampan.prod': 1406,
    'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-zoloto.prod': 1406,
    'skrytye-plintusy-s-podsvetkoy-i-rasseivatelem-chernye.prod': 1406,
    # 5.9 x 16 micro-skirtings.
    'mikroplintus-5-9-16-chyernyy-9121.prod': 1110,
    'mikroplintus-5-9-16-serebro-9121.prod': 1110,
}

OLD_TO_NEW_TITLES = {
    'Скрытые плинтусы для пола с подсветкой и рассеивателем белый матовый 3 метра': 'Скрытые плинтусы для пола с подсветкой белый матовый 3 метра',
    'Скрытые плинтусы для пола с подсветкой и рассеивателем RAL 3 метра': 'Скрытые плинтусы для пола с подсветкой RAL 3 метра',
    'Скрытые плинтусы для пола с подсветкой и рассеивателем серебро матовое 3 метра': 'Скрытые плинтусы для пола с подсветкой серебро матовое 3 метра',
    'Скрытые плинтусы для пола с подсветкой и рассеивателем шампань матовая 3 метра': 'Скрытые плинтусы для пола с подсветкой шампань матовая 3 метра',
    'Скрытые плинтусы для пола с подсветкой и рассеивателем золото матовое 3 метра': 'Скрытые плинтусы для пола с подсветкой золото матовое 3 метра',
    'Скрытые плинтусы для пола с подсветкой и рассеивателем чёрный матовый 3 метра': 'Скрытые плинтусы для пола с подсветкой чёрный матовый 3 метра',
}

NEW_PRODUCTS = [
    {
        'slug': 'skrytye-plintusy-dlya-pola-bez-podsvetki-chernye.prod',
        'title': 'Скрытые плинтусы для пола без подсветки чёрный матовый 3 метра',
        'price': 1406, 'article': '0066', 'id': '2001', 'color': 'Черный',
        'image': '/upload/iblock/custom-hidden/hidden-no-light-black.jpg',
        'properties': [('Подсветка', 'нет')], 'kind': 'plinth',
    },
    {
        'slug': 'skrytye-plintusy-dlya-pola-bez-podsvetki-serebro.prod',
        'title': 'Скрытые плинтусы для пола без подсветки серебро матовое 3 метра',
        'price': 1406, 'article': '0067', 'id': '2002', 'color': 'Серебро',
        'image': '/upload/iblock/custom-hidden/hidden-no-light-silver.jpg',
        'properties': [('Подсветка', 'нет')], 'kind': 'plinth',
    },
    {
        'slug': 'rasseivatel-dlya-svetodiodnoy-lenty-belyy-2-metra.prod',
        'title': 'Рассеиватель для светодиодной ленты белый 2 метра',
        'price': 164, 'article': '0068', 'id': '2003', 'color': 'Белый',
        'image': '/upload/iblock/custom-hidden/led-diffuser-white.jpg',
        'properties': [('Длина, мм', '2000'), ('Ширина, мм', '14')], 'kind': 'diffuser',
    },
    {
        'slug': 'rasseivatel-dlya-svetodiodnoy-lenty-chernyy-2-metra.prod',
        'title': 'Рассеиватель для светодиодной ленты чёрный 2 метра',
        'price': 164, 'article': '0069', 'id': '2004', 'color': 'Черный',
        'image': '/upload/iblock/custom-hidden/led-diffuser-black.jpg',
        'properties': [('Длина, мм', '2000'), ('Ширина, мм', '14')], 'kind': 'diffuser',
    },
]

DIFFUSER_DESCRIPTION = (
    'Рассеиватель для теневого плинтуса — это компонент, который обеспечивает равномерное и мягкое '
    'рассеивание света от встроенной LED-подсветки. Этот элемент изготавливается из прозрачного или '
    'матового материала и устанавливается внутри плинтуса перед светодиодами. Рассеиватель помогает '
    'избежать ярких бликов и теней, создавая комфортное освещение в помещении. Он также защищает '
    'светодиоды от пыли и механических повреждений, продлевая их срок службы. Этот элемент является '
    'важной частью конструкции теневого плинтуса, который обеспечивает не только функциональность, '
    'но и эстетическое воздействие в интерьере.'
)


def all_text_files():
    for p in SITE.rglob('*'):
        if p.is_file() and p.suffix in TEXT_SUFFIXES:
            yield p


def matching_div_end(text: str, start: int) -> int:
    depth = 0
    for m in re.finditer(r'<div\b[^>]*>|</div\s*>', text[start:], re.I):
        token = m.group(0).lower()
        if token.startswith('<div'):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                return start + m.end()
    raise ValueError('unbalanced div')


def replace_div(text: str, start: int, replacement: str) -> str:
    end = matching_div_end(text, start)
    return text[:start] + replacement + text[end:]


def update_product_page_price(text: str, price: int) -> str:
    text = re.sub(r'("price":")\d+(?:\.\d+)?', rf'\g<1>{price}', text, count=1)
    text = re.sub(r'(<span class="pricespace">)\s*\d[\d ]*(\s*</span>)', rf'\g<1>{price}\g<2>', text, count=1)
    text = re.sub(r'(data-price=")\d+(?:\.\d+)?(")', rf'\g<1>{price}\g<2>', text, count=1)
    return text


def update_cards(text: str, price_by_slug: dict[str, int]) -> str:
    marker = '<div class="col-xl-4 col-sm-6 mb-20 pr-10 pl-10" id="bx_'
    if marker not in text:
        return text
    parts = text.split(marker)
    out = [parts[0]]
    for part in parts[1:]:
        segment = marker + part
        for slug, price in price_by_slug.items():
            if f'href="/catalog/{slug}' in segment:
                segment = re.sub(r'(<span class="pricespace">)\s*\d[\d ]*(\s*</span>)', rf'\g<1>{price}\g<2>', segment, count=1)
                segment = re.sub(r'(data-price=")\d+(?:\.\d+)?(")', rf'\g<1>{price}\g<2>', segment, count=1)
                break
        out.append(segment)
    return ''.join(out)


def update_related_prices(text: str, price_by_slug: dict[str, int]) -> str:
    for slug, price in price_by_slug.items():
        pattern = re.compile(r'(<a class="related-accessories__item" href="/catalog/' + re.escape(slug) + r'".*?<strong>)\s*\d[\d ]*\s*(₽</strong></a>)', re.S)
        text = pattern.sub(rf'\g<1>{price} \g<2>', text)
    return text


def param_item(name: str, value: str, prop_array: bool = False) -> str:
    cls = 'catalog-detail__parameter-size prop-array' if prop_array else 'catalog-detail__parameter-size '
    rendered = value if not prop_array else ''.join(f'<span>{html.escape(x)}</span>' for x in value.split('|'))
    return f'''<div class="catalog-detail__parameter-item">
    <div class="catalog-detail__parameter-name">{html.escape(name)}</div>
    <div class="catalog-detail__parameter-line"></div>
    <div class="{cls}">{rendered}</div>
</div>'''


def parameters_block(product: dict) -> str:
    items = []
    if product['kind'] == 'plinth':
        items.append(param_item('Тип профиля', 'Скрытый|Напольный', True))
    items.append(param_item('Цвет', product['color']))
    if product['kind'] == 'plinth':
        items.append(param_item('Длина', '3 метра'))
    else:
        items.append(param_item('Длина, мм', '2000'))
        items.append(param_item('Ширина, мм', '14'))
    return '''<div class="catalog-detail__parameter tabs_block2">
    <div class="row">
        <div class="col-xl-9 col-12">
            %s
        </div>
    </div>
</div>''' % '\n'.join(items)


def color_block(product: dict) -> str:
    if product['kind'] == 'plinth':
        siblings = [NEW_PRODUCTS[0], NEW_PRODUCTS[1]]
    else:
        siblings = [NEW_PRODUCTS[2], NEW_PRODUCTS[3]]
    links = []
    colors = {'Черный': '#000000', 'Серебро': '#c0c0c0', 'Белый': '#ffffff'}
    labels = {'Черный': 'Черный', 'Серебро': 'Серебро', 'Белый': 'Белый'}
    for sib in siblings:
        swatch = f'<div class="related-products-color" style="background-color: {colors[sib["color"]]}"></div>'
        if sib['slug'] == product['slug']:
            links.append(f'<div class="related-product active">{swatch}{labels[sib["color"]]}</div>')
        else:
            links.append(f'<a href="/catalog/{sib["slug"]}" class="related-product">{swatch}{labels[sib["color"]]}</a>')
    return '<div class="block-related-products not-margin">\n' + '\n'.join(links) + '\n</div>'


def replace_color_selector(text: str, product: dict) -> str:
    label = re.search(r'<label class="size-title"[^>]*>Цвет</label>', text)
    if not label:
        return text
    start = text.find('<div class="block-related-products not-margin">', label.end())
    if start < 0:
        return text
    return replace_div(text, start, color_block(product))


def remove_extra_gallery(text: str) -> str:
    start = text.find('<div class="mygallery-catalog justified-gallery catalog-detail__img-view">')
    if start >= 0:
        return replace_div(text, start, '')
    return text


def replace_parameters(text: str, product: dict) -> str:
    start = text.find('<div class="catalog-detail__parameter tabs_block2">')
    if start < 0:
        raise ValueError(f'parameter block missing for {product["slug"]}')
    return replace_div(text, start, parameters_block(product))


def add_diffuser_description(text: str) -> str:
    if '<li>Описание</li>' not in text and '>Описание' not in text:
        pos = text.find('<ul class="catalog-detail__tabs-ul">')
        if pos >= 0:
            close = text.find('>', pos) + 1
            text = text[:close] + '\n<li>Описание</li>' + text[close:]
    marker = '<div class="catalog-detail__parameter tabs_block2">'
    pos = text.find(marker)
    if pos >= 0 and DIFFUSER_DESCRIPTION not in text:
        block = f'<div class="tabs_block1"><div class="catalog-detail__text"><p>{DIFFUSER_DESCRIPTION}</p></div></div>\n'
        text = text[:pos] + block + text[pos:]
    return text


def replace_main_image(text: str, old_filename: str, new_path: str) -> str:
    pattern = re.compile(r'/upload/(?:resize_cache/)?iblock/[^"\'\s<>]*?' + re.escape(old_filename))
    return pattern.sub(new_path, text)


def make_product_page(source: Path, product: dict, source_title: str, source_slug: str, old_image_filename: str) -> str:
    text = source.read_text()
    text = text.replace(source_title, product['title'])
    text = text.replace(source_slug, product['slug'].removesuffix('.prod'))
    text = replace_main_image(text, old_image_filename, product['image'])
    text = remove_extra_gallery(text)
    text = update_product_page_price(text, product['price'])
    text = re.sub(r'(Арт\.\s*)\d{4}', rf'\g<1>{product["article"]}', text, count=1)
    # Product identifiers used by the cart/AJAX stubs.
    old_id = '171' if source_slug == 'skrytye-plintusy-s-podsvetkoy-i-rasseivatelem-chernye' else '1105'
    text = text.replace(f'id="product-id" value="{old_id}"', f'id="product-id" value="{product["id"]}"')
    text = text.replace(f'data-product="{old_id}"', f'data-product="{product["id"]}"')
    text = text.replace(f"'ELEMENT_ID':'{old_id}'", f"'ELEMENT_ID':'{product['id']}'")
    text = replace_color_selector(text, product)
    text = replace_parameters(text, product)
    if product['kind'] == 'diffuser':
        text = add_diffuser_description(text)
    return text


def card_html(product: dict) -> str:
    props = ''.join(
        f'''<div class="main-property-product"><span class="name-product-attribute" data-text="{html.escape(k)}: "></span><span class="value-product-attribute" data-text="{html.escape(v)}"></span></div>'''
        for k, v in product['properties']
    )
    return f'''
<div class="col-xl-4 col-sm-6 mb-20 pr-10 pl-10" id="bx_1373509569_{product['id']}">
  <div class="catalog-section-tile__item">
    <div class="catalog-section-tile__promo-box"></div>
    <a href="/catalog/{product['slug']}"><div class="catalog-section-tile__img-box"><img src="{product['image']}" alt="{html.escape(product['title'])}" title="{html.escape(product['title'])}" class="catalog-section-tile__img-img"></div></a>
    <div class="catalog-section-tile__text-box">
      <div class="catalog-section-tile__status-box"><div class="catalog-section-tile__status"><div class="catalog-section-tile__status-nal">В наличии</div></div><div class="catalog-section-tile__article">Арт. {product['article']}</div><div class="catalog-section-price__article"></div></div>
      <div class="catalog-section-tile__title"><a href="/catalog/{product['slug']}" class="catalog-section-tile__title-link">{html.escape(product['title'])}</a></div>
      <div class="main-property-products">{props}</div>
      <div class="price-in-one-line"><div class="catalog-section-tile__price-box"><div class="catalog-section-tile__price-now"><span class="pricespace">{product['price']}</span><span class="catalog-section-tile__price-rub">р./шт.</span></div></div>
        <div class="order-block"><a data-fancybox data-src="#form-popup-catalog" href="javascript:;" class="btn-order btn-link" data-name="{html.escape(product['title'])}" data-price="{product['price']}">Заказать</a></div>
      </div>
    </div>
    <div class="catalog-cart-input catalog-cart-input-list"><div class="catalog-cart-input-wrap catalog-cart-input-list-wrap"><div class="catalog-cart-input-minus catalog-cart-input-list-minus"><i class="fa fa-minus" aria-hidden="true"></i></div><input type="input" name="quantity" value="1" data-step="1"/><div class="catalog-cart-input-plus catalog-cart-input-list-plus"><i class="fa fa-plus" aria-hidden="true"></i></div></div><div class="btn-link catalog-cart-add catalog-cart-input-btn catalog-cart-input-list-btn" data-product="{product['id']}">В корзину</div></div>
  </div>
</div>
'''


def add_cards(page: Path, products: list[dict]):
    text = page.read_text()
    products = [p for p in products if f'/catalog/{p["slug"]}' not in text]
    if not products:
        return
    section = text.find('<div class="catalog-section__row catalog-section-tile">')
    if section < 0:
        raise ValueError(f'catalog section missing in {page}')
    outer_close = text.find('\n\t</div>\n\t\t<br', section)
    if outer_close < 0:
        raise ValueError(f'catalog closing marker missing in {page}')
    row_close = text.rfind('</div>', section, outer_close)
    text = text[:row_close] + ''.join(card_html(p) for p in products) + text[row_close:]
    # Keep structured list summary coherent.
    count = len(re.findall(r'class="catalog-section-tile__item"', text))
    text = re.sub(r'("offerCount"\s*:\s*")\d+("\s*)', rf'\g<1>{count}\g<2>', text, count=1)
    if any(p['price'] == 164 for p in products):
        text = re.sub(r'("lowPrice"\s*:\s*")\d+("\s*)', rf'\g<1>164\g<2>', text, count=1)
    page.write_text(text)


def recommendation(product: dict) -> str:
    return f'''<section class="related-accessories" aria-labelledby="related-accessories-title">
<h2 id="related-accessories-title">С этим покупают</h2>
<div class="related-accessories__list"><a class="related-accessories__item" href="/catalog/{product['slug']}"><img src="{product['image']}" alt="{html.escape(product['title'])}"><span>{html.escape(product['title'])}</span><strong>{product['price']} ₽</strong></a></div>
</section>
'''


def add_diffuser_recommendations():
    changed = 0
    for page in CAT.glob('*.prod'):
        text = page.read_text()
        m = re.search(r'<h1>(.*?)</h1>', text, re.S)
        if not m:
            continue
        title = html.unescape(re.sub(r'<[^>]+>', '', m.group(1))).strip()
        low = title.lower()
        if 'плинтус' not in low or 'подсветк' not in low or 'без подсветк' in low:
            continue
        if 'related-accessories-title' in text:
            continue
        diffuser = NEW_PRODUCTS[3] if ('чёрн' in low or 'черн' in low) else NEW_PRODUCTS[2]
        marker = '<script>\n                    function initSwiperProductsSlider()'
        pos = text.find(marker)
        if pos < 0:
            raise ValueError(f'recommendation marker missing: {page.name}')
        text = text[:pos] + recommendation(diffuser) + '                                                ' + text[pos:]
        page.write_text(text)
        changed += 1
    return changed


def save_jpeg(src: Path, dest: Path, size=None):
    dest.parent.mkdir(parents=True, exist_ok=True)
    image = Image.open(src).convert('RGB')
    if size:
        image = ImageOps.pad(image, size, color='white', method=Image.Resampling.LANCZOS)
    image.save(dest, 'JPEG', quality=94, optimize=True)


def write_images():
    temp = Path('/var/folders/45/7w5jgz5n20j7xfhflp2_n2d80000gn/T')
    sources = {
        'hidden-no-light-black.jpg': temp / 'codex-clipboard-61c9cd07-e5c6-4e3c-9177-df90e6b0246c.png',
        'hidden-no-light-silver.jpg': temp / 'codex-clipboard-a0ccec59-bdb5-4bd5-bfcb-6000826113e6.png',
        'led-diffuser-white.jpg': temp / 'codex-clipboard-4c7fc4a4-a85f-4371-b932-a06eb542e4f6.png',
        'led-diffuser-black.jpg': temp / 'codex-clipboard-fd016314-7941-44d8-af2c-929076c23529.png',
    }
    for name, src in sources.items():
        save_jpeg(src, SITE / 'upload/iblock/custom-hidden' / name)

    # Replace the existing black 49.5 x 12.4 product image at every cached size.
    replacement = temp / 'codex-clipboard-987231e9-522a-406a-9c26-3dd2efac3405.png'
    filename = 'r1w2x46x0kuynqgo04qagmo3gl6qc477.jpg'
    original = SITE / 'upload/iblock/d2c' / filename
    save_jpeg(replacement, original)
    for cached in (SITE / 'upload/resize_cache/iblock/d2c').rglob(filename):
        m = re.search(r'/(\d+)_(\d+)_\d+/', cached.as_posix())
        size = (int(m.group(1)), int(m.group(2))) if m else None
        save_jpeg(replacement, cached, size)


def create_new_products():
    black_source = CAT / 'skrytye-plintusy-s-podsvetkoy-i-rasseivatelem-chernye.prod'
    silver_source = CAT / 'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-serebro.prod'
    black_old_title = OLD_TO_NEW_TITLES['Скрытые плинтусы для пола с подсветкой и рассеивателем чёрный матовый 3 метра']
    silver_old_title = OLD_TO_NEW_TITLES['Скрытые плинтусы для пола с подсветкой и рассеивателем серебро матовое 3 метра']
    # Sources have already been renamed globally by the time this function runs.
    sources = [
        (black_source, black_old_title, 'skrytye-plintusy-s-podsvetkoy-i-rasseivatelem-chernye', 'r1w2x46x0kuynqgo04qagmo3gl6qc477.jpg'),
        (silver_source, silver_old_title, 'skrytye-plintusy-dlya-pola-s-podsvetkoy-i-rasseivatelem-serebro', 'ptl9g09cvapl4y388gkq3yvux3xkm8o5.jpg'),
    ]
    created = []
    for product, source_info in zip(NEW_PRODUCTS[:2], sources):
        source, title, slug, image_file = source_info
        page = make_product_page(source, product, title, slug, image_file)
        (CAT / product['slug']).write_text(page)
        created.append(product['slug'])
    # Build the two diffuser pages from the clean new black/silver pages.
    diffuser_sources = [
        (CAT / NEW_PRODUCTS[1]['slug'], NEW_PRODUCTS[1]['title'], NEW_PRODUCTS[1]['slug'].removesuffix('.prod'), 'hidden-no-light-silver.jpg'),
        (CAT / NEW_PRODUCTS[0]['slug'], NEW_PRODUCTS[0]['title'], NEW_PRODUCTS[0]['slug'].removesuffix('.prod'), 'hidden-no-light-black.jpg'),
    ]
    for product, source_info in zip(NEW_PRODUCTS[2:], diffuser_sources):
        source, title, slug, image_file = source_info
        page = make_product_page(source, product, title, slug, image_file)
        (CAT / product['slug']).write_text(page)
        created.append(product['slug'])
    return created


def update_sitemap():
    sitemap = SITE / 'sitemap.xml'
    if not sitemap.exists():
        return
    text = sitemap.read_text()
    marker = '</urlset>'
    additions = []
    for p in NEW_PRODUCTS:
        route = p['slug'].removesuffix('.prod') + '/'
        if route not in text:
            additions.append(f'<url><loc>https://kibosh13.github.io/forma-201/catalog/{route}</loc></url>')
    if additions:
        text = text.replace(marker, '\n'.join(additions) + '\n' + marker)
        sitemap.write_text(text)


def main():
    write_images()

    # Rename the complete 49.5 x 12.4 family across cards, metadata and product pages.
    for path in list(all_text_files()):
        text = path.read_text(errors='ignore')
        new = text
        for old, replacement in OLD_TO_NEW_TITLES.items():
            new = new.replace(old, replacement)
        if new != text:
            path.write_text(new)

    # Apply known price changes to detail pages.
    for slug, price in PRICE_MAP.items():
        path = CAT / slug
        if not path.exists():
            raise FileNotFoundError(path)
        text = update_product_page_price(path.read_text(), price)
        path.write_text(text)

    # Update every repeated catalog card and existing recommendation price.
    for path in list(all_text_files()):
        text = path.read_text(errors='ignore')
        new = update_related_prices(update_cards(text, PRICE_MAP), PRICE_MAP)
        # Remove the displayed prefix "от" from all catalog price blocks.
        new = re.sub(r'<div class="(?:catalog-detail|catalog-section-tile)__price-ot">\s*от\s*</div>', '', new)
        if new != text:
            path.write_text(new)

    created = create_new_products()

    # Add the new items to the hidden-skirting selection and relevant colour selections.
    add_cards(CAT / 'skrytye-plintusy-dlya-pola.tag', NEW_PRODUCTS)
    add_cards(CAT / 'chernye-skrytye-plintusy.tag', [NEW_PRODUCTS[0], NEW_PRODUCTS[3]])
    add_cards(CAT / 'skrytye-plintusy-serebro.tag', [NEW_PRODUCTS[1]])
    add_cards(CAT / 'belye-skrytye-plintusy.tag', [NEW_PRODUCTS[2]])

    recommendations = add_diffuser_recommendations()
    update_sitemap()
    print({'created': created, 'recommendations_added': recommendations, 'price_updates': len(PRICE_MAP)})


if __name__ == '__main__':
    main()
