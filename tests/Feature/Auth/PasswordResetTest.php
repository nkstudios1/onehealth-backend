<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const P = self::BASE.'/password-reset';

    private function patient(array $user = []): User
    {
        $u = User::factory()->create(['phone' => '08031234567'] + $user);
        Patient::factory()->create(['id' => $u->id, 'personal_id' => '4829175036']);

        return $u;
    }

    public function test_happy_path_by_phone(): void
    {
        $user = $this->patient();

        $r = $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->assertOk()
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.destination', '080****4567')
            ->assertJsonPath('data.resendAfterSeconds', 300);
        $resetId = $r->json('data.resetId');
        $this->assertSame(1, $this->sms->sentTo('08031234567'));      // sent after the response, still sent

        $token = $this->postJson(self::P.'/verify-otp', ['resetId' => $resetId, 'code' => '1234'])->assertOk()->json('data.resetToken');
        $this->assertSame(64, strlen($token));

        $this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'Brandnew99'])->assertOk();
        $this->assertTrue(Hash::check('Brandnew99', $user->fresh()->password));
        $this->assertSame(2, $this->sms->sentTo('08031234567'));      // "your password changed" notice

        // The token works once.
        $this->assertApiError($this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'Another123']), 404, 'RESET_NOT_FOUND');
    }

    public function test_by_email_sends_mail_not_sms(): void
    {
        Mail::fake();
        $this->patient(['email' => 'chidi@mail.com']);

        $this->postJson(self::P.'/request', ['identifier' => 'chidi@mail.com'])->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.destination', 'ch***@mail.com');

        Mail::assertSent(PasswordResetCodeMail::class, fn ($m) => $m->hasTo('chidi@mail.com') && $m->code === '1234');
        $this->assertSame(0, count($this->sms->sent));
    }

    public function test_by_personal_id_texts_the_phone_on_file(): void
    {
        $this->patient();

        $this->postJson(self::P.'/request', ['identifier' => '4829175036'])->assertOk()
            ->assertJsonPath('data.destination', '080****4567');
        $this->assertSame(1, $this->sms->sentTo('08031234567'));
    }

    public function test_unknown_account_gets_the_same_shape_and_no_message(): void
    {
        $this->patient();

        foreach (['08099999999', '9999999999', 'nobody@mail.com', 'garbage'] as $who) {
            $r = $this->postJson(self::P.'/request', ['identifier' => $who])->assertOk();
            $this->assertEqualsCanonicalizing(['resetId', 'channel', 'destination', 'resendAfterSeconds'], array_keys($r->json('data')));
        }

        $this->assertSame(0, count($this->sms->sent));

        // A fake reset behaves like a wrong code, never "not found".
        $id = $this->postJson(self::P.'/request', ['identifier' => '08099999999'])->json('data.resetId');
        $this->assertApiError($this->postJson(self::P.'/verify-otp', ['resetId' => $id, 'code' => '1234']), 422, 'OTP_INVALID');
    }

    public function test_fake_destination_is_stable_for_the_same_input(): void
    {
        $a = $this->postJson(self::P.'/request', ['identifier' => '08099999999'])->json('data.destination');
        $b = $this->postJson(self::P.'/request', ['identifier' => '08099999999'])->json('data.destination');

        $this->assertSame($a, $b);
    }

    public function test_wrong_code_is_rejected_and_no_token_is_issued(): void
    {
        $this->patient();
        $id = $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->json('data.resetId');

        $this->assertApiError($this->postJson(self::P.'/verify-otp', ['resetId' => $id, 'code' => '9999']), 422, 'OTP_INVALID');
        $this->assertApiError($this->postJson(self::P.'/confirm', ['resetToken' => str_repeat('a', 64), 'password' => 'Brandnew99']), 404, 'RESET_NOT_FOUND');
    }

    public function test_request_cap_per_identifier(): void
    {
        $this->patient();
        // Switch off the per-IP route throttle so this test measures the per-identifier cap only.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->assertOk();
        }

        $this->assertApiError($this->postJson(self::P.'/request', ['identifier' => '08031234567']), 429, 'TOO_MANY_ATTEMPTS');
    }

    public function test_confirming_signs_the_user_out_everywhere(): void
    {
        $user = $this->patient();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $id = $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->json('data.resetId');
        $token = $this->postJson(self::P.'/verify-otp', ['resetId' => $id, 'code' => '1234'])->json('data.resetToken');
        $this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'Brandnew99'])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_token_expires(): void
    {
        $this->patient();
        $id = $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->json('data.resetId');
        $token = $this->postJson(self::P.'/verify-otp', ['resetId' => $id, 'code' => '1234'])->json('data.resetToken');

        $this->travel(16)->minutes();

        $this->assertApiError($this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'Brandnew99']), 404, 'RESET_NOT_FOUND');
    }

    public function test_weak_new_password_is_rejected_and_token_survives(): void
    {
        $this->patient();
        $id = $this->postJson(self::P.'/request', ['identifier' => '08031234567'])->json('data.resetId');
        $token = $this->postJson(self::P.'/verify-otp', ['resetId' => $id, 'code' => '1234'])->json('data.resetToken');

        $this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'weak'])->assertStatus(422);
        $this->postJson(self::P.'/confirm', ['resetToken' => $token, 'password' => 'Brandnew99'])->assertOk();
    }
}
