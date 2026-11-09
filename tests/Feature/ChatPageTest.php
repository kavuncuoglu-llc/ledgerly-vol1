<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_sign_in_form_and_not_the_chat(): void
    {
        $response = $this->get('chat');

        $response->assertOk()
            ->assertSee('id="login-form"', false)
            ->assertDontSee('id="chat-log"', false);
    }

    public function test_a_signed_in_user_sees_the_chat_with_the_realtime_settings(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'local-key');
        config()->set('broadcasting.connections.reverb.options.host', 'localhost');
        config()->set('broadcasting.connections.reverb.options.port', 8080);
        config()->set('broadcasting.connections.reverb.options.scheme', 'http');

        $response = $this->actingAs(User::factory()->create())->get('chat');

        $response->assertOk()
            ->assertSee('id="chat-log"', false)
            ->assertSee('id="chat-form"', false)
            ->assertSee('js/chat.js', false)
            ->assertSee('"key":"local-key"', false)
            ->assertSee('"port":8080', false)
            ->assertSee('"websocketsEnabled":true', false);
    }

    public function test_without_reverb_the_page_tells_the_script_to_use_server_sent_events(): void
    {
        config()->set('broadcasting.default', 'null');

        $response = $this->actingAs(User::factory()->create())->get('chat');

        $response->assertSee('"websocketsEnabled":false', false);
    }

    public function test_the_navigation_links_to_the_assistant(): void
    {
        $this->get('customers')->assertSee('href="'.url('chat').'"', false);
    }

    public function test_the_scripts_are_served_as_static_files(): void
    {
        $this->assertFileExists(public_path('js/chat.js'));
        $this->assertFileExists(public_path('js/reply-channel.js'));
    }
}
