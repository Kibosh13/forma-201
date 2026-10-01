#!/usr/bin/env python3
"""Apply the October 1 catalog batch requested from the visual audit."""

from __future__ import annotations

import hashlib
import html
import json
import re
import shutil
from pathlib import Path

from finish_catalog_updates_2026_10_01 import (
    add_cards,
    add_sitemap,
    clean_title,
    matching_div_end,
    set_color,
    set_parameter,
    set_price,
    set_unit_and_quantity,
)

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "site"
CAT = SITE / "catalog"
TMP = Path("/var/folders/45/7w5jgz5n20j7xfhflp2_n2d80000gn/T")
TEXT_SUFFIXES = {".html", ".prod", ".tag", ".xml", ".txt"}
CARD = '<div class="col-xl-4 col-sm-6 mb-20 pr-10 pl-10"'


def text_files():
    for path in SITE.rglob("*"):
        if path.is_file() and path.suffix.lower() in TEXT_SUFFIXES:
            yield path


def global_replace(replacements: list[tuple[str, str]]) -> None:
    replacements = sorted({x for x in replacements if x[0] != x[1]}, key=lambda x: len(x[0]), reverse=True)
    for path in text_files():
        source = path.read_text(errors="ignore")
        updated = source
        # Mask existing final titles first. Several old titles are exact prefixes
        # of their final title, so replacing them in already-updated text would
        # otherwise duplicate the finish and length.
        final_masks = []
        for index, final in enumerate(sorted({new for _, new in replacements}, key=len, reverse=True)):
            token = f'__CATALOG_FINAL_TITLE_{index}__'
            if final in updated:
                updated = updated.replace(final, token)
                final_masks.append((token, final))
        # Placeholders also prevent a newly inserted title from matching another
        # old title in the same migration (matte and polished handrails overlap).
        placeholders = []
        for index, (old, new) in enumerate(replacements):
            token = f'__CATALOG_TITLE_REPLACEMENT_{index}__'
            if old in updated:
                updated = updated.replace(old, token)
                placeholders.append((token, new))
        for token, new in placeholders:
            updated = updated.replace(token, new)
        for token, final in final_masks:
            updated = updated.replace(token, final)
        if updated != source:
            path.write_text(updated)


def card_blocks(text: str):
    cursor = 0
    while True:
        start = text.find(CARD, cursor)
        if start < 0:
            break
        end = matching_div_end(text, start)
        block = text[start:end]
        match = re.search(r'href="/catalog/([^"?]+\.prod)', block)
        yield start, end, match.group(1) if match else "", block
        cursor = end


def set_card(block: str, *, title: str | None = None, price: int | None = None,
             unit_piece: bool = False, image: str | None = None,
             status: str | None = None, length_mm: int | None = None) -> str:
    if price is not None:
        block = re.sub(r'(<span class="pricespace">)\s*\d[\d ]*(\s*</span>)', rf'\g<1>{price}\g<2>', block, count=1)
        block = re.sub(r'(data-price=")\d+(?:\.\d+)?(")', rf'\g<1>{price}\g<2>', block, count=1)
    if unit_piece:
        block = set_unit_and_quantity(block)
    if image:
        block = re.sub(r'(<img src=")[^"]+(")', rf'\g<1>{image}\g<2>', block, count=1)
    if status:
        block = block.replace("В наличии", status, 1).replace("Под заказ", status, 1)
    if title:
        block = re.sub(r'(data-name=")[^"]+(")', lambda m: m.group(1) + html.escape(title, quote=True) + m.group(2), block, count=1)
        block = re.sub(r'(<img\b[^>]*\balt=")[^"]*(")', lambda m: m.group(1) + html.escape(title, quote=True) + m.group(2), block, count=1)
        block = re.sub(r'(<img\b[^>]*\btitle=")[^"]*(")', lambda m: m.group(1) + html.escape(title, quote=True) + m.group(2), block, count=1)
        block = re.sub(r'(<a\b[^>]*class="catalog-section-tile__title-link"[^>]*>).*?(</a>)', lambda m: m.group(1) + '\n' + title + m.group(2), block, count=1, flags=re.S)
    if length_mm is not None:
        item = (f'<div class="main-property-product"><span class="name-product-attribute" '
                f'data-text="Длина, мм: "></span><span class="value-product-attribute" '
                f'data-text="{length_mm}"></span></div>')
        if re.search(r'data-text="Длина(?:, мм)?: ', block):
            block = re.sub(
                r'<div class="main-property-product">\s*<span class="name-product-attribute" data-text="Длина(?:, мм)?: "></span>\s*<span class="value-product-attribute" data-text="[^"]*"></span>\s*</div>',
                item, block, count=1, flags=re.S,
            )
        else:
            start = block.find('<div class="main-property-products">')
            if start >= 0:
                end = matching_div_end(block, start)
                block = block[:end - len('</div>')] + item + block[end - len('</div>'):]
    return block


