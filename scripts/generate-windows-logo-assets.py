from pathlib import Path
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "LeikwanBridge.Desktop" / "src" / "LeikwanBridge.App" / "Resources" / "Icons"
OUT_DIR.mkdir(parents=True, exist_ok=True)

SCALE = 1024
canvas = Image.new("RGBA", (SCALE, SCALE), "white")
draw = ImageDraw.Draw(canvas)

blue = "#1f78c8"
green = "#8dc63f"
light_blue = "#6ec5f5"
border = "#d9e7f3"
white = "#ffffff"

# white card with subtle border
card_margin = 64
card = (card_margin, card_margin, SCALE - card_margin, SCALE - card_margin)
draw.rounded_rectangle(card, radius=220, fill=white, outline=border, width=18)

# bridge / network links
line_width = 72
joint_r = 86
nodes = {
    "tl": (300, 250),
    "tr": (724, 250),
    "bl": (300, 774),
    "br": (724, 774),
}

# crossing bands inspired by the provided mark
draw.line([nodes["tl"], (512, 410), nodes["br"]], fill=blue, width=line_width, joint="curve")
draw.line([nodes["tr"], (512, 410), nodes["bl"]], fill=green, width=line_width, joint="curve")
draw.line([(512, 410), (512, 610)], fill=white, width=34)

# central cloud-like bridge outline
cloud_y = 540
cloud_width = 420
cloud_height = 180
left = 512 - cloud_width // 2
right = 512 + cloud_width // 2
bottom = cloud_y + cloud_height // 2
outline_w = 32
for bbox in [
    (left - 30, cloud_y - 40, left + 170, bottom + 30),
    (left + 70, cloud_y - 90, right - 70, bottom + 20),
    (right - 170, cloud_y - 40, right + 30, bottom + 30),
]:
    draw.arc(bbox, start=180, end=360, fill=light_blue, width=outline_w)
draw.line([(left + 10, bottom - 8), (right - 10, bottom - 8)], fill=light_blue, width=outline_w)

# nodes
for key, fill in [("tl", blue), ("tr", green), ("bl", green), ("br", blue)]:
    x, y = nodes[key]
    draw.ellipse((x - joint_r, y - joint_r, x + joint_r, y + joint_r), fill=fill, outline=white, width=28)

# resize to final assets
png = canvas.resize((512, 512), Image.Resampling.LANCZOS)
png_path = OUT_DIR / "leikwan-host-app.png"
ico_path = OUT_DIR / "leikwan-host.ico"
png.save(png_path)
png.save(ico_path, sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
print(f"generated {png_path}")
print(f"generated {ico_path}")
