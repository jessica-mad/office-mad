"""Reference prototype for docs/oficina-v2.md: composes the compact vertical office (18x28)
with the pixel-agents asset kit, recoloured to the MAD palette.
Usage: python3 prototipo_oficina_v2.py <out_dir>   (needs Pillow; set A to the kit folder)
Not production code: it is the source of truth for tile positions to port to generate-assets.mjs."""
import json, os, sys, colorsys
from PIL import Image, ImageDraw

A = '../public/kit'  # pixel-agents: webview-ui/public/assets
OUT = sys.argv[1]
T = 16

MAP = [
    "WWWWWWWWWWWWWWWWWW",
    "WWWWWWWWWWWWWWWWWW",
    "WpppppppWppppppppW",
    "WpppppppWppppppppW",
    "WpppppppWppppppppW",
    "WpppppppWppppppppW",
    "WpppppppWppppppppW",
    "WWWppWWWWWWWppWWWW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WggggggggggggggggW",
    "WWWWWWhhWWWWWWWWWW",
    "WccccWhhWccccccccW",
    "WccccWhhWccccccccW",
    "WcccchhhWccccccccW",
    "WccccWhhWccccccccW",
    "WccccWhhWccccccccW",
    "WWWWWWddWWWWddWWWW",
    "WddddddddddddddddW",
    "WddddddddddddddddW",
    "WddddddddddddddddW",
    "WddddddddddddddddW",
    "WddddddddddddddddW",
    "WWWWWWWWeeWWWWWWWW",
]
H, W = len(MAP), len(MAP[0])
assert all(len(r) == W for r in MAP), [len(r) for r in MAP]

hexc = lambda h: tuple(int(h[i:i + 2], 16) for i in (1, 3, 5))


def tint(img, dark, light):
    """Colorize: grey luminance → gradient between two colours (keeps alpha)."""
    img = img.convert('RGBA'); px = img.load(); d, l = hexc(dark), hexc(light)
    for y in range(img.height):
        for x in range(img.width):
            r, g, b, a = px[x, y]
            if not a: continue
            v = (0.299 * r + 0.587 * g + 0.114 * b) / 255
            px[x, y] = tuple(int(d[i] + (l[i] - d[i]) * v) for i in range(3)) + (a,)
    return img


def hue(img, deg):
    img = img.convert('RGBA'); px = img.load()
    for y in range(img.height):
        for x in range(img.width):
            r, g, b, a = px[x, y]
            if not a: continue
            h, l, s = colorsys.rgb_to_hls(r / 255, g / 255, b / 255)
            if s < 0.15 or h < 0.14 or h > 0.95: continue
            r2, g2, b2 = colorsys.hls_to_rgb((h + deg / 360) % 1, l, s)
            px[x, y] = (int(r2 * 255), int(g2 * 255), int(b2 * 255), a)
    return img


