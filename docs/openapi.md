# Swagger (OpenAPI) Guide

This backend serves Swagger UI using a static OpenAPI YAML file.

## Output

- OpenAPI YAML: `storage/app/private/scribe/openapi.yaml`
- Swagger UI page: `/swagger`
- Swagger spec endpoint: `/swagger/openapi.yaml`

## Prerequisites

- PHP 8.2+
- Composer dependencies installed
- Application configured (`.env`)
- Database accessible (recommended so response calls can infer richer examples)

## Update API docs

Update the YAML file directly at `storage/app/private/scribe/openapi.yaml`.

## Serve and access Swagger UI

1. Start app locally:

```bash
php artisan serve
```

2. Open:
   - `http://localhost:8000/swagger` for Swagger UI
   - `http://localhost:8000/swagger/openapi.yaml` for raw OpenAPI spec

## Auth and tenant conventions

- JWT bearer token: `Authorization: Bearer <token>`
- Tenant-scoped routes: include `X-Tenant: <tenant-id-or-slug>`
- Keep bearer auth and `X-Tenant` requirements documented per endpoint where applicable.

## Documentation maintenance conventions

For new or changed endpoints:

1. Update `storage/app/private/scribe/openapi.yaml`.
2. Keep response envelope consistency (`success`, `message`, `data` / `errors`).
3. Verify in `/swagger`.

## Coverage tracking

Use `docs/openapi-coverage-checklist.md` to track route-to-controller coverage against `php artisan route:list --path=api --json`.

## CI recommendation

Validate that `storage/app/private/scribe/openapi.yaml` exists and is up to date as part of release checks.
