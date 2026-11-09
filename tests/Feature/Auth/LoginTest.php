<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_signs_the_user_in_and_returns_the_user_in_the_data_envelope(): void
    {
        $user = User::factory()->create(['email' => 'acme@example.com']);

        $response = $this->postJson('login', [
            'email' => 'acme@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => 'acme@example.com',
            ],
        ]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_store_keeps_the_session_so_the_user_can_reach_an_authenticated_route(): void
    {
        User::factory()->create(['email' => 'acme@example.com']);

        $this->postJson('login', [
            'email' => 'acme@example.com',
            'password' => 'password',
        ]);

        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'ok',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ])]);

        $this->postJson('support/ask', ['question' => 'Hi'])->assertOk();
    }

    public function test_store_rejects_a_wrong_password_with_422(): void
    {
        User::factory()->create(['email' => 'acme@example.com']);

        $response = $this->postJson('login', [
            'email' => 'acme@example.com',
            'password' => 'wrong',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_store_rejects_an_unknown_email_with_422(): void
    {
        $response = $this->postJson('login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_store_requires_an_email_and_a_password(): void
    {
        $response = $this->postJson('login', []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'email',
            'password',
        ]);
    }

    public function test_store_rejects_an_email_that_is_not_an_email(): void
    {
        $response = $this->postJson('login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_store_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('login', [
                'email' => 'nobody@example.com',
                'password' => 'password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertTooManyRequests();
    }
}
