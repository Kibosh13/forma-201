#!/usr/bin/env python3
"""Synchronize catalog labels and section order in the saved HTML pages."""
import argparse
from collections import Counter
import hashlib
from html import escape, unescape
from html.parser import HTMLParser
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent
SECTIONS = {
    '/catalog/alyuminievyy-plintus/': 'Алюминиевые напольные плинтусы, микроплинтусы',
    '/catalog/furnitura-dlya-alyuminievogo-plintusa/': 'Аксессуары и фурнитура для алюминиевого плинтуса',
    '/catalog/alyuminievye-potolochnye-plintusy/': 'Алюминиевые потолочные плинтусы скрытого монтажа',
    '/catalog/zazhimnoy-profil-dlya-tselnosteklyannykh-ograzhdeniy/': 'Зажимной алюминиевый профиль для стекла',
    '/catalog/alyuminievye-perila/': 'Алюминиевые перила и поручни',
    '/catalog/furnitura-dlya-alyuminievykh-peril/': 'Фурнитура для алюминиевых перил и поручней',
}
OLD_LABELS = {
    'Алюминиевые напольные плинтусы': SECTIONS['/catalog/alyuminievyy-plintus/'],
    'Алюминиевые потолочные плинтусы': SECTIONS['/catalog/alyuminievye-potolochnye-plintusy/'],
    'Фурнитура для алюминиевых перил': SECTIONS['/catalog/furnitura-dlya-alyuminievykh-peril/'],
}
VOID = set('area base br col embed hr img input link meta param source track wbr'.split())


class Element:
    def __init__(self, tag, attrs, start, opening_end, parent):
        self.tag, self.attrs = tag, dict(attrs)
        self.start, self.opening_end = start, opening_end
        self.closing_start = self.end = opening_end
        self.parent, self.children = parent, []

    def descendants(self):
        for child in self.children:
            yield child
            yield from child.descendants()


class Document(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=False)
        self.source, self.elements, self.stack = source, [], []
        self.lines = [0] + [m.end() for m in re.finditer('\n', source)]
        self.feed(source)

    def position(self):
        line, column = self.getpos()
        return self.lines[line - 1] + column

    def handle_starttag(self, tag, attrs):
        start = self.position()
        parent = self.stack[-1] if self.stack else None
        node = Element(tag, attrs, start, start + len(self.get_starttag_text()), parent)
        self.elements.append(node)
        if parent:
            parent.children.append(node)
        if tag not in VOID:
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in VOID:
            self.stack.pop()

    def handle_endtag(self, tag):
        for i in range(len(self.stack) - 1, -1, -1):
            if self.stack[i].tag == tag:
                node = self.stack[i]
                node.closing_start = self.position()
                node.end = self.source.index('>', node.closing_start) + 1
                del self.stack[i:]
                break

    def raw(self, node):
        return self.source[node.start:node.end]


def apply_edits(source, edits):
    previous = len(source)
    for start, end, value in sorted(edits, reverse=True):
        assert end <= previous, 'Overlapping HTML edits'
        source = source[:start] + value + source[end:]
        previous = start
    return source


def rename_labels(source):
    document = Document(source)
    edits = []
    for node in document.elements:
        label = SECTIONS.get(node.attrs.get('href')) if node.tag in ('a', 'span') else None
        text = source[node.opening_end:node.closing_start]
        if label and not node.children and text.strip():
            edits.append((node.opening_end, node.closing_start,
                          re.sub(r'\S(?:[\s\S]*\S)?', escape(label), text, count=1)))
        elif node.tag in ('h1', 'span') and not node.children and unescape(text.strip()) in OLD_LABELS:
            label = OLD_LABELS[unescape(text.strip())]
            edits.append((node.opening_end, node.closing_start,
                          re.sub(r'\S(?:[\s\S]*\S)?', escape(label), text, count=1)))
        elif node.tag == 'img':
            opening = source[node.start:node.opening_end]
            updated = re.sub(r'\b(alt|title)="([^"]*)"',
                             lambda m: m[1] + '="' + escape(OLD_LABELS.get(unescape(m[2]), unescape(m[2])), quote=True) + '"', opening)
            if updated != opening:
                edits.append((node.start, node.opening_end, updated))
    return apply_edits(source, edits)


def first_link(node):
    return next((child.attrs['href'] for child in node.descendants()
                 if child.tag in ('a', 'span') and 'href' in child.attrs), None)


def groups(document):
    for node in document.elements:
        if node.tag == 'ul':
            children = [child for child in node.children if child.tag == 'li']
        elif node.tag == 'div' and 'row' in node.attrs.get('class', '').split():
            children = list(node.children)
        else:
            continue
        links = [first_link(child) for child in children]
        footer = node.parent and 'footer__menu-nav' in node.parent.attrs.get('class', '').split()
        if set(SECTIONS).issubset(links) or (footer and list(SECTIONS)[0] in links):
            assert len(links) == len(set(links)), 'Duplicate catalog sections'
            yield node, children, links


def protected(document):
    classes = {'catalog-detail__text', 'catalog-detail__preview', 'catalog-detail__params'}
    return [document.raw(n) for n in document.elements if classes.intersection(n.attrs.get('class', '').split())]


def synchronize(source):
    original = Document(source)
    renamed = rename_labels(source)
    document = Document(renamed)
    edits = []
    count = 0
    for _, children, links in groups(document):
        ordered = [children[links.index(url)] for url in SECTIONS if url in links]
        ordered += [child for child, url in zip(children, links) if url not in SECTIONS]
        for slot, content in zip(children, ordered):
            if slot is not content:
                edits.append((slot.start, slot.end, document.raw(content)))
        count += 1
    result = apply_edits(renamed, edits)
    updated = Document(result)
    assert protected(original) == protected(updated), 'Product copy changed'
    for attr in ('href', 'src', 'id'):
        assert Counter(n.attrs.get(attr) for n in original.elements) == Counter(n.attrs.get(attr) for n in updated.elements), attr
    before_groups, after_groups = list(groups(original)), list(groups(updated))
    assert len(before_groups) == len(after_groups)
    for (_, _, before), (_, _, after) in zip(before_groups, after_groups):
        priority = [url for url in SECTIONS if url in before]
        assert after[:len(priority)] == priority
        assert after[len(priority):] == [url for url in before if url not in SECTIONS]
    return result, count


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Report whether saved HTML is already synchronized')
    args = parser.parse_args()
    manifest_path = ROOT / 'metadata/manifest.json'
    manifest = json.loads(manifest_path.read_text())
    changed, total_groups = [], 0
    for record in manifest['files'].values():
        if 'html' not in record['content_type']:
            continue
        path = ROOT / record['file']
        source = path.read_text()
        updated, count = synchronize(source)
        total_groups += count
        if updated != source:
            changed.append(record['file'])
            if not args.check:
                data = updated.encode()
                path.write_bytes(data)
                record['local_sha256'] = hashlib.sha256(data).hexdigest()
                record['local_bytes'] = len(data)
    if changed and not args.check:
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'changed_pages': len(changed), 'catalog_groups': total_groups, 'check': args.check}, ensure_ascii=False))
    if args.check and changed:
        raise SystemExit(1)


if __name__ == '__main__':
    main()
