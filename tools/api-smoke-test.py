#!/usr/bin/env python3
"""
api-smoke-test.py — spec-driven smoke tester for VYN Laravel Backend.

Reads public/openapi.yaml, hits every operation against a Laravel server
(default http://localhost:8000), and reports per-endpoint pass/fail with
status, response size, and response preview on failures.

Path-independent: detects project root by walking up from this file looking
for composer.json. Run from any directory.

==============================================================================
QUICK START (Hassan / Claude — copy-paste):
==============================================================================
    # All read-only endpoints, owner role (default)
    python3 tools/api-smoke-test.py

    # Run as a different demo user
    python3 tools/api-smoke-test.py --role manager
    python3 tools/api-smoke-test.py --role customer
    python3 tools/api-smoke-test.py --role super_admin

    # Filter to a subset
    python3 tools/api-smoke-test.py --filter customers
    python3 tools/api-smoke-test.py --only "GET /api/staff"

    # Production target (will use prod creds if you set VYN_EMAIL / VYN_PASSWORD env vars)
    python3 tools/api-smoke-test.py --base https://admin.vynhq.com

    # Verbose: show response previews
    python3 tools/api-smoke-test.py --verbose

    # Machine-readable JSON output (for pipes / scripts)
    python3 tools/api-smoke-test.py --json > smoke.json

    # Markdown report
    python3 tools/api-smoke-test.py --report tools/smoke-report.md

    # Include WRITE methods (POST/PUT/PATCH/DELETE) — local only, may modify DB
    python3 tools/api-smoke-test.py --write

    # Combined common flow
    python3 tools/api-smoke-test.py --role manager --filter sales --verbose
==============================================================================

Auth strategy:
  - public ops    -> no headers
  - JWT-only      -> Authorization: Bearer <token>
  - JWT+tenant    -> Authorization + X-Tenant: <slug>

Verdicts:
  PASS -> 2xx, or expected 401/403/422/404 (e.g. customer hitting admin)
  FAIL -> 5xx, transport error, or public op rejected anonymous
  WARN -> unusual status code
  SKIP -> write methods (unless --write), or filtered out

Exit code: 0 if no failures, 1 otherwise. CI-friendly.
"""

from __future__ import annotations
import argparse
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import urllib.error
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Optional

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML not installed. Run: pip3 install pyyaml", file=sys.stderr)
    sys.exit(1)


# ---------- Demo accounts (kept in sync with VYN-Laravel-Backend/CLAUDE.md) ----------
DEMO_ACCOUNTS = {
    "super_admin":  ("admin@platform.com",         "password"),
    "salon_owner":  ("owner@glamour-salon.com",    "password"),
    "owner":        ("owner@glamour-salon.com",    "password"),  # alias
    "manager":      ("manager@glamour-salon.com",  "password"),
    "staff":        ("staff@glamour-salon.com",    "password"),
    "customer":     ("customer@glamour-salon.com", "password"),
}
DEFAULT_ROLE = "salon_owner"
DEFAULT_TENANT = "glamour-salon"


# ---------- ANSI colors ----------
class C:
    RED    = "\033[91m"
    GREEN  = "\033[92m"
    YELLOW = "\033[93m"
    BLUE   = "\033[94m"
    GRAY   = "\033[90m"
    BOLD   = "\033[1m"
    OFF    = "\033[0m"

    @classmethod
    def disable(cls):
        for k in dir(cls):
            if not k.startswith("_") and k.isupper():
                setattr(cls, k, "")


# ---------- Result dataclass ----------
@dataclass
class Result:
    method: str
    path: str
    expected_auth: str   # public | jwt | jwt+tenant
    status: int
    bytes_: int
    duration_ms: int
    verdict: str         # PASS | FAIL | SKIP | WARN
    note: str = ""
    body_preview: str = ""


# ---------- Helpers ----------
def find_project_root() -> Path:
    """Walk up from this file to find composer.json (Laravel project root)."""
    here = Path(__file__).resolve().parent
    for p in [here] + list(here.parents):
        if (p / "composer.json").exists():
            return p
    raise SystemExit("Could not find composer.json (Laravel project root) walking up from this script.")