def update_cards(product_updates: dict[str, dict]) -> None:
    for path in text_files():
        source = path.read_text(errors="ignore")
        if CARD not in source:
            continue
        out, cursor, changed = [], 0, False
        for start, end, slug, block in list(card_blocks(source)):
            out.append(source[cursor:start])
            data = product_updates.get(slug)
            new = set_card(block, **data) if data else block
            out.append(new)
            changed |= new != block
            cursor = end
        out.append(source[cursor:])
        if changed:
            path.write_text(''.join(out))


def set_detail(path: Path, *, price: int | None = None, unit_piece: bool = False,
               length_mm: int | None = None, color: str | None = None,
               status: str | None = None, sizes_length: int | None = None) -> None:
    text = path.read_text()
    if price is not None:
        text = set_price(text, price)
    if unit_piece:
        text = set_unit_and_quantity(text)
    if length_mm is not None:
        text = set_parameter(text, r'Длина(?:\s*,\s*мм)?', 'Длина, мм', str(length_mm))
    if color:
        text = set_color(text, color)
    if sizes_length is not None:
        start = text.find('<div class="catalog-detail__parameter tabs_block2">')
        if start >= 0:
            end = matching_div_end(text, start)
            block = re.sub(r'(?<!\d)6000(?!\d)', str(sizes_length), text[start:end])
            text = text[:start] + block + text[end:]
    if status:
        text = text.replace('https:\/\/schema.org\/InStock', 'https:\/\/schema.org\/PreOrder')
        text = text.replace('https://schema.org/InStock', 'https://schema.org/PreOrder')
        text = text.replace('В наличии', status, 1)
    path.write_text(text)


def replace_description_number(path: Path, old: str, new: str) -> None:
    text = path.read_text()
    cursor = 0
    while True:
        start = text.find('<div class="catalog-detail__text">', cursor)
        if start < 0:
            break
        end = matching_div_end(text, start)
        block = text[start:end].replace(old, new)
        text = text[:start] + block + text[end:]
        cursor = start + len(block)
    path.write_text(text)


def set_category_count(path: Path) -> None:
    text = path.read_text()
    count = len(re.findall(r'class="catalog-section-tile__item"', text))
    text = re.sub(r'("offerCount"\s*:\s*")\d+("\s*)', rf'\g<1>{count}\g<2>', text, count=1)
    path.write_text(text)


def remove_cards(path: Path, slugs: set[str]) -> None:
    text = path.read_text()
    removals = [(s, e) for s, e, slug, _ in card_blocks(text) if slug in slugs]
    for start, end in reversed(removals):
        text = text[:start] + text[end:]
    path.write_text(text)
    set_category_count(path)


def extract_card(path: Path, slug: str) -> str:
    text = path.read_text()
    for _, _, found, block in card_blocks(text):
        if found == slug:
            return block
    raise ValueError(f'card not found: {slug} in {path}')


def append_raw_cards(path: Path, blocks: list[str]) -> None:
    text = path.read_text()
    section = text.find('<div class="catalog-section__row catalog-section-tile">')
    outer = text.find('\n\t</div>\n\t\t<br', section)
    close = text.rfind('</div>', section, outer)
    if min(section, outer, close) < 0:
        raise ValueError(f'catalog row not found: {path}')
    text = text[:close] + '\n'.join(blocks) + text[close:]
    path.write_text(text)
    set_category_count(path)


