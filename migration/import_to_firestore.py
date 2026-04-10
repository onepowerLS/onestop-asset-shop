#!/usr/bin/env python3
"""
Import ETL output JSON into Firestore via REST API.

Uses the Firebase CLI's cached access token for authentication.
Imports collections in dependency order:
  1. pr_master_countries
  2. pr_master_locations
  3. pr_master_categories
  4. am_core_assets
  5. pr_master_requests

Usage:
    python3 migration/import_to_firestore.py [--dry-run] [--collection NAME]
"""

import json
import os
import sys
import time
import urllib.request
import urllib.error
import urllib.parse
from pathlib import Path

PROJECT_ID = "pr-system-4ea55"
BASE_URL = f"https://firestore.googleapis.com/v1/projects/{PROJECT_ID}/databases/(default)/documents"
OUTPUT_DIR = Path(__file__).resolve().parent / "output"

COLLECTIONS = [
    ("pr_master_countries",  "pr_master_countries.json",  "country_code"),
    ("pr_master_locations",  "pr_master_locations.json",  "location_code"),
    ("pr_master_categories", "pr_master_categories.json", "category_code"),
    ("am_core_assets",       "am_core_assets.json",       None),
    ("pr_master_requests",   "pr_master_requests.json",   "request_number"),
]


def get_access_token():
    config_path = os.path.expanduser("~/.config/configstore/firebase-tools.json")
    if not os.path.exists(config_path):
        print("ERROR: Firebase CLI config not found. Run 'firebase login' first.")
        sys.exit(1)
    with open(config_path) as f:
        cfg = json.load(f)
    token = cfg.get("tokens", {}).get("access_token", "")
    if not token:
        print("ERROR: No access_token in Firebase CLI config. Run 'firebase login' first.")
        sys.exit(1)
    return token


def php_to_firestore_value(val):
    if val is None:
        return {"nullValue": None}
    if isinstance(val, bool):
        return {"booleanValue": val}
    if isinstance(val, int):
        return {"integerValue": str(val)}
    if isinstance(val, float):
        return {"doubleValue": val}
    if isinstance(val, str):
        return {"stringValue": val}
    if isinstance(val, list):
        return {"arrayValue": {"values": [php_to_firestore_value(v) for v in val]}}
    if isinstance(val, dict):
        return {"mapValue": {"fields": {k: php_to_firestore_value(v) for k, v in val.items()}}}
    return {"stringValue": str(val)}


def to_firestore_doc(record):
    fields = {}
    for key, val in record.items():
        fields[key] = php_to_firestore_value(val)
    return {"fields": fields}


def create_document(token, collection, record, doc_id=None, retries=2):
    url = f"{BASE_URL}/{urllib.parse.quote(collection)}"
    if doc_id:
        url += f"?documentId={urllib.parse.quote(doc_id)}"

    payload = json.dumps(to_firestore_doc(record)).encode()

    for attempt in range(retries + 1):
        req = urllib.request.Request(url, data=payload, method="POST", headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        })
        try:
            resp = urllib.request.urlopen(req, timeout=15)
            result = json.loads(resp.read())
            name = result.get("name", "")
            created_id = name.rsplit("/", 1)[-1] if name else ""
            return True, created_id
        except urllib.error.HTTPError as e:
            body = e.read().decode()
            try:
                err = json.loads(body)
                msg = err.get("error", {}).get("message", body[:200])
            except Exception:
                msg = body[:200]
            if e.code == 409:
                return True, doc_id or "(exists)"
            return False, msg
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            if attempt < retries:
                time.sleep(1 * (attempt + 1))
                continue
            return False, f"Network error: {e}"


def batch_commit(token, writes, retries=2):
    """Use Firestore commit API for batch writes (up to 500 per request)."""
    url = f"https://firestore.googleapis.com/v1/projects/{PROJECT_ID}/databases/(default)/documents:commit"
    payload = json.dumps({"writes": writes}).encode()

    for attempt in range(retries + 1):
        req = urllib.request.Request(url, data=payload, method="POST", headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        })
        try:
            resp = urllib.request.urlopen(req, timeout=60)
            result = json.loads(resp.read())
            return True, len(result.get("writeResults", []))
        except urllib.error.HTTPError as e:
            body = e.read().decode()
            try:
                err = json.loads(body)
                msg = err.get("error", {}).get("message", body[:300])
            except Exception:
                msg = body[:300]
            return False, msg
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            if attempt < retries:
                time.sleep(2 * (attempt + 1))
                continue
            return False, f"Network error: {e}"


