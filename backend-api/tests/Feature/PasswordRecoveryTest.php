<?php

namespace Tests\Feature;

use App\Models\PasswordResetCode;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_09_19_182046_create_personal_access_tokens_table.php',
            '2026_09_30_034413_add_activo_to_users_table.php',
            '2026_10_13_230949_create_password_reset_codes_table.php',
            '2026_10_14_000002_add_attempts_to_password_reset_codes_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->user = User::create([
            'name' => 'Paciente', 'email' => 'patient@example.test',
            'password' => 'Original123!', 'activo' => true,
        ]);
    }

    private function issue(): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $this->user->email])->assertOk();
        $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame($this->user->email, $message->getTo()[0]->getAddress());
        preg_match('/\b[0-9]{6}\b/', $message->getTextBody(), $matches);

        return $matches[0];
    }

    private function payload(string $code): array
    {
        return [
            'email' => $this->user->email, 'code' => $code,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ];
    }

    public function test_success_hashes_password_revokes_tokens_and_prevents_reuse(): void
    {
        $this->user->createToken('existing');
        $code = $this->issue();
        $this->assertTrue(Hash::check($code, PasswordResetCode::first()->code_hash));
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertNotNull(PasswordResetCode::first()->used_at);
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertUnprocessable();
    }

    public function test_five_incorrect_attempts_permanently_exhaust_the_code(): void
    {
        $code = $this->issue();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', $this->payload('000000'))->assertUnprocessable();
        }
        $this->assertSame(5, PasswordResetCode::first()->attempts);
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertUnprocessable();
        $this->assertTrue(Hash::check('Original123!', $this->user->fresh()->password));
    }

    public function test_code_expires_at_five_minutes(): void
    {
        $code = $this->issue();
        $this->travelTo(PasswordResetCode::first()->expires_at);
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertUnprocessable();
    }

    public function test_unknown_and_inactive_accounts_receive_the_same_response(): void
    {
        $active = $this->postJson('/api/auth/forgot-password', ['email' => $this->user->email])->assertOk()->json();
        $missing = $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->user->update(['activo' => false]);
        $this->travel(61)->seconds();
        $inactive = $this->postJson('/api/auth/forgot-password', ['email' => $this->user->email])->assertOk()->json();
        $this->assertSame($active, $missing);
        $this->assertSame($active, $inactive);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_resend_cooldown_applies_across_ips_and_case_variants(): void
    {
        $this->issue();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])
            ->postJson('/api/auth/forgot-password', ['email' => strtoupper($this->user->email)])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(1, PasswordResetCode::count());
    }

    public function test_hourly_limit_and_resend_replacement(): void
    {
        $this->issue();
        $firstId = PasswordResetCode::first()->id;
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->issue();
        }
        $this->assertSame(1, PasswordResetCode::count());
        $this->assertNotSame($firstId, PasswordResetCode::first()->id);
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/forgot-password', ['email' => $this->user->email])->assertStatus(429);
    }

    public function test_transport_failure_preserves_previous_code_and_hides_smtp_details(): void
    {
        $code = $this->issue();
        $id = PasswordResetCode::first()->id;
        $this->travel(61)->seconds();
        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('sensitive smtp details'));
        Log::shouldReceive('error')->once()->with('password_reset_mail_failed', ['exception_type' => TransportException::class]);
        $this->postJson('/api/auth/forgot-password', ['email' => $this->user->email])
            ->assertOk()->assertDontSee('sensitive smtp details');
        $this->assertSame($id, PasswordResetCode::first()->id);
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertOk();
    }

    public function test_production_rejects_log_transport_even_for_unknown_email(): void
    {
        $this->app->instance('env', 'production');
        config(['mail.default' => 'log']);
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test'])->assertStatus(503);
        $this->assertSame(0, PasswordResetCode::count());
    }

    public function test_validation_requires_digits_and_matching_password_confirmation(): void
    {
        $this->postJson('/api/auth/reset-password', $this->payload('abcdef'))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/auth/reset-password', array_replace($this->payload('123456'), [
            'password_confirmation' => 'different',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_deactivated_account_cannot_redeem_an_existing_code(): void
    {
        $code = $this->issue();
        $this->user->update(['activo' => false]);
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertUnprocessable();
    }

    public function test_multibyte_password_cannot_exceed_bcrypt_byte_limit(): void
    {
        $password = str_repeat('ñ', 40);
        $this->postJson('/api/auth/reset-password', array_replace($this->payload('123456'), [
            'password' => $password, 'password_confirmation' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_ip_limit_blocks_requests_for_many_different_addresses(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "missing$i@example.test"])->assertOk();
        }
        $this->postJson('/api/auth/forgot-password', ['email' => 'another@example.test'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_service_cooldown_preserves_code_without_relying_on_cache(): void
    {
        $this->issue();
        $id = PasswordResetCode::first()->id;
        app(PasswordService::class)->enviarCodigoRecuperacion($this->user->email);
        $this->assertSame($id, PasswordResetCode::first()->id);
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_reset_is_atomic_when_token_revocation_fails(): void
    {
        $code = $this->issue();
        DB::statement('DROP TABLE personal_access_tokens');
        $this->postJson('/api/auth/reset-password', $this->payload($code))->assertStatus(500);
        $this->assertNull(PasswordResetCode::first()->used_at);
        $this->assertTrue(Hash::check('Original123!', $this->user->fresh()->password));
    }
}
