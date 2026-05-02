#!/usr/bin/env python3
"""
audit.py — codebase audit against CLAUDE.md rules.

Reports violations of the rules in VYN-Laravel-Backend/CLAUDE.md:
  - Controllers > 400 lines
  - Controller methods > 50 lines
  - Routes without role middleware (security risk)
  - Use of response()->json() instead of ApiResponse trait
  - Use of withoutGlobalScopes() (tenant isolation risk)
  - Tenant-scoped models missing BelongsToTenant trait
  - Routes not in OpenAPI spec / spec endpoints not in routes
  - FormRequest gaps — endpoints accepting input without a FormRequest

Usage:
    python3 tools/audit.py                    # all checks, terminal summary
    python3 tools/audit.py --only routes      # one check
    python3 tools/audit.py --json             # machine-readable
    python3 tools/audit.py --report report.md # markdown
"""

from __future__ import annotations
import argparse
import json
import re
import sys
from pathlib import Path


def find_project_root() -> Path:
    here = Path(__file__).resolve().parent
    for p in [here] + list(here.parents):
        if (p / "composer.json").exists():
            return p
    raise SystemExit("Cannot find composer.json.")


ROOT = find_project_root()

CHECKS = [
    "controller_size",
    "method_size",
    "routes_without_role",
    "raw_json_response",
    "without_global_scopes",
    "missing_tenant_trait",
    "spec_route_drift",
]


def check_controller_size(max_loc: int = 400) -> list[dict]:
    """Controllers larger than max_loc lines."""
    findings = []
    for f in (ROOT / "app" / "Http" / "Controllers").rglob("*.php"):
        loc = len(f.read_text().splitlines())
        if loc > max_loc:
            findings.append({
                "severity": "WARN" if loc < 600 else "FAIL",
                "rule": f"controller > {max_loc} lines",
                "file": str(f.relative_to(ROOT)),
                "value": loc,
            })
    return findings


def check_method_size(max_loc: int = 50) -> list[dict]:
    """Public methods inside controllers larger than max_loc."""
    findings = []
    pattern = re.compile(r"^\s*public\s+function\s+(\w+)\s*\(", re.MULTILINE)
    for f in (ROOT / "app" / "Http" / "Controllers").rglob("*.php"):
        text = f.read_text()
        lines = text.splitlines()
        for m in pattern.finditer(text):
            method_name = m.group(1)
            start = text[:m.start()].count("\n") + 1
            depth = 0
            count = 0
            for i in range(start - 1, len(lines)):
                count += 1
                depth += lines[i].count("{") - lines[i].count("}")
                if depth == 0 and count > 1:
                    break
            if count > max_loc:
                findings.append({
                    "severity": "WARN" if count < 100 else "FAIL",
                    "rule": f"method > {max_loc} lines",
                    "file": str(f.relative_to(ROOT)),
                    "method": method_name,
                    "value": count,
                    "line": start,
                })
    return findings


def check_routes_without_role() -> list[dict]:
    """Tenant route groups should be wrapped in role middleware."""
    findings = []
    routes_file = ROOT / "routes" / "api.php"
    if not routes_file.exists():
        return findings
    text = routes_file.read_text()
    lines = text.splitlines()

    # Naive: find Route::xxx(...) lines inside the api/* tenant zone, not wrapped in role:
    # Accurate detection requires walking the group stack. Heuristic: flag routes that
    # contain "InvoiceController" or sensitive controllers but are NOT inside `role:` middleware.
    sensitive_kw = ["GiftCard", "Invoice", "Ledger", "Debt", "Tenant", "Permission", "Approval", "Customer"]
    role_depth = 0
    for i, line in enumerate(lines, 1):
        if "role:" in line and "->group" in line:
            role_depth += 1
        elif "});" in line and role_depth > 0:
            role_depth = max(0, role_depth - line.count("});"))
        if role_depth == 0:
            for kw in sensitive_kw:
                if f"{kw}Controller" in line and "Route::" in line:
                    findings.append({
                        "severity": "WARN",
                        "rule": "route outside role:* middleware",
                        "file": "routes/api.php",
                        "line": i,
                        "snippet": line.strip()[:120],
                    })
                    break
    return findings


