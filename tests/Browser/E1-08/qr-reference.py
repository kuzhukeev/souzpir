"""Create independent offline QR reference matrices using ReportLab.
Run only when intentionally verifying encoder changes; fixtures are not runtime data.
No network requests or application database access.
"""
import json
from pathlib import Path
from reportlab.graphics.barcode import qrencoder

samples = [
    "A",
    "otpauth://totp/souzpir:person%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=souzpir&algorithm=SHA1&digits=6&period=30",
    "otpauth://totp/" + "a" * 240 + "?secret=JBSWY3DPEHPK3PXP&issuer=souzpir",
    "x" * 600,
    "Ключ: проверка Unicode",
]
references = []
for text in samples:
    payload = text.encode("utf-8")
    qr = qrencoder.QRCode(None, qrencoder.QRErrorCorrectLevel.L)
    qr.addData(qrencoder.QR8bitByte(payload))
    qr.version = qr.calculate_version()
    qr.makeImpl(False, 0)
    references.append({"value": text, "version": qr.version, "matrix": ["".join("1" if value else "0" for value in row) for row in qr.modules]})
Path(__file__).with_name("qr-reference.json").write_text(json.dumps(references, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print("Generated", len(references), "independent ReportLab matrices (byte mode, L, mask 0).")
