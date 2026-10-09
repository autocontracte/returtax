"""Generează blogul Returtax și sitemap.xml.

Articolele se scriu în content/blog/<slug>.html, cu un antet ca acesta:

    <!--
    title: Titlul articolului
    description: O frază pentru Google (max. ~160 de caractere)
    date: 2026-10-07
    read: 3 min
    -->
    <p>Conținutul articolului…</p>

Paginile statice (termeni, confidențialitate) se scriu la fel, în content/pagini/<slug>.html,
și se publică la /<slug>/.

Rulare (din folderul proiectului):

    python tools/genereaza-blog.py
"""

import html
import json
import re
import sys
from pathlib import Path

SITE = "https://returtax.ro"
ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "content" / "blog"
PAGES = ROOT / "content" / "pagini"
OUT = ROOT / "blog"

PHONE_HREF = "+40752176807"
PHONE_TEXT = "0752 176 807"
EMAIL = "contact@returtax.ro"

MONTHS = ["ianuarie", "februarie", "martie", "aprilie", "mai", "iunie", "iulie",
          "august", "septembrie", "octombrie", "noiembrie", "decembrie"]

NAV = [
    ("/#calculator", "Calculator"),
    ("/#cum-functioneaza", "Cum funcționează"),
    ("/#despre", "Despre noi"),
    ("/#pret", "Preț"),
    ("/#recenzii", "Recenzii"),
    ("/blog/", "Blog"),
    ("/#contact", "Contact"),
]


def ro_date(iso):
    y, m, d = iso.split("-")
    return f"{int(d)} {MONTHS[int(m) - 1]} {y}"


def head(title, description, url, og_type, ld):
    e = html.escape
    return f"""<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{e(title)}</title>
  <meta name="description" content="{e(description)}">
  <link rel="canonical" href="{url}">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <meta name="theme-color" content="#ffffff">
  <meta property="og:type" content="{og_type}">
  <meta property="og:locale" content="ro_RO">
  <meta property="og:site_name" content="Returtax">
  <meta property="og:title" content="{e(title)}">
  <meta property="og:description" content="{e(description)}">
  <meta property="og:url" content="{url}">
  <meta property="og:image" content="{SITE}/assets/og-image.png">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" type="image/png" href="/assets/icon.png">
  <link rel="apple-touch-icon" href="/assets/icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/style.css?v=20261009220207">
  <script type="application/ld+json">
{json.dumps(ld, ensure_ascii=False, indent=2)}
  </script>
</head>
<body>
  <a class="skip-link" href="#continut">Sari la conținut</a>
{header()}
"""


def header():
    desktop = "\n".join(f'        <a href="{h}">{t}</a>' for h, t in NAV)
    mobile = "\n".join(f'      <a href="{h}">{t}</a>' for h, t in NAV)
    return f"""  <header class="topbar">
    <div class="topbar-inner">
      <a href="/" class="brand" aria-label="Returtax — pagina principală">
        <img src="/assets/logo.png" alt="Returtax" width="740" height="380">
      </a>
      <nav class="nav" aria-label="Meniu principal">
{desktop}
      </nav>
      <a class="call" href="tel:{PHONE_HREF}" aria-label="Sunați-ne: {PHONE_TEXT}">
        <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>
        <span>Sunați</span>
      </a>
      <button type="button" class="menu-btn" aria-expanded="false" aria-controls="mobile-menu" aria-label="Deschide meniul">
        <span></span><span></span><span></span>
      </button>
    </div>
    <nav id="mobile-menu" class="mobile-menu" aria-label="Meniu" hidden>
{mobile}
      <a class="btn btn-dark" href="tel:{PHONE_HREF}">Sunați: {PHONE_TEXT}</a>
    </nav>
  </header>"""


