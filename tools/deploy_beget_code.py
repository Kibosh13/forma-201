#!/usr/bin/env python3
"""Deploy explicitly selected code files; never upload customer content/templates."""
import argparse
from datetime import datetime
from pathlib import Path
import shutil
import subprocess

REPO = Path(__file__).resolve().parents[1]
SITE = REPO / 'site'
HOST = 'semen08d@semen08d.beget.tech'
REMOTE = '/home/s/semen08d/semen08d.beget.tech/public_html/'

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true', help='Write files; default is a dry run')
    parser.add_argument('--control-path', default='/tmp/alym-audit-%C')
    parser.add_argument('files', nargs='+', help='Exact paths relative to site/')
    args = parser.parse_args()
    selected = []
    for raw in args.files:
        path = Path(raw)
        if path.is_absolute() or '..' in path.parts:
            parser.error('Only relative code paths are allowed')
        if raw.startswith(('admin/storage/', 'admin/data/', 'upload/')) or raw == 'admin/config.local.php':
            parser.error('Customer data/configuration cannot be deployed: ' + raw)
        if path.suffix not in {'.php', '.js', '.css'} and raw not in {'.htaccess', 'robots.txt'}:
            parser.error('HTML, product cards, archives and media are customer content: ' + raw)
        if not (SITE / path).is_file():
            parser.error('File does not exist: ' + raw)
        selected.append(path.as_posix())
        # The imported bundle paths must never depend on disposable cache.
        # Keep their permanent copies current when a bundle is redeployed.
        if path.parts[:3] in {('bitrix', 'cache', 'css'), ('bitrix', 'cache', 'js')}:
            permanent = Path('assets/design') / path.relative_to('bitrix/cache')
            if args.apply:
                (SITE / permanent).parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(SITE / path, SITE / permanent)
            if (SITE / permanent).is_file():
                selected.append(permanent.as_posix())
    selected = list(dict.fromkeys(selected))
    stamp = datetime.now().strftime('%Y%m%d-%H%M%S')
    command = ['rsync', '-avzR', '--backup', '--backup-dir=' + REMOTE + 'admin/storage/backups/code-' + stamp,
               '-e', 'ssh -o ControlPath=' + args.control_path]
    if not args.apply:
        command.append('--dry-run')
    subprocess.run(command + selected + [HOST + ':' + REMOTE], cwd=SITE, check=True)

if __name__ == '__main__':
    main()
