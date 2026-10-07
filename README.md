# ReturTax.ro

Site pentru recuperarea taxelor din Norvegia pentru românii care au lucrat acolo.
Pagina principală este un chat (în stil ChatGPT) cu **Dexter**, asistentul ReturTax, iar dedesubt sunt explicațiile.

## Structură

- `index.html` — pagina (chat + secțiuni explicative + șablonul SVG al lui Dexter)
- `css/style.css` — stiluri, mobile-first
- `js/chat.js` — logica conversației (deocamdată pe bază de cuvinte-cheie, fără server)
- `assets/` — logo, iconiță, imaginea lui Dexter

## Rulare locală

```bash
python -m http.server 5500
```

apoi deschideți http://localhost:5500