def footer():
    links = "\n".join(f'        <a href="{h}">{t}</a>' for h, t in NAV if h != "/#recenzii")
    return f"""
  <footer class="site-footer">
    <div class="footer-inner">
      <div class="footer-brand">
        <img src="/assets/logo.png" alt="Returtax" width="740" height="380" class="footer-logo">
        <p>Recuperarea taxelor din Norvegia pentru românii care au muncit acolo. Banii vin direct de la Skatteetaten, în contul dumneavoastră.</p>
      </div>
      <nav class="footer-nav" aria-label="Linkuri subsol">
{links}
      </nav>
      <p class="footer-contact"><a href="tel:{PHONE_HREF}">{PHONE_TEXT}</a> · <a href="mailto:{EMAIL}">{EMAIL}</a></p>
      <p class="footer-legal"><a href="/termeni/">Termeni și condiții</a> · <a href="/confidentialitate/">Confidențialitate</a></p>
      <p class="footer-company">Returtax este un serviciu oferit de OLARU DRAGOȘ-IULIAN PFA · CUI 52743741 · F2025041319007 · Galați</p>
      <p class="copy"><a href="/admin/" class="copy-link" rel="nofollow">©</a> <span class="year">2026</span> Returtax.ro</p>
    </div>
  </footer>

  <script src="/js/site.js?v=20261009220207"></script>
</body>
</html>
"""


ORG = {"@type": "Organization", "name": "Returtax", "url": SITE + "/", "logo": SITE + "/assets/logo.png"}


def breadcrumbs_ld(items):
    return {
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": i + 1, "name": name, "item": url}
            for i, (name, url) in enumerate(items)
        ],
    }


def read_post(path):
    raw = path.read_text(encoding="utf-8")
    m = re.match(r"\s*<!--(.*?)-->\s*(.*)", raw, re.S)
    if not m:
        raise SystemExit(f"{path.name}: lipsește antetul <!-- title: … -->")
    meta = dict(
        (k.strip(), v.strip())
        for k, v in (line.split(":", 1) for line in m.group(1).strip().splitlines() if ":" in line)
    )
    for key in ("title", "description", "date"):
        if key not in meta:
            raise SystemExit(f"{path.name}: lipsește „{key}” din antet")
    meta["slug"] = path.stem
    meta["body"] = m.group(2).strip()
    meta.setdefault("read", "3 min")
    return meta


def build_post(p):
    url = f"{SITE}/blog/{p['slug']}/"
    ld = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "BlogPosting",
                "headline": p["title"],
                "description": p["description"],
                "datePublished": p["date"],
                "dateModified": p.get("updated", p["date"]),
                "inLanguage": "ro-RO",
                "mainEntityOfPage": url,
                "image": SITE + "/assets/og-image.png",
                "author": ORG,
                "publisher": ORG,
            },
            breadcrumbs_ld([("Acasă", SITE + "/"), ("Blog", SITE + "/blog/"), (p["title"], url)]),
        ],
    }
    e = html.escape
    page = head(f"{p['title']} | Returtax", p["description"], url, "article", ld)
    page += f"""
  <main id="continut">
    <header class="page-hero">
      <p class="breadcrumbs"><a href="/">Acasă</a> › <a href="/blog/">Blog</a></p>
      <h1>{e(p['title'])}</h1>
    </header>
    <article class="article">
      <p class="article-meta">Echipa Returtax · <time datetime="{p['date']}">{ro_date(p['date'])}</time> · {e(p['read'])} de citit</p>
{p['body']}

      <aside class="article-cta">
        <h2>Vreți să știți cât puteți primi?</h2>
        <p>Vorbiți cu Marcel, asistentul nostru, sau sunați-ne. Verificarea este gratuită.</p>
        <a class="btn btn-primary" href="/">Vorbiți cu Marcel</a>
      </aside>
    </article>
  </main>
"""
    page += footer()
    out = OUT / p["slug"] / "index.html"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(page, encoding="utf-8")


