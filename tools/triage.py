#!/usr/bin/env python3
"""
triage.py — gather everything needed to fix a failing endpoint.

Given an HTTP method + path (or the smoke-runner JSON output), this tool
produces a markdown brief with:
  - Route definition (line in routes/api.php)
  - Controller class + method source
  - FormRequest class (if any) with validation rules
  - Resource class (if any) with returned fields
  - Recent Laravel log slice
  - PHPUnit feature test file (if exists)
  - Related Eloquent models, BelongsToTenant trait status
  - Suggested next-step commands (PHPStan, targeted test)

This is the Day 3 research finding #3 — "failure → source → fix triage
bundle." Turns a 5xx into a one-shot prompt Claude can act on without
re-discovery.

Usage:
    python3 tools/triage.py GET /api/staff
    python3 tools/triage.py "POST /api/sales"
    python3 tools/triage.py --route /api/debts/aging-report --method GET
    python3 tools/triage.py --from-smoke smoke.json   # triages every failure
    python3 tools/triage.py GET /api/me --output triage/me.md

Path-independent. Honors VYN_EMAIL / VYN_PASSWORD env vars (forwarded to
smoke when --from-smoke is used).
"""

from __future__ import annotations
import argparse
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Optional


def find_project_root() -> Path:
    here = Path(__file__).resolve().parent
    for p in [here] + list(here.parents):
        if (p / "composer.json").exists():
            return p
    raise SystemExit("Cannot find composer.json above this script.")


ROOT = find_project_root()


def grep(pattern: str, *paths: str, line_numbers: bool = True) -> list[str]:
    args = ["grep", "-rn" if line_numbers else "-r", pattern, *paths]
    try:
        out = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=10)
        return out.stdout.splitlines()
    except Exception:
        return []


def read_lines(path: Path, start: int, end: int) -> str:
    if not path.exists():
        return ""
    try:
        lines = path.read_text().splitlines()
        return "\n".join(lines[max(0, start-1):end])
    except Exception:
        return ""


def find_route(method: str, path: str) -> dict:
    """Find route definition in routes/api.php."""
    out = {"file": "routes/api.php", "lines": [], "controller": None, "method_name": None, "middleware": []}
    routes_file = ROOT / "routes" / "api.php"
    if not routes_file.exists():
        return out

    method_lower = method.lower()
    norm_path = path.lstrip("/").replace("api/", "")

    text = routes_file.read_text()
    for i, line in enumerate(text.splitlines(), 1):
        if f"Route::{method_lower}(" in line and norm_path in line:
            out["lines"].append((i, line.strip()))
            m = re.search(r"\[(\w+Controller)::class\s*,\s*'(\w+)'\]", line)
            if m:
                out["controller"], out["method_name"] = m.group(1), m.group(2)

    return out


def find_controller_method(controller_class: str, method_name: str) -> dict:
    """Find controller class file and method source."""
    matches = grep(f"class {controller_class}", "app/Http/Controllers/", line_numbers=True)
    out = {"class": controller_class, "method_name": method_name, "file": None, "method_lines": "", "method_loc": 0, "file_loc": 0, "imports": []}

    for m in matches:
        parts = m.split(":", 2)
        if len(parts) >= 1:
            out["file"] = parts[0]
            break

    if not out["file"]:
        return out

    file_path = ROOT / out["file"]
    text = file_path.read_text()
    out["file_loc"] = len(text.splitlines())

    # Imports
    out["imports"] = [l for l in text.splitlines() if l.strip().startswith("use ")]

    # Find method
    pattern = re.compile(rf"public\s+function\s+{re.escape(method_name)}\s*\(")
    lines = text.splitlines()
    start = None
    depth = 0
    method_lines = []
    for i, line in enumerate(lines, 1):
        if start is None and pattern.search(line):
            start = i
        if start is not None:
            method_lines.append(line)
            depth += line.count("{") - line.count("}")
            if depth == 0 and len(method_lines) > 1:
                break
    if method_lines:
        out["method_lines"] = "\n".join(method_lines)
        out["method_loc"] = len(method_lines)
        out["start_line"] = start

    return out


