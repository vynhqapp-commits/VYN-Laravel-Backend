# tools/ — VYN Backend Developer Harness

Quick-reference for the dev toolkit. Canonical docs live in [`../CLAUDE.md`](../CLAUDE.md).

## What's in here

| File | Purpose |
|---|---|
| `dev.sh` | One-stop bash harness. Subcommands: doctor, up, bg, down, migrate, fresh, smoke, test, phpstan, pint, audit, check, tail, triage |
| `api-smoke-test.py` | Spec-driven API smoke tester. Reads `public/openapi.yaml`, hits every endpoint, reports per-endpoint pass/fail. Multi-role, JSON output, path-independent. |
| `triage.py` | Failure-to-fix triage bundle. Given an endpoint, produces a markdown brief with route, controller, FormRequest, Resource, tests, logs, and suggested next commands. |
| `audit.py` | Codebase audit against CLAUDE.md rules. Catches fat controllers, missing tenant traits, raw json responses, spec drift. |
| `last-smoke-report*.md` | Output from smoke runs (gitignored). |
| `triage/` | Output from `triage.py --from-smoke` (gitignored). |

## Cheat sheet

```bash
# Start of every session
./tools/dev.sh doctor          # is the env healthy?
./tools/dev.sh bg              # start server in background
./tools/dev.sh smoke           # is every endpoint working?

# Fixing a bug
python3 tools/triage.py GET /api/<broken>     # gather context
# fix code
./tools/dev.sh smoke --only "GET /api/<broken>"  # verify

# Before commit
./tools/dev.sh check           # pint + phpstan + test + smoke

# Before PR
./tools/dev.sh audit           # CLAUDE.md rule compliance
```

## Multi-role testing

```bash
./tools/dev.sh smoke --role super_admin
./tools/dev.sh smoke --role salon_owner    # default
./tools/dev.sh smoke --role manager
./tools/dev.sh smoke --role staff
./tools/dev.sh smoke --role customer
```

## Production targets

```bash
./tools/dev.sh smoke --base https://admin.vynhq.com
# or with prod credentials:
VYN_EMAIL=real@prod.com VYN_PASSWORD=... ./tools/dev.sh smoke --base https://admin.vynhq.com
```

## Why these tools exist

Captain handed off a working backend but no developer harness. Without these tools, every dev session burns time on:
- Verifying the env is OK (now: `dev.sh doctor`, 2 sec)
- Checking which endpoints work (now: `dev.sh smoke`, 10 sec)
- Hunting context to fix a 500 (now: `triage.py`, 1 sec)
- Knowing if you violated a CLAUDE.md rule (now: `audit.py`, 5 sec)

Every tool here saves Claude/human dev time on a routine they would otherwise repeat 50× per project.

## Adding new tools

1. Drop the script in `tools/`
2. `chmod +x` if it's executable
3. Add a row to the table in `../CLAUDE.md` under "Developer Toolkit"
4. Add a row to this README's "What's in here" table
5. If it's a common command, add it as a subcommand in `dev.sh`
6. Test from a directory that isn't the project root (path-independence)