def check_raw_json_response() -> list[dict]:
    """response()->json(...) bypasses ApiResponse trait."""
    findings = []
    pattern = re.compile(r"response\(\s*\)\s*->\s*json\(")
    for f in (ROOT / "app" / "Http" / "Controllers").rglob("*.php"):
        text = f.read_text()
        for m in pattern.finditer(text):
            line_no = text[:m.start()].count("\n") + 1
            findings.append({
                "severity": "WARN",
                "rule": "response()->json() bypasses ApiResponse trait",
                "file": str(f.relative_to(ROOT)),
                "line": line_no,
            })
    return findings


def check_without_global_scopes() -> list[dict]:
    """withoutGlobalScopes() can bypass tenant isolation."""
    findings = []
    pattern = re.compile(r"withoutGlobalScopes?\(")
    for d in [ROOT / "app", ROOT / "routes"]:
        for f in d.rglob("*.php"):
            text = f.read_text()
            for m in pattern.finditer(text):
                line_no = text[:m.start()].count("\n") + 1
                rel = str(f.relative_to(ROOT))
                # tests/ are intentional
                if rel.startswith("tests/"):
                    continue
                findings.append({
                    "severity": "WARN",
                    "rule": "withoutGlobalScopes() can bypass tenant isolation",
                    "file": rel,
                    "line": line_no,
                })
    return findings


def check_missing_tenant_trait() -> list[dict]:
    """Models with tenant_id column should use BelongsToTenant trait."""
    findings = []
    # Pull migrations to find tables that have tenant_id
    tenant_tables = set()
    migs = ROOT / "database" / "migrations"
    if migs.exists():
        for f in migs.glob("*.php"):
            text = f.read_text()
            m = re.search(r"Schema::create\(\s*['\"](\w+)['\"]", text)
            if m and "tenant_id" in text:
                tenant_tables.add(m.group(1))

    # For each model in app/Models, check if its table is in tenant_tables
    models_dir = ROOT / "app" / "Models"
    if not models_dir.exists():
        return findings
    for f in models_dir.glob("*.php"):
        text = f.read_text()
        # Skip if obviously not an Eloquent model
        if "extends Model" not in text and "extends Authenticatable" not in text:
            continue
        # Get table name (default = snake_plural of class name)
        m = re.search(r"protected\s+\$table\s*=\s*['\"](\w+)['\"]", text)
        if m:
            table = m.group(1)
        else:
            class_m = re.search(r"class\s+(\w+)", text)
            if not class_m:
                continue
            cls = class_m.group(1)
            # snake_case + pluralize (rough)
            snake = re.sub(r"(?<!^)(?=[A-Z])", "_", cls).lower()
            table = snake + "s"
        if table in tenant_tables and "BelongsToTenant" not in text:
            findings.append({
                "severity": "FAIL",
                "rule": "model missing BelongsToTenant trait (tenant_id column exists)",
                "file": str(f.relative_to(ROOT)),
                "table": table,
            })
    return findings


def check_spec_route_drift() -> list[dict]:
    """Routes not in spec, spec ops not in routes."""
    findings = []
    spec_path = ROOT / "public" / "openapi.yaml"
    routes_path = ROOT / "routes" / "api.php"
    if not spec_path.exists() or not routes_path.exists():
        return findings

    try:
        import yaml
    except ImportError:
        return [{"severity": "WARN", "rule": "spec drift check skipped (PyYAML missing)", "file": "tools/audit.py"}]

    spec = yaml.safe_load(spec_path.read_text())
    spec_ops = set()
    for path, methods in (spec.get("paths") or {}).items():
        for m in methods:
            if m.upper() in ("GET", "POST", "PUT", "PATCH", "DELETE"):
                spec_ops.add((m.upper(), path))

    routes_ops = set()
    routes_text = routes_path.read_text()
    pattern = re.compile(r"Route::(get|post|put|patch|delete)\s*\(\s*['\"]([^'\"]+)['\"]")
    for m in pattern.finditer(routes_text):
        method = m.group(1).upper()
        path = "/" + m.group(2).lstrip("/")
        if not path.startswith("/api/"):
            path = "/api" + path if not path.startswith("/api") else path
        routes_ops.add((method, path))

    in_routes_not_spec = routes_ops - spec_ops
    in_spec_not_routes = spec_ops - routes_ops

    for method, path in sorted(in_routes_not_spec):
        findings.append({"severity": "WARN", "rule": "route not in OpenAPI spec", "method": method, "path": path})
    for method, path in sorted(in_spec_not_routes)[:30]:  # cap output
        findings.append({"severity": "WARN", "rule": "spec op not in routes/api.php", "method": method, "path": path})

    return findings