def http_request(base: str, method: str, path: str, headers: dict, body: Optional[dict] = None, timeout: int = 10) -> tuple[int, bytes, int]:
    url = base.rstrip("/") + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, method=method.upper(), data=data, headers=headers)
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, resp.read(), int((time.time() - t0) * 1000)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), int((time.time() - t0) * 1000)
    except Exception as e:
        return 0, str(e).encode(), int((time.time() - t0) * 1000)


def login(base: str, email: str, password: str) -> Optional[str]:
    status, body, _ = http_request(
        base, "POST", "/api/login",
        {"Content-Type": "application/json", "Accept": "application/json"},
        {"email": email, "password": password},
    )
    if status != 200:
        print(f"{C.RED}Login failed:{C.OFF} HTTP {status}: {body[:200].decode(errors='replace')}", file=sys.stderr)
        return None
    try:
        d = json.loads(body)
        return (d.get("data") or {}).get("token") or d.get("access_token") or d.get("token")
    except Exception as e:
        print(f"{C.RED}Cannot parse login response:{C.OFF} {e}", file=sys.stderr)
        return None


def categorize_auth(op: dict) -> str:
    sec = op.get("security")
    params = op.get("parameters") or []
    has_tenant = any(
        (isinstance(p, dict) and p.get("name") == "X-Tenant") or
        (isinstance(p, dict) and (p.get("$ref") or "").endswith("XTenantHeader"))
        for p in params
    )
    if sec == []:
        return "public"
    return "jwt+tenant" if has_tenant else "jwt"


def substitute_path_params(path: str) -> str:
    return re.sub(r"\{[^}]+\}", "1", path)


def build_minimal_body(op: dict) -> Optional[dict]:
    rb = op.get("requestBody") or {}
    content = (rb.get("content") or {}).get("application/json") or {}
    schema = content.get("schema") or {}
    example = schema.get("example") or content.get("example")
    if isinstance(example, dict):
        return example
    props = schema.get("properties") or {}
    if not props:
        return {}
    out = {}
    for k, v in props.items():
        if isinstance(v, dict) and "example" in v and v["example"] is not None:
            out[k] = v["example"]
    return out


def matches_only(op_label: str, only_filter: str) -> bool:
    """--only 'GET /api/staff' or '/api/staff' or 'staff' all match."""
    return only_filter.lower() in op_label.lower()


