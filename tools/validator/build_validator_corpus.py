"""Build the shared validator parity corpus + golden results from the W System Python engine.

Run on the box from a vatcheck worktree off main (read-only use):
  cd /workspace/vatcheck-tkval && PYTHONPATH=. FREE_PER_DAY=1000 /workspace/vatcheck/.venv/bin/python \
     <repo>/tools/build_validator_corpus.py <repo>/tests/fixtures/validator [--golden-only]

Sources (public, licences kept in tests/fixtures/validator/NOTICE.md):
  CEN/TC 434 eInvoicing-EN16931 validation-1.3.17 examples (EUPL-1.2)
  KoSIT xrechnung-testsuite v2026-08-31 (Apache-2.0)
  Mustang project ZUGFeRD/Factur-X samples (Apache-2.0)
  W System engine output (create()) and targeted single-defect mutations of the above.
"""
import sys, os, io, re, json, zipfile, hashlib, pathlib, copy, base64, subprocess

OUT = pathlib.Path(sys.argv[1]).resolve()
GOLDEN_ONLY = "--golden-only" in sys.argv
VC = pathlib.Path.cwd()
CACHE = pathlib.Path("/workspace/vatcheck/tests/.cache")  # read-only: downloaded official samples
CORPUS = VC / "tests" / "corpus"
CEN = {"ubl": CACHE / "en16931-ubl-1.3.17.zip", "cii": CACHE / "en16931-cii-1.3.17.zip"}
XRTS = CACHE / "xrechnung-3.0.2-testsuite-2026-08-31.zip"

from vatcheck import einvoice  # noqa: E402

def engine_version():
    try:
        return subprocess.check_output(["git", "-C", str(VC), "rev-parse", "HEAD"], text=True).strip()
    except Exception:
        return "unknown"

files: dict[str, bytes] = {}
meta: dict[str, dict] = {}

def add(name, data, source, note=""):
    files[name] = data
    meta[name] = {"source": source, **({"note": note} if note else {})}