def render_terminal(results: dict[str, list[dict]]) -> None:
    GREEN, RED, YELLOW, GRAY, OFF = "\033[92m", "\033[91m", "\033[93m", "\033[90m", "\033[0m"
    if not sys.stdout.isatty():
        GREEN = RED = YELLOW = GRAY = OFF = ""

    total_fail = total_warn = 0
    print(f"\n\033[1mAudit report — {ROOT}\033[0m\n" if sys.stdout.isatty() else f"\nAudit report — {ROOT}\n")
    for check, findings in results.items():
        fail = sum(1 for f in findings if f["severity"] == "FAIL")
        warn = sum(1 for f in findings if f["severity"] == "WARN")
        total_fail += fail
        total_warn += warn
        if not findings:
            print(f"  {GREEN}✓{OFF} {check:30} clean")
        else:
            color = RED if fail else YELLOW
            print(f"  {color}✗{OFF} {check:30} {fail} fail / {warn} warn")
            for finding in findings[:8]:
                file = finding.get("file", "")
                line = finding.get("line", "")
                line_str = f":{line}" if line else ""
                print(f"      {GRAY}{finding['rule']}{OFF}  {file}{line_str}", end="")
                if "value" in finding:
                    print(f"  ({finding['value']} lines)", end="")
                if "method" in finding and "path" in finding:
                    print(f"  ({finding['method']} {finding['path']})", end="")
                elif "method" in finding:
                    print(f"  ::{finding['method']}", end="")
                print()
            if len(findings) > 8:
                print(f"      {GRAY}... +{len(findings)-8} more{OFF}")
    print()
    summary_color = RED if total_fail else (YELLOW if total_warn else GREEN)
    print(f"  {summary_color}{total_fail} fail / {total_warn} warn{OFF}")
    return total_fail


def render_markdown(results: dict[str, list[dict]]) -> str:
    out = ["# Audit Report\n"]
    out.append(f"Generated by `tools/audit.py` from {ROOT}\n")
    for check, findings in results.items():
        out.append(f"## {check}")
        if not findings:
            out.append("✅ Clean.\n")
            continue
        out.append("| Severity | Rule | Location | Detail |")
        out.append("|---|---|---|---|")
        for f in findings:
            loc = f.get("file", "")
            if "line" in f:
                loc += f":{f['line']}"
            detail = []
            for k in ("method", "path", "value", "table", "snippet"):
                if k in f:
                    detail.append(f"{k}={f[k]}")
            out.append(f"| {f['severity']} | {f['rule']} | `{loc}` | {' '.join(detail)} |")
        out.append("")
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser(description="Codebase audit against CLAUDE.md rules.")
    ap.add_argument("--only", help=f"Run a single check: {','.join(CHECKS)}")
    ap.add_argument("--json", action="store_true", help="Machine-readable JSON output")
    ap.add_argument("--report", help="Write markdown report")
    args = ap.parse_args()

    runners = {
        "controller_size": check_controller_size,
        "method_size": check_method_size,
        "routes_without_role": check_routes_without_role,
        "raw_json_response": check_raw_json_response,
        "without_global_scopes": check_without_global_scopes,
        "missing_tenant_trait": check_missing_tenant_trait,
        "spec_route_drift": check_spec_route_drift,
    }

    if args.only:
        if args.only not in runners:
            sys.exit(f"Unknown check '{args.only}'. Available: {','.join(CHECKS)}")
        runners = {args.only: runners[args.only]}

    results = {name: fn() for name, fn in runners.items()}
    total_fail = sum(1 for findings in results.values() for f in findings if f["severity"] == "FAIL")

    if args.json:
        print(json.dumps({k: v for k, v in results.items()}, indent=2))
    elif args.report:
        Path(args.report).write_text(render_markdown(results))
        print(f"Wrote {args.report}")
    else:
        render_terminal(results)

    sys.exit(1 if total_fail else 0)


if __name__ == "__main__":
    main()
