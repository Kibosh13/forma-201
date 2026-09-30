#!/usr/bin/env python3
"""Finish the 2026-09-30/10-01 catalog correction batch.

This is intentionally a one-time, explicit migration: the source mirror is static HTML,
so every product detail and every repeated catalog card must be kept in sync.
"""
from __future__ import annotations

from pathlib import Path
from PIL import Image
import html
import re

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "site"
CAT = SITE / "catalog"
TMP = Path("/var/folders/45/7w5jgz5n20j7xfhflp2_n2d80000gn/T")
TEXT_SUFFIXES = {".html", ".prod", ".tag"}
CARD_MARKER = '<div class="col-xl-4 col-sm-6 mb-20 pr-10 pl-10" id="bx_'


def all_text_files():
    for path in SITE.rglob("*"):
        if path.is_file() and path.suffix in TEXT_SUFFIXES:
            yield path


def matching_div_end(text: str, start: int) -> int:
    depth = 0
    for match in re.finditer(r"<div\b[^>]*>|</div\s*>", text[start:], re.I):
        if match.group(0).lower().startswith("<div"):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                return start + match.end()
    raise ValueError("unbalanced div")


def matching_section_end(text: str, start: int) -> int:
    depth = 0
    for match in re.finditer(r"<section\b[^>]*>|</section\s*>", text[start:], re.I):
        if match.group(0).lower().startswith("<section"):
            depth += 1
        else:
            depth -= 1
            if depth == 0:
                return start + match.end()
    raise ValueError("unbalanced section")


def clean_title(text: str) -> str:
    match = re.search(r"<h1[^>]*>(.*?)</h1>", text, re.S | re.I)
    if not match:
        return ""
    return " ".join(html.unescape(re.sub(r"<[^>]+>", " ", match.group(1))).split())


def save_jpeg(source: Path, destination: Path):
    destination.parent.mkdir(parents=True, exist_ok=True)
    Image.open(source).convert("RGB").save(destination, "JPEG", quality=94, optimize=True)


def set_price(text: str, price: int) -> str:
    text = re.sub(r'("price"\s*:\s*")\d+(?:\.\d+)?', rf"\g<1>{price}", text, count=1)
    text = re.sub(
        r'(<span class="pricespace">)\s*\d[\d ]*(\s*</span>)',
        rf"\g<1>{price}\g<2>", text, count=1,
    )
    text = re.sub(r'(data-price=")\d+(?:\.\d+)?(")', rf"\g<1>{price}\g<2>", text, count=1)
    return text


def set_unit_and_quantity(text: str) -> str:
    text = re.sub(
        r'(<div class="catalog-detail__price-rub">).*?(</div>)',
        r"\g<1>р./шт.\g<2>", text, count=1, flags=re.S,
    )
    text = re.sub(
        r'(<span class="catalog-section-tile__price-rub">).*?(</span>)',
        r"\g<1>р./шт.\g<2>", text, count=1, flags=re.S,
    )
    text = re.sub(
        r"(<input\s+type=['\"]input['\"]\s+name=['\"]quantity['\"]\s+)value=['\"]\d+['\"]\s+data-step=['\"]\d+['\"]",
        r"\g<1>value='1' data-step='1'", text,
    )
    return text


def set_article_detail(text: str, article: str) -> str:
    if re.search(r'<div class="catalog-detail__article">', text):
        return re.sub(
            r'(<div class="catalog-detail__article">).*?(</div>)',
            rf"\g<1>Арт. {article}\g<2>", text, count=1,
            flags=re.S,
        )
    marker = '<div class="catalog-detail__status-box">'
    pos = text.find(marker)
    if pos < 0:
        raise ValueError("detail status box missing")
    insert = pos + len(marker)
    return text[:insert] + f'\n<div class="catalog-detail__article">Арт. {article}</div>' + text[insert:]


def parameter_item(name: str, value: str) -> str:
    return f'''<div class="catalog-detail__parameter-item">
<div class="catalog-detail__parameter-name">{html.escape(name)}</div>
<div class="catalog-detail__parameter-line"></div>
<div class="catalog-detail__parameter-size ">{html.escape(value)}</div>
</div>'''


