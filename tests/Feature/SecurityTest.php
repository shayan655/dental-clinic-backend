<?php

namespace Tests\Feature;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class SecurityTest extends ApiTestCase
{
    public static function privateEndpoints(): array
    {
        return collect([
            '/api/user',
            '/api/patients',
            '/api/patients/1',
            '/api/patients/1/records',
            '/api/appointments',
            '/api/appointments/1',
            '/api/availability',
            '/api/invoices',
            '/api/staff',
            '/api/doctors',
            '/api/dashboard/manager',
            '/api/dashboard/doctor',
            '/api/dashboard/receptionist',
            '/api/dashboard/patient',
        ])->mapWithKeys(fn (string $uri) => [$uri => [$uri]])->all();
    }

    #[DataProvider('privateEndpoints')]
    public function test_guests_are_turned_away_from_private_endpoints(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }

    public function test_guests_cannot_write_anything_private(): void
    {
        $this->postJson('/api/patients', [])->assertUnauthorized();
        $this->postJson('/api/appointments', [])->assertUnauthorized();
        $this->postJson('/api/invoices', [])->assertUnauthorized();
        $this->postJson('/api/staff', [])->assertUnauthorized();
        $this->putJson('/api/settings', [])->assertUnauthorized();
        $this->putJson('/api/profile', [])->assertUnauthorized();
        $this->putJson('/api/profile/password', [])->assertUnauthorized();
    }

    public function test_only_the_public_pages_are_open(): void
    {
        $this->getJson('/api/services')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings/hours')->assertOk();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->userWithRole(Role::Doctor, ['email' => 'doc@clinic.test', 'password' => 'secret-pass-1']);

        for ($i = 0; $i < 6; $i++) {
            $this->asFrontend()
                ->postJson('/api/login', ['email' => 'doc@clinic.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        // even the right password is refused while the limit is active
        $this->asFrontend()
            ->postJson('/api/login', ['email' => 'doc@clinic.test', 'password' => 'secret-pass-1'])
            ->assertTooManyRequests();
    }
}