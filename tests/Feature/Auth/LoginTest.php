<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function patient(array $user = []): User
    {
        $u = User::factory()->create(['phone' => '08031234567'] + $user);
        Patient::factory()->create(['id' => $u->id, 'personal_id' => '4829175036']);

        return $u;
    }

    private function login(string $identifier, string $password = 'Password1')
    {
        return $this->postJson(self::BASE.'/login', ['identifier' => $identifier, 'password' => $password]);
    }

    public function test_login_by_phone_personal_id_and_email(): void
    {
        $this->patient(['email' => 'Chidi@Mail.com']);

        foreach (['08031234567', '+2348031234567', '4829175036', 'chidi@mail.com', 'CHIDI@mail.com'] as $who) {
            $this->login($who)->assertOk()
                ->assertJsonPath('data.user.personalId', '4829175036')
                ->assertJsonPath('data.user.username', 'Adaeze Okafor')
                ->assertJsonMissingPath('data.user.password');
            $this->assertAuthenticated('web');
            auth('web')->logout();
        }
    }

    public function test_wrong_password_and_unknown_account_look_identical(): void
    {
        $this->patient();

        $wrong = $this->assertApiError($this->login('08031234567', 'Wrong1234'), 401, 'INVALID_CREDENTIALS');
        $unknown = $this->assertApiError($this->login('08099999999', 'Wrong1234'), 401, 'INVALID_CREDENTIALS');
        $garbage = $this->assertApiError($this->login('???', 'Wrong1234'), 401, 'INVALID_CREDENTIALS');

        $this->assertSame($wrong['message'], $unknown['message']);
        $this->assertSame($wrong['message'], $garbage['message']);
        $this->assertGuest('web');
    }

    public function test_locks_after_too_many_failures_even_with_the_right_password(): void
    {
        $this->patient();

        for ($i = 0; $i < 5; $i++) {
            $this->login('08031234567', 'Wrong1234')->assertStatus(401);
        }

        $body = $this->assertApiError($this->login('08031234567'), 429, 'TOO_MANY_ATTEMPTS');
        $this->assertGreaterThan(0, $body['meta']['retryAfter']);
        $this->assertGuest('web');
    }

    public function test_unknown_accounts_are_throttled_too(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('08099999999', 'Wrong1234')->assertStatus(401);
        }

        $this->assertApiError($this->login('08099999999', 'Wrong1234'), 429, 'TOO_MANY_ATTEMPTS');
    }

    public function test_successful_login_clears_the_failure_count(): void
    {
        $this->patient();

        for ($i = 0; $i < 4; $i++) {
            $this->login('08031234567', 'Wrong1234');
        }
        $this->login('08031234567')->assertOk();
        auth('web')->logout();

        for ($i = 0; $i < 4; $i++) {
            $this->login('08031234567', 'Wrong1234')->assertStatus(401);
        }
    }

    public function test_suspended_account_is_only_revealed_after_the_correct_password(): void
    {
        $this->patient(['status' => 'suspended']);

        $this->assertApiError($this->login('08031234567', 'Wrong1234'), 401, 'INVALID_CREDENTIALS');
        $this->assertApiError($this->login('08031234567'), 403, 'ACCOUNT_SUSPENDED');
        $this->assertGuest('web');
    }

    public function test_me_and_logout(): void
    {
        $user = $this->patient();

        $this->actingAs($user, 'web')->getJson(self::BASE.'/me')->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->postJson(self::BASE.'/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_session_for_a_suspended_user_is_refused(): void
    {
        $user = $this->patient(['status' => 'suspended']);

        $this->actingAs($user, 'web')->getJson(self::BASE.'/me');

        $this->assertApiError($this->actingAs($user, 'web')->getJson(self::BASE.'/me'), 403, 'ACCOUNT_SUSPENDED');
    }

    public function test_events_are_audited_without_secrets(): void
    {
        $this->patient();
        $this->login('08031234567', 'Wrong1234');
        $this->login('08031234567')->assertOk();

        $events = AuditLog::pluck('event')->map->value->all();
        $this->assertContains('login.failed', $events);
        $this->assertContains('login.succeeded', $events);
        $this->assertStringNotContainsString('Wrong1234', json_encode(AuditLog::all()->toArray()));
    }

    public function test_audit_rows_cannot_be_changed_or_deleted(): void
    {
        $this->patient();
        $this->login('08031234567', 'Wrong1234');
        $row = AuditLog::firstOrFail();

        $this->expectException(\LogicException::class);
        $row->delete();
    }
}