def set_parameter(text: str, name_pattern: str, name: str, value: str) -> str:
    start = text.find('<div class="catalog-detail__parameter tabs_block2">')
    if start < 0:
        return text
    end = matching_div_end(text, start)
    block = text[start:end]
    pattern = re.compile(
        r'(<div class="catalog-detail__parameter-item">\s*'
        r'<div class="catalog-detail__parameter-name">\s*' + name_pattern + r'\s*</div>\s*'
        r'<div class="catalog-detail__parameter-line"></div>\s*'
        r'<div class="catalog-detail__parameter-size[^>]*>).*?(</div>\s*</div>)',
        re.S | re.I,
    )
    if pattern.search(block):
        block = pattern.sub(rf"\g<1>{html.escape(value)}\g<2>", block, count=1)
        block = re.sub(
            r'(<div class="catalog-detail__parameter-name">)\s*' + name_pattern + r'\s*(</div>)',
            rf"\g<1>{html.escape(name)}\g<2>", block, count=1, flags=re.S | re.I,
        )
    else:
        marker = '<!--                                '
        pos = block.find(marker)
        if pos < 0:
            # Put the new item before the inner column closes.
            pos = block.rfind('</div>')
        block = block[:pos] + parameter_item(name, value) + '\n' + block[pos:]
    return text[:start] + block + text[end:]


def set_length(text: str, metres: int) -> str:
    text = set_parameter(text, r"Длина(?:\s*,\s*мм)?", "Длина", f"{metres} метра")
    start = text.find('<div class="catalog-detail__parameter tabs_block2">')
    if start >= 0:
        end = matching_div_end(text, start)
        block = text[start:end]
        target = "3000" if metres == 3 else "2000"
        block = re.sub(r"(?<!\d)6000(?!\d)", target, block)
        text = text[:start] + block + text[end:]
    return text


def set_color(text: str, color: str) -> str:
    return set_parameter(text, r"Цвет", "Цвет", color)


def add_compatibility_description(text: str) -> str:
    sentence = 'Профиль подходит для стекла толщиной 8, 10 и 12 мм.'
    text = text.replace('стекла толщиной 10 мм', 'стекла толщиной 8, 10 и 12 мм')
    text = text.replace('Уплотнители под толщину 10 мм', 'Уплотнители для стекла толщиной 8, 10 и 12 мм')
    text = text.replace('перегородках из стекла 10 мм', 'перегородках из стекла 8, 10 или 12 мм')
    if sentence in text:
        return text
    marker = '<div class="catalog-detail__parameter tabs_block2">'
    pos = text.find(marker)
    if pos < 0:
        return text
    block = f'<div class="tabs_block1"><div class="catalog-detail__text"><p>{sentence}</p></div></div>\n'
    return text[:pos] + block + text[pos:]


def add_secondary_image(text: str, image_path: str, title: str) -> str:
    if image_path in text:
        return text
    marker = '</div>\n                                                    \n                            </div>\n            <div class="col-lg-5">'
    pos = text.find(marker)
    if pos < 0:
        return text
    gallery = f'''<div class="mygallery-catalog justified-gallery catalog-detail__img-view">
<a href="{image_path}" data-fancybox="gallery_product"><img src="{image_path}" alt="{html.escape(title)} — чертёж" title="{html.escape(title)} — чертёж"></a>
</div>\n'''
    return text[:pos] + gallery + text[pos:]


def set_main_image(text: str, image_path: str) -> str:
    # Replace the primary image path in JSON-LD, OG, gallery link and main image.
    patterns = [
        r'("image"\s*:\s*")[^"]*',
        r'(<meta property="og:image" content="https://)(?:[^"/]+)?/upload/(?:resize_cache/)?(?:iblock|medialibrary)/[^"?]+',
        r'(<a href=")/upload/(?:resize_cache/)?(?:iblock|medialibrary)/[^"?]+(" class="gallery")',
        r'(<img src=")/upload/(?:resize_cache/)?(?:iblock|medialibrary)/[^"?]+("[^>]*class="catalog-detail__img-img")',
    ]
    escaped = image_path.replace("/", r"\/")
    text = re.sub(patterns[0], lambda m: m.group(1) + escaped, text, count=1)
    text = re.sub(patterns[1], lambda m: m.group(1) + image_path, text, count=1)
    text = re.sub(patterns[2], lambda m: m.group(1) + image_path + m.group(2), text, count=1)
    text = re.sub(patterns[3], lambda m: m.group(1) + image_path + m.group(2), text, count=1)
    return text


