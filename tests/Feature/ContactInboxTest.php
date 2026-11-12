<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ContactMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class ContactInboxTest extends ApiTestCase
{
    private function message(array $overrides = []): ContactMessage
    {
        return ContactMessage::create($overrides + [
            'name' => 'Emma Johnson',
            'email' => 'emma@example.com',
            'phone' => null,
            'message' => 'Do you offer evening appointments?',
        ]);
    }

    public function test_guests_cannot_read_the_inbox(): void
    {
        $this->getJson('/api/contact-messages')->assertUnauthorized();
    }

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_read_the_inbox_with_an_unread_count(Role $role): void
    {
        $read = $this->message();
        $read->forceFill(['read_at' => now()])->save();
        $this->message();

        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/contact-messages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 1);

        $this->getJson('/api/contact-messages?status=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[DataProvider('nonFrontDeskRoles')]
    public function test_doctors_and_patients_cannot_read_or_change_messages(Role $role): void
    {
        $message = $this->message();
        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/contact-messages')->assertForbidden();
        $this->putJson("/api/contact-messages/{$message->id}", ['read' => true])->assertForbidden();
        $this->deleteJson("/api/contact-messages/{$message->id}")->assertForbidden();

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_a_message_can_be_marked_read_and_unread(): void
    {
        $message = $this->message();
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/contact-messages/{$message->id}", ['read' => true])->assertOk();
        $this->assertNotNull($message->fresh()->read_at);

        $this->putJson("/api/contact-messages/{$message->id}", ['read' => false])->assertOk();
        $this->assertNull($message->fresh()->read_at);

        $this->putJson("/api/contact-messages/{$message->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('read');
    }

    #[DataProvider('adminRoles')]
    public function test_admins_can_delete_messages(Role $role): void
    {
        $message = $this->message();
        $this->actingAs($this->userWithRole($role));

        $this->deleteJson("/api/contact-messages/{$message->id}")->assertNoContent();

        $this->assertDatabaseMissing('contact_messages', ['id' => $message->id]);
    }

    public function test_receptionists_cannot_delete_messages(): void
    {
        $message = $this->message();
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->deleteJson("/api/contact-messages/{$message->id}")->assertForbidden();

        $this->assertDatabaseHas('contact_messages', ['id' => $message->id]);
    }

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_get_the_unread_count(Role $role): void
    {
        $read = $this->message();
        $read->forceFill(['read_at' => now()])->save();
        $this->message();
        $this->message();

        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/contact-messages/unread-count')
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    #[DataProvider('nonFrontDeskRoles')]
    public function test_doctors_and_patients_cannot_get_the_unread_count(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/contact-messages/unread-count')->assertForbidden();
    }
}