def move_holders() -> dict[str, dict]:
    holders = {
        'stalnoy-stekloderzhatel.prod': ('Стальной стеклодержатель для стекла 10 мм', 1990),
        'stalnoy-stekloderzhatel-12mm.prod': ('Стальной стеклодержатель для стекла 12 мм', 1990),
        'stalnoy-stekloderzhatel-16-mm.prod': ('Стальной стеклодержатель для стекла 16 мм', 2074),
    }
    replacements = []
    for slug, (title, _) in holders.items():
        replacements.append((clean_title((CAT / slug).read_text()), title))
    global_replace(replacements)
    source = CAT / 'furnitura-dlya-alyuminievykh-peril/index.html'
    target = CAT / 'zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/index.html'
    blocks = [extract_card(source, slug) for slug in holders]
    remove_cards(source, set(holders))
    append_raw_cards(target, blocks)
    updates = {}
    for slug, (title, price) in holders.items():
        set_detail(CAT / slug, price=price, unit_piece=True)
        text = (CAT / slug).read_text()
        text = re.sub(r'<a href="/catalog/furnitura-dlya-alyuminievykh-peril/">.*?</a>', '<a href="/catalog/zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/">Зажимной профиль для стекла</a>', text, count=1, flags=re.S)
        (CAT / slug).write_text(text)
        updates[slug] = dict(title=title, price=price, unit_piece=True)
    return updates


def handrail_specs():
    groups = []
    for glass, slug in [(10, 'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo.prod'), (12, 'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-12-mm.prod'), (16, 'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-16-mm.prod')]:
        groups.append((slug, f'Алюминиевые поручни 40*40 мм с пазом под стекло {glass} мм серебро матовое длина 3 метра', 5680, 'серебро матовое', 'square-matte'))
    for glass in (10, 12, 16):
        slug=f'alyuminievye-poruchni-polorovannye-40-40-mm-s-pazom-pod-steklo-{glass}-mm.prod'
        groups.append((slug, f'Алюминиевые поручни 40*40 мм с пазом под стекло {glass} мм серебро полированное длина 3 метра', 5690, 'серебро полированное', 'square-polished'))
    for glass, slug in [(10, 'alyuminievye-poruchni-kruglye-43-mm-pod-steklo.prod'), (12, 'alyuminievye-poruchni-kruglye-43-mm-pod-steklo-12-mm.prod'), (16, 'alyuminievye-poruchni-kruglye-43-mm-pod-steklo-16-mm.prod')]:
        groups.append((slug, f'Алюминиевые поручни круглые 43 мм под стекло {glass} мм серебро матовое длина 3 метра', 4890, 'серебро матовое', 'round-matte'))
    for glass in (10, 12, 16):
        slug=f'alyuminievye-poruchni-polorovannye-kruglye-43-mm-pod-steklo-{glass}-mm.prod'
        groups.append((slug, f'Алюминиевые поручни круглые 43 мм под стекло {glass} мм серебро полированное длина 3 метра', 5280, 'серебро полированное', 'round-polished'))
    for glass in (10, 12, 16):
        slug=f'alyuminievye-poruchni-treugolnye-45-30-mm-pod-steklo-{glass}-mm.prod'
        groups.append((slug, f'Алюминиевые поручни треугольные 45*30 мм под стекло {glass} мм серебро матовое длина 3 метра', 4480, 'серебро матовое', 'triangle'))
    for glass in (10, 12, 16, 20):
        matte = f'alyuminievyy-p-obraznyy-poruchen-pod-uplotnitel-dlya-stekla-{glass}-mm.prod'
        polished = f'alyuminievyy-p-obraznyy-polirovanyi-poruchen-pod-uplotnitel-dlya-stekla-{glass}-mm.prod'
        groups.append((matte, f'Алюминиевый П-образный поручень для стекла {glass} мм серебро матовое длина 3 метра', 2672, 'серебро матовое', 'p-matte'))
        groups.append((polished, f'Алюминиевый П-образный поручень для стекла {glass} мм серебро полированное длина 3 метра', 2918, 'серебро полированное', 'p-polished'))
    return groups


def set_related(path: Path, items: list[tuple[str, str]] | None) -> None:
    text = path.read_text()
    start = text.find('<div class="to_delete"><h2>С этим товаром покупают</h2>')
    if start < 0:
        start = text.find('<div class="to_delete">\n                    <h2>С этим товаром покупают</h2>')
    if start < 0:
        return
    end = matching_div_end(text, start)
    if items is None:
        replacement = ''
    else:
        links = ''.join(f'<a href="/catalog/{slug}">{html.escape(title)}</a>' for slug, title in items)
        replacement = f'<div class="to_delete"><h2>С этим товаром покупают</h2>{links}</div>'
    path.write_text(text[:start] + replacement + text[end:])


