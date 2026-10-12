#!/usr/bin/env python3
"""Exercise cart sessions, checkout and captured email in a private HTTP copy."""
import http.cookiejar
from html import unescape
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor

REPO = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')


def main():
    with tempfile.TemporaryDirectory(prefix='alym-checkout-qa-') as temporary:
        root = Path(temporary)
        for folder in ('admin', 'lib/cart', 'lib/feedback'):
            shutil.copytree(REPO / 'site' / folder, root / folder, ignore=shutil.ignore_patterns('storage', 'config.local.php'))
        shutil.copyfile(REPO / 'site/order.php', root / 'order.php')
        shutil.copyfile(REPO / 'site/content.php', root / 'content.php')
        (root / 'catalog').mkdir()
        catalog = {'products': {}, 'categories': {}, 'pages': {}}
        for slug, name, price in [('qa-profile', 'Профиль & алюминий', '100'), ('qa-cap', 'Заглушка', '25.5')]:
            route = f'/catalog/{slug}.prod'
            catalog['products'][slug] = {'slug': slug, 'name': name, 'price': price, 'status': 'В наличии', 'image': '/qa.png', 'route': route, 'path': route[1:]}
            # Cloned imported templates can share a numeric legacy ID.
            (root / route[1:]).write_text(f'<html><head><title>{name}</title></head><body><h1>{name}</h1><input id="product-id" value="99"><div class="catalog-detail__price"><span class="pricespace">{price}</span><div class="catalog-detail__price-rub">р./шт.</div></div><div class="catalog-detail__img-box"><img src="/qa.png"></div><div class="catalog-detail__btn catalog-cart-input"><input name="quantity" value="1" data-step="1"><button data-product="99" class="catalog-cart-add">В корзину</button></div></body></html>')
        (root / 'admin/data/catalog.json').write_text(json.dumps(catalog, ensure_ascii=False))
        (root / 'admin/storage').mkdir()
        (root / 'admin/storage/settings.json').write_text('{"lead_email":"qa@example.invalid"}')
        (root / 'catalog/index.html').write_text('<html><head><title>Каталог</title></head><body><header>АлюмПрофи</header><div id="sidebar"></div><div class="content-box"></div><footer>Контакты</footer></body></html>')
        capture = root / 'capture-mail.py'
        capture.write_text('import pathlib,sys,json\nr=pathlib.Path(__file__).parent\nassert json.loads((r / "admin/storage/leads.json").read_text()), "Lead must exist before sending email"\nif (r / "fail-mail").exists(): sys.exit(1)\n(r / ("mail-"+str(len(list(r.glob("mail-*.eml"))))+".eml")).write_bytes(sys.stdin.buffer.read())\n')
        router = root / 'router.php'
        router.write_text('''<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if($p==='/order/') {require __DIR__.'/order.php';return true;}if(preg_match('~\\.(html|prod|tag)$~',$p)){$_GET['alym_file']=ltrim($p,'/');require __DIR__.'/content.php';return true;}return false;''')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        log = tempfile.TemporaryFile()
        server = subprocess.Popen([PHP, '-d', f'sendmail_path={sys.executable} {capture}', '-S', f'127.0.0.1:{port}', str(router)], cwd=root, stdout=log, stderr=log)
        base = f'http://127.0.0.1:{port}'
        customer = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        other = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        def request(path, fields=None, opener=customer):
            data = None if fields is None else urlencode(fields).encode()
            try:
                with opener.open(base + path, data, timeout=15) as response:
                    text = response.read().decode()
                    return response.status, unescape(text) if 'text/html' in response.headers.get('Content-Type', '') else text
            except HTTPError as error:
                return error.code, error.read().decode()
        checks = []
        def check(name, condition):
            assert condition, name
            checks.append(name)
        def cart(action='cart', **values):
            return request('/lib/cart/cart-ajax.php', {'action': action, **values})
        def leads():
            return json.loads((root / 'admin/storage/leads.json').read_text()) if (root / 'admin/storage/leads.json').exists() else []
        try:
            for _ in range(50):
                try:
                    request('/order/')
                    break
                except OSError:
                    time.sleep(.05)
            check('Empty cart loads for an anonymous customer', 'Ваша корзина пока пуста' in request('/order/')[1])
            for slug in catalog['products']:
                page = request(catalog['products'][slug]['route'])[1]
                check(f'Cloned product uses its unique slug: {slug}', f'data-product="{slug}"' in page and f'value="{slug}"' in page)
            check('Add six units', json.loads(cart('add', product='qa-profile', quantity=6)[1]) == {'qa-profile': 6})
            check('Cart session survives another request', json.loads(cart()[1]) == {'qa-profile': 6})
            check('Different customers have independent carts', json.loads(request('/lib/cart/cart-ajax.php', {'action': 'cart'}, other)[1]) == {})
            check('Invalid quantity leaves cart intact', cart('update', product='qa-profile', quantity=-1)[0] == 400 and json.loads(cart()[1]) == {'qa-profile': 6})
            cart('add', product='qa-cap', quantity=2)
            page = request('/order/')[1]
            check('Checkout accepts individuals without mandatory company details', 'частные покупатели и организации' in page and not re.search(r'name="form_company"[^>]*required', page))
            token = re.search(r'name="order_token" value="([a-f0-9]+)"', page)[1]
            fields = {'id_form': 'checkout', 'form_name': 'Тестовый покупатель', 'form_phone': '+7 000 000 00 00', 'form_delivery': 'self', 'policy_read': 1, 'policy_agree': 1, 'order_token': token, 'order_total': '1', 'order_items': 'Подмена клиента'}
            check('Expired checkout token creates no lead or email', request('/lib/feedback/mail-form.php', {**fields, 'order_token': 'wrong'})[1].startswith('error#') and not leads() and not list(root.glob('mail-*.eml')))
            check('Missing consent creates no order', request('/lib/feedback/mail-form.php', {**fields, 'policy_agree': ''})[1].startswith('error#') and not leads())
            # The admin edits the price after the customer has opened checkout.
            prepare = "require 'admin/bootstrap.php';require 'admin/editor.php';$e=load_catalog()['products']['qa-profile'];$v=product_read($e);$v['price']='125';$v['name']='Обновлённый профиль';save_product_content($e,$v);"
            subprocess.run([PHP, '-r', prepare], cwd=root, check=True, capture_output=True)
            response = request('/lib/feedback/mail-form.php', fields)[1]
            check('Checkout succeeds with name and phone only', response.startswith('success#'))
            lead = leads()[0]
            check('Admin lead preserves every item and quantity', [(i['slug'], i['quantity']) for i in lead['items']] == [('qa-profile', 6), ('qa-cap', 2)])
            check('Order uses current saved price and ignores forged totals', lead['fields']['order_total'] == '801 руб.' and lead['items'][0]['name'] == 'Обновлённый профиль' and 'Подмена клиента' not in lead['fields']['order_items'])
            mail = (root / 'mail-0.eml').read_text()
            check('Email contains both quantities, names and total', 'Количество: 6' in mail and 'Количество: 2' in mail and 'Обновлённый профиль' in mail and '801 руб.' in mail)
            check('Session token never appears in email or lead fields', token not in mail and 'order_token' not in lead['fields'])
            check('Sent email status is saved in admin', lead['mail_sent'] is True)
            check('Accepted order empties the cart', json.loads(cart()[1]) == {})
            check('Repeated checkout cannot duplicate an order', request('/lib/feedback/mail-form.php', fields)[1].startswith('error#') and len(leads()) == 1)
            direct = {'id_form': 'catalog', 'product_id': 'qa-profile', 'quantity': 5, 'form_catalog': 'Старое имя', 'form_phone': '+7 000 000 00 00'}
            check('Direct order succeeds without adding to cart', request('/lib/feedback/mail-form.php', direct)[1].startswith('success#'))
            check('Direct order quantity reaches email and admin', leads()[0]['fields']['quantity'] == '5' and 'Количество: 5' in (root / 'mail-1.eml').read_text() and '625 руб.' in (root / 'mail-1.eml').read_text())
            cart('add', product='qa-profile', quantity=1)
            cart('update', product='qa-profile', quantity=7)
            check('Manual cart quantity update persists', json.loads(cart()[1])['qa-profile'] == 7)
            cart('remove', product='qa-profile')
            check('Removing last item produces an empty cart', json.loads(cart()[1]) == {})
            cart('add', product='qa-cap', quantity=1)
            cart('clear')
            check('Clearing cart persists', json.loads(cart()[1]) == {})
            check('Unknown product cannot enter the cart', cart('add', product='missing', quantity=1)[0] == 400)
            (root / 'fail-mail').touch()
            check('Mail failure does not lose a contact request', request('/lib/feedback/mail-form.php', {'id_form': 'callback', 'form_name': 'Тест связи', 'form_phone': '+7 000 000 00 00'})[1].startswith('success#') and leads()[0]['mail_sent'] is False)
            print(json.dumps({'passed': len(checks), 'checks': checks}, ensure_ascii=False, indent=2))
            log.seek(0)
            errors = [line for line in log.read().decode().splitlines() if 'Fatal error' in line or 'Warning:' in line]
            assert not errors, errors
        finally:
            server.terminate()
            server.wait(timeout=10)
            if sys.exc_info()[0]:
                log.seek(0)
                print(log.read().decode(), file=sys.stderr)


if __name__ == '__main__':
    main()
