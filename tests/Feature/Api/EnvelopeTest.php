<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/** Every response, good or bad, must have the same shape. New modules inherit this for free. */
class EnvelopeTest extends TestCase
{
    public function test_success_has_standard_shape_and_headers(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk()->assertJsonStructure(['success', 'message', 'data', 'meta' => ['requestId']]);
        $this->assertTrue($response->json('success'));
        $this->assertSame($response->json('meta.requestId'), $response->headers->get('X-Request-Id'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_incoming_request_id_is_kept_only_if_it_is_a_uuid(): void
    {
        $id = '9f1c2b0e-3d4a-4e5f-8a6b-7c8d9e0f1a2b';
        $this->getJson('/api/ping', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);

        $bad = $this->getJson('/api/ping', ['X-Request-Id' => "evil\nHeader: x"]);
        $this->assertNotSame("evil\nHeader: x", $bad->headers->get('X-Request-Id'));
    }

    public function test_unknown_route_is_a_json_404(): void
    {
        $this->assertApiError($this->getJson('/api/v1/nope'), 404, 'NOT_FOUND');
    }

    public function test_wrong_method_is_a_json_405(): void
    {
        $response = $this->getJson('/api/v1/auth/login');

        $this->assertApiError($response, 405, 'METHOD_NOT_ALLOWED');
        $this->assertNotEmpty($response->headers->get('Allow'));
    }

    public function test_unauthenticated_is_a_json_401(): void
    {
        $this->assertApiError($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_validation_errors_are_422_with_field_messages(): void
    {
        $body = $this->assertApiError($this->postJson('/api/v1/auth/login', []), 422, 'VALIDATION_FAILED');

        $this->assertArrayHasKey('identifier', $body['errors']);
        $this->assertArrayHasKey('password', $body['errors']);
    }

    public function test_errors_never_leak_internals(): void
    {
        $response = $this->postJson('/api/v1/auth/register/start', ['phone' => 'x', 'nin' => 'y', 'consent' => false]);

        $text = json_encode($response->json());
        $this->assertStringNotContainsString('vendor/', $text);
        $this->assertStringNotContainsString('Illuminate', $text);
    }
}