def remove_extra_gallery(text: str) -> str:
    start = text.find('<div class="mygallery-catalog justified-gallery catalog-detail__img-view">')
    if start >= 0:
        end = matching_div_end(text, start)
        return text[:start] + text[end:]
    return text


def set_product(path: Path, data: dict) -> str:
    text = path.read_text()
    old_title = clean_title(text)
    new_title = data["title"]
    if old_title and old_title != new_title:
        text = text.replace(old_title, new_title)
    if "slug_from" in data:
        text = text.replace(data["slug_from"], data["slug"].removesuffix(".prod"))
    text = set_price(text, data["price"])
    text = set_unit_and_quantity(text)
    text = set_article_detail(text, data["article"])
    text = set_length(text, data.get("metres", 3))
    if data.get("color"):
        text = set_color(text, data["color"])
    if data.get("image"):
        text = set_main_image(text, data["image"])
        text = remove_extra_gallery(text)
    if data.get("id"):
        # Clones use fresh cart identifiers.
        text = re.sub(r'(id="product-id" value=")\d+("\s*/?>)', rf'\g<1>{data["id"]}\g<2>', text)
        text = re.sub(r'(data-product=")\d+("\s*)', rf'\g<1>{data["id"]}\g<2>', text)
        text = re.sub(r"('ELEMENT_ID'\s*:\s*')\d+(')", rf'\g<1>{data["id"]}\g<2>', text)
    path.write_text(text)
    return old_title


def card_length_html(metres: int) -> str:
    return f'''<div class="main-property-product">
<span class="name-product-attribute" data-text="Длина: "></span>
<span class="value-product-attribute" data-text="{metres} метра"></span>
</div>'''


def update_card_segment(segment: str, data: dict) -> str:
    segment = re.sub(
        r'(<span class="pricespace">)\s*\d[\d ]*(\s*</span>)',
        rf'\g<1>{data["price"]}\g<2>', segment, count=1,
    )
    segment = re.sub(r'(data-price=")\d+(?:\.\d+)?(")', rf'\g<1>{data["price"]}\g<2>', segment, count=1)
    segment = set_unit_and_quantity(segment)
    if '<div class="catalog-section-tile__article">' in segment:
        segment = re.sub(
            r'(<div class="catalog-section-tile__article">).*?(</div>)',
            rf'\g<1>Арт. {data["article"]}\g<2>', segment, count=1,
            flags=re.S,
        )
    else:
        marker = '<div class="catalog-section-price__article">'
        pos = segment.find(marker)
        if pos >= 0:
            segment = segment[:pos] + f'<div class="catalog-section-tile__article">Арт. {data["article"]}</div>\n' + segment[pos:]
    if data.get("image"):
        segment = re.sub(
            r'(<img src=")[^"]+("[^>]*class="catalog-section-tile__img-img")',
            rf'\g<1>{data["image"]}\g<2>', segment, count=1,
        )
    if data.get("color"):
        segment = re.sub(
            r'(<span class="name-product-attribute" data-text="Цвет:\s*"></span>\s*'
            r'<span class="value-product-attribute" data-text=")[^"]*("></span>)',
            rf'\g<1>{html.escape(data["color"])}\g<2>', segment, count=1, flags=re.S,
        )
    if 'data-text="Длина: "' not in segment:
        start = segment.find('<div class="main-property-products">')
        if start >= 0:
            end = matching_div_end(segment, start)
            pos = segment.rfind('</div>', start, end)
            segment = segment[:pos] + card_length_html(data.get("metres", 3)) + segment[pos:]
    return segment


def update_all_cards(product_map: dict[str, dict]):
    for path in all_text_files():
        text = path.read_text(errors="ignore")
        if CARD_MARKER not in text:
            continue
        parts = text.split(CARD_MARKER)
        output = [parts[0]]
        changed = False
        for part in parts[1:]:
            segment = CARD_MARKER + part
            match = re.search(r'href="/catalog/([^"?]+\.prod)', segment)
            if match and match.group(1) in product_map:
                new_segment = update_card_segment(segment, product_map[match.group(1)])
                changed |= new_segment != segment
                segment = new_segment
            output.append(segment)
        if changed:
            path.write_text(''.join(output))


