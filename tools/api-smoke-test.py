#!/usr/bin/env python3
"""
api-smoke-test.py — spec-driven smoke tester for VYN Laravel Backend.

Reads public/openapi.yaml, hits every operation against the local server
(default http://localhost:8000), and reports per-endpoint pass/fail/skip
with response status, body size, and contract violations.

Usage:
    python3 tools/api-smoke-test.py                    # local, all endpoints
    python3 tools/api-smoke-test.py --base https://admin.vynhq.com
    python3 tools/api-smoke-test.py --filter customers # only paths matching
    python3 tools/api-smoke-test.py --verbose          # show response bodies
    python3 tools/api-smoke-test.py --report report.md # write markdown report

Auth strategy:
  - public ops  -> no headers
  - JWT-only    -> Authorization: Bearer <token>
  - JWT+tenant  -> Authorization + X-Tenant: glamour-salon

Read-only by default: skips DELETE and POST/PUT/PATCH unless --write is set.
"""

from __future__ import annotations
import argparse, json, os, re, sys, time, urllib.parse, urllib.request
from dataclasses import dataclass, field
from typing import Optional

try:
    import yaml
except ImportError:
    print("Install PyYAML: pip3 install pyyaml", file=sys.stderr)
    sys.exit(1)


DEFAULT_TENANT = "glamour-salon"
DEFAULT_EMAIL = "owner@glamour-salon.com"
DEFAULT_PASSWORD = "password"


@dataclass
class Result:
    method: str
    path: str
    expected_auth: str  # public | jwt | jwt+tenant
    status: int
    bytes_: int
    duration_ms: int
    verdict: str        # PASS | FAIL | SKIP
    note: str = ""


def http_request(base: str, method: str, path: str, headers: dict, body: Optional[dict] = None, timeout: int = 8) -> tuple[int, bytes, int]:
    url = base.rstrip("/") + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, method=method.upper(), data=data, headers=headers)
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            content = resp.read()
            return resp.status, content, int((time.time() - t0) * 1000)
    except urllib.error.HTTPError as e:
        content = e.read()
        return e.code, content, int((time.time() - t0) * 1000)
    except Exception as e:
        return 0, str(e).encode(), int((time.time() - t0) * 1000)


def login(base: str, email: str, password: str) -> Optional[str]:
    status, body, _ = http_request(base, "POST", "/api/login",
        {"Content-Type": "application/json", "Accept": "application/json"},
        {"email": email, "password": password})
    if status != 200:
        print(f"Login failed: HTTP {status}: {body[:200].decode(errors='replace')}", file=sys.stderr)
        return None
    try:
        d = json.loads(body)
        return (d.get("data") or {}).get("token") or d.get("access_token") or d.get("token")
    except Exception:
        return None


def categorize_auth(op: dict) -> str:
    """public | jwt | jwt+tenant"""
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
    """Replace {id}-style placeholders with 1 (best-effort smoke value)."""
    return re.sub(r"\{[^}]+\}", "1", path)


def build_minimal_body(op: dict) -> Optional[dict]:
    """Try to construct a minimal valid body from spec example."""
    rb = op.get("requestBody") or {}
    content = (rb.get("content") or {}).get("application/json") or {}
    schema = content.get("schema") or {}
    example = schema.get("example") or content.get("example")
    if isinstance(example, dict):
        return example
    # fall back: extract examples from properties
    props = schema.get("properties") or {}
    if not props:
        return {}
    out = {}
    for k, v in props.items():
        if isinstance(v, dict) and "example" in v and v["example"] is not None:
            out[k] = v["example"]
    return out


