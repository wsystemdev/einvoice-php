"""Generate parity fixtures: curated invoices from the W System engine's own tests plus seeded random variants.
Each fixture = {"name", "format", "input"}. Run with the vatcheck venv; output tests/fixtures/inputs.json."""
import copy, json, random, sys, os

SAMPLE = {"format": "xrechnung", "invoice_number": "INV-2026-001", "issue_date": "2026-10-18", "due_date": "2026-11-17", "delivery_date": "2026-10-18", "currency": "EUR",
    "buyer_reference": "04011000-12345-34",
    "seller": {"name": "Muster GmbH", "vat_id": "DE123456789", "street": "Hauptstr. 1", "city": "Berlin", "postal_code": "10115", "country": "DE",
               "email": "billing@muster.de", "phone": "+49 30 1234", "contact_name": "Anna Muster"},
    "buyer": {"name": "Kunde AG", "vat_id": "DE811193231", "street": "Ring 2", "city": "Muenchen", "postal_code": "80331", "country": "DE", "email": "ap@kunde.de"},
    "lines": [{"description": "Consulting", "quantity": 10, "unit": "HUR", "unit_price": 95.5, "vat_rate": 19},
              {"description": "Book", "quantity": 2, "unit_price": 12.99, "vat_rate": 7}],
    "payment": {"iban": "DE89370400440532013000", "terms": "30 days net"}}
SK = {"format": "peppol-bis3", "invoice_number": "SK-1", "issue_date": "2026-10-19", "currency": "EUR", "buyer_reference": "PO-1",
      "seller": {"name": "Predajca s.r.o.", "vat_id": "SK2023489446", "street": "Hlavna 1", "city": "Bratislava", "postal_code": "81101", "country": "SK"},
      "buyer": {"name": "Kupujuci a.s.", "vat_id": "SK2020317068", "street": "Mlynska 2", "city": "Kosice", "postal_code": "04001", "country": "SK"},
      "lines": [{"description": "Consulting", "quantity": 2, "unit_price": 100, "vat_rate": 23}],
      "payment": {"iban": "SK3112000000198742637541"}}
NO = {"format": "peppol-bis3", "invoice_number": "NO-1", "issue_date": "2027-01-04", "due_date": "2027-02-03",
      "currency": "NOK", "buyer_reference": "PO-1", "delivery_date": "2027-01-04",
      "seller": {"name": "Selger AS", "vat_id": "NO310001007MVA", "street": "Karl Johans gate 1", "city": "Oslo", "postal_code": "0154", "country": "NO", "legal_form": "AS"},
      "buyer": {"name": "Kjoper AS", "vat_id": "NO310001015MVA", "street": "Bryggen 2", "city": "Bergen", "postal_code": "5003", "country": "NO"},
      "lines": [{"description": "Konsulent", "quantity": 1, "unit": "C62", "unit_price": 1000, "vat_rate": 25}]}
FR = {"format": "en16931", "invoice_number": "F-1", "issue_date": "2026-10-20", "due_date": "2026-11-19", "currency": "EUR", "buyer_reference": "BC-1",
      "seller": {"name": "Exemple Logiciel SAS", "vat_id": "FR11123456782", "company_id": "123456782", "company_id_scheme": "0002",
                 "street": "10 rue de Rivoli", "city": "Paris", "postal_code": "75001", "country": "FR"},
      "buyer": {"name": "Client Exemple SARL", "vat_id": "FR14987654324", "company_id": "987654324", "company_id_scheme": "0002",
                "street": "5 quai Saint-Antoine", "city": "Lyon", "postal_code": "69002", "country": "FR"},
      "lines": [{"description": "Abonnement", "quantity": 1, "unit": "C62", "unit_price": 150, "vat_rate": 20}],
      "payment": {"iban": "FR7630006000011234567890189", "terms": "30 jours"}}