# ---------- Main run ----------
def run(args) -> list[Result]:
    spec_path = Path(args.spec) if Path(args.spec).is_absolute() else find_project_root() / args.spec

    if not spec_path.exists():
        raise SystemExit(f"Spec not found at {spec_path}")

    with open(spec_path) as f:
        spec = yaml.safe_load(f)

    # Resolve role -> credentials
    role = args.role.lower()
    if role not in DEMO_ACCOUNTS:
        raise SystemExit(f"Unknown role '{role}'. Available: {', '.join(sorted(set(DEMO_ACCOUNTS)))}")

    email = os.environ.get("VYN_EMAIL") or DEMO_ACCOUNTS[role][0]
    password = os.environ.get("VYN_PASSWORD") or DEMO_ACCOUNTS[role][1]

    out = sys.stderr if args.json else sys.stdout
    print(f"{C.BOLD}[+] Smoke test starting{C.OFF}", file=out)
    print(f"    base:    {args.base}", file=out)
    print(f"    spec:    {spec_path.relative_to(Path.cwd()) if spec_path.is_relative_to(Path.cwd()) else spec_path}", file=out)
    print(f"    role:    {role} ({email})", file=out)
    print(f"    tenant:  {args.tenant}", file=out)
    if args.filter:
        print(f"    filter:  {args.filter}", file=out)
    if args.only:
        print(f"    only:    {args.only}", file=out)
    if args.write:
        print(f"    {C.YELLOW}!! write mode ON — POST/PUT/PATCH/DELETE will execute{C.OFF}", file=out)
    print(file=out)

    token = login(args.base, email, password)
    if not token:
        raise SystemExit(2)
    print(f"{C.GREEN}[+] Logged in.{C.OFF} Token: {token[:30]}...\n", file=out)

    results: list[Result] = []
    write_methods = {"POST", "PUT", "PATCH", "DELETE"}
    paths = spec.get("paths") or {}

    for path, methods in paths.items():
        for method, op in methods.items():
            if method.upper() not in {"GET", "POST", "PUT", "PATCH", "DELETE"}:
                continue
            if not isinstance(op, dict):
                continue

            method_u = method.upper()
            label = f"{method_u} {path}"

            if args.filter and args.filter not in path:
                continue
            if args.only and not matches_only(label, args.only):
                continue

            auth = categorize_auth(op)
            headers = {"Accept": "application/json"}
            if auth in ("jwt", "jwt+tenant"):
                headers["Authorization"] = f"Bearer {token}"
            if auth == "jwt+tenant":
                headers["X-Tenant"] = args.tenant

            if method_u in write_methods and not args.write:
                results.append(Result(method_u, path, auth, 0, 0, 0, "SKIP", "write op (--write to enable)"))
                continue

            real_path = substitute_path_params(path)
            body = build_minimal_body(op) if method_u in {"POST", "PUT", "PATCH"} else None
            if body is not None:
                headers["Content-Type"] = "application/json"

            status, content, dur = http_request(args.base, method_u, real_path, headers, body)
            preview = content[:240].decode(errors="replace").replace("\n", " ")

            # Verdict
            if status == 0:
                verdict, note = "FAIL", "transport error"
            elif status >= 500:
                verdict, note = "FAIL", "server error"
            elif status == 401 and auth == "public":
                verdict, note = "FAIL", "public op rejected anonymous"
            elif status == 404 and "{" in path:
                verdict, note = "PASS", "404 (id=1 may not exist)"
            elif status in (200, 201, 204):
                verdict, note = "PASS", ""
            elif status in (401, 403, 422):
                verdict, note = "PASS", f"expected {status} for role {role}"
            else:
                verdict, note = "WARN", "unusual status"

            r = Result(method_u, path, auth, status, len(content), dur, verdict, note,
                       preview if verdict in ("FAIL", "WARN") or args.verbose else "")
            results.append(r)

            if (args.verbose or verdict == "FAIL") and not args.json:
                color = {"PASS": C.GREEN, "FAIL": C.RED, "WARN": C.YELLOW, "SKIP": C.GRAY}[verdict]
                print(f"  {color}{verdict:4}{C.OFF} {method_u:6} {path:55} {color}->{C.OFF} {status} {len(content):>6}b {dur:>4}ms {C.GRAY}{note}{C.OFF}")
                if verdict == "FAIL" and preview:
                    print(f"       {C.GRAY}body:{C.OFF} {preview[:180]}")

    return results


# ---------- Output formatters ----------
def render_summary_terminal(results: list[Result], role: str):
    n = len(results)
    pas = sum(1 for r in results if r.verdict == "PASS")
    fail = sum(1 for r in results if r.verdict == "FAIL")
    warn = sum(1 for r in results if r.verdict == "WARN")
    skip = sum(1 for r in results if r.verdict == "SKIP")

    bar = lambda ct, total: "█" * int(40 * ct / max(1, total))

    print(f"\n{C.BOLD}=== Summary ({role}) ==={C.OFF}")
    print(f"  {C.GREEN}PASS{C.OFF}  {pas:4d} {bar(pas, n)}")
    if fail:
        print(f"  {C.RED}FAIL{C.OFF}  {fail:4d} {bar(fail, n)}")
    if warn:
        print(f"  {C.YELLOW}WARN{C.OFF}  {warn:4d} {bar(warn, n)}")
    print(f"  {C.GRAY}SKIP{C.OFF}  {skip:4d} {bar(skip, n)}")
    print(f"  total {n}")

    if fail:
        print(f"\n{C.RED}{C.BOLD}Failures:{C.OFF}")
        for r in results:
            if r.verdict == "FAIL":
                print(f"  {C.RED}✗{C.OFF} {r.method:6} {r.path}  ({r.status}) {C.GRAY}{r.note}{C.OFF}")
                if r.body_preview:
                    print(f"       {C.GRAY}{r.body_preview[:160]}{C.OFF}")


