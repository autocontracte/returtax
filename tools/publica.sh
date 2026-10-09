#!/usr/bin/env bash
# Returtax — publică site-ul pe VPS.
# Pune o versiune nouă pe CSS/JS (ca browserele să ia fișierele proaspete), reface blogul și urcă totul.
#
# Rulare (din folderul proiectului):  bash tools/publica.sh
set -euo pipefail
cd "$(dirname "$0")/.."

V=$(date +%Y%m%d%H%M%S)
sed -i -E "s#(/(css/style\.css|js/site\.js|js/chat\.js))\?v=[0-9a-z]+#\1?v=$V#g" index.html tools/genereaza-blog.py
python tools/genereaza-blog.py

tar -czf - index.html css js assets blog api admin robots.txt sitemap.xml llms.txt .htaccess \
  | ssh psiholog-vps 'tar -xzf - -C /home/returtax/public_html && chown -R returtax:returtax /home/returtax/public_html'
tar -czf - bin | ssh psiholog-vps 'tar -xzf - -C /home/returtax && chown -R returtax:returtax /home/returtax/bin'

echo "Publicat pe https://returtax.ro (versiunea $V)"