def update_handrails() -> tuple[dict[str, dict], list[dict]]:
    specs = handrail_specs()
    global_replace([(clean_title((CAT / slug).read_text()), title) for slug, title, *_ in specs])
    updates = {}
    by_group = {}
    for slug, title, price, color, group in specs:
        set_detail(CAT / slug, price=price, unit_piece=True, length_mm=3000, color=color)
        updates[slug] = dict(title=title, price=price, unit_piece=True, length_mm=3000)
        by_group.setdefault(group, []).append(slug)

    hardware = {
        'square-matte': [
            'flanets-s-pazom-dlya-poruchnya-40-40-mm-alyuminievyy-serebro-matovoe.prod',
            'soedinitel-pryamoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-serebro-matovoe.prod',
            'soedinitel-uglovoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-serebro-matovoe.prod',
            'zaglushka-s-pazom-dlya-poruchnya-40-40-mm-alyuminievaya-serebro-matovoe.prod'],
        'square-polished': [
            'flanets-s-pazom-dlya-poruchnya-40-40-mm-alyuminievyy-polirovannyy.prod',
            'soedinitel-pryamoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-polirovannyy.prod',
            'soedinitel-uglovoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-polirovannyy.prod'],
        'round-matte': [
            'flanets-s-pazom-dlya-kruglogo-poruchnya-43-mm-alyuminievyy-serebro-matovoe.prod',
            'soedinitel-pryamoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-serebro-matovoe.prod',
            'soedinitel-uglovoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-serebro-matovoe.prod',
            'zaglushka-s-pazom-dlya-kruglogo-poruchnya-43-mm-alyuminievaya-serebro-matovoe.prod'],
        'round-polished': [
            'flanets-s-pazom-dlya-kruglogo-poruchnya-43-mm-alyuminievyy-polirovannyy.prod',
            'soedinitel-pryamoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-polirovannyy.prod',
            'soedinitel-uglovoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-polirovannyy.prod'],
        'square-black': [
            'flanets-s-pazom-dlya-poruchnya-40-40-mm-alyuminievyy-chyernyy-matovyy.prod',
            'soedinitel-pryamoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod',
            'soedinitel-uglovoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod',
            'zaglushka-s-pazom-dlya-poruchnya-40-40-mm-alyuminievaya-chyernyy-matovyy.prod'],
    }
    for group in ['square-matte','square-polished','round-matte','round-polished']:
        items=[(s,clean_title((CAT/s).read_text())) for s in hardware[group]]
        for slug in by_group[group]: set_related(CAT/slug, items)
    for slug in by_group['triangle']: set_related(CAT/slug, None)

    image = '/upload/product-updates/square-handrail-black-matte.png'
    dest = SITE / image.lstrip('/')
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(TMP / 'codex-clipboard-df6a234b-9c55-415f-a988-c3f159133362.png', dest)
    new_products=[]
    sources={10:'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo.prod',12:'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-12-mm.prod',16:'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-16-mm.prod'}
    for idx,(glass,source) in enumerate(sources.items(),2601):
        slug=f'alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-{glass}-mm-chernyy-matovyy.prod'
        title=f'Алюминиевые поручни 40*40 мм с пазом под стекло {glass} мм чёрный матовый длина 3 метра'
        path=CAT/slug; path.write_text((CAT/source).read_text())
        text=path.read_text().replace(clean_title(path.read_text()),title).replace(source.removesuffix('.prod'),slug.removesuffix('.prod'))
        text=re.sub(r'(id="product-id" value=")\d+',rf'\g<1>{idx}',text)
        text=re.sub(r'(data-product=")\d+',rf'\g<1>{idx}',text)
        path.write_text(text)
        set_detail(path,price=5692,unit_piece=True,length_mm=3000,color='чёрный матовый',status='Под заказ')
        # New products use the supplied black image on both card and detail.
        text=path.read_text()
        text=re.sub(r'("image"\s*:\s*")[^"]+',lambda m:m.group(1)+image.replace('/','\\/'),text,count=1)
        text=re.sub(r'(<meta property="og:image" content=")[^"]+',rf'\g<1>{image}',text,count=1)
        text=re.sub(r'(<a href=")[^"]+(" class="gallery")',rf'\g<1>{image}\g<2>',text,count=1)
        text=re.sub(r'(<img src=")[^"]+("[^>]*class="catalog-detail__img-img")',rf'\g<1>{image}\g<2>',text,count=1)
        path.write_text(text)
        set_related(path,[(s,clean_title((CAT/s).read_text())) for s in hardware['square-black']])
        data={'slug':slug,'title':title,'price':5692,'article':f'260{glass}','metres':3,'id':str(idx),'image':image}
        new_products.append(data)
        updates[slug]=dict(title=title,price=5692,unit_piece=True,image=image,status='Под заказ',length_mm=3000)
    add_cards(CAT/'alyuminievye-perila/index.html',new_products)
    add_sitemap(new_products)
    return updates,new_products


