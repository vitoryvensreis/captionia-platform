#!/usr/bin/env python3
"""Generate static Caption IA social preview + PNG favicons.

Deterministic output for WhatsApp/Open Graph crawlers: no runtime PHP/GD
or external image hosting. All assets live under the Nginx public/ root.
Requires Pillow only at build time.
"""
from __future__ import annotations

import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "public"
ASSETS = PUBLIC / "assets"
ASSETS.mkdir(parents=True, exist_ok=True)

ORANGE = (255, 95, 38)
WHITE = (249, 250, 252)
MUTED = (190, 194, 204)
BACKGROUND = (9, 11, 15)

BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
REGULAR = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def font(size: int, bold: bool = True) -> ImageFont.FreeTypeFont:
    path = BOLD if bold else REGULAR
    if not Path(path).is_file():
        raise RuntimeError(f"Required build font unavailable: {path}")
    return ImageFont.truetype(path, size)


def monogram(size: int) -> Image.Image:
    scale = 4
    s = size * scale
    canvas = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)
    r = int(s * 0.22)
    draw.rounded_rectangle((0, 0, s - 1, s - 1), radius=r, fill=(250, 87, 29, 255))
    # Subtle highlight keeps it polished at social card and favicon resolutions.
    draw.rounded_rectangle(
        (int(.04 * s), int(.04 * s), int(.96 * s), int(.45 * s)),
        radius=int(s * .17), fill=(255, 132, 69, 48),
    )
    glyph = "C"
    f = font(int(s * .64))
    bounds = draw.textbbox((0, 0), glyph, font=f, stroke_width=0)
    gw, gh = bounds[2] - bounds[0], bounds[3] - bounds[1]
    draw.text(
        ((s - gw) / 2 - bounds[0], (s - gh) / 2 - bounds[1] - int(.015 * s)),
        glyph, font=f, fill=(255, 255, 255, 255),
    )
    return canvas.resize((size, size), Image.Resampling.LANCZOS)


def social_image() -> None:
    width, height = 1200, 630
    im = Image.new("RGB", (width, height), BACKGROUND)
    px = im.load()
    for y in range(height):
        for x in range(width):
            # Dark gradient with subdued warm right-side glow.
            d = ((x - 1120) / 630) ** 2 + ((y - 130) / 510) ** 2
            warm = max(0.0, 1.0 - math.sqrt(d))
            px[x, y] = (
                min(255, int(9 + 29 * warm)),
                min(255, int(11 + 7 * warm)),
                min(255, int(15 + 5 * warm)),
            )
    layer = Image.new("RGBA", im.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    # Delicate framing; avoid competing with the preview copy.
    draw.rounded_rectangle((32, 30, 1168, 600), radius=30,
                           outline=(255, 255, 255, 24), width=2)
    for n in range(10):
        x = 1010 + n * 25
        draw.line((x, 50, x - 245, 590), fill=(255, 117, 65, 9), width=2)
    draw.rounded_rectangle((72, 145, 302, 375), radius=48,
                           fill=(255, 118, 63, 12))
    im = Image.alpha_composite(im.convert("RGBA"), layer)
    logo = monogram(202)
    im.alpha_composite(logo, (86, 158))

    draw = ImageDraw.Draw(im)
    draw.text((355, 141), "PARA QUEM EDITA NO PREMIERE",
              font=font(21, False), fill=(255, 145, 99, 255))
    draw.text((349, 192), "Caption IA",
              font=font(93), fill=WHITE + (255,))
    draw.text((353, 320), "Legendas dinâmicas.",
              font=font(38, True), fill=WHITE + (255,))
    draw.text((353, 375), "Edição mais inteligente.",
              font=font(38, False), fill=(215, 222, 230, 255))
    labels = ["LEGENDAS IA", "AUTOEDIT", "AUTOCUT", "SFX"]
    x = 355
    for label in labels:
        f = font(17)
        w = draw.textbbox((0, 0), label, font=f)[2] + 36
        draw.rounded_rectangle((x, 470, x + w, 513), radius=16,
                               fill=(35, 39, 47, 255), outline=(75, 80, 91, 255), width=1)
        draw.text((x + 18, 479), label, font=f, fill=(229, 233, 240, 255))
        x += w + 12
    draw.line((72, 555, 1128, 555), fill=(55, 59, 67, 255), width=2)
    draw.text((76, 572), "caption.escotilhaprodutora.com",
              font=font(20, False), fill=MUTED + (255,))
    draw.text((1018, 573), "CAPTION IA",
              font=font(16), fill=ORANGE + (255,))
    outfile = ASSETS / "og-captionia.png"
    im.convert("RGB").save(outfile, format="PNG", optimize=True)
    assert Image.open(outfile).size == (1200, 630)


def main() -> None:
    social_image()
    monogram(32).save(PUBLIC / "favicon-32.png", optimize=True)
    monogram(180).save(PUBLIC / "apple-touch-icon.png", optimize=True)
    for name in [ASSETS / "og-captionia.png", PUBLIC / "favicon-32.png", PUBLIC / "apple-touch-icon.png"]:
        with Image.open(name) as item:
            item.verify()
        print(f"{name.relative_to(ROOT)}: {name.stat().st_size} bytes")


if __name__ == "__main__":
    main()