def product_card(data: dict) -> str:
    image = data["image"]
    title = data["title"]
    slug = data["slug"]
    return f'''
<div class="col-xl-4 col-sm-6 mb-20 pr-10 pl-10" id="bx_1373509569_{data['id']}">
<div class="catalog-section-tile__item">
<div class="catalog-section-tile__promo-box"></div>
<a href="/catalog/{slug}"><div class="catalog-section-tile__img-box"><img src="{image}" alt="{html.escape(title)}" title="{html.escape(title)}" class="catalog-section-tile__img-img"></div></a>
<div class="catalog-section-tile__text-box">
<div class="catalog-section-tile__status-box"><div class="catalog-section-tile__status"><div class="catalog-section-tile__status-nal">В наличии</div></div><div class="catalog-section-tile__article">Арт. {data['article']}</div><div class="catalog-section-price__article"></div></div>
<div class="catalog-section-tile__title"><a href="/catalog/{slug}" class="catalog-section-tile__title-link">{html.escape(title)}</a></div>
<div class="main-property-products"><div class="main-property-product"><span class="name-product-attribute" data-text="Длина: "></span><span class="value-product-attribute" data-text="{data.get('metres',3)} метра"></span></div></div>
<div class="price-in-one-line"><div class="catalog-section-tile__price-box"><div class="catalog-section-tile__price-now"><span class="pricespace">{data['price']}</span><span class="catalog-section-tile__price-rub">р./шт.</span></div></div><div class="order-block"><a data-fancybox data-src="#form-popup-catalog" href="javascript:;" class="btn-order btn-link" data-name="{html.escape(title)}" data-price="{data['price']}">Заказать</a></div></div>
</div>
<div class="catalog-cart-input catalog-cart-input-list"><div class="catalog-cart-input-wrap catalog-cart-input-list-wrap"><div class="catalog-cart-input-minus catalog-cart-input-list-minus"><i class="fa fa-minus" aria-hidden="true"></i></div><input type="input" name="quantity" value="1" data-step="1"/><div class="catalog-cart-input-plus catalog-cart-input-list-plus"><i class="fa fa-plus" aria-hidden="true"></i></div></div><div class="btn-link catalog-cart-add catalog-cart-input-btn catalog-cart-input-list-btn" data-product="{data['id']}">В корзину</div></div>
</div></div>
'''


def add_cards(page: Path, products: list[dict]):
    text = page.read_text()
    products = [p for p in products if f'/catalog/{p["slug"]}' not in text]
    if not products:
        return
    section = text.find('<div class="catalog-section__row catalog-section-tile">')
    outer_close = text.find('\n\t</div>\n\t\t<br', section)
    if section < 0 or outer_close < 0:
        raise ValueError(f"catalog row closing marker missing: {page}")
    row_close = text.rfind('</div>', section, outer_close)
    text = text[:row_close] + ''.join(product_card(p) for p in products) + text[row_close:]
    count = len(re.findall(r'class="catalog-section-tile__item"', text))
    text = re.sub(r'("offerCount"\s*:\s*")\d+("\s*)', rf'\g<1>{count}\g<2>', text, count=1)
    page.write_text(text)


def add_sitemap(products: list[dict]):
    sitemap = SITE / "sitemap.xml"
    if not sitemap.exists():
        return
    text = sitemap.read_text()
    additions = []
    for product in products:
        route = product["slug"].removesuffix(".prod") + "/"
        if route not in text:
            additions.append(f'<url><loc>https://kibosh13.github.io/forma-201/catalog/{route}</loc></url>')
    if additions:
        text = text.replace('</urlset>', '\n'.join(additions) + '\n</urlset>')
        sitemap.write_text(text)