def update_hardware() -> dict[str, dict]:
    page=CAT/'furnitura-dlya-alyuminievykh-peril/index.html'
    remove=set(); updates={}; replacements=[]
    for _,_,slug,block in card_blocks(page.read_text()):
        if not slug: continue
        title=clean_title((CAT/slug).read_text())
        low=title.lower()
        if not any(x in low for x in ('чёрн','серебро матов','полирован')):
            remove.add(slug); continue
        new_title=title
        if 'полирован' in low and 'серебро полирован' not in low:
            new_title=re.sub(r'\bполированный\b','серебро полированное',title,flags=re.I)
            replacements.append((title,new_title))
        price=int(re.search(r'<span class="pricespace">(\d+)</span>',block).group(1))
        new_price=round(price*3.5)
        set_detail(CAT/slug,price=new_price,unit_piece=True)
        updates[slug]=dict(title=new_title,price=new_price,unit_piece=True)
    global_replace(replacements)
    remove_cards(page,remove)

    # The static category initially omitted some colored variants. Restore every
    # explicit matte/polished/black fitting and apply the same 250% markup.
    candidates=[]
    for path in sorted(CAT.glob('*.prod')):
        slug=path.name
        if not any(word in slug for word in ('flanets-s-pazom','soedinitel-pryamoy-dlya-','soedinitel-uglovoy-dlya-','zaglushka-s-pazom-dlya-')):
            continue
        title=clean_title(path.read_text()); low=title.lower()
        if not any(x in low for x in ('чёрн','серебро матов','полирован')):
            continue
        price_match=re.search(r'<span class="pricespace">\s*(\d+)',path.read_text())
        if not price_match:
            continue
        if slug not in updates:
            old_price=int(price_match.group(1)); new_price=round(old_price*3.5)
            new_title=title
            if 'полирован' in low and 'серебро полирован' not in low:
                new_title=re.sub(r'\bполированный\b','серебро полированное',title,flags=re.I)
                replacements.append((title,new_title))
            set_detail(path,price=new_price,unit_piece=True)
            updates[slug]=dict(title=new_title,price=new_price,unit_piece=True)
        detail=path.read_text()
        img=re.search(r'<img src="([^"]+)"[^>]*class="catalog-detail__img-img"',detail)
        candidates.append({'slug':slug,'title':updates[slug]['title'],'price':updates[slug]['price'],'article':'28'+str(len(candidates)+1).zfill(2),'id':str(2801+len(candidates)),'image':img.group(1) if img else ''})
    global_replace(replacements)
    add_cards(page,candidates)
    # Hardware has no profile length. ``add_cards`` normally emits a default
    # three-metre property for profiles, so remove that generated property from
    # these restored fitting cards.
    text=page.read_text()
    text=re.sub(
        r'<div class="main-property-products"><div class="main-property-product"><span class="name-product-attribute" data-text="Длина: "></span><span class="value-product-attribute" data-text="3 метра"></span></div></div>',
        '', text,
    )
    pager=text.find('<div class="bx-pagination ')
    if pager>=0:
        text=text[:pager]+text[matching_div_end(text,pager):]
    page.write_text(text)

    black_images={
        'flanets-s-pazom-dlya-poruchnya-40-40-mm-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-82441d6a-461d-451b-8e0e-5177acd7836f.png',
        'soedinitel-pryamoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-5d465254-5c7a-4394-981f-7e241a598975.png',
        'soedinitel-uglovoy-dlya-poruchnya-40-40-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-b75adacd-a81a-49d4-8d05-63a90c48a3a8.png',
        'zaglushka-s-pazom-dlya-poruchnya-40-40-mm-alyuminievaya-chyernyy-matovyy.prod':'codex-clipboard-70d5d8ee-8df6-4a55-a30d-76d2b63f2323.png',
        'flanets-s-pazom-dlya-kruglogo-poruchnya-43-mm-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-025d0e71-f6fd-4fa9-a357-16735aecc666.png',
        'soedinitel-pryamoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-937de9d8-47c7-46d7-b604-17b6fc303e2f.png',
        'soedinitel-uglovoy-dlya-kruglogo-poruchnya-43-mm-s-pazom-alyuminievyy-chyernyy-matovyy.prod':'codex-clipboard-09c7dab7-c45e-4e5f-a7fa-03e04964301c.png',
        'zaglushka-s-pazom-dlya-kruglogo-poruchnya-43-mm-alyuminievaya-chyernaya-matovaya.prod':'codex-clipboard-da2efcf9-f85c-4856-b19d-a7c1bf6bf2aa.png',
    }
    assets={}
    for slug,source in black_images.items():
        target=f'/upload/product-updates/hardware-black-{slug.removesuffix(".prod")}.png'
        dest=SITE/target.lstrip('/');dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(TMP/source,dest)
        updates[slug]['image']=target;assets[slug]=target
    # These photos are specifically card images; leave detail galleries intact.
    text=page.read_text();out=[];cursor=0
    for start,end,slug,block in list(card_blocks(text)):
        out.append(text[cursor:start]);out.append(set_card(block,image=assets[slug]) if slug in assets else block);cursor=end
    out.append(text[cursor:]);page.write_text(''.join(out))
    # Keep the previously mirrored second-page URL consistent for direct visits,
    # even though the consolidated page no longer exposes pagination.
    for mirror in category_pages('furnitura-dlya-alyuminievykh-peril')[1:]:
        mirror.write_text(page.read_text())
    return updates