CN = {"type_code": "381", "preceding_invoice": "INV-2026-000", "preceding_invoice_date": "2026-10-01"}
AC = {"allowances": [{"amount": 10, "reason": "Treuerabatt", "reason_code": "95", "vat_rate": 19}],
      "charges": [{"amount": 4.9, "reason": "Versand", "reason_code": "FC", "vat_category": "S", "vat_rate": 7}],
      "lines": SAMPLE["lines"] + [{"description": "Licence", "quantity": 3, "unit_price": 100, "vat_rate": 19, "seller_item_id": "LIC-1",
                                   "item_description": "Annual licence", "allowances": [{"percent": 10, "base_amount": 300, "reason_code": "95"}],
                                   "charges": [{"amount": 2, "reason": "Setup"}]}]}
UBL = ["en16931", "xrechnung", "peppol-bis3"]
CII = ["cii", "xrechnung-cii", "zugferd", "factur-x", "zugferd-xrechnung"]
ALL = UBL + CII

out = []
def add(name, fmt, inp):
    j = copy.deepcopy(inp); j["format"] = fmt
    out.append({"name": name, "format": fmt, "input": j})

for fmt in ALL:
    add(f"sample-{fmt}", fmt, SAMPLE)
    add(f"sample-cn-{fmt}", fmt, {**SAMPLE, **CN})
    add(f"sample-ac-{fmt}", fmt, {**SAMPLE, **AC})
    add(f"sample-cn-ac-{fmt}", fmt, {**SAMPLE, **AC, **CN})
    add(f"fr-{fmt}", fmt, FR)
for fmt in ("peppol-bis3", "en16931", "cii"):
    add(f"sk-{fmt}", fmt, SK); add(f"no-{fmt}", fmt, NO)

# the engine's shop-order fixtures (vatcheck tests/fixtures/shop_orders_b253.json), every format
SHOP = json.load(open(os.path.join(os.path.dirname(__file__), "..", "..", "tests", "fixtures", "shop_orders_b253.json")))
for case, inv in SHOP.items():
    if case.startswith("_") or not isinstance(inv, dict):
        continue
    for fmt in ALL:
        add(f"shop-{case}-{fmt}", fmt, inv)

# edge cases
E = copy.deepcopy(SAMPLE)
add("reverse-charge-xrechnung", "xrechnung", {**E, "buyer": {**E["buyer"], "country": "AT", "vat_id": "ATU12345678"}, "delivery_country": "AT",
    "lines": [{"description": "Service", "quantity": 1, "unit_price": 500, "vat_category": "AE"}]})
add("reverse-charge-cii", "xrechnung-cii", out[-1]["input"])
add("ic-supply-k", "en16931", {**E, "buyer": {**E["buyer"], "country": "FR", "vat_id": "FR14987654324"}, "delivery_country": "fr",
    "lines": [{"description": "Goods", "quantity": 5, "unit": "PK", "unit_price": 20, "vat_category": "K"}]})
add("ic-supply-k-cii", "cii", out[-1]["input"])
add("export-g", "en16931", {**E, "lines": [{"description": "Export", "quantity": 1, "unit_price": 1000, "vat_category": "G"}]})
add("zero-z-mixed", "cii", {**E, "lines": E["lines"] + [{"description": "Zero", "quantity": 1, "unit_price": 3, "vat_category": "Z", "vat_rate": 0}]})
add("exempt-e-custom", "xrechnung", {**E, "lines": [{"description": "Medical", "quantity": 1, "unit_price": 80, "vat_category": "E"}],
    "vat_exemption_reason": {"E": "Steuerfrei nach § 4 Nr. 14 UStG"}, "vat_exemption_code": {"E": "VATEX-EU-132-1B"}})