def make_ceiling_products() -> tuple[list[dict], list[dict]]:
    order = [
        ('karniz-dlya-skrytogo-osveshcheniya-pod-gipsokarton-60-mm.prod', 3568),
        ('razdelitel-dlya-sten.prod', 1576),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki.prod', 1690),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki-shampan.prod', 1690),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-chyernyy.prod', 1256),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-12-mm-zoloto.prod', 1256),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-chyernyy-6m.prod', 1190),
        ('razdeliteli-dlya-sten-zoloto.prod', 1576),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-12-mm-shampan.prod', 1256),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-10-mm-serebro-6m.prod', 1190),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki-serebro.prod', 1690),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki-belye.prod', 1690),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-10-mm-zoloto-6m.prod', 1190),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-10-mm-belye-6m.prod', 1190),
        ('razdeliteli-dlya-sten-belye.prod', 1576),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-12-mm-serebro.prod', 1256),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-10-mm-shampan-6m.prod', 1190),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-12-mm-belyy.prod', 1256),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki-zoloto.prod', 1690),
        ('razdeliteli-dlya-sten-serebro.prod', 1576),
        ('razdeliteli-dlya-sten-shampan.prod', 1576),
    ]
    products = []
    for idx, (slug, price) in enumerate(order, 70):
        text = (CAT / slug).read_text()
        title = clean_title(text)
        low = title.lower()
        if slug.startswith('karniz-'):
            title = 'Карниз 60 мм под гипсокартон потолочный для подсветки белый матовый 3 метра'
            color = 'белый матовый'
        else:
            replacements = [
                (r'\bчёрные\b|\bчёрный\b', 'чёрный матовый', 'чёрный матовый'),
                (r'\bсеребро\b', 'серебро матовое', 'серебро матовое'),
                (r'\bбелые\b|\bбелый\b', 'белый матовый', 'белый матовый'),
                (r'\bшампань\b', 'шампань матовая', 'шампань матовая'),
                (r'\bзолото\b', 'золото матовое', 'золото матовое'),
            ]
            color = ''
            for pattern, replacement, finish in replacements:
                if re.search(pattern, low):
                    title = re.sub(pattern, replacement, title, count=1, flags=re.I)
                    color = finish
                    break
            title = re.sub(r'\s+[236]\s*метр(?:а|ов)?\s*$', '', title, flags=re.I).strip() + ' 3 метра'
        products.append({'slug': slug, 'title': title, 'price': price, 'article': f'{idx:04d}', 'metres': 3, 'color': color})

    # Only black profiles receive additional 2 m cards.
    clone_specs = [
        ('razdelitel-dlya-sten.prod', 'razdelitel-dlya-sten-chernyy-2-metra.prod', 1120),
        ('potolochnye-skrytye-plintusy-dlya-podsvetki.prod', 'potolochnye-skrytye-plintusy-dlya-podsvetki-chernye-2-metra.prod', 1485),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-chyernyy.prod', 'potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-12-mm-chernye-2-metra.prod', 934),
        ('potolochnye-skrytye-plintusy-bez-podsvetki-chyernyy-6m.prod', 'potolochnye-skrytye-plintusy-bez-podsvetki-pod-gipsokarton-10-mm-chernye-2-metra.prod', 876),
    ]
    clones = []
    by_slug = {p['slug']: p for p in products}
    for offset, (source_slug, slug, price) in enumerate(clone_specs, 91):
        source = by_slug[source_slug]
        source_card = (CAT / 'alyuminievye-potolochnye-plintusy/index.html').read_text()
        image_match = re.search(r'href="/catalog/' + re.escape(source_slug) + r'".*?<img src="([^"]+)"', source_card, re.S)
        title = source['title'].replace('3 метра', '2 метра')
        clones.append({
            'slug': slug, 'slug_from': source_slug.removesuffix('.prod'), 'source': source_slug,
            'title': title, 'price': price, 'article': f'{offset:04d}', 'metres': 2,
            'color': 'чёрный матовый', 'id': str(2200 + offset),
            'image': image_match.group(1) if image_match else '',
        })
    return products, clones