def category_slugs(path: Path) -> list[str]:
    return [slug for _,_,slug,_ in card_blocks(path.read_text()) if slug]


def category_pages(category: str) -> list[Path]:
    """Return the first page and every mirrored PAGEN page for a category."""
    manifest=json.loads((ROOT/'metadata/manifest.json').read_text())
    base=f'https://dial-td.ru/catalog/{category}/'
    rows=[]
    for url,record in manifest['files'].items():
        if url==base or url.startswith(base+'?PAGEN_1='):
            page=ROOT/record['file']
            if page.is_file(): rows.append((0 if url==base else int(url.rsplit('=',1)[1]),page))
    return [page for _,page in sorted(rows)]


def category_slugs_all(category: str) -> list[str]:
    return list(dict.fromkeys(slug for page in category_pages(category) for slug in category_slugs(page)))


def category_card(pages: list[Path], slug: str) -> str:
    for page in pages:
        try: return extract_card(page,slug)
        except ValueError: pass
    raise ValueError(f'card not found for {slug}')


def append_length(title: str, metres: str) -> str:
    title=re.sub(r'\s+(?:длина\s+)?\d+(?:[,.]\d+)?\s*метр(?:а|ов)?\s*$','',title,flags=re.I).strip()
    return f'{title} длина {metres}'


def update_sanitary() -> dict[str,dict]:
    page=CAT/'profil-dlya-santekhnicheskikh-kabinok-santekhnicheskie-peregorodki/index.html'
    slugs=category_slugs(page); profile_words=('профиль','стойка','окантовка')
    replacements=[]; updates={}
    for slug in slugs:
        path=CAT/slug; title=clean_title(path.read_text()); low=title.lower()
        card=extract_card(page,slug); old_price=int(re.search(r'<span class="pricespace">(\d+)</span>',card).group(1))
        is_small=any(x in low for x in ('ручка','петли','задвижка'))
        price=old_price*2 if is_small else old_price*4
        new_title=append_length(title,'2 метра') if any(x in low for x in profile_words) else title
        replacements.append((title,new_title))
        set_detail(path,price=price,unit_piece=True,length_mm=2000 if new_title!=title else None)
        updates[slug]=dict(title=new_title,price=price,unit_piece=True,length_mm=2000 if new_title!=title else None)
    global_replace(replacements)
    return updates


def update_ventilation() -> dict[str,dict]:
    category='alyuminievyy-profil-dlya-sistem-ventilyatsii'; pages=category_pages(category); updates={}; replacements=[]
    for slug in category_slugs_all(category):
        path=CAT/slug; title=clean_title(path.read_text()); new_title=append_length(title,'3 метра')
        card=category_card(pages,slug); price=int(re.search(r'<span class="pricespace">(\d+)</span>',card).group(1))*6
        replacements.append((title,new_title)); set_detail(path,price=price,unit_piece=True,sizes_length=3000)
        replace_description_number(path,'6000','3000')
        updates[slug]=dict(title=new_title,price=price,unit_piece=True,length_mm=3000)
    global_replace(replacements)
    return updates


