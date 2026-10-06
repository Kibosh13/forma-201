#!/usr/bin/env python3
"""Exercise real admin HTTP saves in a disposable copy, without touching live data."""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
from html.parser import HTMLParser
from html import unescape
from urllib.request import build_opener, HTTPCookieProcessor, Request

REPO = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
SLUG = 'komplekt-opornogo-profilya-matovyy-h-102-mm-dlya-stekol-10-12-16-20-mm'
NO_DESC = 'komplekt-opornogo-profilya-matovyy-h-106-mm-dlya-stekol-10-12-16-20-mm'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6XAAAAABJRU5ErkJggg==')


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.forms, self.current, self.capture, self.select, self.option = [], None, None, None, None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.current = []
            self.forms.append(self.current)
        if self.current is None:
            return
        if tag == 'input' and a.get('name') and 'disabled' not in a:
            kind = a.get('type', 'text')
            if kind not in ('file', 'submit', 'button') and (kind not in ('checkbox', 'radio') or 'checked' in a):
                self.current.append((a['name'], a.get('value', 'on' if kind in ('checkbox', 'radio') else '')))
        if tag == 'textarea' and a.get('name'):
            self.capture = [a['name'], '']
        if tag == 'select' and a.get('name'):
            self.select = [a['name'], []]
        if tag == 'option' and self.select:
            self.option = [a.get('value'), '', 'selected' in a]

    def handle_data(self, data):
        if self.capture:
            self.capture[1] += data
        if self.option:
            self.option[1] += data

    def handle_endtag(self, tag):
        if tag == 'textarea' and self.capture:
            self.current.append(tuple(self.capture))
            self.capture = None
        if tag == 'option' and self.option:
            self.select[1].append(self.option)
            self.option = None
        if tag == 'select' and self.select:
            options = self.select[1]
            selected = next((o for o in options if o[2]), options[0])
            self.current.append((self.select[0], selected[0] if selected[0] is not None else selected[1]))
            self.select = None
        if tag == 'form':
            self.current = None


def set_field(fields, key, value):
    fields[:] = [(k, v) for k, v in fields if k != key]
    if value is not None:
        fields.append((key, value))