def find_form_request(controller_method_source: str) -> Optional[dict]:
    """If method signature uses a FormRequest, find the class."""
    m = re.search(r"public\s+function\s+\w+\s*\(\s*(\w+Request)\s+\$", controller_method_source)
    if not m:
        return None
    class_name = m.group(1)
    matches = grep(f"class {class_name}", "app/Http/Requests/", line_numbers=False)
    if not matches:
        return None
    file_path = matches[0].split(":", 1)[0]
    full = (ROOT / file_path).read_text()
    return {"class": class_name, "file": file_path, "source": full}


def find_resource(controller_method_source: str) -> Optional[dict]:
    """Find Resource class referenced in method body."""
    m = re.search(r"(\w+Resource)::collection|new\s+(\w+Resource)\(", controller_method_source)
    if not m:
        return None
    class_name = m.group(1) or m.group(2)
    matches = grep(f"class {class_name}", "app/Http/Resources/", line_numbers=False)
    if not matches:
        return None
    file_path = matches[0].split(":", 1)[0]
    return {"class": class_name, "file": file_path, "source": (ROOT / file_path).read_text()}


def find_test(controller_class: str, method_name: str) -> list[str]:
    """Find PHPUnit feature tests touching this controller/method."""
    hits = grep(f"{controller_class}|{method_name}", "tests/", line_numbers=False)
    files = sorted(set(h.split(":", 1)[0] for h in hits))
    return files[:5]


def get_log_slice(grep_pattern: str = None, lines: int = 80) -> str:
    log = ROOT / "storage" / "logs" / "laravel.log"
    if not log.exists():
        return "(no laravel.log on disk)"
    try:
        out = subprocess.run(["tail", "-n", str(lines), str(log)], capture_output=True, text=True, timeout=5)
        return out.stdout
    except Exception:
        return "(could not read log)"


