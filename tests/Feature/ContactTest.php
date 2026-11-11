<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Tests\ApiTestCase;

class ContactTest extends ApiTestCase
{
    private function message(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Emma Johnson',
            'email' => 'emma@example.com',
            'phone' => '+31 6 12345678',
            'message' => 'Do you offer evening appointments?',
        ];
    }

    public function test_visitors_can_send_a_message_without_logging_in(): void
    {
        $this->postJson('/api/contact', $this->message())->assertCreated();

        $stored = ContactMessage::firstOrFail();

        $this->assertSame('emma@example.com', $stored->email);
        $this->assertNull($stored->read_at);
    }

    public function test_the_phone_number_is_optional(): void
    {
        $this->postJson('/api/contact', $this->message(['phone' => null]))->assertCreated();
    }

    public function test_the_message_is_validated(): void
    {
        $this->postJson('/api/contact', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->postJson('/api/contact', $this->message(['email' => 'not-an-email']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/contact', $this->message(['message' => 'Hi']))
            ->assertUnprocessable()->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_the_form_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $this->message())->assertCreated();
        }

        $this->postJson('/api/contact', $this->message())->assertTooManyRequests();

        $this->assertDatabaseCount('contact_messages', 5);
    }
}