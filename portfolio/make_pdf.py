import re, sys
def nice(stem): return stem[3:].replace('-',' ').title().replace('Cms','CMS')
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path(sys.argv[1])
shots = sorted((root/'screenshots').glob('*.png'))
caps = dict(re.findall(r'`webp/(\d\d-[^`]+)\.webp` \| ([^|]+?) \|', (root/'README.md').read_text()))

def font(size, bold=False):
    for p in ['/System/Library/Fonts/Supplemental/Arial Bold.ttf' if bold else '/System/Library/Fonts/Supplemental/Arial.ttf',
              '/System/Library/Fonts/Helvetica.ttc']:
        try: return ImageFont.truetype(p, size)
        except OSError: pass
    return ImageFont.load_default()

BG, INK, MUTED, ACCENT = (248,250,252), (15,23,42), (100,116,139), (79,70,229)
W, H = 2000, 1400          # landscape page
IMG_W = 1840               # screenshot width on page
pages = []

# cover
c = Image.new('RGB', (W, H), BG); d = ImageDraw.Draw(c)
d.rectangle([0,0,W,14], fill=ACCENT)
d.text((120,200), 'Membership LMS Platform', font=font(96,True), fill=INK)
d.text((120,330), 'Learn Skills. Build Income.', font=font(48), fill=ACCENT)
d.text((120,420), 'Laravel 13 API  ·  React 18 + TypeScript  ·  Stripe & Razorpay  ·  Secure HLS video', font=font(34), fill=MUTED)
y = 540
for i, s in enumerate(shots):
    col, row = divmod(i, 9)
    d.text((120 + col*900, y + row*72), f'{i+1:02d}  {nice(s.stem)}', font=font(34), fill=INK)
pages.append(c)

for i, s in enumerate(shots):
    p = Image.new('RGB', (W, H), BG); d = ImageDraw.Draw(p)
    d.rectangle([0,0,W,10], fill=ACCENT)
    title = nice(s.stem)
    d.text((80,50), f'{i+1:02d}  {title}', font=font(46,True), fill=INK)
    d.text((80,115), caps.get(s.stem, ''), font=font(30), fill=MUTED)
    im = Image.open(s).convert('RGB')
    h = round(im.height * IMG_W / im.width)
    max_h = H - 200 - 50
    if h > max_h: w2 = round(im.width*max_h/im.height); h = max_h
    else: w2 = IMG_W
    im = im.resize((w2, h), Image.LANCZOS)
    x = (W - w2)//2
    d.rectangle([x-2, 198, x+w2+1, 200+h+1], fill=(226,232,240))
    p.paste(im, (x, 200))
    d.text((W-80, H-45), f'{i+2} / {len(shots)+1}', font=font(24), fill=MUTED, anchor='rs')
    pages.append(p)

out = root/'lms-portfolio.pdf'
pages[0].save(out, save_all=True, append_images=pages[1:], resolution=150, quality=88)

# single PNG overview: 3 columns x 6 rows
cols, tw = 3, 900; th = round(tw*1800/2880); gap, top, lab = 40, 220, 56
rows = -(-len(shots)//cols)
sheet = Image.new('RGB', (cols*tw+(cols+1)*gap, top + rows*(th+lab+gap) + gap), BG)
d = ImageDraw.Draw(sheet)
d.rectangle([0,0,sheet.width,12], fill=ACCENT)
d.text((gap,60), 'Membership LMS Platform — feature overview', font=font(64,True), fill=INK)
d.text((gap,145), 'Laravel 13 · React 18 + TypeScript · Stripe & Razorpay · Secure HLS video', font=font(32), fill=MUTED)
for i, s in enumerate(shots):
    r, cidx = divmod(i, cols)
    x = gap + cidx*(tw+gap); y = top + r*(th+lab+gap)
    im = Image.open(s).convert('RGB').resize((tw, th), Image.LANCZOS)
    d.rectangle([x-2,y-2,x+tw+1,y+th+1], fill=(226,232,240))
    sheet.paste(im, (x,y))
    d.text((x, y+th+14), f'{i+1:02d}  {nice(s.stem)}', font=font(30,True), fill=INK)
sheet.save(root/'lms-all-features.png', optimize=True)
print(out, sheet.size)