def main():
    temp = tempfile.TemporaryDirectory(prefix='alym-editor-qa-')
    root = Path(temp.name)
    for name in ('admin', 'poisk'):
        shutil.copytree(REPO / 'site' / name, root / name, ignore=shutil.ignore_patterns('storage', 'config.local.php'))
    shutil.copyfile(REPO / 'site/content.php', root / 'content.php')
    (root / 'admin/storage').mkdir()
    (root / 'catalog').mkdir()
    (root / '_mirror/query').mkdir(parents=True)
    template_name = 'alyuminievaya-dvernaya-korobka-l'
    for slug in (SLUG, NO_DESC, template_name):
        shutil.copyfile(REPO / 'site/catalog' / (slug + '.prod'), root / 'catalog' / (slug + '.prod'))
    seed = json.loads((REPO / 'site/admin/data/catalog.json').read_text())
    full_source = os.environ.get('ALYM_QA_FULL_ROOT')
    if full_source:
        full_root = Path(full_source)
        full_index = full_root / 'admin/storage/catalog.json'
        seed = json.loads((full_index if full_index.exists() else full_root / 'admin/data/catalog.json').read_text())
        for entry in list(seed['products'].values()) + list(seed['categories'].values()):
            src, dst = full_root / entry['path'], root / entry['path']
            if src.is_file():
                dst.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(src, dst)
        for src in (full_root / '_mirror/query').glob('*.html'):
            shutil.copyfile(src, root / '_mirror/query' / src.name)
    product = seed['products'][SLUG]
    product['category'] = 'qa'
    categories = dict(seed['categories']) if full_source else {}
    categories.update({c: {'slug': c, 'name': c, 'path': f'catalog/{c}/index.html', 'route': f'/catalog/{c}/', 'image': ''} for c in ('qa', 'other')})
    products = dict(seed['products']) if full_source else {SLUG: product, NO_DESC: seed['products'][NO_DESC], template_name: seed['products'][template_name]}
    catalog = {'products': products, 'categories': categories, 'pages': {}}
    (root / 'admin/data/catalog.json').write_text(json.dumps(catalog, ensure_ascii=False))
    route = product['route']
    def listing(href, title):
        return f'''<!doctype html><html><head><title>QA</title></head><body><div class="content-box"><div class="row"><div class="col-xl-4"><div class="catalog-section-tile__item"><a class="catalog-section-tile__title-link" href="{href}">{title}</a><img class="catalog-section-tile__img-img" src="/qa.png"><span class="pricespace">1</span><span class="catalog-section-tile__price-rub">р./шт.</span><span class="catalog-section-tile__article">Арт. 1</span><span class="catalog-section-tile__status-nal">В наличии</span><button data-name="{title}" data-price="1">Купить</button></div></div></div></div></body></html>'''
    for category in ('qa', 'other'):
        (root / f'catalog/{category}').mkdir()
        (root / categories[category]['path']).write_text(listing('/catalog/' + template_name + '.prod', 'Другой товар'))
    (root / 'catalog/index.html').write_text(listing(route, product['name']))
    for target in ('index.html', '_mirror/query/page2.html', 'catalog/qa.tag'):
        (root / target).write_text(listing(route, product['name']))
    (root / 'pagination-routes.php').write_text("<?php return ['/catalog/qa/?PAGEN_1=2'=>'_mirror/query/page2.html'];")
    (root / 'qa.png').write_bytes(PNG)
    password_hash = subprocess.check_output([PHP, '-r', 'echo password_hash("local-qa-password", PASSWORD_DEFAULT);'], text=True)
    (root / 'admin/config.local.php').write_text(f"<?php return ['user'=>'qa','password_hash'=>'{password_hash}'];")
    # Keep the legacy-review regression independent of the production content:
    # the real imported review can already have been removed by the migration.
    prepare = '''require 'admin/bootstrap.php';require 'admin/editor.php';$e=load_catalog()['products']["''' + SLUG + '''"];[$d,$x]=load_dom_file($e['path']);apply_product_reviews($d,$x,[],'QA');$block=$d->createElement('div');$block->setAttribute('class','portfolio-list');set_inner_html($block,'<div class="portfolio-detail"><div class="client-text">Автор: Сергей</div><span class="rating" value="5"></span><div class="review-tex-padding"><p>Наша компания специализируется на остеклении зданий.</p></div><img class="portfolio-detail__photo-img" src="/qa.png"></div>');first_node($x,'//body')->appendChild($block);sync_product_review_schema($x,[['author'=>'Сергей','rating'=>5,'text'=>'Наша компания специализируется на остеклении зданий.','images'=>['/qa.png']]]);save_dom_file($e['path'],$d);'''
    subprocess.run([PHP, '-r', prepare], cwd=root, check=True, capture_output=True)
    (root / 'inspect.php').write_text('''<?php require 'admin/bootstrap.php'; require 'admin/editor.php'; $c=load_catalog(); $e=$c['products'][$_GET['slug']]??null; header('Content-Type: application/json'); echo json_encode($e?product_read($e):null,JSON_UNESCAPED_UNICODE);''')
    (root / 'router.php').write_text('''<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); if(str_ends_with($p,'.prod')||str_ends_with($p,'.tag')||str_ends_with($p,'.html')){$_GET['alym_file']=ltrim($p,'/');require __DIR__.'/content.php';return true;}return false;''')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = tempfile.TemporaryFile()
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(root), str(root / 'router.php')], stdout=log, stderr=log)
    client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base = f'http://127.0.0.1:{port}'
    checks = []
    def request(path, fields=None, uploads=()):
        if fields is None:
            return client.open(base + path).read().decode()
        boundary = 'qa-' + os.urandom(12).hex()
        parts = []
        for key, value in fields:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        for key, filename in uploads:
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode() + PNG + b'\r\n')
        data = b''.join(parts) + f'--{boundary}--\r\n'.encode()
        return client.open(Request(base + path, data=data, headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})).read().decode()
    def form(slug=SLUG):
        html = request('/admin/?section=products&action=edit&slug=' + slug)
        return next(f for f in Forms(html).forms if ('action', 'save_product') in f)
    def read(slug=SLUG):
        return json.loads(build_opener().open(base + '/inspect.php?slug=' + slug).read().decode())
    def save(fields, uploads=(), expect_error=None):
        html = request('/admin/', fields, uploads)
        assert (expect_error in html) if expect_error else ('сразу опубликован' in html), html[:2000]
    def schema(slug=SLUG):
        html = request('/catalog/' + slug + '.prod')
        entries = [json.loads(s) for s in re.findall(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S)]
        return next(j for j in entries if j.get('@type') == 'Product')
    def check(name, condition, details=None):
        assert condition, (name, details)
        checks.append(name)
    try:
        for _ in range(50):
            try:
                login = request('/admin/')
                break
            except OSError:
                time.sleep(.05)
        login_fields = Forms(login).forms[0]
        set_field(login_fields, 'user', 'qa')
        set_field(login_fields, 'password', 'local-qa-password')
        request('/admin/', login_fields)
        check('Legacy review is exposed in admin', read()['reviews'][0]['author'] == 'Сергей')
        inline_before = re.findall(r'<script\s*>(.*?)</script>', request(route), re.S)
        fields = form()
        stale = list(fields)
        fields.append(('review_delete[existing0]', '1'))
        save(fields)
        check('Saving preserves JavaScript containing HTML strings', re.findall(r'<script\s*>(.*?)</script>', request(route), re.S) == inline_before)
        check('Review deleted from rendered page, form and schema', not read()['reviews'] and 'review' not in schema() and 'Наша компания специализируется' not in request(route))
        save(form())
        check('Second save does not restore review', not read()['reviews'])
        save(stale, expect_error='Карточка изменилась после открытия формы')
        check('Stale form cannot restore deleted review', not read()['reviews'])
        fields = form()
        set_field(fields, 'name', 'QA профиль обновлённый')
        set_field(fields, 'price', '1 234,50')
        set_field(fields, 'unit', 'р./м')
        set_field(fields, 'article', 'QA-100')
        set_field(fields, 'status', 'Под заказ')
        set_field(fields, 'seo_title', 'QA SEO заголовок')
        set_field(fields, 'seo_description', 'QA SEO описание')
        set_field(fields, 'description', '<h2>QA описание</h2><p><strong>Форматирование</strong> и текст</p>')
        set_field(fields, 'summary', '<p>QA краткий текст</p>')
        fields[:] = [(k, v) for k, v in fields if not k.startswith('attribute_')]
        fields += [('attribute_name[]', 'Длина, мм'), ('attribute_value[]', '3000'), ('attribute_name[]', 'Цвет'), ('attribute_value[]', 'Чёрный & матовый')]
        old_main = read()['image']
        old_count = len(read()['images'])
        save(fields, [('gallery_upload[]', 'first.png'), ('gallery_upload[]', 'second.png')])
        p = read()
        check('Text, price, status, article, SEO and attributes round-trip', p['name'] == 'QA профиль обновлённый' and p['price'] == '1 234,50' and p['status'] == 'Под заказ' and p['article'] == 'QA-100' and p['seo_title'] == 'QA SEO заголовок' and p['attributes'][1]['value'] == 'Чёрный & матовый' and '<strong>Форматирование</strong>' in p['description'], p)
        check('Multiple uploads preserve chosen main image', p['image'] == old_main and len(p['images']) == old_count + 2)
        check('Gallery and availability match structured data', schema()['image'] == p['images'] and schema()['offers']['price'] == '1234.50' and schema()['offers']['availability'].endswith('/PreOrder'))
        for target in ('index.html', '_mirror/query/page2.html', 'catalog/qa.tag'):
            check('Updated product references: ' + target, 'QA профиль обновлённый' in unescape((root / target).read_text()))
        check('Page 2 product is not duplicated on page 1', 'QA профиль обновлённый' not in unescape((root / 'catalog/qa/index.html').read_text()))
        check('Search uses current catalog rather than seed', 'QA профиль обновлённый' in request('/poisk/?q=QA'))
        # Simulate the actual incident: deployment replaces the public template
        # with an old repository copy. Saved customer fields must still win.
        shutil.copyfile(REPO / 'site/catalog' / (SLUG + '.prod'), root / 'catalog' / (SLUG + '.prod'))
        html = request(route)
        check('Replacing template does not reset admin data', read() == p)
        check('Replacing template does not reset public name, price, photos or attributes', 'QA профиль обновлённый' in unescape(html) and '1 234,50' in html and p['images'][-1] in html and 'Чёрный & матовый' in unescape(html))
        (root / '_mirror/query/page2.html').write_text(listing(route, 'Старое название'))
        check('Old category archive still renders saved price and name', 'QA профиль обновлённый' in unescape(request('/_mirror/query/page2.html')) and '1 234,50' in request('/_mirror/query/page2.html'))
        many = ''.join(listing(route, 'Старое название').split('<body>')[1].split('</body>')[0] for _ in range(12))
        (root / '_mirror/query/page2.html').write_text('<html><body>' + many + '</body></html>')
        check('Every card in a multi-card listing is refreshed', 'Старое название' not in unescape(request('/_mirror/query/page2.html')))
        check('Complete saved versions have a separate history', len(list((root / 'admin/storage/product-history').rglob('*.json'))) >= 3)
        fields = form()
        for key, author, rating, text in [('new1', 'Тест & контроль', '5', 'Первая строка\nВторая строка\n\nДругой абзац'), ('new2', 'Другой автор', '4', 'Второй отзыв')]:
            fields += [(f'review_author[{key}]', author), (f'review_rating[{key}]', rating), (f'review_text[{key}]', text)]
        set_field(fields, 'video_url', 'https://rutube.ru/video/abcdef1234567890/')
        save(fields, [('review_upload_new1[]', 'review1.png'), ('review_upload_new1[]', 'review2.png')])
        p = read()
        check('Create reviews with uploaded photos and special characters', len(p['reviews']) == 2 and p['reviews'][0]['author'] == 'Тест & контроль' and len(p['reviews'][0]['images']) == 2)
        check('Review line breaks survive save', p['reviews'][0]['text'] == 'Первая строка\nВторая строка\n\nДругой абзац')
        check('Review schema is recalculated', schema()['aggregateRating']['reviewCount'] == 2 and schema()['aggregateRating']['ratingValue'] == 4.5)
        check('Video is editable as a separate field', p['video_url'] == 'https://rutube.ru/play/embed/abcdef1234567890/')
        save(form())
        again = read()
        check('Repeat save preserves review content, photos and video', again == p, {k:[p[k],again[k]] for k in p if p[k] != again[k]})
        fields = form()
        photo = p['reviews'][0]['images'][0]
        fields.append(('review_remove_image[existing0][]', photo))
        set_field(fields, 'review_text[existing1]', 'Отредактированный отзыв')
        set_field(fields, 'review_rating[existing1]', '3')
        save(fields)
        check('Edit review and delete individual photo', photo not in read()['reviews'][0]['images'] and read()['reviews'][1]['text'] == 'Отредактированный отзыв' and read()['reviews'][1]['rating'] == 3)
        fields = form()
        chosen = read()['images'][-1]
        set_field(fields, 'main_image', str(len(read()['images']) - 1))
        save(fields)
        check('Select main image from existing gallery', read()['image'] == chosen and schema()['image'][0] == chosen)
        fields = form()
        fields.append(('remove_image[]', '0'))
        save(fields)
        check('Delete selected main image with safe fallback', chosen not in read()['images'] and read()['image'] == old_main)
        fields = form()
        fields += [('review_delete[existing0]', '1'), ('review_delete[existing1]', '1'), ('remove_videos', '1')]
        fields[:] = [(k, v) for k, v in fields if not k.startswith('attribute_')]
        save(fields)
        save(form())
        check('Delete all reviews, video and attributes; save twice', not read()['reviews'] and not read()['video_url'] and not read()['attributes'] and 'aggregateRating' not in schema())
        fields = form()
        fields += [('remove_image[]', str(i)) for i in range(len(read()['images']))]
        before = (root / 'catalog' / (SLUG + '.prod')).read_bytes()
        save(fields, expect_error='Добавьте хотя бы одно изображение товара')
        check('Invalid save does not erase product', (root / 'catalog' / (SLUG + '.prod')).read_bytes() == before)
        fields = form(NO_DESC)
        check('Imported template has no description block initially', not read(NO_DESC)['description'])
        set_field(fields, 'description', '<h2>Новое описание</h2><p>Добавлено в пустую карточку</p>')
        set_field(fields, 'video_url', '/upload/qa-video.mp4')
        save(fields)
        check('Missing description tab is created with direct video', 'Добавлено в пустую карточку' in read(NO_DESC)['description'] and read(NO_DESC)['video_url'] == '/upload/qa-video.mp4')
        fields = form(NO_DESC)
        set_field(fields, 'video_url', 'https://untrusted.invalid/?youtube.com')
        save(fields, expect_error='Укажите корректную ссылку')
        check('Invalid video source leaves existing video intact', read(NO_DESC)['video_url'] == '/upload/qa-video.mp4')
        fields = form(NO_DESC)
        fields.append(('remove_videos', '1'))
        save(fields)
        save(form(NO_DESC))
        check('Direct video deletion persists on repeat save', not read(NO_DESC)['video_url'])
        fields = form('')
        set_field(fields, 'slug', 'qa-new-product')
        set_field(fields, 'name', 'QA новый товар')
        set_field(fields, 'price', '99')
        set_field(fields, 'category', 'other')
        save(fields, expect_error='Добавьте хотя бы одно изображение товара')
        check('Invalid creation leaves no orphan card', not (root / 'catalog/qa-new-product.prod').exists())
        save(fields, [('gallery_upload[]', 'new.png')])
        check('Create product and publish in category/search', read('qa-new-product')['name'] == 'QA новый товар' and 'QA новый товар' in request('/poisk/?q=QA') and 'QA новый товар' in unescape((root / 'catalog/other/index.html').read_text()))
        fields = form('qa-new-product')
        set_field(fields, 'category', 'qa')
        save(fields)
        check('Move product between categories', 'QA новый товар' not in unescape((root / 'catalog/other/index.html').read_text()) and 'QA новый товар' in unescape((root / 'catalog/qa/index.html').read_text()))
        csrf = dict(fields)['csrf']
        html = request('/admin/', [('action', 'delete_product'), ('csrf', csrf), ('slug', 'qa-new-product')])
        check('Delete product from disk, category and search', 'Товар удалён' in html and read('qa-new-product') is None and 'QA новый товар' not in unescape((root / 'catalog/qa/index.html').read_text()) and 'QA новый товар' not in request('/poisk/?q=QA'))
        (root / 'catalog/qa/index.html').write_text(listing('/catalog/qa-new-product.prod', 'QA новый товар'))
        check('Old listing cannot restore a deleted product', 'QA новый товар' not in unescape(request('/catalog/qa/index.html')))
        # Duplicate imported blocks are a regression case for whole-list deletion.
        test_php = '''require 'admin/bootstrap.php';require 'admin/editor.php';[$d,$x]=load_dom_file('catalog/''' + SLUG + '''.prod');$p=product_read(['path'=>'catalog/''' + SLUG + '''.prod']);apply_product_reviews($d,$x,[['author'=>'Duplicate','rating'=>5,'text'=>'Duplicate body','images'=>[]]],'QA');$l=first_node($x,class_query('portfolio-list'));$l->parentNode->appendChild($l->cloneNode(true));if(count(product_reviews_read($x))!==2)exit(2);apply_product_reviews($d,$x,[],'QA');if(product_reviews_read($x)||str_contains($d->saveHTML(),'Duplicate body'))exit(3);'''
        result = subprocess.run([PHP, '-r', test_php], cwd=root, capture_output=True, text=True)
        check('All duplicate legacy/managed review blocks removed', result.returncode == 0)
        test_php = '''require 'admin/bootstrap.php';require 'admin/editor.php';$e=load_catalog()['products']["''' + SLUG + '''"];$v=product_read($e);[$d,$x]=load_dom_file($e['path']);$body=first_node($x,'//body');$n=$d->createElement('div');$n->setAttribute('class','catalog-section-tile__item');$b=$d->createElement('button');$b->setAttribute('data-name','Other product');$b->setAttribute('data-price','999');$n->appendChild($b);$body->appendChild($n);apply_product_values($d,$x,$v);if($b->getAttribute('data-name')!=='Other product'||$b->getAttribute('data-price')!=='999')exit(2);'''
        result = subprocess.run([PHP, '-r', test_php], cwd=root, capture_output=True, text=True)
        check('Editing one product preserves other products order buttons', result.returncode == 0, result.stderr)
        print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
        log.seek(0)
        errors = [line for line in log.read().decode().splitlines() if 'Fatal error' in line or 'Warning:' in line]
        assert not errors, errors
    finally:
        server.terminate()
        server.wait(timeout=10)
        if os.environ.get('ALYM_QA_COPY'):
            shutil.copytree(root, os.environ['ALYM_QA_COPY'], dirs_exist_ok=True)
        temp.cleanup()


if __name__ == '__main__':
    main()
