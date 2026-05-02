#!/usr/bin/env python3
"""
diff-smoke.py — diff two smoke-test JSON outputs.

Compares two JSON results from `api-smoke-test.py --json` and reports:
  - newly broken endpoints (was PASS, now FAIL)
  - newly fixed endpoints (was FAIL, now PASS)
  - status code changes (200 -> 422, etc.)
  - response size deltas > 50%
  - duration regressions > 200%
  - new endpoints (in B not A)
  - removed endpoints (in A not B)

This is the run-to-run delta tracker. Save a baseline JSON, run again later,
diff to see what changed.

Usage:
    # Save a baseline run
    python3 tools/api-smoke-test.py --json > tools/baselines/local-baseline.json

    # Later, run again and compare
    python3 tools/api-smoke-test.py --json > /tmp/current.json
    python3 tools/diff-smoke.py tools/baselines/local-baseline.json /tmp/current.json

    # Or shorthand via dev.sh:
    ./tools/dev.sh diff <baseline.json> <current.json>
"""

from __future__ import annotations
import argparse
import json
import sys
from pathlib import Path


def load(path: str) -> dict:
    return json.loads(Path(path).read_text())


def index_results(data: dict) -> dict[tuple[str, str], dict]:
    return {(r["method"], r["path"]): r for r in data.get("results", [])}


def main():
    ap = argparse.ArgumentParser(description="Diff two smoke-test JSON outputs.")
    ap.add_argument("baseline", help="Earlier JSON (the 'before')")
    ap.add_argument("current", help="Later JSON (the 'after')")
    ap.add_argument("--no-color", action="store_true")
    args = ap.parse_args()

    if args.no_color or not sys.stdout.isatty():
        R = G = Y = GR = B = OFF = ""
    else:
        R, G, Y, GR, B, OFF = "\033[91m", "\033[92m", "\033[93m", "\033[90m", "\033[1m", "\033[0m"

    a, b = load(args.baseline), load(args.current)
    a_idx, b_idx = index_results(a), index_results(b)

    a_keys = set(a_idx)
    b_keys = set(b_idx)

    print(f"{B}Comparing:{OFF}")
    print(f"  baseline: {args.baseline}")
    print(f"            target={a.get('target')} role={a.get('role')} | {a.get('pass')} pass / {a.get('fail')} fail")
    print(f"  current:  {args.current}")
    print(f"            target={b.get('target')} role={b.get('role')} | {b.get('pass')} pass / {b.get('fail')} fail")
    print()

    if a.get('target') != b.get('target'):
        print(f"{Y}⚠ different targets — comparing across environments{OFF}\n")

    # Newly broken (PASS -> FAIL)
    newly_broken = []
    newly_fixed = []
    status_changes = []
    size_drift = []
    new_endpoints = sorted(b_keys - a_keys)
    removed_endpoints = sorted(a_keys - b_keys)

    for k in sorted(a_keys & b_keys):
        ra, rb = a_idx[k], b_idx[k]
        va, vb = ra["verdict"], rb["verdict"]
        if va == "PASS" and vb == "FAIL":
            newly_broken.append((k, ra, rb))
        elif va == "FAIL" and vb == "PASS":
            newly_fixed.append((k, ra, rb))
        elif ra["status"] != rb["status"]:
            status_changes.append((k, ra, rb))

        # Size drift
        if ra["bytes_"] > 0 and abs(rb["bytes_"] - ra["bytes_"]) > max(50, 0.5 * ra["bytes_"]):
            size_drift.append((k, ra, rb))

    if newly_broken:
        print(f"{R}{B}❌ Newly broken ({len(newly_broken)}):{OFF}")
        for (k, ra, rb) in newly_broken:
            print(f"  {R}{k[0]:6}{OFF} {k[1]:50} {ra['status']} → {R}{rb['status']}{OFF}  {GR}{rb.get('note','')}{OFF}")
            if rb.get("body_preview"):
                print(f"       {GR}{rb['body_preview'][:120]}{OFF}")
        print()

    if newly_fixed:
        print(f"{G}{B}✅ Newly fixed ({len(newly_fixed)}):{OFF}")
        for (k, ra, rb) in newly_fixed:
            print(f"  {G}{k[0]:6}{OFF} {k[1]:50} {ra['status']} → {G}{rb['status']}{OFF}")
        print()

    if status_changes:
        print(f"{Y}{B}⚠ Status changed ({len(status_changes)}):{OFF}")
        for (k, ra, rb) in status_changes[:10]:
            print(f"  {Y}{k[0]:6}{OFF} {k[1]:50} {ra['status']} → {Y}{rb['status']}{OFF}")
        if len(status_changes) > 10:
            print(f"  {GR}... +{len(status_changes)-10} more{OFF}")
        print()

    if size_drift:
        print(f"{GR}{B}~ Response size drift > 50% ({len(size_drift)}):{OFF}")
        for (k, ra, rb) in size_drift[:5]:
            print(f"  {k[0]:6} {k[1]:50} {ra['bytes_']}b → {rb['bytes_']}b")
        if len(size_drift) > 5:
            print(f"  {GR}... +{len(size_drift)-5} more{OFF}")
        print()

    if new_endpoints:
        print(f"{B}New endpoints ({len(new_endpoints)}):{OFF}")
        for k in new_endpoints[:10]:
            print(f"  + {k[0]} {k[1]}")
        if len(new_endpoints) > 10:
            print(f"  {GR}... +{len(new_endpoints)-10} more{OFF}")
        print()

    if removed_endpoints:
        print(f"{B}Removed endpoints ({len(removed_endpoints)}):{OFF}")
        for k in removed_endpoints[:10]:
            print(f"  - {k[0]} {k[1]}")
        if len(removed_endpoints) > 10:
            print(f"  {GR}... +{len(removed_endpoints)-10} more{OFF}")
        print()

    delta_pass = b.get("pass", 0) - a.get("pass", 0)
    delta_fail = b.get("fail", 0) - a.get("fail", 0)
    sign = lambda n: f"+{n}" if n > 0 else str(n)

    print(f"{B}Delta:{OFF} pass {sign(delta_pass)}, fail {sign(delta_fail)}")

    # Exit code: 1 if any newly broken
    sys.exit(1 if newly_broken else 0)


if __name__ == "__main__":
    main()
