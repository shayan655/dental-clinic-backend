<?php

namespace Tests\Feature;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class ServiceTest extends ApiTestCase
{
    public function test_anyone_can_list_services_without_logging_in(): void
    {
        $this->service(['name' => 'Cleaning']);
        $this->service(['name' => 'Filling']);

        $this->getJson('/api/services')->assertOk()->assertJsonCount(2, 'data');
    }

    #[DataProvider('adminRoles')]
    public function test_admins_can_create_update_and_delete_services(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $id = $this->postJson('/api/services', ['name' => 'Whitening', 'duration_minutes' => 60, 'price' => 250])
            ->assertCreated()
            ->assertJsonPath('data.price', '250.00')
            ->json('data.id');

        $this->putJson("/api/services/{$id}", ['price' => 300])
            ->assertOk()
            ->assertJsonPath('data.price', '300.00');

        $this->deleteJson("/api/services/{$id}")->assertNoContent();

        $this->assertSoftDeleted('services', ['id' => $id]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_change_services(Role $role): void
    {
        $service = $this->service();
        $this->actingAs($this->userWithRole($role));

        $this->postJson('/api/services', ['name' => 'Hacked', 'duration_minutes' => 30, 'price' => 1])->assertForbidden();
        $this->putJson("/api/services/{$service->id}", ['price' => 1])->assertForbidden();
        $this->deleteJson("/api/services/{$service->id}")->assertForbidden();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'deleted_at' => null]);
    }

    public function test_guests_cannot_change_services(): void
    {
        $this->postJson('/api/services', ['name' => 'X', 'duration_minutes' => 30, 'price' => 1])->assertUnauthorized();
    }

    public function test_service_details_are_validated(): void
    {
        $this->service(['name' => 'Cleaning']);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->postJson('/api/services', ['name' => 'Quick', 'duration_minutes' => 2, 'price' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_minutes');

        $this->postJson('/api/services', ['name' => 'Cleaning', 'duration_minutes' => 30, 'price' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/services', ['name' => 'Free', 'duration_minutes' => 30, 'price' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_a_deleted_service_leaves_the_list_but_stays_on_old_appointments(): void
    {
        $service = $this->service(['name' => 'Old treatment']);
        $appointment = $this->appointment($this->patientFor(), $this->userWithRole(Role::Doctor), $service, self::MONDAY . ' 10:00');

        $this->actingAs($this->userWithRole(Role::ClinicManager));
        $this->deleteJson("/api/services/{$service->id}")->assertNoContent();

        $this->getJson('/api/services')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.service.name', 'Old treatment');
    }
}