if not GOLDEN_ONLY:
    # --- CEN examples
    for syn, zp in CEN.items():
        z = zipfile.ZipFile(zp)
        for n in sorted(z.namelist()):
            if re.match(r"examples/[^/]+\.(xml|XML)$", n):
                add(f"cen/{syn}/{n.split('/')[-1]}", z.read(n), "CEN eInvoicing-EN16931 validation-1.3.17 (EUPL-1.2)")
    # --- KoSIT testsuite
    z = zipfile.ZipFile(XRTS)
    for n in sorted(z.namelist()):
        m = re.match(r".*instances/(.+\.xml)$", n)
        if m:
            add("kosit/" + m.group(1), z.read(n), "KoSIT xrechnung-testsuite v2026-08-31 (Apache-2.0)")
    # --- Mustang PDFs from the vatcheck corpus + test cache
    for p in sorted(CORPUS.glob("*.pdf")):
        add(f"mustang/{p.name}", p.read_bytes(), "Mustang project sample (Apache-2.0), via vatcheck tests/corpus")
    add("mustang/EN16931_Einfach.pdf", (CACHE / "EN16931_Einfach.pdf").read_bytes(), "Mustang project sample (Apache-2.0)")

    # --- W System engine output (create())
    from vatcheck.api import EINV_SAMPLE
    for fmt in ("en16931", "xrechnung", "peppol-bis3", "cii", "xrechnung-cii", "zugferd", "zugferd-xrechnung"):
        j = copy.deepcopy(EINV_SAMPLE); j["format"] = fmt
        r = einvoice.create(j)
        if r.get("pdf_base64"):
            add(f"wsystem/create_{fmt}.pdf", base64.b64decode(r["pdf_base64"]), "W System engine create() output")
        add(f"wsystem/create_{fmt}.xml", r["xml"].encode("utf-8"), "W System engine create() output")
    j = copy.deepcopy(EINV_SAMPLE); j["format"] = "en16931"; j["type_code"] = "381"
    try:
        r = einvoice.create(j); add("wsystem/create_creditnote.xml", r["xml"].encode(), "W System engine create() output (credit note)")
    except Exception as e:
        print("credit note skipped:", e)

    # --- Slovak / Norwegian sellers (national W System field checks) from the generator's shared inputs
    from vatcheck import einvoice_cii
    inputs = {f["name"]: f["input"] for f in json.load(open(pathlib.Path(__file__).resolve().parents[2] / "tests/fixtures/inputs.json"))}
    for nm in ("sk-peppol-bis3", "no-peppol-bis3", "sk-en16931", "no-en16931", "sk-cii", "no-cii"):
        j = inputs[nm]; fmt = (j.get("format") or "en16931").lower()
        xml = einvoice_cii.build_cii(j, fmt)[0] if fmt in einvoice_cii.CII_FORMATS else einvoice.build_ubl(j)[0]
        add(f"national/{nm}.xml", xml.encode("utf-8"), "W System engine output for the shared generator input " + nm)
    skx = files["national/sk-en16931.xml"]
    add("national/sk_no_endpoints.xml", re.sub(rb"<cbc:EndpointID[^>]*>[^<]*</cbc:EndpointID>", b"", skx), "mutation", "SK-FS-02/05")
    add("national/sk_no_address_ico.xml", re.sub(rb"<cbc:(StreetName|CityName|PostalZone)>[^<]*</cbc:\1>|<cbc:CompanyLegalForm>[^<]*</cbc:CompanyLegalForm>", b"", skx), "mutation", "SK-FS-07/08/10")
    nox = files["national/no-peppol-bis3.xml"]
    nox = nox.replace(b"310001007", b"310001008").replace(b"NO310001008MVA", b"NO310001008").replace(b">Foretaksregisteret<", b">X<")
    add("national/no_bad_orgnr.xml", nox.replace(b"310001015", b"310001016"), "mutation", "NO-LAW-03/04/05/06/08/09")

    # --- targeted single-defect mutations
    def cen(syn, name):
        return zipfile.ZipFile(CEN[syn]).read(f"examples/{name}")
    def xr(name):
        zz = zipfile.ZipFile(XRTS); return zz.read(next(n for n in zz.namelist() if n.endswith(name)))
    U = cen("ubl", "ubl-tc434-example1.xml"); C = cen("cii", "CII_example1.xml")
    XU = xr("standard/01.01a-INVOICE_ubl.xml"); XC = xr("standard/01.01a-INVOICE_uncefact.xml")
    def sub(pat, rep, data, label):
        out = re.sub(pat, rep, data, count=1, flags=re.S)
        assert out != data, label
        return out
    def mut(name, data, expect):
        add(f"invalid/{name}", data, "mutation of a public sample", note=f"targets {expect}")
    mut("ubl_no_customization_id.xml", sub(rb"<cbc:CustomizationID>.*?</cbc:CustomizationID>", b"", U, 1), "BR-01")
    mut("ubl_payable_amount.xml", sub(rb"(<cbc:PayableAmount[^>]*>)[^<]+", rb"\g<1>1.00", U, 2), "BR-CO-16")
    i = U.index(b"<cac:AccountingCustomerParty>")
    mut("ubl_buyer_country_name.xml", U[:i] + sub(rb"(<cbc:IdentificationCode[^>]*>)[^<]+", rb"\g<1>Deutschland", U[i:], 3), "BR-CL-14")
    mut("ubl_xsd_unknown_element.xml", U.replace(b"<cbc:IssueDate>", b"<cbc:Bogus>x</cbc:Bogus><cbc:IssueDate>", 1), "XSD")
    mut("ubl_currency_name.xml", sub(rb"<cbc:DocumentCurrencyCode>[^<]+", b"<cbc:DocumentCurrencyCode>Euro", U, 5), "BR-CL-04")
    mut("ubl_line_amount.xml", sub(rb"(<cac:InvoiceLine>.*?<cbc:LineExtensionAmount[^>]*>)[^<]+", rb"\g<1>12345.67", U, 6), "BR-CO-10")
    mut("ubl_tax_amount.xml", sub(rb"(<cac:TaxTotal>\s*<cbc:TaxAmount[^>]*>)[^<]+", rb"\g<1>0.01", U, 7), "BR-CO-15")
    mut("ubl_unit_code.xml", sub(rb'unitCode="[^"]+"', b'unitCode="Stk"', U, 8), "BR-CL-23")
    mut("ubl_vat_category.xml", sub(rb"(<cac:ClassifiedTaxCategory>\s*<cbc:ID>)[^<]+", rb"\g<1>Q", U, 9), "BR-CL-18")
    mut("ubl_no_seller_name.xml", sub(rb"(<cac:AccountingSupplierParty>.*?<cac:PartyLegalEntity>\s*)<cbc:RegistrationName>[^<]*</cbc:RegistrationName>", rb"\g<1>", U, 10), "BR-06")
    mut("ubl_type_code.xml", sub(rb"<cbc:InvoiceTypeCode>[^<]+", b"<cbc:InvoiceTypeCode>999", U, 11), "BR-CL-01")
    mut("ubl_not_wellformed.xml", U[: len(U) // 2], "InputError")
    mut("ubl_doctype.xml", b'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Invoice>&e;</Invoice>', "InputError")
    mut("unsupported_root.xml", b'<?xml version="1.0"?><Order xmlns="urn:x"/>', "InputError")
    mut("cii_line_total.xml", sub(rb"(<ram:LineTotalAmount[^>]*>)[^<]+(</ram:LineTotalAmount>\s*<ram:(?:ChargeTotalAmount|AllowanceTotalAmount|TaxBasisTotalAmount))", rb"\g<1>99999.99\g<2>", C, 20), "BR-CO-10")
    i = C.index(b"<ram:SellerTradeParty>")
    mut("cii_seller_country_name.xml", C[:i] + sub(rb"(<ram:CountryID>)[^<]+", rb"\g<1>Germany", C[i:], 21), "BR-CL-14")
    ext = sub(rb"(<ram:GuidelineSpecifiedDocumentContextParameter>\s*<ram:ID>)[^<]*(</ram:ID>)", rb"\g<1>urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended\g<2>", C, 22)
    ext = sub(rb"(</ram:SpecifiedLineTradeDelivery>)", rb"<ram:DeliveryNoteReferencedDocument><ram:IssuerAssignedID>LS-1</ram:IssuerAssignedID><ram:FormattedIssueDateTime><qdt:DateTimeString format='102'>20261101</qdt:DateTimeString></ram:FormattedIssueDateTime></ram:DeliveryNoteReferencedDocument>\g<1>", ext, 23)
    mut("cii_extended_relaxed.xml", ext, "CII-DT-027 as warning")
    mut("cii_extended_as_en16931.xml", ext.replace(b"#conformant#urn:factur-x.eu:1p0:extended", b""), "CII-DT-027 as error")
    mut("cii_no_guideline.xml", sub(rb"(<ram:GuidelineSpecifiedDocumentContextParameter>\s*)<ram:ID>[^<]*</ram:ID>", rb"\g<1>", C, 24), "BR-01")
    mut("cii_type_code.xml", sub(rb"(<rsm:ExchangedDocument>.*?<ram:TypeCode>)[^<]+", rb"\g<1>999", C, 25), "BR-CL-01")
    mut("cii_xsd_unknown_element.xml", sub(rb"(<rsm:ExchangedDocument>)", rb"\g<1><ram:Bogus>x</ram:Bogus>", C, 26), "XSD")
    mut("cii_tax_total.xml", sub(rb"(<ram:TaxTotalAmount[^>]*>)[^<]+", rb"\g<1>0.01", C, 27), "BR-CO-15")
    mut("xr_ubl_no_buyer_reference.xml", sub(rb"<cbc:BuyerReference>.*?</cbc:BuyerReference>", b"", XU, 30), "BR-DE-15")
    mut("xr_ubl_no_payment_means.xml", sub(rb"<cac:PaymentMeans>.*?</cac:PaymentMeans>", b"", XU, 31), "BR-DE-1")
    mut("xr_ubl_no_seller_contact.xml", sub(rb"(<cac:AccountingSupplierParty>.*?)<cac:Contact>.*?</cac:Contact>", rb"\g<1>", XU, 32), "BR-DE-2")
    mut("xr_cii_no_buyer_reference.xml", sub(rb"<ram:BuyerReference>.*?</ram:BuyerReference>", b"", XC, 33), "BR-DE-15")
    mut("xr_cii_no_seller_email.xml", sub(rb"<ram:EmailURIUniversalCommunication>.*?</ram:EmailURIUniversalCommunication>", b"", XC, 34), "BR-DE-7")
    # PDF hybrids with a defective embedded XML (pypdf writer: classic xref, Flate streams)
    from pypdf import PdfWriter
    def pdf_with(xml, fname="factur-x.xml"):
        w = PdfWriter(); w.add_blank_page(595, 842); w.add_attachment(fname, xml)
        b = io.BytesIO(); w.write(b); return b.getvalue()
    mut("pdf_cii_line_total.pdf", pdf_with(files["invalid/cii_line_total.xml"]), "BR-CO-10 inside PDF")
    mut("pdf_xr_cii_no_buyer_reference.pdf", pdf_with(files["invalid/xr_cii_no_buyer_reference.xml"], "xrechnung.xml"), "BR-DE-15 inside PDF")
    mut("pdf_other_xml_name.pdf", pdf_with(C, "invoice-data.xml"), "fallback to any .xml attachment")
    w = PdfWriter(); w.add_blank_page(595, 842); b = io.BytesIO(); w.write(b)
    mut("pdf_no_attachment.pdf", b.getvalue(), "InputError")
    w = PdfWriter(); w.add_blank_page(595, 842); w.add_attachment("readme.txt", b"hi"); b = io.BytesIO(); w.write(b)
    mut("pdf_non_xml_attachment.pdf", b.getvalue(), "InputError")
    mut("empty.xml", b"", "InputError")

    OUT.mkdir(parents=True, exist_ok=True)
    for n, d in files.items():
        p = OUT / "files" / n; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(d)
else:
    for p in sorted((OUT / "files").rglob("*")):
        if p.is_file():
            n = str(p.relative_to(OUT / "files")); files[n] = p.read_bytes(); meta[n] = {}
    old = json.loads((OUT / "golden.json").read_text())
    for n in meta:
        meta[n] = {k: v for k, v in old["cases"].get(n, {}).items() if k in ("source", "note")}

def slim(i):
    return {"rule_id": i["rule_id"], "severity": i["severity"], "ruleset": i["ruleset"], "location": i.get("location"),
            "message": i.get("message"), "business_terms": i.get("business_terms", [])}

cases = {}
for n in sorted(files):
    d = files[n]
    for rules in (["auto"] + (["xrechnung", "en16931"] if n.startswith("invalid/xr_") or n.startswith("kosit/standard/01.01a") else [])):
        key = n if rules == "auto" else f"{n}?rules={rules}"
        c = dict(meta[n]); c["file"] = n; c["rules"] = rules; c["sha256"] = hashlib.sha256(d).hexdigest()
        try:
            r = einvoice.validate(d, rules)
            c.update({"input_error": None, "valid": r["valid"], "syntax": r["syntax"], "document_type": r["document_type"],
                      "customization_id": r["customization_id"], "profile": r["profile"], "container": r["container"],
                      "embedded_file": r.get("embedded_file"), "checked": {k: r["checked"][k] for k in ("xsd", "EN16931", "XRechnung", "PeppolBIS3")},
                      "errors": [slim(e) for e in r["errors"]], "warnings": [slim(w) for w in r["warnings"]],
                      "notes": r["notes"], "national": [x["ruleset"] for x in r["errors"] + r["warnings"] if x["ruleset"] in ("SK-FS", "NO-LAW")]})
        except einvoice.InputError as e:
            c.update({"input_error": str(e)})
        cases[key] = c
json.dump({"engine": "W System Python engine (vatcheck einvoice.validate)", "engine_commit": engine_version(),
           "rules": {"xrechnung_config": einvoice.XR_VERSION, "sha256": einvoice.XR_SHA256},
           "cases": cases}, open(OUT / "golden.json", "w"), indent=1, sort_keys=True, ensure_ascii=False)
v = sum(1 for c in cases.values() if c.get("valid")); inv = sum(1 for c in cases.values() if c.get("valid") is False); ie = sum(1 for c in cases.values() if c.get("input_error"))
print(f"{len(files)} files, {len(cases)} cases: valid={v} invalid={inv} input_error={ie}")
