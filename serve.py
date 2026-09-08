#!/usr/bin/env python3
"""Local static preview of the archived public site (Python 3.9+)."""
import argparse
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
import mimetypes
from pathlib import Path
import urllib.parse as U

ROOT = Path(__file__).resolve().parent
SITE = ROOT / 'site'
MANIFEST = ROOT / 'metadata/manifest.json'

class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(SITE), **kwargs)

    def send_head(self):
        self.archive_type = None
        if MANIFEST.exists() and MANIFEST.stat().st_mtime_ns != self.server.manifest_mtime:
            try:
                self.server.routes = json.loads(MANIFEST.read_text())['files']
                self.server.manifest_mtime = MANIFEST.stat().st_mtime_ns
            except (ValueError, OSError):
                pass
        parsed = U.urlsplit(self.path)
        path = U.quote(U.unquote(parsed.path), safe='/!$&\'()*+,;=:@~-._')
        query = U.urlencode(sorted((k, v) for k, v in U.parse_qsl(parsed.query) if k.startswith('PAGEN_') or k == 'page'))
        key = 'https://dial-td.ru' + path + ('?' + query if query else '')
        record = self.server.routes.get(key)
        if record is None and not query:
            record = self.server.routes.get('https://dial-td.ru' + path)
        if record:
            final = U.urlsplit(record.get('final_url', key))
            final_query = U.urlencode(sorted((k, v) for k, v in U.parse_qsl(final.query) if k.startswith('PAGEN_') or k == 'page'))
            final_target = final.path + ('?' + final_query if final_query else '')
            requested_target = path + ('?' + query if query else '')
            if final.hostname in ('dial-td.ru', 'www.dial-td.ru') and final_target != requested_target:
                self.send_response(301)
                self.send_header('Location', final_target)
                self.send_header('Content-Length', '0')
                self.end_headers()
                return None
            target = ROOT / record['file']
            if target.is_file():
                self.archive_type = record['content_type']
                original = self.path
                self.path = '/' + U.quote(str(target.relative_to(SITE)), safe='/')
                result = super().send_head()
                self.path = original
                return result
        return super().send_head()

    def guess_type(self, path):
        return self.archive_type or super().guess_type(path)

    def end_headers(self):
        # Keep an offline preview from calling the old site's services or trackers.
        self.send_header('Content-Security-Policy', "default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'self'; object-src 'self'")
        self.send_header('X-Robots-Tag', 'noindex, nofollow')
        self.send_header('Cache-Control', 'no-cache')
        super().end_headers()

    def do_POST(self):
        body = json.dumps({'success': False, 'error': 'Это локальная статическая копия. Отправка форм и серверные функции Битрикс не подключены.'}, ensure_ascii=False).encode()
        self.send_response(501)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def list_directory(self, path):
        self.send_error(404, 'Page was not archived')
        return None

    def log_message(self, fmt, *args):
        # Log failures only; a catalog page can request hundreds of files.
        if len(args) > 1 and str(args[1]) not in ('200', '304'):
            super().log_message(fmt, *args)

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8847)
    args = parser.parse_args()
    mimetypes.add_type('text/html; charset=utf-8', '.prod')
    mimetypes.add_type('text/html; charset=utf-8', '.tag')
    mimetypes.add_type('application/javascript', '.js')
    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    server.routes = json.loads(MANIFEST.read_text())['files'] if MANIFEST.exists() else {}
    server.manifest_mtime = MANIFEST.stat().st_mtime_ns if MANIFEST.exists() else 0
    print(f'Local: http://127.0.0.1:{args.port}/', flush=True)
    print(f'Files: {SITE}', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.server_close()
