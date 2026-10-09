# Returtax.ro

Site pentru recuperarea taxelor din Norvegia pentru românii care au lucrat acolo — https://returtax.ro

Pagina principală începe cu un chat în stil ChatGPT cu **Marcel**, asistentul Returtax (o cască de protecție cu față animată), iar dedesubt este site-ul propriu-zis: calculator, cum funcționează, despre noi, preț, recenzii, întrebări frecvente, blog și contact. Pe toate paginile există butonul de WhatsApp.

## Structură

- `index.html` — pagina principală (chat + secțiuni + date structurate pentru Google)
- `css/style.css` — stiluri, mobile-first, efect liquid glass
- `js/chat.js` — conversația cu Marcel (deocamdată pe bază de cuvinte-cheie, fără AI)
- `js/site.js` — meniu, calculator, formulare, pop-up „Sunați-ne”, butonul WhatsApp
- `api/contact.php` — primește cererile (formular, calculator, chat), trimite e-mail și salvează în `/home/returtax/leads/leads.csv`
- `content/blog/` — sursele articolelor (nu se publică)
- `blog/`, `sitemap.xml` — generate din `content/blog/`
- `tools/` — generatorul de blog și de imaginea pentru rețele sociale (nu se publică)
- `robots.txt`, `llms.txt`, `.htaccess` — SEO, GEO și configurare Apache

## Articol nou pe blog

Creați `content/blog/<slug>.html` (vedeți un articol existent pentru antet), apoi:

```bash
python tools/genereaza-blog.py
```

## Rulare locală

```bash
python -m http.server 5500
```

apoi deschideți http://localhost:5500 (formularele au nevoie de PHP, deci merg doar pe server).

## Publicare pe VPS

Site-ul rulează pe VPS-ul `psiholog-vps` (31.97.54.70), în Virtualmin, ca site separat `returtax.ro`, în `/home/returtax/public_html`. DNS-ul este în Cloudflare.

```bash
tar -czf - index.html css js assets blog api robots.txt sitemap.xml llms.txt .htaccess | ssh psiholog-vps 'tar -xzf - -C /home/returtax/public_html && chown -R returtax:returtax /home/returtax/public_html'
```

## Chei și secrete

Nicio cheie nu se pune în cod sau pe GitHub. Cheia API Claude (pentru viitoarea integrare AI a lui Marcel) va sta doar pe server, în `/home/returtax/secrets/anthropic.key`, în afara folderului public.