def update_construction() -> dict[str,dict]:
    category='stroitelnyy-alyuminievyy-profil'; updates={}; replacements=[]
    for slug in category_slugs_all(category):
        path=CAT/slug; title=clean_title(path.read_text()); new_title=re.sub(r'(?<!\d)6000(?!\d)','3000',title)
        replacements.append((title,new_title)); set_detail(path,unit_piece=True,sizes_length=3000)
        replace_description_number(path,'6000','3000')
        updates[slug]=dict(title=new_title,unit_piece=True,length_mm=3000)
    global_replace(replacements)
    return updates


def clone_canopy() -> tuple[dict[str,dict],list[dict]]:
    source='zazhimnoy-profil-kozyrka-dlya-stekla.prod'; source_path=CAT/source
    old=clean_title(source_path.read_text())
    image='/upload/product-updates/canopy-clamp-profile.png'; dest=SITE/image.lstrip('/'); dest.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(TMP/'codex-clipboard-df9d0c64-b136-45f6-b831-b9699f8314bb.png',dest)
    specs=[(source,'Зажимной профиль козырька для стекла 16,20 мм серебро матовое 1,5 метра',16990,1500,2701),('zazhimnoy-profil-kozyrka-dlya-stekla-2-metra.prod','Зажимной профиль козырька для стекла 16,20 мм серебро матовое 2 метра',23990,2000,2702),('zazhimnoy-profil-kozyrka-dlya-stekla-3-metra.prod','Зажимной профиль козырька для стекла 16,20 мм серебро матовое 3 метра',35990,3000,2703)]
    global_replace([(old,specs[0][1])])
    updates={}; new=[]
    for i,(slug,title,price,length,pid) in enumerate(specs):
        path=CAT/slug
        if i:
            path.write_text(source_path.read_text().replace(specs[0][1],title).replace(source.removesuffix('.prod'),slug.removesuffix('.prod')))
            text=path.read_text(); text=re.sub(r'(id="product-id" value=")\d+',rf'\g<1>{pid}',text); text=re.sub(r'(data-product=")\d+',rf'\g<1>{pid}',text); path.write_text(text)
        set_detail(path,price=price,unit_piece=True,length_mm=length,color='серебро матовое')
        updates[slug]=dict(title=title,price=price,unit_piece=True,image=image,length_mm=length)
        if i:
            new.append({'slug':slug,'title':title,'price':price,'article':f'27{i+1:02d}','metres':2 if length==2000 else 3,'id':str(pid),'image':image})
    # Source card is updated via update_cards; add two clones.
    add_cards(CAT/'zazhimnye-profili-i-furnitura-dlya-steklyannykh-kozyrkov/index.html',new)
    add_sitemap(new)
    return updates,new


def replace_sanitary_card_images() -> None:
    image='/upload/product-updates/angular-dsp-profile-watermark.png'; dest=SITE/image.lstrip('/'); dest.parent.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(TMP/'codex-clipboard-1ed1bcaa-9a42-4545-bf36-1f2f6f19a5af.png',dest)
    page=CAT/'profil-dlya-santekhnicheskikh-kabinok-santekhnicheskie-peregorodki/index.html'
    text=page.read_text(); targets={'uglovoy-90-pod-dsp-16-mm.prod','uglovoy-90-pod-dsp-16-mm-1-4mm.prod'}
    out=[];cursor=0
    for start,end,slug,block in list(card_blocks(text)):
        out.append(text[cursor:start]); out.append(set_card(block,image=image) if slug in targets else block); cursor=end
    out.append(text[cursor:]); page.write_text(''.join(out))


def update_door_boxes() -> dict[str,dict]:
    prices={'alyuminievaya-dvernaya-korobka-z.prod':5409,'alyuminievaya-dvernaya-korobka-l.prod':4800}; updates={}
    for slug,price in prices.items():
        set_detail(CAT/slug,price=price); updates[slug]=dict(title=clean_title((CAT/slug).read_text()),price=price)
    return updates


