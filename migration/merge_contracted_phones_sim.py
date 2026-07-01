#!/usr/bin/env python3
"""
Normalize MSISDNs from "Contracted 1PWR Team Phone number" CSV and reconcile
against migration/output/am_core_sim_cards.import.json.

Lesotho mobiles in the sheet are typically 8 digits (local); full MSISDN is
266 + 8 digits. Other digit lengths are passed through unchanged when already
international-length.

Writes:
  migration/output/contracted_phones_normalized.json
  migration/output/contracted_phones_sim_merge_report.json

Usage:
  python3 migration/merge_contracted_phones_sim.py \\
      [Spreadsheets/Contracted\\ 1PWR\\ Team\\ Phone\\ number\\(Sheet1\\).csv] \\
      [migration/output/am_core_sim_cards.import.json]
"""

from __future__ import annotations

import csv
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
OUTPUT_DIR = REPO / "migration" / "output"


def digits_only(raw: str | None) -> str:
    if raw is None:
        return ""
    return re.sub(r"\D+", "", str(raw).strip())


def normalize_msisdn(d: str) -> tuple[str | None, str]:
    """
    Return (normalized_digits, display_hint) or (None, '') if unusable.
    """
    if not d:
        return None, ""
    display = str(d).strip()
    if len(d) == 8:
        return "266" + d, display
    if len(d) == 11 and d.startswith("266"):
        return d, display
    if len(d) >= 10:
        return d, display
    return None, display


def load_sim_keys(path: Path) -> dict[str, dict]:
    """msisdn_normalized -> one representative record from import JSON."""
    if not path.is_file():
        return {}
    with open(path, encoding="utf-8") as f:
        rows = json.load(f)
    out: dict[str, dict] = {}
    for r in rows:
        k = (r.get("msisdn_normalized") or "").strip()
        if k and k not in out:
            out[k] = r
    return out


def find_header_row(reader: csv.reader) -> tuple[list[str], list[list[str]]]:
    """Return header cells and remaining rows."""
    rows = list(reader)
    for i, row in enumerate(rows):
        joined = ",".join(row).upper()
        if "SITE PHONE NUMBER" in joined or "SITE/NAME" in joined:
            return row, rows[i + 1 :]
    return [], rows


def parse_contracted_rows(header: list[str], data_rows: list[list[str]]) -> list[dict]:
    records: list[dict] = []
    # Map columns by header name (flexible)
    hlow = [c.strip().lower() if c else "" for c in header]

    def idx(*candidates: str) -> int:
        for c in candidates:
            try:
                return hlow.index(c.lower())
            except ValueError:
                continue
        return -1

    i_name = idx("site/name")
    i_site_phone = idx("site phone number")
    try:
        first_make = hlow.index("make")
    except ValueError:
        first_make = -1
    i_sec_site = idx("site")
    i_security = idx("security number")
    second_make = -1
    if first_make >= 0:
        for j in range(first_make + 1, len(hlow)):
            if hlow[j] == "make":
                second_make = j
                break

    if i_site_phone < 0:
        return records

    i_make_left = first_make
    i_make_right = second_make

    for row in data_rows:
        if not row or all(not (c or "").strip() for c in row):
            continue

        def cell(i: int) -> str:
            if i < 0 or i >= len(row):
                return ""
            return (row[i] or "").strip()

        label_left = cell(i_name) if i_name >= 0 else ""
        site_phone = digits_only(cell(i_site_phone))
        make_left = cell(i_make_left) if i_make_left >= 0 else ""

        sec_label = cell(i_sec_site) if i_sec_site >= 0 else ""
        security_raw = digits_only(cell(i_security)) if i_security >= 0 else ""
        make_right = cell(i_make_right) if i_make_right >= 0 else ""

        if site_phone:
            norm, disp = normalize_msisdn(site_phone)
            if norm:
                records.append(
                    {
                        "kind": "site_phone",
                        "label": label_left,
                        "msisdn_normalized": norm,
                        "msisdn_display": disp or site_phone,
                        "make": make_left,
                        "raw_digits": site_phone,
                    }
                )

        if security_raw:
            norm, disp = normalize_msisdn(security_raw)
            if norm:
                records.append(
                    {
                        "kind": "security_phone",
                        "label": sec_label or label_left,
                        "msisdn_normalized": norm,
                        "msisdn_display": disp or security_raw,
                        "make": make_right,
                        "raw_digits": security_raw,
                    }
                )

    return records


def main() -> int:
    default_csv = REPO / "Spreadsheets" / "Contracted 1PWR Team Phone number(Sheet1).csv"
    default_sim = OUTPUT_DIR / "am_core_sim_cards.import.json"

    csv_path = Path(sys.argv[1]) if len(sys.argv) > 1 else default_csv
    sim_path = Path(sys.argv[2]) if len(sys.argv) > 2 else default_sim

    if not csv_path.is_file():
        print(f"CSV not found: {csv_path}", file=sys.stderr)
        return 1

    sim_by_msisdn = load_sim_keys(sim_path)

    with open(csv_path, encoding="utf-8-sig", newline="") as f:
        rdr = csv.reader(f)
        header, data_rows = find_header_row(rdr)

    if not header:
        print("Could not find header row with SITE PHONE NUMBER.", file=sys.stderr)
        return 1

    records = parse_contracted_rows(header, data_rows)

    csv_keys = {r["msisdn_normalized"] for r in records}
    sim_keys = set(sim_by_msisdn.keys())

    in_both = sorted(csv_keys & sim_keys)
    csv_only = sorted(csv_keys - sim_keys)
    sim_only = sorted(sim_keys - csv_keys)

    enriched = []
    for r in records:
        k = r["msisdn_normalized"]
        m = sim_by_msisdn.get(k)
        enriched.append(
            {
                **r,
                "in_sim_import": m is not None,
                "sim_pool": (m or {}).get("pool"),
                "sim_person_assigned": (m or {}).get("person_assigned"),
            }
        )

    report = {
        "csv_path": str(csv_path),
        "sim_import_path": str(sim_path) if sim_path.is_file() else None,
        "counts": {
            "csv_phone_rows_expanded": len(records),
            "distinct_msisdn_in_csv": len(csv_keys),
            "distinct_msisdn_in_sim_import": len(sim_keys),
            "in_both": len(in_both),
            "csv_only": len(csv_only),
            "sim_import_only": len(sim_only),
        },
        "in_both_msisdns": in_both,
        "csv_only_msisdns": csv_only,
        "sim_import_only_msisdns_sample": sim_only[:80],
        "sim_import_only_note": "Full list omitted if long; use set(sim) - set(csv) offline.",
    }

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    out_norm = OUTPUT_DIR / "contracted_phones_normalized.json"
    out_report = OUTPUT_DIR / "contracted_phones_sim_merge_report.json"

    with open(out_norm, "w", encoding="utf-8") as f:
        json.dump(enriched, f, indent=2)

    with open(out_report, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)

    print(f"Wrote {len(enriched)} CSV-derived rows to {out_norm}")
    print(f"Wrote merge report to {out_report}")
    c = report["counts"]
    print(
        f"Distinct CSV: {c['distinct_msisdn_in_csv']} | SIM import: {c['distinct_msisdn_in_sim_import']} | "
        f"both: {c['in_both']} | CSV only: {c['csv_only']} | SIM only: {c['sim_import_only']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