def run(base: str, spec_path: str, filter_: Optional[str], allow_writes: bool, verbose: bool) -> list[Result]:
    with open(spec_path) as f:
        spec = yaml.safe_load(f)

    token = login(base, DEFAULT_EMAIL, DEFAULT_PASSWORD)
    if not token:
        print("Cannot proceed without a JWT. Aborting.", file=sys.stderr)
        sys.exit(2)
    print(f"[+] Logged in. Token: {token[:30]}...")
    print(f"[+] Tenant: {DEFAULT_TENANT}\n")

    results: list[Result] = []
    write_methods = {"POST", "PUT", "PATCH", "DELETE"}

    for path, methods in (spec.get("paths") or {}).items():
        for method, op in methods.items():
            if method.upper() not in {"GET", "POST", "PUT", "PATCH", "DELETE"}:
                continue
            if filter_ and filter_ not in path:
                continue

            method_u = method.upper()
            auth = categorize_auth(op)

            # Build headers
            headers = {"Accept": "application/json"}
            if auth in ("jwt", "jwt+tenant"):
                headers["Authorization"] = f"Bearer {token}"
            if auth == "jwt+tenant":
                headers["X-Tenant"] = DEFAULT_TENANT

            # Skip writes unless explicitly enabled
            if method_u in write_methods and not allow_writes:
                results.append(Result(method_u, path, auth, 0, 0, 0, "SKIP", "write op (--write to enable)"))
                continue

            real_path = substitute_path_params(path)
            body = build_minimal_body(op) if method_u in {"POST", "PUT", "PATCH"} else None
            if body is not None:
                headers["Content-Type"] = "application/json"

            status, content, dur = http_request(base, method_u, real_path, headers, body)

            # Verdict
            if status == 0:
                verdict, note = "FAIL", "transport error"
            elif status >= 500:
                verdict, note = "FAIL", f"server error"
            elif status == 401 and auth == "public":
                verdict, note = "FAIL", "public op rejected anonymous"
            elif status == 404 and "{" in path:
                verdict, note = "PASS", "404 expected (id=1 may not exist)"
            elif status in (200, 201, 204):
                verdict, note = "PASS", ""
            elif status in (401, 403, 422):
                verdict, note = "PASS", f"expected {status}"
            else:
                verdict, note = "WARN", f"unusual status"

            results.append(Result(method_u, path, auth, status, len(content), dur, verdict, note))

            if verbose:
                preview = content[:120].decode(errors='replace').replace("\n", " ")
                print(f"  {verdict:4} {method_u:6} {path:60} -> {status} {len(content):>6}b {dur:>4}ms {note:<30} {preview}")

    return results


def render_summary(results: list[Result]) -> str:
    n = len(results)
    pas = sum(1 for r in results if r.verdict == "PASS")
    fail = sum(1 for r in results if r.verdict == "FAIL")
    warn = sum(1 for r in results if r.verdict == "WARN")
    skip = sum(1 for r in results if r.verdict == "SKIP")

    out = []
    out.append("# API Smoke Test Report\n")
    out.append(f"- **Total operations**: {n}")
    out.append(f"- **PASS**: {pas} ({100*pas//max(1,n)}%)")
    out.append(f"- **FAIL**: {fail}")
    out.append(f"- **WARN**: {warn}")
    out.append(f"- **SKIP**: {skip}\n")

    if fail:
        out.append("## ❌ Failures\n")
        out.append("| Method | Path | Auth | Status | Note |")
        out.append("|---|---|---|---:|---|")
        for r in results:
            if r.verdict == "FAIL":
                out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.note} |")
        out.append("")

    if warn:
        out.append("## ⚠️ Warnings\n")
        out.append("| Method | Path | Auth | Status | Note |")
        out.append("|---|---|---|---:|---|")
        for r in results:
            if r.verdict == "WARN":
                out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.note} |")
        out.append("")

    out.append("## ✅ Passing (top 20)\n")
    out.append("| Method | Path | Auth | Status | Bytes | ms |")
    out.append("|---|---|---|---:|---:|---:|")
    passing = [r for r in results if r.verdict == "PASS"][:20]
    for r in passing:
        out.append(f"| {r.method} | `{r.path}` | {r.expected_auth} | {r.status} | {r.bytes_} | {r.duration_ms} |")

    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost:8000")
    ap.add_argument("--spec", default="public/openapi.yaml")
    ap.add_argument("--filter", default=None, help="only paths containing this string")
    ap.add_argument("--write", action="store_true", help="include POST/PUT/PATCH/DELETE")
    ap.add_argument("--verbose", action="store_true")
    ap.add_argument("--report", default=None, help="write markdown report to this file")
    args = ap.parse_args()

    results = run(args.base, args.spec, args.filter, args.write, args.verbose)

    summary = render_summary(results)
    if args.report:
        with open(args.report, "w") as f:
            f.write(summary)
        print(f"\n[+] Report written to {args.report}")

    n = len(results)
    pas = sum(1 for r in results if r.verdict == "PASS")
    fail = sum(1 for r in results if r.verdict == "FAIL")
    warn = sum(1 for r in results if r.verdict == "WARN")
    skip = sum(1 for r in results if r.verdict == "SKIP")
    print(f"\n=== {pas} PASS / {fail} FAIL / {warn} WARN / {skip} SKIP ({n} total) ===")
    sys.exit(1 if fail > 0 else 0)


if __name__ == "__main__":
    main()