floor = lambda i: Image.open(f'{A}/floors/floor_{i}.png').convert('RGBA')
FLOORS = {
    'p': tint(floor(6), '#7A4E26', '#D9A867'),
    'c': tint(floor(4), '#CFC6A8', '#FBF5E4'),
    'g': tint(floor(3), '#B9B993', '#F2F2DC'),
    'h': tint(floor(1), '#C9B98F', '#EFE3C4'),
    'd': tint(floor(0), '#151515', '#2B2B2B'),
    'e': tint(floor(0), '#D99419', '#FBB62F'),
}
wall_sheet = tint(Image.open(f'{A}/walls/wall_0.png'), '#1C1C1C', '#E8E2C8')
WALLS = [wall_sheet.crop(((k % 4) * 16, (k // 4) * 32, (k % 4) * 16 + 16, (k // 4) * 32 + 32)) for k in range(16)]

# furniture catalogue from manifests
CAT = {}
def walk(node, folder):
    if 'file' in node:
        CAT[node['id']] = dict(file=f'{folder}/{node["file"]}', fw=node.get('footprintW', 1), fh=node.get('footprintH', 1))
    for m in node.get('members', []): walk(m, folder)
for f in os.listdir(f'{A}/furniture'):
    man = json.load(open(f'{A}/furniture/{f}/manifest.json'))
    if man.get('type') == 'asset': CAT[man['id']] = dict(file=f'{A}/furniture/{f}/{man["id"]}.png', fw=man.get('footprintW', 1), fh=man.get('footprintH', 1))
    if 'file' in man: CAT[man['id']] = dict(file=f'{A}/furniture/{f}/{man["file"]}', fw=man.get('footprintW', 1), fh=man.get('footprintH', 1))
    walk(man, f'{A}/furniture/{f}')

img = Image.new('RGBA', (W * T, H * T), (11, 11, 11, 255))
for y in range(H):
    for x in range(W):
        ch = MAP[y][x]
        if ch in FLOORS: img.alpha_composite(FLOORS[ch], (x * T, y * T))
# MAD dots on the reception carpet
dd = ImageDraw.Draw(img)
for y in range(H):
    for x in range(W):
        if MAP[y][x] == 'd':
            for oy in (3, 11):
                for ox in ((3, 11) if oy == 3 else (7, 15)):
                    dd.point((x * T + ox, y * T + oy), fill=(251, 182, 47, 150))

items = []  # (zY, x, y, image)
def isw(x, y): return 0 <= y < H and 0 <= x < W and MAP[y][x] == 'W'
for y in range(H):
    for x in range(W):
        if MAP[y][x] == 'W':
            m = (1 if isw(x, y - 1) else 0) | (2 if isw(x + 1, y) else 0) | (4 if isw(x, y + 1) else 0) | (8 if isw(x - 1, y) else 0)
            items.append(((y + 1) * T, x * T, (y + 1) * T - 32, WALLS[m]))

def put(id_, col, row, flip=False, z=None, img_=None, recolor=None):
    c = CAT[id_]; im = img_ or Image.open(c['file']).convert('RGBA')
    if recolor: im = recolor(im)
    if flip: im = im.transpose(Image.FLIP_LEFT_RIGHT)
    bottom = (row + c['fh']) * T
    items.append((z if z is not None else bottom, col * T, bottom - im.height, im))

CH = {}
def char(pal, col, row, frame=3, dirrow=0, shift=0, sit=True):
    sh = Image.open(f'{A}/characters/char_{pal}.png').convert('RGBA')
    if shift: sh = hue(sh, shift)
    fr = sh.crop((frame * 16, dirrow * 32, frame * 16 + 16, dirrow * 32 + 32))
    y = (row + 1) * T - 32 + (-3 if sit else 0)
    items.append(((row + 1) * T, col * T, y, fr))

mustard = lambda im: tint(im, '#7A5410', '#FFD27A')

# ── Estudio (Liz)
put('LARGE_PAINTING', 2, 0); put('CLOCK', 5, 0); put('HANGING_PLANT', 7, 0)
put('DESK_FRONT', 2, 4); char(1, 3, 4); put('PC_SIDE', 2, 4, z=6 * T + 1)
put('PLANT', 6, 5); put('CACTUS', 1, 6)
# ── Gerencia (Jessica)
put('LARGE_PAINTING', 10, 0); put('SMALL_PAINTING_2', 14, 0); put('HANGING_PLANT', 16, 0)
put('DESK_FRONT', 11, 4); char(2, 12, 4); put('PC_SIDE', 13, 4, z=6 * T + 1, flip=True); put('COFFEE', 11, 4, z=6 * T + 1)
put('SOFA_FRONT', 14, 6); put('PLANT_2', 9, 5)
# ── Planta abierta + antesala/café
put('WHITEBOARD', 5, 6); put('BOOKSHELF', 8, 6) if False else None; put('CLOCK', 9, 6)
put('DESK_FRONT', 1, 10); char(0, 2, 10); put('PC_SIDE', 1, 10, z=12 * T + 1)
put('DESK_FRONT', 5, 10); char(4, 6, 10); put('PC_FRONT_ON_1', 7, 10, z=12 * T + 1)
put('DESK_FRONT', 9, 10); char(5, 10, 10); put('PC_SIDE', 11, 10, z=12 * T + 1, flip=True)
for x in (12, 13, 14, 15): put('WOODEN_BENCH', x, 8)
put('SMALL_TABLE_FRONT', 13, 12); put('COFFEE', 13, 12, z=14 * T + 1); put('COFFEE', 14, 12, z=14 * T + 1)
put('CUSHIONED_CHAIR_SIDE', 12, 13); put('CUSHIONED_CHAIR_SIDE', 15, 13, flip=True)
put('PLANT', 16, 9); put('LARGE_PLANT', 1, 13) if False else put('PLANT_2', 1, 13); put('BIN', 4, 14)
# ── Archivo
put('DOUBLE_BOOKSHELF', 1, 14); put('DOUBLE_BOOKSHELF', 3, 14)
put('DESK_FRONT', 1, 18); char(3, 2, 18, shift=120); put('BIN', 4, 20)
# ── Pasillo
put('PLANT', 7, 16)
# ── Reuniones
put('LARGE_PAINTING', 11, 14); put('HANGING_PLANT', 16, 14)
put('TABLE_FRONT', 11, 16)
for r in (17, 18): put('WOODEN_CHAIR_SIDE', 10, r); put('WOODEN_CHAIR_SIDE', 14, r, flip=True)
char(1, 15, 18, frame=0, shift=210, sit=False); put('PLANT_2', 9, 19)
# ── Recepción
put('CLOCK', 3, 20); put('SMALL_PAINTING', 15, 20)
put('DESK_FRONT', 6, 23, recolor=mustard); put('DESK_FRONT', 9, 23, recolor=mustard)
char(5, 8, 23, frame=0, shift=170, sit=False)
put('SOFA_SIDE', 1, 24); put('SOFA_SIDE', 16, 24, flip=True)
put('PLANT', 1, 22); put('PLANT_2', 16, 22)

for z, x, y, im in sorted(items, key=lambda t: t[0]):
    img.alpha_composite(im, (x, max(y, -1000)) if y >= 0 else (x, y)) if y >= 0 else img.paste(im.crop((0, -y, im.width, im.height)), (x, 0), im.crop((0, -y, im.width, im.height)))

# MAD sign over the reception wall
dd = ImageDraw.Draw(img)
dd.rectangle([8 * T + 2, 20 * T + 4, 12 * T - 2, 21 * T + 10], fill=(251, 182, 47), outline=(11, 11, 11))
for x0 in (8 * T + 12, 8 * T + 26, 8 * T + 40):
    dd.rectangle([x0, 20 * T + 9, x0 + 8, 21 * T + 5], fill=(11, 11, 11))

img.save(OUT + '/oficina-v2-referencia-1x.png')
img.resize((W * T * 3, H * T * 3), Image.NEAREST).save(OUT + '/oficina-v2-referencia.png')
print(img.size)