GLASS_ORDER = [
    ('komplekt-opornogo-profilya-serebro-matovoe-h-40-mm-dlya-stekol-10-mm.prod', 4790, None),
    ('komplekt-opornogo-profilya-serebro-matovoe-h-102-mm-dlya-stekol-10-12-16-20-mm.prod', 16845, None),
    ('universalnyy-zazhimnoy-profil-dlya-stekla-102-mm-serebro.prod', 19855, None),
    ('komplekt-opornogo-profilya-matovyy-h-40-mm-dlya-stekol-10-mm.prod', 4638, 'матовый без покрытия'),
    ('p-profil-serebristyy.prod', 2956, 'серебро матовое'),
    ('komplekt-opornogo-profilya-matovyy-h-106-mm-dlya-stekol-10-12-16-20-mm.prod', 18756, 'матовый без покрытия'),
    ('komplekt-opornogo-profilya-h-102-mm-dlya-stekol-10-12-16-20-mm.prod', 15900, 'матовый без покрытия'),
    ('komplekt-opornogo-profilya-serebro-matovoe-h-106-mm-dlya-stekol-10-12-16-20-mm.prod', 18200, None),
    ('polirovanyi-p-profil-serebristyy.prod', 3284, 'серебро полированное'),
    ('komplekt-opornogo-profilya-chyernyy-matovyy-h-40-mm-dlya-stekol-10-mm.prod', 5070, 'чёрный матовый'),
    ('komplekt-opornogo-profilya-matovyy-h-80-mm-dlya-stekol-10-12-16-mm.prod', 13150, 'матовый без покрытия'),
    ('komplekt-opornogo-profilya-h-80-mm-dlya-stekol-10-12-16-mm-serebro-matovoe.prod', 13950, 'серебро матовое'),
    ('komplekt-opornogo-profilya-chyernyy-polirovannyy-h-40-mm-dlya-stekol-10-mm.prod', 5223, 'чёрный полированный'),
    ('komplekt-opornogo-profilya-serebro-polirovannoe-h-40-mm-dlya-stekol-10-mm.prod', 5169, 'серебро полированное'),
    ('p-profil-chyernyy.prod', 2990, 'чёрный матовый'),
    ('polirovanyi-p-profil-chyernyy.prod', 2990, 'чёрный полированный'),
]


CUSTOM_IMAGES = {
    'komplekt-opornogo-profilya-chyernyy-matovyy-h-40-mm-dlya-stekol-10-mm.prod': ('glass-black-matt-h40.jpg', 'codex-clipboard-fe8decdf-920e-4ba9-901d-c56347b58c65.png'),
    'komplekt-opornogo-profilya-chyernyy-polirovannyy-h-40-mm-dlya-stekol-10-mm.prod': ('glass-black-polished-h40.jpg', 'codex-clipboard-340af450-026c-4a59-a98a-940ff1dba182.png'),
    'komplekt-opornogo-profilya-serebro-polirovannoe-h-40-mm-dlya-stekol-10-mm.prod': ('glass-silver-polished-h40.jpg', 'codex-clipboard-d906f731-135a-4116-b86c-acdbadf3b1d3.png'),
    'p-profil-chyernyy.prod': ('glass-u-black-matt.jpg', 'codex-clipboard-49b292cf-694c-4431-8d7b-68eb086499c4.png'),
    'polirovanyi-p-profil-chyernyy.prod': ('glass-u-black-polished.jpg', 'codex-clipboard-f5efc205-c682-49ae-85f1-165fe02a19ae.png'),
}


def glass_title(slug: str, current: str, finish: str | None) -> str:
    fixed_titles = {
        'polirovanyi-p-profil-serebristyy.prod': 'Полированный П-образный профиль для стекла серебро полированное 3 метра',
        'polirovanyi-p-profil-chyernyy.prod': 'Полированный П-образный профиль для стекла чёрный полированный 3 метра',
    }
    if slug in fixed_titles:
        return fixed_titles[slug]
    title = current.replace('Комплект опорного профиля', 'Комплект зажимного профиля')
    if finish == 'матовый без покрытия':
        title = re.sub(r'\bматовый\b(?:\s+без покрытия)?', '', title, count=1, flags=re.I)
        title = re.sub(r'(профиля)\s+', r'\1 матовый без покрытия ', title, count=1)
    elif finish:
        color_patterns = [r'чёрный\s+(?:матовый|полированный)', r'серебро\s+(?:матовое|полированное)', r'\bчёрный\b', r'\bсеребро\b']
        replaced = False
        for pattern in color_patterns:
            if re.search(pattern, title, re.I):
                title = re.sub(pattern, finish, title, count=1, flags=re.I)
                replaced = True
                break
        if not replaced:
            title += ' ' + finish
    title = re.sub(r'\s+', ' ', title).strip()
    title = re.sub(r'\s+3\s*метра\s*$', '', title, flags=re.I) + ' 3 метра'
    return title


