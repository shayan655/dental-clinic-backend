<?php

namespace Tests\Feature;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class SettingsTest extends ApiTestCase
{
    private array $settings = [
        'name' => 'DentalCare',
        'address' => 'Hoofdstraat 12, Amsterdam',
        'phone' => '+31 20 123 4567',
        'email' => 'info@dentalcare.nl',
    ];

    public function test_clinic_info_and_hours_are_public(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonStructure(['data' => ['name', 'address', 'phone', 'email']]);
        $this->getJson('/api/settings/hours')->assertOk()->assertJsonCount(7, 'data');
    }

    public function test_the_week_starts_on_monday_and_the_weekend_is_closed(): void
    {
        $hours = $this->getJson('/api/settings/hours')->json('data');

        $this->assertSame('Monday', $hours[0]['day']);
        $this->assertTrue($hours[0]['is_open']);
        $this->assertSame('09:00', $hours[0]['open_time']);
        $this->assertSame('Saturday', $hours[5]['day']);
        $this->assertFalse($hours[5]['is_open']);
    }

    #[DataProvider('adminRoles')]
    public function test_admins_can_update_clinic_info_and_hours(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $this->putJson('/api/settings', $this->settings)->assertOk()->assertJsonPath('data.address', 'Hoofdstraat 12, Amsterdam');

        $hours = $this->getJson('/api/settings/hours')->json('data');
        $hours[4]['close_time'] = '15:00'; // Friday

        $this->putJson('/api/settings/hours', ['hours' => $hours])->assertOk();

        $this->assertSame('15:00', $this->getJson('/api/settings/hours')->json('data.4.close_time'));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_update_settings(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $hours = $this->getJson('/api/settings/hours')->json('data');

        $this->putJson('/api/settings', $this->settings)->assertForbidden();
        $this->putJson('/api/settings/hours', ['hours' => $hours])->assertForbidden();
    }

    public function test_a_day_cannot_close_before_it_opens(): void
    {
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $hours = $this->getJson('/api/settings/hours')->json('data');
        $hours[0]['open_time'] = '17:00';
        $hours[0]['close_time'] = '09:00';

        $this->putJson('/api/settings/hours', ['hours' => $hours])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hours.0.close_time');
    }
}