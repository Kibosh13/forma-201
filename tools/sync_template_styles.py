#!/usr/bin/env python3
"""Sync the shared template overrides into the archived public CSS bundles."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'site/local/templates/gvozdevsoft_zavodgs_s1/template_styles.css'
SECTION = re.compile(
    r'(/\* Start:/local/templates/gvozdevsoft_zavodgs_s1/template_styles\.css\?[^*]*\*/)'
    r'.*?(/\* End \*/)', re.S,
)

if __name__ == '__main__':
    source = SOURCE.read_text()
    bundles = sorted((ROOT / 'site/bitrix/cache/css').rglob('template_*.css'))
    updates = []
    for path in bundles:
        original = path.read_text()
        updated, count = SECTION.subn(lambda match: match[1] + '\n' + source + '\n' + match[2], original)
        if count != 1:
            raise SystemExit(f'Expected one shared template section in {path}; found {count}')
        if updated != original:
            updates.append((path, updated))
    for path, updated in updates:
        path.write_text(updated)
    print(f'Synchronized {len(updates)} of {len(bundles)} shared CSS bundles.')
