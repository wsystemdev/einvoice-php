#!/usr/bin/env bash
# Mustang ZUGFeRD/Factur-X validator on every generated PDF whose XML is expected valid (PDF/A + XML + profile checks).
# Usage: tools/ci/mustang-check.sh <generated dir> <Mustang-CLI.jar>
set -uo pipefail
DIR=${1:?dir}; JAR=${2:?jar}
mapfile -t FILES < <(python3 -c "import json;m=json.load(open('$DIR/manifest.json'));print('\n'.join(e['pdf'] for e in m.values() if e['pdf'] and e['expected_valid']))")
bad=0
for f in "${FILES[@]}"; do
  out=$(java -jar "$JAR" --no-notices --action validate --source "$DIR/$f" 2>&1)
  if ! grep -q '<summary status="valid"/>' <<<"$(echo "$out" | tail -3)"; then
    bad=$((bad+1)); echo "MUSTANG INVALID $f"; echo "$out" | grep -iE "<error|status=" | head -5
  fi
done
echo "Mustang checked ${#FILES[@]} PDFs: $bad invalid"
exit $((bad > 0))
