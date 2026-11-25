#!/usr/bin/env bash
# Run the official KoSIT validator (Java) with the bundled XRechnung 3.0.2 configuration on every generated XRechnung
# file (UBL + CII, and the XML embedded in ZUGFeRD-XRECHNUNG PDFs, which is byte-identical) and compare accept/reject
# with the W System engine's verdict in tests/fixtures/expected.json.
# Usage: tools/ci/kosit-check.sh <generated dir> <validator-standalone.jar>
set -euo pipefail
DIR=${1:?dir}; JAR=${2:?jar}
CFG="$(cd "$(dirname "$0")/../.." && pwd)/resources/kosit/xrechnung-3.0.2-2026-08-31"
OUT="$DIR/kosit-reports"; mkdir -p "$OUT"
mapfile -t FILES < <(python3 -c "import json,sys;m=json.load(open('$DIR/manifest.json'));print('\n'.join('$DIR/'+e['xml'] for e in m.values() if e['xrechnung']))")
echo "KoSIT: ${#FILES[@]} XRechnung files"
java -jar "$JAR" -s "$CFG/scenarios.xml" -r "$CFG" -o "$OUT" "${FILES[@]}" > "$OUT/kosit.log" 2>&1 || true
python3 - "$DIR" "$OUT" <<'PY'
import json, os, re, sys
d, out = sys.argv[1], sys.argv[2]
man = json.load(open(os.path.join(d, "manifest.json")))
bad = n = 0
for name, e in man.items():
    if not e["xrechnung"]:
        continue
    n += 1
    rep = os.path.join(out, name + "-report.xml")
    if not os.path.exists(rep):
        print("NO REPORT", name); bad += 1; continue
    s = open(rep, encoding="utf-8").read()
    accept = "<rep:accept" in s or re.search(r"<rep:assessment>\s*<rep:accept", s) is not None
    if accept != bool(e["expected_valid"]):
        bad += 1
        ids = sorted(set(re.findall(r'id="([A-Z]+-[A-Z0-9-]+)"', s)))[:6]
        print("MISMATCH", name, "kosit_accept=", accept, "expected_valid=", e["expected_valid"], ids)
print(f"KoSIT checked {n} XRechnung files: {bad} mismatches against the W System engine")
sys.exit(1 if bad else 0)
PY