def make_write_op(collection, record, doc_id=None):
    """Build a single write operation for batch commit."""
    import uuid
    did = doc_id or str(uuid.uuid4())
    doc_path = f"projects/{PROJECT_ID}/databases/(default)/documents/{collection}/{did}"
    return {"update": {"name": doc_path, "fields": to_firestore_doc(record)["fields"]}}


def count_existing(token, collection):
    url = f"{BASE_URL}/{urllib.parse.quote(collection)}?pageSize=1"
    req = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        resp = urllib.request.urlopen(req, timeout=10)
        data = json.loads(resp.read())
        if data.get("documents"):
            return True
    except Exception:
        pass
    return False


def import_collection(token, collection, filename, id_field, dry_run=False, force=False):
    json_path = OUTPUT_DIR / filename
    if not json_path.exists():
        print(f"  SKIP: {filename} not found")
        return 0, 0

    with open(json_path) as f:
        records = json.load(f)

    if not isinstance(records, list):
        print(f"  SKIP: {filename} is not a JSON array")
        return 0, 0

    total = len(records)
    print(f"  File: {filename} ({total:,d} records)")

    if dry_run:
        print(f"  DRY RUN: would import {total:,d} records")
        return total, 0

    if not force:
        has_data = count_existing(token, collection)
        if has_data:
            print(f"  WARNING: {collection} already has data. Skipping to avoid duplicates.")
            print(f"  To reimport, use --force flag.")
            return 0, 0

    ok = 0
    fail = 0
    errors = []
    start = time.time()

    BATCH_SIZE = 200

    for batch_start in range(0, total, BATCH_SIZE):
        batch_end = min(batch_start + BATCH_SIZE, total)
        batch_records = records[batch_start:batch_end]

        writes = []
        for record in batch_records:
            doc_id = record.get(id_field) if id_field else None
            writes.append(make_write_op(collection, record, doc_id))

        success, result = batch_commit(token, writes)
        if success:
            ok += (batch_end - batch_start)
        else:
            fail += (batch_end - batch_start)
            if len(errors) < 5:
                errors.append(f"Batch {batch_start}-{batch_end}: {result}")

        elapsed = time.time() - start
        rate = ok / elapsed if elapsed > 0 else 0
        pct = (batch_end / total) * 100
        sys.stdout.write(f"\r    {batch_end:>5d}/{total} ({pct:.0f}%) -- {rate:.1f} docs/sec -- {ok} ok, {fail} fail")
        sys.stdout.flush()
        time.sleep(0.1)

    elapsed = time.time() - start
    print(f"\n    Done: {elapsed:.1f}s total                      ")

    if errors:
        print(f"  First errors:")
        for e in errors:
            print(f"    {e}")

    return ok, fail


def main():
    dry_run = "--dry-run" in sys.argv
    force = "--force" in sys.argv
    target = None
    for arg in sys.argv[1:]:
        if arg.startswith("--collection="):
            target = arg.split("=", 1)[1]
        elif not arg.startswith("--"):
            target = arg

    print("=" * 60)
    print("  Firestore Import")
    print("=" * 60)

    if dry_run:
        print("  MODE: Dry run (no writes)")

    token = get_access_token()
    print(f"  Token: ...{token[-8:]}")
    print()

    total_ok = 0
    total_fail = 0

    for collection, filename, id_field in COLLECTIONS:
        if target and collection != target:
            continue
        print(f"[{collection}]")
        ok, fail = import_collection(token, collection, filename, id_field, dry_run, force)
        total_ok += ok
        total_fail += fail
        print()

    print("-" * 40)
    print(f"  Total: {total_ok:,d} created, {total_fail:,d} failed")
    if total_fail == 0 and total_ok > 0:
        print("  All imports successful.")


if __name__ == "__main__":
    main()
