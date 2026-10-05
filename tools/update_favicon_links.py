#!/usr/bin/env python3
"""Update only favicon tags, including on pages edited on the live server."""
import argparse
from pathlib import Path
import re
import shutil
import tempfile
import os
from urllib.parse import urlsplit

VERSION = '20261005-logo-mark'
ICON_PATHS = {
    '/favicon.ico', '/favicon.svg', '/favicon-16x16.png', '/favicon-32x32.png',
    '/apple-touch-icon.png', '/safari-pinned-tab.svg', '/site.webmanifest',
}
LINK = re.compile(r'<link\b[^>]*>', re.I)
HEAD = re.compile(r'(<head\b[^>]*>)(.*?)(</head\s*>)', re.I | re.S)
HREF = re.compile(r'(\bhref\s*=\s*)([\'"])(.*?)(\2)', re.I | re.S)


def update_head(match):
    content = match[2]

    def update_link(link_match):
        tag = link_match[0]
        href = HREF.search(tag)
        if href and urlsplit(href[3]).path in ICON_PATHS:
            replacement = href[1] + href[2] + urlsplit(href[3]).path + '?v=' + VERSION + href[4]
            tag = tag[:href.start()] + replacement + tag[href.end():]
            if urlsplit(href[3]).path == '/safari-pinned-tab.svg':
                tag = re.sub(r'(\bcolor\s*=\s*)([\'"]).*?\2', r'\1"#303438"', tag)
        return tag

    content = LINK.sub(update_link, content)
    if not re.search(r'href=[\'"]/favicon\.svg\?', content):
        svg = '<link rel="icon" type="image/svg+xml" sizes="any" href="/favicon.svg?v=' + VERSION + '">'
        # Put SVG after legacy sizes, so supporting browsers prefer the vector.
        content += '\n    ' + svg + '\n'
    if not re.search(r'href=[\'"]/favicon\.ico\?', content):
        content += '    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=' + VERSION + '">\n'
    if not re.search(r'href=[\'"]/apple-touch-icon\.png\?', content):
        content += '    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=' + VERSION + '">\n'
    content = re.sub(
        r'(<meta\b[^>]*name=[\'"]msapplication-TileColor[\'"][^>]*content=)([\'"]).*?\2',
        r'\1"#303438"', content, flags=re.I,
    )
    return match[1] + content + match[3]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', type=Path)
    parser.add_argument('--backup-dir', type=Path)
    args = parser.parse_args()
    root = args.root.resolve()
    changed = 0
    for path in sorted(root.rglob('*')):
        if not path.is_file():
            continue
        relative = path.relative_to(root)
        if relative.parts[0] == 'admin':
            if relative.as_posix() != 'admin/index.php':
                continue
        elif path.suffix.lower() not in {'.html', '.prod', '.tag'}:
            continue
        source = path.read_text(encoding='utf-8')
        updated = HEAD.sub(update_head, source)
        if source == updated:
            continue
        if args.backup_dir:
            backup = args.backup_dir / relative
            backup.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, backup)
        stat = path.stat()
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=path.parent, delete=False) as temp:
            temp.write(updated)
            temp_path = Path(temp.name)
        os.chmod(temp_path, stat.st_mode & 0o777)
        if path.read_text(encoding='utf-8') != source:
            temp_path.unlink()
            raise RuntimeError('File changed while updating favicon tags: ' + str(relative))
        os.replace(temp_path, path)
        changed += 1
    print('Updated favicon tags in', changed, 'files.')


if __name__ == '__main__':
    main()
