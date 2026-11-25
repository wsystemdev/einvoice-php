"""Golden output of the W System engine's parse() (vatcheck einvoice_parse) for every validator-corpus file, so the
PHP Parser can be checked for identical output. Run with the vatcheck venv from a read-only worktree:
  cd <vatcheck worktree> && PYTHONPATH=. python tools/validator/build_parse_golden.py <repo>/tests/fixtures/validator"""
import json, pathlib, sys
from vatcheck import einvoice_parse
from vatcheck.einvoice import InputError

root = pathlib.Path(sys.argv[1])
out = {}
for f in sorted(p for p in (root / "files").rglob("*") if p.is_file()) + sorted(p for p in (root / "shared").rglob("*") if p.suffix in (".xml", ".pdf")):
    rel = str(f.relative_to(root))
    try:
        out[rel] = einvoice_parse.parse(f.read_bytes())
    except InputError as e:
        out[rel] = {"input_error": str(e)}
(root / "parse_golden.json").write_text(json.dumps(out, ensure_ascii=False, indent=1, sort_keys=False) + "\n")
print(len(out), "files")
