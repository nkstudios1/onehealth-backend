<?php

namespace Tests;

use App\Contracts\SmsGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeSmsGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    protected const BASE = '/api/v1/auth';

    /** Assert the standard error envelope and return the decoded body. */
    protected function assertApiError($response, int $status, string $code): array
    {
        $response->assertStatus($status);
        $body = $response->json();

        $this->assertFalse($body['success'], 'success must be false');
        $this->assertSame($code, $body['code']);
        $this->assertIsString($body['message']);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('requestId', $body['meta']);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));

        return $body;
    }
}
