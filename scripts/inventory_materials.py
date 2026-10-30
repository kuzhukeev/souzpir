"""Read-only source inventory; generate local overview sheets, never alter originals."""
from pathlib import Path
from PIL import Image, ImageOps, ImageDraw
import hashlib, json, re

ROOT = Path(__file__).resolve().parents[1]
IMAGES = Path(r'C:\OSPanel\home\tasks.local\completed')
downloads = Path(r'C:\Users\eralash\Downloads')
records = []
for path in sorted(IMAGES.rglob('*')):
    if path.suffix.lower() not in {'.png', '.jpg', '.jpeg', '.webp', '.gif'}:
        continue
    with Image.open(path) as im:
        width, height = im.size
    records.append({'path': str(path), 'relative': path.relative_to(IMAGES).as_posix(),
                    'bytes': path.stat().st_size, 'sha256': hashlib.file_digest(path.open('rb'), 'sha256').hexdigest(),
                    'width': width, 'height': height,
                    'screenHints': re.findall(r'[SVD]-\d+(?:\.(?:\d+|P))?[a-d]?', path.name)})
originals = [r for r in records if r['relative'].split('/')[0].isdigit()]
out = ROOT / '.local/contact-sheets'
out.mkdir(parents=True, exist_ok=True)
for batch in range(0, len(originals), 16):
    sheet = Image.new('RGB', (1920, 1400), '#d9dde5')
    draw = ImageDraw.Draw(sheet)
    for index, row in enumerate(originals[batch:batch+16]):
        x, y = (index % 4)*480, (index // 4)*350
        with Image.open(row['path']) as im:
            thumb = ImageOps.contain(im.convert('RGB'), (468, 310))
            sheet.paste(thumb, (x + (480-thumb.width)//2, y+25))
        draw.text((x+6,y+5), row['relative'][:66], fill='black')
    sheet.save(out / f'sheet-{batch//16+1:02d}.jpg', quality=88)
sources=[]
for path in [downloads/'eyon-pilot (6).zip', downloads/'Прототип ИИ-продавца «Союз Пир» (Laravel) (1).zip', *sorted((ROOT/'docs/sources').glob('*.md'))]:
    with path.open('rb') as stream:
        digest=hashlib.file_digest(stream,'sha256').hexdigest()
    sources.append({'path':str(path), 'bytes':path.stat().st_size,'sha256':digest})
manifest={'imagesRoot':str(IMAGES), 'totalImages':len(records), 'originalImages':len(originals),
          'originalFolders':len({r['relative'].split('/')[0] for r in originals}),
          'overviewNote':'Contact sheets show composition; small text needs original-size verification per task.',
          'sources':sources,'images':records}
(ROOT/'planning/materials-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps({k:v for k,v in manifest.items() if k not in {'images','sources'}},ensure_ascii=False))
