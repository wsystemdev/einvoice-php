"""Run the W System Python engine (reference) on every fixture: XML + totals (or the InputError message),
plus the engine's validate() verdict on the XML. Usage: py_ref.py inputs.json expected.json [--validate]"""
import json, sys, os
sys.path.insert(0, os.environ.get("VATCHECK", "/workspace/vatcheck-main"))
from vatcheck import einvoice, einvoice_cii

fx = json.load(open(sys.argv[1]))
do_val = "--validate" in sys.argv
res = {}
for f in fx:
    j, fmt = f["input"], einvoice_cii.norm_format(f["format"])
    try:
        if fmt in einvoice_cii.CII_FORMATS:
            xml, totals, _ = einvoice_cii.build_cii(j, fmt)
            xr = einvoice_cii.CII_FORMATS[fmt][0] == einvoice.XRECHNUNG_ID
        else:
            xml, totals = einvoice.build_ubl(j)
            xr = (j.get("format") or "en16931").lower() == "xrechnung"
        r = {"xml": xml, "totals": totals}
        if do_val:
            v = einvoice.validate(xml.encode(), "xrechnung" if xr else "en16931")
            r["valid"] = v["valid"]
            r["error_ids"] = sorted({e.get("rule_id") or e.get("id") or "?" for e in v["errors"]})
            r["warning_ids"] = sorted({e.get("rule_id") or e.get("id") or "?" for e in v["warnings"]})
    except einvoice.InputError as e:
        r = {"error": str(e)}
    res[f["name"]] = r
json.dump(res, open(sys.argv[2], "w"), ensure_ascii=False, indent=0)
print(len(res), "reference results;", sum(1 for r in res.values() if "error" in r), "input errors;",
      sum(1 for r in res.values() if r.get("valid") is False), "invalid")