add("exempt-e-custom-cii", "zugferd-xrechnung", out[-1]["input"])
add("not-subject-o", "en16931", {**E, "seller": {**E["seller"]}, "lines": [{"description": "Out of scope", "quantity": 1, "unit_price": 50, "vat_category": "O"}]})
add("prepaid-rounding", "xrechnung", {**E, "prepaid_amount": "100.005", "lines": [{"description": "x & <y>", "quantity": "0.333", "unit_price": "3.335", "vat_rate": "19.0"},
    {"description": "ÄÖÜ ß € łódź", "quantity": -1, "unit_price": "0.5", "vat_rate": 19}]})
add("prepaid-rounding-cii", "factur-x", out[-1]["input"])
add("tax-point-note-order", "cii", {**E, "tax_point_date": "2026-10-17", "note": "Danke!", "order_reference": "PO-77", "buyer_reference": None})
add("tax-point-note-order-ubl", "en16931", out[-1]["input"])
add("peppol-endpoint-full", "peppol-bis3", {**E, "seller": {**E["seller"], "endpoint_id": "iso6523-actorid-upis::0208:0685660237"},
    "buyer": {**E["buyer"], "endpoint_id": "buyer@example.com"}})
add("peppol-derive-schemes", "peppol-bis3", {**E, "seller": {**E["seller"], "vat_id": "FI12345678", "country": "FI"},
    "buyer": {**E["buyer"], "vat_id": "SE556677889901", "country": "SE"}})
add("peppol-dk-be", "peppol-bis3", {**E, "seller": {**E["seller"], "vat_id": "DK12345678", "country": "DK"},
    "buyer": {**E["buyer"], "vat_id": "BE 0685.660.237".replace(".", ""), "country": "BE"}})
add("bank-bic-means", "cii", {**E, "payment": {"iban": "DE89 3704 0044 0532 0130 00", "bic": "COBADEFFXXX", "account_name": "Muster GmbH", "reference": "RF18 5390 0754 7034"}})
add("bank-bic-means-ubl", "xrechnung", out[-1]["input"])
add("means-code-only", "xrechnung", {**E, "payment": {"means_code": "30", "terms": "Sofort"}})
add("legal-form-trading", "cii", {**E, "seller": {**E["seller"], "legal_form": "GmbH, HRB 1234 Berlin", "trading_name": "Muster Shop", "company_id": "HRB1234", "tax_number": "12/345/67890"}})
add("legal-form-trading-ubl", "en16931", out[-1]["input"])
add("pct-allowance-doc-multi", "en16931", {**E, "allowances": [{"percent": 3, "base_amount": 955, "vat_rate": 19, "reason": "Skonto"}],
    "charges": [{"amount": 1.235, "vat_category": "S", "vat_rate": 7}]})
add("pct-allowance-doc-multi-cii", "zugferd", out[-1]["input"])
# input errors (must give the same message)
bad = [("err-no-lines", {**E, "lines": []}), ("err-date", {**E, "issue_date": "18.10.2026"}), ("err-cur", {**E, "currency": "EURO"}),
       ("err-neg-price", {**E, "lines": [{"description": "x", "unit_price": -1, "vat_rate": 19}]}),
       ("err-no-rate", {**E, "lines": [{"description": "x", "unit_price": 1}]}),
       ("err-nan", {**E, "lines": [{"description": "x", "unit_price": "abc", "vat_rate": 19}]}),
       ("err-ac-multi", {**E, "allowances": [{"amount": 1}]}),
       ("err-ac-neg", {**E, "charges": [{"amount": -1, "vat_rate": 19}]}),
       ("err-seller-name", {**E, "seller": {"country": "DE"}}),
       ("err-peppol-endpoint", {**E, "format": "peppol-bis3", "seller": {"name": "X", "country": "US"}}),
       ("err-endpoint-scheme", {**E, "seller": {**E["seller"], "endpoint_id": "0208:123", "endpoint_scheme": "0088"}}),
       ("err-format", {**E, "format": "pdf"})]
for n, j in bad:
    for fmt in ("xrechnung", "xrechnung-cii") if n not in ("err-format", "err-peppol-endpoint") else (j["format"] if n == "err-peppol-endpoint" else "pdf",):
        add(f"{n}-{fmt}", fmt, j)

