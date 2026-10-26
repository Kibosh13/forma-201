#!/usr/bin/env python3
"""Replace legal-owner references in four SEO blocks, preserving all other bytes."""
import argparse
from datetime import datetime
import hashlib
from html import escape, unescape
import json
import os
from pathlib import Path
import re
import stat
import tempfile

PAGES = {
    'kompaniya/index.html': (
        'О компании АлюмПрофи',
        'Информация о компании АлюмПрофи. Каталог алюминиевых профилей и комплектующих, контакты и реквизиты для оформления заказов.',
    ),
    'kompaniya/rekvizity/index.html': (
        'Реквизиты АлюмПрофи',
        'Реквизиты АлюмПрофи: банковские реквизиты, контактная информация и данные для оформления заказов на алюминиевый профиль.',
    ),
    'kontakty/index.html': (
        'Контакты АлюмПрофи',
        'Контакты АлюмПрофи: г. Люберцы, ул. Преображенская, д. 13. Телефон: 8 (495) 664-30-04. Пн–Пт: 09:00–19:00.',
    ),
    'kontakty/politika-konfidentsialnosti/index.html': (
        'Политика конфиденциальности АлюмПрофи',
        'Политика конфиденциальности АлюмПрофи. Информация об обработке персональных данных пользователей сайта.',
    ),
}
HEAD = re.compile(r'(<head\b[^>]*>)(.*?)(</head\s*>)', re.I | re.S)
TAGS = re.compile(r'<title\b[^>]*>.*?</title\s*>|<meta\b[^>]*>', re.I | re.S)
ATTRS = re.compile(r'([\w:.-]+)\s*=\s*(["\'])(.*?)\2', re.S)
META_FIELDS = {'description': 1, 'og:title': 0, 'og:description': 1, 'twitter:title': 0, 'twitter:description': 1}


def transform(source, title, description, mask=False):
    head = HEAD.search(source)
    if not head:
        raise RuntimeError('Missing head')
    changes = {}
    counts = {}

    def tag(match):
        original = match[0]
        if original.lower().startswith('<title'):
            before = re.match(r'(<title\b[^>]*>)(.*?)(</title\s*>)', original, re.I | re.S)
            counts['title'] = counts.get('title', 0) + 1
            if not mask and unescape(before[2]) != title:
                changes['title'] = {'before': unescape(before[2]), 'after': title}
            return before[1] + ('' if mask else escape(title, quote=False)) + before[3]
        attrs = {m[1].lower(): m for m in ATTRS.finditer(original)}
        key = attrs.get('name', attrs.get('property'))
        field = key[3].lower() if key else ''
        if field not in META_FIELDS or 'content' not in attrs:
            return original
        counts[field] = counts.get(field, 0) + 1
        content = attrs['content']
        value = (title, description)[META_FIELDS[field]]
        if not mask and unescape(content[3]) != value:
            changes[field] = {'before': unescape(content[3]), 'after': value}
        return original[:content.start(3)] + ('' if mask else escape(value, quote=True)) + original[content.end(3):]

    replaced = TAGS.sub(tag, head[2])
    for field in ('title', 'description', 'og:title', 'og:description'):
        if counts.get(field) != 1:
            raise RuntimeError(f'Expected exactly one {field}, found {counts.get(field, 0)}')
    return source[:head.start(2)] + replaced + source[head.end(2):], changes


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1] / 'site')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--backup-root', type=Path)
    args = parser.parse_args()
    root = args.root.resolve()
    backup = args.backup_root or root / 'admin/storage/backups' / ('seo-brand-' + datetime.now().strftime('%Y%m%d-%H%M%S'))
    plans = []
    for relative, (title, description) in PAGES.items():
        path = (root / relative).resolve()
        if root not in path.parents:
            raise RuntimeError('Page is outside site root')
        before = path.read_bytes()
        source = before.decode('utf-8')
        updated, changes = transform(source, title, description)
        if transform(source, '', '', mask=True)[0] != transform(updated, '', '', mask=True)[0]:
            raise RuntimeError('A non-SEO byte would change: ' + relative)
        if changes:
            plans.append((relative, path, before, updated.encode('utf-8'), changes))
    if args.apply:
        for relative, path, before, after, changes in plans:
            if path.read_bytes() != before:
                raise RuntimeError('Page changed since it was read: ' + relative)
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(before)
            saved.chmod(0o600)
            mode = stat.S_IMODE(path.stat().st_mode)
            fd, temporary = tempfile.mkstemp(prefix='.seo-brand-', dir=path.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(after)
                os.chmod(temporary, mode)
                os.replace(temporary, path)
            finally:
                if os.path.exists(temporary):
                    os.unlink(temporary)
    print(json.dumps({'applied': args.apply, 'changed_pages': len(plans), 'backup': str(backup) if args.apply and plans else None,
        'pages': [{'path': relative, 'changes': changes, 'before_sha256': hashlib.sha256(before).hexdigest(), 'after_sha256': hashlib.sha256(after).hexdigest()} for relative, path, before, after, changes in plans]}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
