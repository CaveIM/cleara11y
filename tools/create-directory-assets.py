#!/usr/bin/env python3
"""Render original geometric WordPress.org artwork (Pillow, development only)."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parents[1] / 'wordpress-org'
OUT.mkdir(exist_ok=True)
FONT = '/System/Library/Fonts/Supplemental/Arial.ttf'
BOLD = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
NAVY = '#102c39'
MINT = '#a4eed1'
WHITE = '#f5faf7'

def mark(draw, x, y, size):
    # Open circular C and a central accessibility person, built from geometric shapes.
    draw.arc((x, y, x+size, y+size), 45, 315, fill=MINT, width=max(2, int(size*.075)))
    cx=x+size*.51
    draw.ellipse((cx-size*.062,y+size*.2,cx+size*.062,y+size*.324),fill=WHITE)
    draw.line([(x+size*.27,y+size*.41),(cx,y+size*.46),(x+size*.76,y+size*.36)],fill=WHITE,width=max(2,int(size*.055)))
    draw.line([(cx,y+size*.43),(cx,y+size*.60),(x+size*.37,y+size*.80)],fill=WHITE,width=max(2,int(size*.055)))
    draw.line([(cx,y+size*.60),(x+size*.65,y+size*.80)],fill=WHITE,width=max(2,int(size*.055)))

icon=Image.new('RGB',(1024,1024),NAVY)
mark(ImageDraw.Draw(icon),144,144,736)
for size in (128,256):icon.resize((size,size),Image.Resampling.LANCZOS).save(OUT/f'icon-{size}x{size}.png')
banner=Image.new('RGB',(1544,500),NAVY)
d=ImageDraw.Draw(banner)
mark(d,1110,105,282)
d.text((88,97),'ClearA11y',font=ImageFont.truetype(BOLD,100),fill=WHITE)
d.text((94,233),'Accessibility audits, inside WordPress.',font=ImageFont.truetype(FONT,36),fill=MINT)
d.text((94,321),'SCAN  /  REVIEW  /  IMPROVE',font=ImageFont.truetype(BOLD,22),fill=WHITE)
banner.save(OUT/'banner-1544x500.png')
banner.resize((772,250),Image.Resampling.LANCZOS).save(OUT/'banner-772x250.png')
print('Created icons and banners in',OUT)