# seeded random invoices
rnd = random.Random(int(sys.argv[1]) if len(sys.argv) > 1 else 20261125)
CATS = [("S", [19, 7, 20, 21, 23, 25, 27, 5.5, 10, 0.5]), ("Z", [0]), ("E", [0]), ("AE", [0]), ("K", [0]), ("G", [0]), ("O", [None])]
UNITS = ["C62", "HUR", "DAY", "KGM", "XPK", "PK", "MTR", "LS", None]
def money():
    return rnd.choice([rnd.randint(0, 5000), round(rnd.uniform(0, 999), rnd.choice([0, 1, 2, 3, 4])), str(round(rnd.uniform(0, 999), rnd.choice([2, 3, 5])))])
N = int(sys.argv[2]) if len(sys.argv) > 2 else 300
for k in range(N):
    j = copy.deepcopy(SAMPLE)
    j["invoice_number"] = f"R-{k:04d}"
    j["currency"] = rnd.choice(["EUR", "eur", "HUF", "PLN", "CZK", None])
    cat, rates = rnd.choice(CATS[:2]) if rnd.random() < 0.7 else rnd.choice(CATS)
    lines = []
    for i in range(rnd.randint(1, 6)):
        c2, r2 = (cat, rates) if rnd.random() < 0.6 or cat != "S" else rnd.choice(CATS[:2])
        ln = {"description": rnd.choice(["Beratung", "Widget <A&B>", "Lizenz \"pro\"", "Fracht", "Ñandú café", "x" * 250]),
              "quantity": rnd.choice([1, 2, 3, 0.5, "1.25", 10, -1, 1000, "0.001"]), "unit_price": money()}
        rv = rnd.choice(r2)
        if c2 != "S":
            ln["vat_category"] = rnd.choice([c2, c2.lower()])
        if rv is not None and (c2 == "S" or rnd.random() < 0.5):
            ln["vat_rate"] = rv
        u = rnd.choice(UNITS)
        if u:
            ln["unit"] = u
        if rnd.random() < 0.2:
            ln["seller_item_id"] = f"SKU-{i}"
        if rnd.random() < 0.2:
            ln["item_description"] = "Detail " + str(i)
        if rnd.random() < 0.2:
            ln["allowances"] = [{"amount": rnd.choice([1, 0.5, "2.345"]), "reason": "Nachlass"}]
        if rnd.random() < 0.1:
            ln["charges"] = [{"percent": 2, "base_amount": 50, "reason_code": "ABK"}]
        lines.append(ln)
    j["lines"] = lines
    if rnd.random() < 0.25:
        j["allowances"] = [{"amount": rnd.choice([1, 2.5, "0.015"]), "vat_category": lines[0].get("vat_category", "S"), "vat_rate": lines[0].get("vat_rate", 0)}]
    if rnd.random() < 0.15:
        j["charges"] = [{"amount": 5, "reason": "Porto", "vat_category": lines[0].get("vat_category", "S"), "vat_rate": lines[0].get("vat_rate", 0)}]
    if rnd.random() < 0.3:
        j.update(CN)
    if rnd.random() < 0.2:
        j["prepaid_amount"] = rnd.choice([10, "0.01", 0])
    if rnd.random() < 0.2:
        j["buyer"]["country"] = rnd.choice(["at", "FR", "NL", "PL", "HU"])
        j["delivery_country"] = j["buyer"]["country"]
    if rnd.random() < 0.2:
        del j["payment"]["iban"]
    if rnd.random() < 0.2:
        j["seller"]["company_id"] = "HRB 99"; j["seller"]["company_id_scheme"] = rnd.choice(["0204", None])
    add(f"rnd-{k:04d}", rnd.choice(ALL), j)

json.dump(out, open(sys.argv[3] if len(sys.argv) > 3 else "inputs.json", "w"), ensure_ascii=False, indent=0)
print(len(out), "fixtures")