def make_glass_products() -> tuple[list[dict], list[dict]]:
    products = []
    for idx, (slug, price, finish) in enumerate(GLASS_ORDER, 95):
        current = clean_title((CAT / slug).read_text())
        title = glass_title(slug, current, finish)
        data = {'slug': slug, 'title': title, 'price': price, 'article': f'{idx:04d}', 'metres': 3, 'color': finish or ''}
        if slug in CUSTOM_IMAGES:
            name, source = CUSTOM_IMAGES[slug]
            data['image'] = f'/upload/iblock/custom-glass/{name}'
            save_jpeg(TMP / source, SITE / data['image'].lstrip('/'))
        products.append(data)

    category = (CAT / 'zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/index.html').read_text()
    source_slug = 'komplekt-opornogo-profilya-serebro-matovoe-h-102-mm-dlya-stekol-10-12-16-20-mm.prod'
    black_h102 = {
        'slug': 'komplekt-zazhimnogo-profilya-chernyy-matovyy-h-102-mm-dlya-stekol-10-12-16-20-mm.prod',
        'slug_from': source_slug.removesuffix('.prod'), 'source': source_slug,
        'title': 'Комплект зажимного профиля чёрный матовый h 102 мм для стекол 10, 12, 16, 20 мм 3 метра',
        'price': 16975, 'article': '0111', 'metres': 3, 'color': 'чёрный матовый', 'id': '2311',
        'image': '/upload/iblock/custom-glass/glass-black-matt-h102.jpg',
    }
    save_jpeg(TMP / 'codex-clipboard-9c4e9391-81db-4c82-9f13-0997d56539cb.png', SITE / black_h102['image'].lstrip('/'))
    new_multi = {
        'slug': 'komplekt-zazhimnogo-profilya-chernyy-matovyy-h-40-mm-dlya-stekol-8-10-12-mm.prod',
        'slug_from': 'komplekt-opornogo-profilya-chyernyy-matovyy-h-40-mm-dlya-stekol-10-mm',
        'source': 'komplekt-opornogo-profilya-chyernyy-matovyy-h-40-mm-dlya-stekol-10-mm.prod',
        'title': 'Комплект зажимного профиля чёрный матовый h 40 мм для стекол 8,10,12 мм длина 3 метра',
        'price': 5368, 'article': '0112', 'metres': 3, 'color': 'чёрный матовый', 'id': '2312',
        'image': '/upload/iblock/custom-glass/glass-clamp-8-10-12-drawing.jpg',
        'special_8_10_12': True,
    }
    save_jpeg(TMP / 'codex-clipboard-bceeda27-a591-4847-977a-2675fc77e91b.png', SITE / new_multi['image'].lstrip('/'))
    new_multi_silver = {
        'slug': 'komplekt-zazhimnogo-profilya-serebro-matovoe-h-40-mm-dlya-stekol-8-10-12-mm.prod',
        'slug_from': 'komplekt-opornogo-profilya-serebro-matovoe-h-40-mm-dlya-stekol-10-mm',
        'source': 'komplekt-opornogo-profilya-serebro-matovoe-h-40-mm-dlya-stekol-10-mm.prod',
        'title': 'Комплект зажимного профиля серебро матовое h 40 мм для стекол 8,10,12 мм длина 3 метра',
        'price': 5168, 'article': '0113', 'metres': 3, 'color': 'серебро матовое', 'id': '2313',
        'image': '/upload/iblock/custom-glass/glass-clamp-8-10-12-silver.jpg',
        'secondary_image': '/upload/iblock/custom-glass/glass-clamp-8-10-12-drawing-silver.jpg',
        'special_8_10_12': True,
    }
    save_jpeg(TMP / 'codex-clipboard-82cd4f26-e99b-4beb-9033-b9991eb3a655.png', SITE / new_multi_silver['image'].lstrip('/'))
    save_jpeg(TMP / 'codex-clipboard-e86c67d1-c185-4e4b-a95a-2c0b89eff66a.png', SITE / new_multi_silver['secondary_image'].lstrip('/'))
    return products, [black_h102, new_multi, new_multi_silver]


def clone_products(products: list[dict]):
    for product in products:
        destination = CAT / product['slug']
        if destination.exists():
            destination.unlink()
        source = CAT / product['source']
        destination.write_text(source.read_text())
        set_product(destination, product)
        if product.get('special_8_10_12'):
            text = destination.read_text()
            text = set_parameter(text, r'Высота(?:\s*,\s*мм)?', 'Высота, мм', '40')
            text = set_parameter(text, r'Длина(?:\s*,\s*мм)?', 'Длина, мм', '3000')
            text = set_parameter(text, r'Размеры', 'Размеры', '40 x 3000')
            text = set_parameter(text, r'Толщина стекла(?:\s*,\s*мм)?', 'Толщина стекла, мм', '8, 10, 12')
            text = add_compatibility_description(text)
            if product.get('secondary_image'):
                text = add_secondary_image(text, product['secondary_image'], product['title'])
            destination.write_text(text)