def render(method: str, path: str) -> str:
    md = []
    md.append(f"# Triage: {method} {path}\n")
    md.append(f"Generated by `tools/triage.py` from {ROOT}\n")

    # Route
    route = find_route(method, path)
    md.append("## 1. Route definition\n")
    if route["lines"]:
        md.append(f"In `routes/api.php`:\n")
        for ln, content in route["lines"]:
            md.append(f"```\nL{ln}: {content}\n```")
        if route["controller"] and route["method_name"]:
            md.append(f"\n**Handler:** `{route['controller']}::{route['method_name']}()`\n")
    else:
        md.append(f"⚠️  No route matching `{method} {path}` found in routes/api.php.")
        md.append("Check whether the path is correct, or whether it's a sub-routed path (e.g., a Resource controller).\n")

    if not route.get("controller"):
        md.append("\n*(Cannot continue triage without controller binding. Inspect route manually.)*")
        return "\n".join(md)

    # Controller method
    md.append("\n## 2. Controller method source\n")
    ctrl = find_controller_method(route["controller"], route["method_name"])
    if ctrl["file"]:
        md.append(f"`{ctrl['file']}` — file is **{ctrl['file_loc']} lines** "
                  f"(CLAUDE.md max: 400 — {'⚠️ over limit' if ctrl['file_loc'] > 400 else 'ok'})")
        md.append(f"\nMethod `{ctrl['method_name']}` starts at line {ctrl.get('start_line', '?')}, "
                  f"is **{ctrl['method_loc']} lines** "
                  f"(CLAUDE.md max: 50 — {'⚠️ over limit' if ctrl['method_loc'] > 50 else 'ok'}):\n")
        md.append("```php\n" + ctrl["method_lines"] + "\n```\n")
    else:
        md.append(f"⚠️  Could not locate `{route['controller']}` in app/Http/Controllers/.\n")

    # FormRequest
    md.append("\n## 3. FormRequest validation\n")
    fr = find_form_request(ctrl.get("method_lines", ""))
    if fr:
        md.append(f"Method uses `{fr['class']}` from `{fr['file']}`:\n")
        md.append("```php\n" + fr["source"] + "\n```\n")
    else:
        md.append("⚠️  Method does NOT type-hint a FormRequest. May be using inline `$request->validate(...)` "
                  "or skipping validation entirely. CLAUDE.md prefers FormRequest classes.\n")

    # Resource
    md.append("\n## 4. Response Resource\n")
    res = find_resource(ctrl.get("method_lines", ""))
    if res:
        md.append(f"Returns via `{res['class']}` from `{res['file']}`:\n")
        md.append("```php\n" + res["source"] + "\n```\n")
    else:
        md.append("(No Resource class detected. Method may return raw model or array.)\n")

    # Tests
    md.append("\n## 5. Existing tests\n")
    tests = find_test(route["controller"], route["method_name"])
    if tests:
        md.append("Files in tests/ that mention this controller/method:\n")
        for t in tests:
            md.append(f"- `{t}`")
        md.append("")
    else:
        md.append("⚠️  No PHPUnit feature test found for this method. CLAUDE.md requires a test for every endpoint.\n")

    # Logs
    md.append("\n## 6. Recent Laravel log (last 80 lines)\n")
    log = get_log_slice()
    md.append("```\n" + (log[-3000:] if len(log) > 3000 else log) + "\n```\n")

    # Suggested next commands
    md.append("\n## 7. Suggested next commands\n")
    md.append("```bash")
    md.append("# Reproduce the failure")
    md.append(f"python3 tools/api-smoke-test.py --only \"{method} {path}\" --verbose\n")
    if ctrl["file"]:
        md.append("# Run static analysis on the file")
        md.append(f"vendor/bin/phpstan analyse {ctrl['file']} --level=5\n")
    if tests:
        first_test = tests[0].split("/")[-1].replace(".php", "")
        md.append("# Run targeted feature test")
        md.append(f"php artisan test --filter={first_test}\n")
    md.append("# Tail logs while you fix")
    md.append("tail -f storage/logs/laravel.log")
    md.append("```")

    return "\n".join(md)


def main():
    ap = argparse.ArgumentParser(
        description="Gather everything needed to fix a failing endpoint into one markdown brief.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    ap.add_argument("method_or_label", nargs="?", help="HTTP method (GET/POST/...) or full label like 'GET /api/staff'")
    ap.add_argument("path", nargs="?", help="Path (when method is given separately)")
    ap.add_argument("--method", help="Override method")
    ap.add_argument("--route", help="Override path")
    ap.add_argument("--from-smoke", help="Path to smoke-runner JSON; triages every FAIL row")
    ap.add_argument("--output", help="Write to this file instead of stdout")
    args = ap.parse_args()

    if args.from_smoke:
        data = json.loads(Path(args.from_smoke).read_text())
        outdir = Path(args.output) if args.output else ROOT / "tools" / "triage"
        outdir.mkdir(parents=True, exist_ok=True)
        n = 0
        for r in data.get("results", []):
            if r["verdict"] != "FAIL":
                continue
            slug = re.sub(r"[^a-z0-9]+", "-", f"{r['method']}-{r['path']}".lower()).strip("-")
            outfile = outdir / f"{slug}.md"
            outfile.write_text(render(r["method"], r["path"]))
            n += 1
        print(f"Wrote {n} triage briefs to {outdir}", file=sys.stderr)
        return

    method = args.method
    path = args.route
    if args.method_or_label and not method:
        if " " in args.method_or_label:
            method, path = args.method_or_label.split(maxsplit=1)
        else:
            method = args.method_or_label
    if not path and args.path:
        path = args.path

    if not method or not path:
        ap.error("provide method + path: e.g. `python3 tools/triage.py GET /api/staff`")

    md = render(method.upper(), path)
    if args.output:
        Path(args.output).write_text(md)
        print(f"Wrote {args.output}", file=sys.stderr)
    else:
        print(md)


if __name__ == "__main__":
    main()
