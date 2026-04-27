# Swagger (OpenAPI) Guide

This backend uses [Scribe](https://scribe.knuckles.wtf/laravel) to generate full OpenAPI documentation for all APIs defined in `routes/api.php`.

## Output

- OpenAPI YAML: `storage/app/private/scribe/openapi.yaml`
- Swagger UI page: `/swagger`
- Swagger spec endpoint: `/swagger/openapi.yaml`

## Prerequisites

- PHP 8.2+
- Composer dependencies installed
- Application configured (`.env`)
- Database accessible (recommended so response calls can infer richer examples)

## Generate API docs

```bash
php artisan scribe:generate
```

Or use the Swagger-only workflow (generate + auto-clean Scribe UI artifacts):

```bash
composer docs:swagger
```

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
- Public/unauthenticated endpoints are explicitly marked with `@unauthenticated`
- Authentication and common request headers are configured globally in Scribe/OpenAPI so Swagger applies them consistently.

## Documentation implementation conventions

For new or changed endpoints:

1. Add/maintain method docblocks with:
   - `@group`
   - `@unauthenticated` or authenticated defaults
   - `@urlParam`, `@queryParam`, `@bodyParam`
2. Keep response envelope consistency (`success`, `message`, `data` / `errors`).
3. Re-run `php artisan scribe:generate`.
4. Verify the endpoint in `/swagger`.

## Coverage tracking

Use `docs/openapi-coverage-checklist.md` to track route-to-controller coverage against `php artisan route:list --path=api --json`.

## CI recommendation

Run this in CI and fail the pipeline if it errors:

```bash
php artisan scribe:generate
```
