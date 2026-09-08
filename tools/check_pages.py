#!/usr/bin/env python3
"""Check all exported HTML pages and their local asset/navigation dependencies."""
from html.parser import HTMLParser
import html
import json
from pathlib import Path
import re
import urllib.parse as U

ROOT=Path(__file__).resolve().parent.parent
BUILD=ROOT/'.pages-build'
PREFIX='/forma-201/'
CSS_URL=re.compile(r'url\(\s*[\'"]?([^\)\'"\s]+)[\'"]?\s*\)',re.I)
refs=set();problems=[];count=0
def ref(raw,parent):
    if not raw or raw.startswith(('#','data:','blob:','javascript:','mailto:','tel:','http:','https:','//')):return
    p=U.urlsplit(U.urljoin('https://local.invalid'+parent,html.unescape(raw)))
    path=U.unquote(p.path)
    if not path.startswith(PREFIX):problems.append({'page':parent,'path':path,'error':'path outside project mount'});return
    refs.add((path,parent))
class Check(HTMLParser):
    def __init__(self,path):super().__init__();self.path=path;self.noindex=False
    def handle_starttag(self,t,attrs):
        a=dict(attrs)
        if t=='meta' and a.get('name')=='robots' and 'noindex' in a.get('content',''):self.noindex=True
        for k in ('src','poster','data-src','data-original','data-background','background'):
            if a.get(k):ref(a[k],self.path)
        if t in ('a','link') and a.get('href'):ref(a['href'],self.path)
        for k in ('srcset','data-srcset'):
            if a.get(k):
                for part in a[k].split(','):
                    if part.strip():ref(part.strip().split()[0],self.path)
        for m in CSS_URL.finditer(a.get('style','')):ref(m[1],self.path)
for file in BUILD.rglob('*'):
    if not file.is_file():continue
    path=PREFIX+str(file.relative_to(BUILD))
    if file.suffix=='.html':
        count+=1;p=Check(path);p.feed(file.read_text())
        if not p.noindex:problems.append({'page':path,'error':'missing noindex'})
    elif file.suffix=='.css':
        for m in CSS_URL.finditer(file.read_text()):ref(m[1],path)
    elif file.suffix=='.webmanifest':
        for icon in json.loads(file.read_text()).get('icons',[]):ref(icon['src'],path)
for path,parent in refs:
    file=BUILD/path[len(PREFIX):]
    if not file.is_file() and not (file/'index.html').is_file():problems.append({'page':parent,'path':path,'error':'missing local file'})
report={'html_pages':count,'checked_references':len(refs),'errors':problems}
(ROOT/'metadata/publication-check.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({'html_pages':count,'checked_references':len(refs),'errors':len(problems),'examples':problems[:20]},ensure_ascii=False,indent=2))
raise SystemExit(bool(problems))
