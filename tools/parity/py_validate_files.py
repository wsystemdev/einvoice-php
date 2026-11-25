"""Validate the PHP-generated files (XML and PDF) with the W System Python engine (same code as api.wsystem.dev /einvoice/validate)
and compare with expected_valid. Usage: py_validate_files.py <dir with manifest.json>"""
import json, os, sys
sys.path.insert(0, os.environ.get("VATCHECK", "/workspace/vatcheck-main"))
from vatcheck import einvoice
d = sys.argv[1]
man = json.load(open(os.path.join(d, "manifest.json")))
bad = n = 0
for name, e in man.items():
    for key in ("xml", "pdf"):
        if not e[key]:
            continue
        n += 1
        v = einvoice.validate(open(os.path.join(d, e[key]), "rb").read(), "xrechnung" if e["xrechnung"] else "en16931")
        if v["valid"] != e["expected_valid"] or (key == "pdf" and v.get("container") != "pdf"):
            bad += 1
            print("MISMATCH", name, key, v["valid"], e["expected_valid"], [x["rule_id"] for x in v["errors"]][:5])
print(f"{n} files checked with the W System engine, {bad} mismatches; "
      f"{sum(1 for e in man.values() if e['expected_valid'])} fixtures expected valid")
sys.exit(1 if bad else 0)
