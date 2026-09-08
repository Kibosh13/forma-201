#!/usr/bin/env python3
"""Export the archived site for a GitHub Pages project path. Python standard library only."""
import argparse
import hashlib
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import shutil
import urllib.parse as U

ROOT=Path(__file__).resolve().parent.parent
SOURCE=ROOT/'site'
BASE='https://dial-td.ru'
NOINDEX='<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">'
TRACKERS=('mc.yandex.ru','googletagmanager.com','bitrix.info','code.jivo.ru','api-maps.yandex.ru')
CSS_URL=re.compile(r'url\(\s*[\'"]?([^\)\'"\s]+)[\'"]?\s*\)',re.I)
QUOTED=re.compile(r'''(["'])((?:(?:https?:)?//|/|\.\.?/)[^\s<>"']*)\1''')
SCRIPT=re.compile(r'<script\b([^>]*)>(.*?)</script\s*>',re.I|re.S)
NOINDEX_CSP="default-src 'self' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'none'; frame-src 'none'; object-src 'self'; form-action 'none'; base-uri 'self'"

def normalize(raw,parent):
    p=U.urlsplit(U.urljoin(parent,html.unescape(raw).replace('\\/','/')))
    path=U.quote(U.unquote(p.path or '/'),safe='/!$&\'()*+,;=:@~-._')
    query=U.urlencode(sorted((k,v) for k,v in U.parse_qsl(p.query) if k.startswith('PAGEN_') or k=='page'))
    return p,path,query

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--base-path',default='/forma-201/')
    parser.add_argument('--output',type=Path,default=ROOT/'.pages-build')
    args=parser.parse_args()
    prefix='/'+args.base_path.strip('/')+'/'
    dest=args.output.resolve();dest.mkdir(parents=True,exist_ok=True)
    manifest=json.loads((ROOT/'metadata/manifest.json').read_text())
    files=manifest['files'];failures=set(manifest['failures'])
    routes={}; output_paths={}
    for url,r in files.items():
        p=U.urlsplit(url)
        if 'html' not in r['content_type']:continue
        if p.query:
            route='_pages/'+hashlib.sha256(url.encode()).hexdigest()[:20]+'/'
        else:
            route=U.unquote(p.path).lstrip('/')
            if route and not route.endswith('/'):
                route=route+'/' if not route.endswith('.html') else route
        routes[url]=prefix+U.quote(route,safe='/@')
        output_paths[url]=route+('index.html' if not route or route.endswith('/') else '')

    def rewrite(raw,parent,kind='asset'):
        if not raw or raw.startswith(('#','javascript:','data:','blob:','mailto:','tel:')):return raw
        if raw.startswith(prefix):return raw
        try:p,path,query=normalize(raw,parent)
        except ValueError:return raw
        if p.scheme not in ('http','https'):return raw
        if p.hostname not in ('dial-td.ru','www.dial-td.ru'):
            absolute=U.urlunsplit((p.scheme,p.netloc,p.path,p.query,''))
            rec=files.get(absolute)
            if rec:return prefix+U.quote(str(Path(rec['file']).relative_to('site')),safe='/@')
            return raw
        key=BASE+path+('?' + query if query else '')
        rec=files.get(key)
        if rec and 'html' in rec['content_type']:
            final=rec.get('final_url',key)
            return routes.get(final,routes[key])+('#'+p.fragment if p.fragment else '')
        if key in routes:return routes[key]+('#'+p.fragment if p.fragment else '')
        basekey=BASE+path
        if basekey in routes:return routes[basekey]+('#'+p.fragment if p.fragment else '')
        if kind=='page' and not (SOURCE/U.unquote(path).lstrip('/')).is_file():
            return prefix+'demo-unavailable/'
        return prefix+path.lstrip('/')+('?' + p.query if p.query else '')+('#'+p.fragment if p.fragment else '')

    def rewrite_css(text,parent):
        def replace(m):
            try:p,path,_=normalize(m[1],parent)
            except ValueError:return m[0]
            if BASE+path in failures:return 'none'
            return 'url("'+rewrite(m[1],parent)+'")'
        return CSS_URL.sub(replace,text)

    def rewrite_code(text,parent):
        # Only modify complete known URLs; leave regular expressions and JS expressions intact.
        def replace(m):
            raw=m[2];escaped='\\/' in raw;normal=raw.replace('\\/','/')
            try:p,path,query=normalize(normal,parent)
            except ValueError:return m[0]
            key=BASE+path+('?' + query if query else '')
            known=(key in files or BASE+path in files or (SOURCE/U.unquote(path).lstrip('/')).is_file())
            if not known and not normal.startswith(('/upload/','/local/','/bitrix/','/_external/')):return m[0]
            value=rewrite(normal,parent)
            if escaped:value=value.replace('/','\\/')
            return m[1]+value+m[1]
        return QUOTED.sub(replace,text)

    class ExportHTML(HTMLParser):
        def __init__(self,parent):
            super().__init__(convert_charrefs=False);self.parent=parent;self.parts=[];self.script=False
        def handle_decl(self,d):self.parts.append('<!'+d+'>')
        def handle_comment(self,d):self.parts.append('<!--'+d+'-->')
        def handle_entityref(self,n):self.parts.append('&'+n+';')
        def handle_charref(self,n):self.parts.append('&#'+n+';')
        def handle_starttag(self,t,attrs):
            a=dict(attrs)
            if t=='meta' and (a.get('name','').lower() in ('robots','googlebot','yandex') or a.get('http-equiv','').lower()=='content-security-policy'):return
            if t=='link' and a.get('rel')=='canonical':return
            if t=='img' and any(x in a.get('src','') for x in TRACKERS):return
            out=[]
            for k,v in attrs:
                if v is None:out.append(k);continue
                if k.startswith('on') and 'smartFilter' in v:continue
                if k=='action' and t=='form':v='#'
                elif k in ('href','src','poster','data-src','data-original','data-background','data-lazy','background','data-url'):
                    v=rewrite(v,self.parent,'page' if t=='a' and k=='href' else 'asset')
                elif k in ('srcset','data-srcset'):
                    v=', '.join(' '.join([rewrite(z[0],self.parent),*z[1:]]) for part in v.split(',') if (z:=part.strip().split()))
                elif k=='style':v=rewrite_css(v,self.parent)
                out.append(k+'="'+html.escape(v,quote=True)+'"')
            if t=='a' and a.get('href','').startswith(('http:','https:','//')) and not 'dial-td.ru' in a['href']:
                out.append('rel="nofollow noopener noreferrer"')
            self.parts.append('<'+t+(' '+' '.join(out) if out else '')+'>')
            if t=='script':self.script=True
            if t=='head':
                self.parts.append('\n'+NOINDEX+'\n<meta http-equiv="Content-Security-Policy" content="'+html.escape(NOINDEX_CSP,quote=True)+'">\n')
                self.parts.append('<link rel="stylesheet" href="'+prefix+'_demo/demo.css"><script src="'+prefix+'_demo/demo.js"></script>\n')
            if t=='body':self.parts.append('<aside class="demo-banner">Демонстрация сайта · заявки, поиск и серверные фильтры отключены</aside>')
        def handle_startendtag(self,t,a):self.handle_starttag(t,a)
        def handle_endtag(self,t):
            self.parts.append('</'+t+'>')
            if t=='script':self.script=False
        def handle_data(self,d):self.parts.append(rewrite_code(d,self.parent) if self.script else d)

    written=set()
    def write(rel,data):
        path=dest/rel;path.parent.mkdir(parents=True,exist_ok=True);written.add(path)
        if path.is_file() and path.read_bytes()==data:return
        path.write_bytes(data)

    for url,r in files.items():
        path=ROOT/r['file'];data=path.read_bytes()
        rel=str(path.relative_to(SOURCE))
        if 'html' in r['content_type']:
            text=data.decode('utf-8')
            text=SCRIPT.sub(lambda m:'' if any(x in (m[1]+m[2]) for x in TRACKERS) else m[0],text)
            text=re.sub(r'<iframe\b[^>]*>.*?</iframe\s*>','<div class="demo-embed">Внешнее видео или карта недоступны в демонстрации.</div>',text,flags=re.I|re.S)
            text=re.sub(r'<video\b[^>]*>.*?</video\s*>',lambda m:'' if any(u in m[0] for u in ('rio8p3pulpoygfnuwa96cp4ro60v5mv0.mp4',)) else m[0],text,flags=re.I|re.S)
            exporter=ExportHTML(url);exporter.feed(text)
            data=''.join(exporter.parts).encode('utf-8');rel=output_paths[url]
        elif 'css' in r['content_type']:
            data=rewrite_css(data.decode('utf-8'),url).encode()
        elif 'javascript' in r['content_type']:
            data=rewrite_code(data.decode('utf-8'),url).encode()
        elif U.urlsplit(url).path.endswith('.webmanifest'):
            doc=json.loads(data)
            for icon in doc.get('icons',[]):icon['src']=rewrite(icon['src'],url)
            data=json.dumps(doc,ensure_ascii=False,indent=2).encode()
        elif U.urlsplit(url).path.endswith('.xml') or rel=='robots.txt':continue
        write(rel,data)

    write('.nojekyll',b'')
    # A project-level robots.txt does not govern the github.io origin. HTML noindex is authoritative.
    write('robots.txt',b'User-agent: *\nAllow: /\n# Every HTML page carries noindex, nofollow, noimageindex.\n')
    for name in ('demo.js','demo.css'):write('_demo/'+name,(ROOT/'tools/demo'/name).read_bytes())
    page='<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'+NOINDEX+'<title>Статическая демонстрация</title></head><body><main style="max-width:700px;margin:10vh auto;padding:24px;font:18px/1.6 system-ui"><h1>Статическая демонстрация</h1><p>Этот адрес или серверная функция недоступны в демонстрации. Каталог, карточки и фотографии доступны для просмотра.</p><a href="'+prefix+'">На главную</a></main></body></html>'
    write('demo-unavailable/index.html',page.encode())
    write('404.html',page.encode())
    for p in dest.rglob('*'):
        if p.is_file() and p not in written:p.unlink()
    print(json.dumps({'output':str(dest),'files':len(written),'html_pages':len(routes)+2,'base_path':prefix},ensure_ascii=False))

if __name__=='__main__':main()