def remove_unwanted_recommendations():
    for slug in ['alyuminievyy-plintus-100-mm-9145.prod', 'alyuminievyy-plintus-100mm-9146.prod']:
        path = CAT / slug
        text = path.read_text()
        start = text.find('<section class="related-accessories"')
        if start >= 0:
            end = matching_section_end(text, start)
            path.write_text(text[:start] + text[end:])


def set_preorder_globally():
    preorder_slugs = set()
    for page in CAT.glob('*.prod'):
        text = page.read_text(errors='ignore')
        title = clean_title(text).lower()
        if re.search(r'\b(?:бел\w*|золот\w*|золото|шампань\w*)\b', title):
            preorder_slugs.add(page.name)
            new = text.replace('https:\/\/schema.org\/InStock', 'https:\/\/schema.org\/PreOrder')
            new = new.replace('https://schema.org/InStock', 'https://schema.org/PreOrder')
            new = new.replace('В наличии', 'Под заказ')
            if new != text:
                page.write_text(new)
    for path in all_text_files():
        text = path.read_text(errors='ignore')
        if CARD_MARKER not in text:
            continue
        parts = text.split(CARD_MARKER)
        output = [parts[0]]
        changed = False
        for part in parts[1:]:
            segment = CARD_MARKER + part
            match = re.search(r'href="/catalog/([^"?]+\.prod)', segment)
            if match and match.group(1) in preorder_slugs:
                new_segment = segment.replace('В наличии', 'Под заказ')
                changed |= new_segment != segment
                segment = new_segment
            output.append(segment)
        if changed:
            path.write_text(''.join(output))
    return preorder_slugs


def main():
    ceiling, ceiling_clones = make_ceiling_products()
    glass, glass_new = make_glass_products()

    # Rename exact product titles everywhere before changing detail files.
    title_changes = []
    for product in ceiling + glass:
        old = clean_title((CAT / product['slug']).read_text())
        if old and old != product['title']:
            title_changes.append((old, product['title']))
    for path in all_text_files():
        text = path.read_text(errors='ignore')
        new = text
        for old, title in sorted(title_changes, key=lambda item: len(item[0]), reverse=True):
            new = new.replace(old, title)
        if new != text:
            path.write_text(new)

    for product in ceiling + glass:
        set_product(CAT / product['slug'], product)

    clone_products(ceiling_clones + glass_new)
    product_map = {p['slug']: p for p in ceiling + glass + ceiling_clones + glass_new}
    update_all_cards(product_map)

    ceiling_page = CAT / 'alyuminievye-potolochnye-plintusy/index.html'
    add_cards(ceiling_page, ceiling_clones)
    # Diffusers already have fixed articles 0068/0069 and appear in this category too.
    diffuser_products = [
        {'slug': 'rasseivatel-dlya-svetodiodnoy-lenty-belyy-2-metra.prod', 'title': 'Рассеиватель для светодиодной ленты белый 2 метра', 'price': 164, 'article': '0068', 'metres': 2, 'id': '2003', 'image': '/upload/iblock/custom-hidden/led-diffuser-white.jpg'},
        {'slug': 'rasseivatel-dlya-svetodiodnoy-lenty-chernyy-2-metra.prod', 'title': 'Рассеиватель для светодиодной ленты чёрный 2 метра', 'price': 164, 'article': '0069', 'metres': 2, 'id': '2004', 'image': '/upload/iblock/custom-hidden/led-diffuser-black.jpg'},
    ]
    add_cards(ceiling_page, diffuser_products)
    add_cards(CAT / 'zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/index.html', glass_new)

    remove_unwanted_recommendations()
    preorder = set_preorder_globally()
    add_sitemap(ceiling_clones + glass_new)
    print({'ceiling': len(ceiling), 'ceiling_2m': len(ceiling_clones), 'glass': len(glass), 'glass_new': len(glass_new), 'preorder': len(preorder)})


if __name__ == '__main__':
    main()