def remove_trailing_dimension_lists() -> int:
    labels=re.compile(r'(?:Толщина|Ширина|Высота|Длина|Диаметр|Размер|Габарит|Паз|Стенк)',re.I); changed=0
    for path in CAT.glob('*.prod'):
        text=path.read_text(); original=text; cursor=0
        while True:
            start=text.find('<div class="catalog-detail__text">',cursor)
            if start<0: break
            end=matching_div_end(text,start); block=text[start:end]
            # Remove size-only lists at the end of a description block.
            block=re.sub(r'<ul>([\s\S]*?)</ul>(?=\s*</div>$)',lambda m:'' if labels.search(re.sub('<[^>]+>',' ',m.group(0))) else m.group(0),block,flags=re.I)
            text=text[:start]+block+text[end:]; cursor=start+len(block)
        if text!=original: path.write_text(text); changed+=1
    return changed


def remove_price_from_prefixes() -> int:
    changed=0
    patterns=[
        (r'(<div class="catalog-detail__price-now">\s*)от\s+',r'\1'),
        (r'(<div class="catalog-section-tile__price-now">\s*)от\s+',r'\1'),
        (r'(<span[^>]*class="[^"]*(?:price-from|from-price)[^"]*"[^>]*>)\s*от\s*(</span>)',r'\1\2'),
    ]
    for path in text_files():
        text=path.read_text(errors='ignore'); new=text
        for pat,repl in patterns:new=re.sub(pat,repl,new,flags=re.I)
        if new!=text:path.write_text(new);changed+=1
    return changed


def refresh_manifest() -> None:
    path=ROOT/'metadata/manifest.json'; data=json.loads(path.read_text())
    new_pages = [
        'catalog/alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-10-mm-chernyy-matovyy.prod',
        'catalog/alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-12-mm-chernyy-matovyy.prod',
        'catalog/alyuminievye-poruchni-40-40-mm-s-pazom-pod-steklo-16-mm-chernyy-matovyy.prod',
        'catalog/zazhimnoy-profil-kozyrka-dlya-stekla-2-metra.prod',
        'catalog/zazhimnoy-profil-kozyrka-dlya-stekla-3-metra.prod',
    ]
    new_assets = [str(p.relative_to(SITE)) for p in (SITE/'upload/product-updates').glob('*') if p.is_file()]
    for relative in new_pages + new_assets:
        local = SITE / relative
        raw = local.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        url = 'https://dial-td.ru/' + relative
        is_page = relative.endswith('.prod')
        record = {
            'url': url, 'final_url': url, 'file': 'site/' + relative,
            'content_type': 'text/html; charset=UTF-8' if is_page else 'image/png',
            'bytes': len(raw), 'sha256': digest, 'http_status': 200,
            'last_modified': None, 'kind': 'page' if is_page else 'asset',
            'inferred_original': False, 'status': 'ok',
            'local_sha256': digest, 'local_bytes': len(raw),
        }
        if not is_page:
            record['cached'] = True
        data['files'][url] = record
    for record in data['files'].values():
        local=ROOT/record['file']
        if local.is_file():
            raw=local.read_bytes();record['local_sha256']=hashlib.sha256(raw).hexdigest();record['local_bytes']=len(raw)
    path.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')


def main() -> None:
    updates={}
    updates.update(move_holders())
    handrails,_=update_handrails();updates.update(handrails)
    updates.update(update_hardware())
    updates.update(update_sanitary())
    updates.update(update_ventilation())
    updates.update(update_construction())
    canopy,_=clone_canopy();updates.update(canopy)
    updates.update(update_door_boxes())
    update_cards(updates)
    replace_sanitary_card_images()
    removed=remove_trailing_dimension_lists()
    no_from=remove_price_from_prefixes()
    for page in [CAT/'alyuminievye-perila/index.html',CAT/'furnitura-dlya-alyuminievykh-peril/index.html',CAT/'profil-dlya-santekhnicheskikh-kabinok-santekhnicheskie-peregorodki/index.html',CAT/'alyuminievyy-profil-dlya-sistem-ventilyatsii/index.html',CAT/'stroitelnyy-alyuminievyy-profil/index.html',CAT/'zazhimnye-profili-i-furnitura-dlya-steklyannykh-kozyrkov/index.html',CAT/'zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/index.html']:
        set_category_count(page)
    refresh_manifest()
    print({'updated_products':len(updates),'description_lists_removed':removed,'from_prefix_files':no_from})


if __name__=='__main__':
    main()
