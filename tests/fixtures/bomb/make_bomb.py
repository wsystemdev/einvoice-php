# Decompression-bomb regression fixture: writes a ~1 MB PDF whose embedded factur-x.xml inflates to N MiB of zeros.
# Usage: python3 make_bomb.py out.pdf [MiB=1024]
import sys, zlib
out_path = sys.argv[1]; mib = int(sys.argv[2]) if len(sys.argv) > 2 else 1024
c = zlib.compressobj(9); chunk = b"\0" * (1 << 20)
data = b"".join(c.compress(chunk) for _ in range(mib)) + c.flush()
objs = [b"<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(factur-x.xml) 4 0 R] >> >> >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>", b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] >>",
        b"<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml) /EF << /F 5 0 R >> >>"]
pdf = b"%PDF-1.7\n"; offs = []
for i, o in enumerate(objs, 1):
    offs.append(len(pdf)); pdf += b"%d 0 obj\n" % i + o + b"\nendobj\n"
offs.append(len(pdf))
pdf += b"5 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml /Filter /FlateDecode /Length %d >>\nstream\n" % len(data) + data + b"\nendstream\nendobj\n"
x = len(pdf)
pdf += b"xref\n0 6\n0000000000 65535 f \n" + b"".join(b"%010d 00000 n \n" % o for o in offs) + b"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % x
open(out_path, "wb").write(pdf); print(out_path, len(pdf), "bytes")