def build_index(posts):
    url = f"{SITE}/blog/"
    description = "Ghiduri pe înțelesul tuturor despre recuperarea taxelor din Norvegia: D-nummer, MinID, Skatteetaten și cum să nu fiți păcălit."
    ld = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Blog",
                "name": "Blogul Returtax",
                "url": url,
                "inLanguage": "ro-RO",
                "publisher": ORG,
                "blogPost": [
                    {"@type": "BlogPosting", "headline": p["title"], "url": f"{SITE}/blog/{p['slug']}/", "datePublished": p["date"]}
                    for p in posts
                ],
            },
            breadcrumbs_ld([("Acasă", SITE + "/"), ("Blog", url)]),
        ],
    }
    e = html.escape
    cards = "\n".join(
        f"""        <li class="post-card">
          <a href="/blog/{p['slug']}/">
            <p class="post-meta">{ro_date(p['date'])} · {e(p['read'])}</p>
            <h2 class="h3">{e(p['title'])}</h2>
            <p>{e(p['description'])}</p>
          </a>
        </li>"""
        for p in posts
    )
    page = head("Blog — recuperarea taxelor din Norvegia | Returtax", description, url, "website", ld)
    page += f"""
  <main id="continut">
    <header class="page-hero">
      <p class="breadcrumbs"><a href="/">Acasă</a> › Blog</p>
      <h1>Ghiduri pe înțelesul tuturor</h1>
      <p>Tot ce trebuie să știți despre taxele din Norvegia, explicat simplu, în română.</p>
    </header>
    <section class="section">
      <ul class="posts">
{cards}
      </ul>
    </section>
  </main>
"""
    page += footer()
    (OUT / "index.html").write_text(page, encoding="utf-8")


def build_page(p):
    url = f"{SITE}/{p['slug']}/"
    ld = {
        "@context": "https://schema.org",
        "@graph": [
            {"@type": "WebPage", "name": p["title"], "description": p["description"], "url": url,
             "inLanguage": "ro-RO", "dateModified": p["date"], "publisher": ORG},
            breadcrumbs_ld([("Acasă", SITE + "/"), (p["title"], url)]),
        ],
    }
    e = html.escape
    page = head(f"{p['title']} | Returtax", p["description"], url, "website", ld)
    page += f"""
  <main id="continut">
    <header class="page-hero">
      <p class="breadcrumbs"><a href="/">Acasă</a> › {e(p['title'])}</p>
      <h1>{e(p['title'])}</h1>
    </header>
    <article class="article legal">
      <p class="article-meta">Ultima actualizare: <time datetime="{p['date']}">{ro_date(p['date'])}</time></p>
{p['body']}
    </article>
  </main>
"""
    page += footer()
    out = ROOT / p["slug"] / "index.html"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(page, encoding="utf-8")


def build_sitemap(posts, pages=()):
    urls = [(SITE + "/", posts[0]["date"] if posts else None, "1.0"), (SITE + "/blog/", posts[0]["date"] if posts else None, "0.8")]
    urls += [(f"{SITE}/blog/{p['slug']}/", p.get("updated", p["date"]), "0.7") for p in posts]
    urls += [(f"{SITE}/{p['slug']}/", p["date"], "0.3") for p in pages]
    rows = "\n".join(
        f"  <url><loc>{u}</loc>" + (f"<lastmod>{d}</lastmod>" if d else "") + f"<priority>{pr}</priority></url>"
        for u, d, pr in urls
    )
    (ROOT / "sitemap.xml").write_text(
        f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n{rows}\n</urlset>\n',
        encoding="utf-8",
    )


def main():
    sys.stdout.reconfigure(encoding="utf-8")  # consola Windows nu afișează altfel diacriticele
    posts = sorted((read_post(p) for p in SRC.glob("*.html")), key=lambda p: p["date"], reverse=True)
    for p in posts:
        build_post(p)
    build_index(posts)
    pages = [read_post(p) for p in sorted(PAGES.glob("*.html"))]
    for p in pages:
        build_page(p)
    build_sitemap(posts, pages)
    print(f"Gata: {len(posts)} articole, {len(pages)} pagini, blog/index.html și sitemap.xml.")


if __name__ == "__main__":
    main()
