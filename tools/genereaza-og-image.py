"""Generează assets/og-image.png (1200×630) — imaginea afișată când linkul e distribuit pe Facebook, WhatsApp etc.

Rulare:  python tools/genereaza-og-image.py   (necesită Pillow)
"""

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
W, H = 1200, 630
INK = (15, 23, 42)
MUTED = (91, 100, 117)
BLUE = (0, 168, 255)
BLUE_DEEP = (11, 92, 173)


def font(names, size):
    for name in names:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def main():
    img = Image.new("RGB", (W, H), (255, 255, 255))
    d = ImageDraw.Draw(img)

    # Bandă albastră jos
    d.rectangle([0, H - 16, W, H], fill=BLUE)

    # Logo
    logo = Image.open(ROOT / "assets" / "logo.png").convert("RGBA")
    logo.thumbnail((260, 140))
    img.paste(logo, (70, 56), logo)

    # Marcel (casca) cu ochi și zâmbet, desenat la fel ca pe site
    hat = Image.open(ROOT / "assets" / "marcel.webp").convert("RGBA")
    scale = 1.05
    hat = hat.resize((int(hat.width * scale), int(hat.height * scale)), Image.LANCZOS)
    hx, hy = 700, 150
    img.paste(hat, (hx, hy), hat)

    def p(x, y):
        return hx + x * scale, hy + y * scale

    for cx in (143, 242):
        x, y = p(cx, 118)
        r = 50 * scale
        d.ellipse([x - r, y - r, x + r, y + r], fill="white", outline=(31, 41, 55), width=8)
        x, y = p(cx + 5, 126)
        r = 22 * scale
        d.ellipse([x - r, y - r, x + r, y + r], fill=(31, 41, 55))
        x, y = p(cx + 13, 117)
        r = 7.5 * scale
        d.ellipse([x - r, y - r, x + r, y + r], fill="white")
    x0, y0 = p(163, 186)
    x1, y1 = p(221, 226)
    d.arc([x0, y0, x1, y1], 20, 160, fill=(31, 41, 55), width=10)

    # Text
    title = font(["impact.ttf", "arialbd.ttf"], 88)
    body = font(["arialbd.ttf", "arial.ttf"], 32)
    small = font(["arial.ttf"], 28)
    d.text((70, 230), "AȚI LUCRAT ÎN", font=title, fill=INK)
    d.text((70, 322), "NORVEGIA?", font=title, fill=(186, 12, 47))
    d.text((70, 440), "Recuperați impozitul plătit în plus.", font=body, fill=INK)
    d.text((70, 488), "Gratuit sub 1.000 € · 100 € fix peste 1.000 €", font=small, fill=MUTED)
    d.text((70, 530), "returtax.ro", font=body, fill=BLUE_DEEP)

    out = ROOT / "assets" / "og-image.png"
    img.save(out, optimize=True)
    print("Gata:", out.relative_to(ROOT))


if __name__ == "__main__":
    main()
