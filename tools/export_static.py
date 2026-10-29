#!/usr/bin/env python3
"""
Export the CMS (running locally in live mode) to plain HTML for hosts without PHP.

    php -S 127.0.0.1:8096 -t <installed cms> router.php
    python3 tools/export_static.py http://127.0.0.1:8096 <installed cms> static

Pages become folder/index.html, the estimate form posts to /forms/contact.ashx
(the ASP.NET handler kept in static-src/), and assets/ + uploads/ are copied.
"""
import os, re, shutil, sys, urllib.request
from collections import deque
from html.parser import HTMLParser

BASE, CMS, OUT = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
HOST = 'rielbuild.ca'
SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'static-src')


def fetch(path):
    req = urllib.request.Request(BASE + path, headers={'Host': HOST, 'X-Forwarded-Proto': 'https'})
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8')


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.found = set()

    def handle_starttag(self, tag, attrs):
        if tag != 'a':
            return
        href = dict(attrs).get('href') or ''
        href = re.sub(r'^https://' + re.escape(HOST), '', href).split('#')[0].split('?')[0]
        if href.startswith('/') and href.endswith('/') and not re.match(r'^/(admin|forms|install|assets|uploads)/', href):
            self.found.add(href)


def clean(html):
    html = html.replace('action="/forms/contact/"', 'action="/forms/contact.ashx"')
    html = re.sub(r'<input type="hidden" name="_t" value="[^"]*">', '<input type="hidden" name="_ts" value="">', html)
    html = html.replace('https://' + HOST + '/feed/', 'https://' + HOST + '/feed.xml')
    return html


def save(rel, text):
    dest = os.path.join(OUT, rel.lstrip('/'))
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    with open(dest, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(text)


if os.path.isdir(OUT):
    shutil.rmtree(OUT)
os.makedirs(OUT)

seen, queue, pages = {'/'}, deque(['/']), 0
while queue:
    path = queue.popleft()
    status, html = fetch(path)
    if status != 200:
        print('skip', status, path)
        continue
    save(path + 'index.html', clean(html))
    pages += 1
    p = Links()
    p.feed(html)
    for link in sorted(p.found - seen):
        seen.add(link)
        queue.append(link)

status, html = fetch('/this-page-does-not-exist/')
save('404.html', clean(html))

_, robots = fetch('/robots.txt')
robots = ''.join(l + '\n' for l in robots.splitlines() if not re.match(r'Disallow: /(install|app|storage)/', l))
save('robots.txt', robots)
_, sitemap = fetch('/sitemap.xml')
save('sitemap.xml', sitemap)
_, feed = fetch('/feed/')
save('feed.xml', feed.replace('https://' + HOST + '/feed/', 'https://' + HOST + '/feed.xml'))

ignore = shutil.ignore_patterns('.htaccess')
shutil.copytree(os.path.join(CMS, 'assets'), os.path.join(OUT, 'assets'), ignore=ignore)
shutil.copytree(os.path.join(CMS, 'uploads'), os.path.join(OUT, 'uploads'), ignore=ignore)
shutil.copytree(SRC, OUT, dirs_exist_ok=True)

# The static form: send the time spent on the page (simple bot check) and show the thank-you
# note after a no-JavaScript post comes back with ?sent=contact.
js = os.path.join(OUT, 'assets/js/site.js')
with open(js, encoding='utf-8') as fh:
    s = fh.read()
hook = "  $$('form[data-ajax]').forEach(function (form) {\n    var wrap = form.closest('.form-wrap');\n"
assert hook in s, 'site.js form hook moved'
s = s.replace(hook, hook + "    var ts = $('input[name=\"_ts\"]', form), t0 = Date.now();\n"
                         "    form.addEventListener('submit', function () { if (ts) ts.value = String(Date.now() - t0); });\n"
                         "    if (new URLSearchParams(w.location.search).get('sent') === 'contact') wrap.classList.add('is-sent');\n", 1)
with open(js, 'w', encoding='utf-8', newline='\n') as fh:
    fh.write(s)

print(pages, 'pages exported to', OUT)