def render_summary_markdown(results: list[Result], role: str, base: str) -> str:
    n = len(results)
    pas = sum(1 for r in results if r.verdict == "PASS")
    fail = sum(1 for r in results if r.verdict == "FAIL")
    warn = sum(1 for r in results if r.verdict == "WARN")
    skip = sum(1 for r in results if r.verdict == "SKIP")

    out = []
    out.append("# API Smoke Test Report\n")
    out.append(f"- **Target**: {base}")
    out.append(f"- **Role**: {role}")
    out.append(f"- **Total operations**: {n}")
    out.append(f"- **PASS**: {pas} ({100*pas//max(1,n)}%)")
    out.append(f"- **FAIL**: {fail}")
    out.append(f"- **WARN**: {warn}")
    out.append(f"- **SKIP**: {skip}\n")

    if fail:
        out.append("## ❌ Failures\n")
        out.append("| Method | Path | Auth | Status | Note | Response |")
        out.append("|---|---|---|---:|---|---|")
        for r in results:
            if r.verdict == "FAIL":
                preview = r.body_preview[:80].replace("|", "\\|") if r.body_preview else ""
                out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.note} | `{preview}` |")
        out.append("")

    if warn:
        out.append("## ⚠️ Warnings\n")
        out.append("| Method | Path | Auth | Status | Note |")
        out.append("|---|---|---|---:|---|")
        for r in results:
            if r.verdict == "WARN":
                out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.note} |")
        out.append("")

    out.append("## ✅ Passing\n")
    out.append("| Method | Path | Auth | Status | Bytes | ms |")
    out.append("|---|---|---|---:|---:|---:|")
    for r in results:
        if r.verdict == "PASS":
            out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.bytes_} | {r.duration_ms} |")

    return "\n".join(out)


def render_summary_json(results: list[Result], role: str, base: str) -> str:
    n = len(results)
    summary = {
        "target": base,
        "role": role,
        "total": n,
        "pass": sum(1 for r in results if r.verdict == "PASS"),
        "fail": sum(1 for r in results if r.verdict == "FAIL"),
        "warn": sum(1 for r in results if r.verdict == "WARN"),
        "skip": sum(1 for r in results if r.verdict == "SKIP"),
        "results": [asdict(r) for r in results],
    }
    return json.dumps(summary, indent=2)


# ---------- Entry ----------
def main():
    ap = argparse.ArgumentParser(
        description="Spec-driven smoke tester for VYN Laravel Backend. Reads public/openapi.yaml and hits every endpoint.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="See module docstring for examples. Exit 0 if no failures, 1 otherwise.",
    )
    ap.add_argument("--base", default="http://localhost:8000",
                    help="Server base URL (default: http://localhost:8000)")
    ap.add_argument("--spec", default="public/openapi.yaml",
                    help="OpenAPI spec path, relative to project root (default: public/openapi.yaml)")
    ap.add_argument("--role", default=DEFAULT_ROLE,
                    help=f"Demo role to authenticate as. Choices: {', '.join(sorted(set(DEMO_ACCOUNTS)))} (default: {DEFAULT_ROLE})")
    ap.add_argument("--tenant", default=DEFAULT_TENANT,
                    help=f"Tenant slug for X-Tenant header (default: {DEFAULT_TENANT})")
    ap.add_argument("--filter", default=None,
                    help="Substring filter on path (e.g. 'customers')")
    ap.add_argument("--only", default=None,
                    help="Match a single endpoint, e.g. 'GET /api/staff' or 'staff'")
    ap.add_argument("--write", action="store_true",
                    help="Include POST/PUT/PATCH/DELETE (will modify DB — local only)")
    ap.add_argument("--verbose", action="store_true",
                    help="Show every request, including passes")
    ap.add_argument("--report", default=None,
                    help="Write markdown report to this path")
    ap.add_argument("--json", action="store_true",
                    help="Emit JSON summary to stdout (suppresses other output)")
    ap.add_argument("--no-color", action="store_true",
                    help="Disable ANSI colors (useful in CI/logs)")
    args = ap.parse_args()

    if args.no_color or not sys.stdout.isatty() or args.json:
        C.disable()

    results = run(args)

    role_label = args.role
    if args.json:
        print(render_summary_json(results, role_label, args.base))
    else:
        render_summary_terminal(results, role_label)

    if args.report:
        Path(args.report).write_text(render_summary_markdown(results, role_label, args.base))
        if not args.json:
            print(f"\n{C.GRAY}[+] Report written to {args.report}{C.OFF}")

    fail = sum(1 for r in results if r.verdict == "FAIL")
    sys.exit(1 if fail > 0 else 0)


if __name__ == "__main__":
    main()
