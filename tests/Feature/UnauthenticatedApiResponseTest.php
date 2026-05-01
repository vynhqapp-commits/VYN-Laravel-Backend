<?php

namespace Tests\Feature;

use Tests\TestCase;

class UnauthenticatedApiResponseTest extends TestCase
{
    public function test_me_with_json_accept_returns_401_envelope(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_me_with_invalid_token_returns_401_envelope(): void
    {
        $response = $this->getJson('/api/me', [
            'Authorization' => 'Bearer not-a-real-jwt',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_me_without_json_accept_still_returns_401_envelope(): void
    {
        // Reproduces the production 500 bug: when a request lacks the
        // Accept: application/json header, Laravel's default handler tries
        // to redirect to a 'login' named route. In an API-only project
        // there is no such route, which previously surfaced as 500 + HTML.
        // Post-fix, /api/* requests must always receive 401 + JSON.
        $response = $this->call('GET', '/api/me');

        $response->assertStatus(401);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_customers_without_json_accept_returns_401_envelope(): void
    {
        // Same bug class on a tenant-scoped endpoint — confirms the fix
        // applies to the whole api/* surface, not just /api/me.
        $response = $this->call('GET', '/api/customers');

        $response->assertStatus(401);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false]);
    }

    public function test_appointments_without_json_accept_returns_401_envelope(): void
    {
        $response = $this->call('GET', '/api/appointments');

        $response->assertStatus(401);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false]);
    }

    public function test_get_login_fallback_returns_401_envelope(): void
    {
        // Locks in the GET /api/login sentinel route's contract — it
        // exists only to catch clients following Laravel's auth redirect
        // and must always emit the standard 401 envelope.
        $response = $this->call('GET', '/api/login');

        $response->assertStatus(401);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_wrong_verb_on_api_route_returns_405_json_envelope(): void
    {
        // Wrong HTTP verb on an existing api/* path used to return Symfony's
        // HTML 405 page; with the MethodNotAllowedHttpException renderer
        // in bootstrap/app.php it now returns a JSON envelope.
        $response = $this->call('PUT', '/api/login');

        $response->assertStatus(405);
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJson(['success' => false, 'message' => 'Method not allowed.']);
    }

    public function test_genuine_404_on_api_is_not_masked_as_401(): void
    {
        // Defensive regression: the RouteNotFoundException renderer is
        // narrowed to only handle the 'login' name. A request to an
        // entirely undefined api/* path must still get a 404, not 401.
        $response = $this->call('GET', '/api/this-endpoint-does-not-exist-anywhere');

        $response->assertStatus(404);
    }
}
