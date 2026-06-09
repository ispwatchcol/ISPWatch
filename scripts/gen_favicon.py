"""Genera favicon.ico + PNGs de respaldo a partir del app-icon de ISP Watch.

El ícono es geométrico (fondo redondeado oscuro + una "W" de líneas y nodos con
degradado azul->cyan), así que se dibuja directamente con Pillow en alta
resolución y se reescala. No requiere rasterizador de SVG.
"""
from PIL import Image, ImageDraw

# --- geometria en espacio del mark (igual que el SVG) ---
# transform del app-icon: translate(180,180) scale(0.86) translate(-200,-280)
def TX(x): return 180 + 0.86 * (x - 200)
def TY(y): return 180 + 0.86 * (y - 280)

MARK = {
    "tl": (95, 232), "v1": (148, 292), "tm": (207, 232),
    "mm": (207, 290), "bot": (252, 332), "tr": (305, 228),
    "bl": (150, 332), "e1": (256, 286), "e2": (196, 306),
}
P = {k: (TX(x), TY(y)) for k, (x, y) in MARK.items()}      # espacio 360
STROKE = 24 * 0.86                                          # ancho de línea
RNODE = 19 * 0.86                                           # radio de nodo
NODES = ["tl", "tm", "tr", "mm", "bl"]
POLYS = [["tl", "v1", "tm"], ["mm", "bot", "tr"]]
LINES = [("tm", "e1"), ("bl", "e2")]
TRI = ["mm", "bot", "tr"]
BG = (15, 30, 53, 255)          # #0F1E35
GSTOPS = [(0.0, (12, 93, 226)), (0.5, (3, 155, 240)), (1.0, (10, 201, 238))]
GX0, GX1 = TX(70), TX(318)      # rango del degradado (userSpaceOnUse)

def lerp(a, b, t): return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))

def grad_color(x360):
    t = (x360 - GX0) / (GX1 - GX0)
    t = max(0.0, min(1.0, t))
    for i in range(len(GSTOPS) - 1):
        t0, c0 = GSTOPS[i]
        t1, c1 = GSTOPS[i + 1]
        if t <= t1:
            return lerp(c0, c1, (t - t0) / (t1 - t0))
    return GSTOPS[-1][1]

def seg(draw, p1, p2, w, fill):
    """Línea con extremos redondeados (round cap/join)."""
    draw.line([p1, p2], fill=fill, width=int(round(w)))
    r = w / 2.0
    for (x, y) in (p1, p2):
        draw.ellipse([x - r, y - r, x + r, y + r], fill=fill)

def render(size):
    SS = 4                       # supersampling
    S = size * SS
    sc = S / 360.0
    def pt(k): return (P[k][0] * sc, P[k][1] * sc)

    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    # fondo redondeado
    d.rounded_rectangle([0, 0, S - 1, S - 1], radius=80 * sc, fill=BG)

    # triángulo cyan tenue (debajo de la W)
    d.polygon([pt(k) for k in TRI], fill=(42, 207, 246, 140))

    # máscara de la W (líneas + nodos) -> se rellena con degradado
    mask = Image.new("L", (S, S), 0)
    md = ImageDraw.Draw(mask)
    w = STROKE * sc
    for poly in POLYS:
        for a, b in zip(poly, poly[1:]):
            seg(md, pt(a), pt(b), w, 255)
    for a, b in LINES:
        seg(md, pt(a), pt(b), w, 255)
    rn = RNODE * sc
    for k in NODES:
        x, y = pt(k)
        md.ellipse([x - rn, y - rn, x + rn, y + rn], fill=255)

    # imagen de degradado horizontal
    grad = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    gpx = grad.load()
    cols = [grad_color((px / sc)) for px in range(S)]
    for px in range(S):
        c = cols[px] + (255,)
        for py in range(S):
            gpx[px, py] = c
    img.paste(grad, (0, 0), mask)

    return img.resize((size, size), Image.LANCZOS)

base = render(256)
out = "public"
base.save(f"{out}/favicon.ico", format="ICO",
          sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
render(32).save(f"{out}/favicon-32.png")
render(180).save(f"{out}/apple-touch-icon.png")
render(512).save(f"{out}/favicon-512.png")
print("favicon.ico + PNGs generados")
