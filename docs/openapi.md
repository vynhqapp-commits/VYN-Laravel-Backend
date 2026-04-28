# Swagger (OpenAPI) Guide

This backend serves Swagger UI using a static OpenAPI YAML file.

## Output

- OpenAPI YAML: `public/openapi.yaml`
- Swagger UI page: `/swagger`
- Swagger spec endpoint: `/swagger/openapi.yaml`

## Prerequisites

- PHP 8.2+
- Composer dependencies installed
- Application configured (`.env`)
- Database accessible (recommended so response calls can infer richer examples)

## Update API docs

Update the YAML file directly at `public/openapi.yaml`.

### Server URL (Try it out)

Paths in this spec already include the `/api` prefix (for example `/api/login`). The OpenAPI `servers` entry must be the **site origin only** (no `/api` suffix), for example `https://admin.vynhq.com`. If you set `https://example.com/api` while paths are `/api/...`, Swagger will call `https://example.com/api/api/...` and requests will fail.

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

1. Update `public/openapi.yaml`.
2. Keep response envelope consistency (`success`, `message`, `data` / `errors`).
3. Verify in `/swagger`.

## Coverage tracking

Use `docs/openapi-coverage-checklist.md` to track route-to-controller coverage against `php artisan route:list --path=api --json`.

### Recently added backend surfaces to keep documented

- `App\Http\Controllers\Api\Tenant\ApprovalRequestController`
  - `GET /api/approval-requests`
  - `POST /api/approval-requests/{id}/approve`
  - `POST /api/approval-requests/{id}/reject`
- `App\Http\Controllers\Api\Tenant\FranchiseOwnerInvitationController`
  - `POST /api/franchise-owner-invitations`
  - `POST /api/auth/franchise-owner-invitations/accept`

## CI recommendation

Validate that `public/openapi.yaml` exists and is up to date as part of release checks.
