<?php

namespace Tests\Feature\CompanySettings;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\Facades\UserFactory;
use Tests\TestCase;

class SlackSettingsTest extends TestCase
{
    private const URI = 'api/company-settings';

    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // UserFactory::asAdmin() sets role_id to the same value as asUser() and only flips
        // the unrelated `is_admin` column, which hasRole()/isAdmin() never read — so it does
        // not actually satisfy `hasRole(Role::ADMIN)`. Set role_id directly instead.
        $this->admin = UserFactory::refresh()->asUser()->create();
        $this->admin->role_id = Role::ADMIN->value;
        $this->admin->save();

        $this->user = UserFactory::refresh()->asUser()->create();
    }

    public function test_index_never_exposes_slack_bot_token(): void
    {
        Setting::updateOrCreate(
            ['module_name' => 'core', 'key' => 'slack_bot_token'],
            ['value' => Crypt::encryptString('xoxb-super-secret')],
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(self::URI);

        $response->assertOk();
        $response->assertJsonMissingPath('data.slack_bot_token');
        $this->assertStringNotContainsString('xoxb-super-secret', $response->getContent());
    }

    public function test_index_reports_slack_not_configured_by_default(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(self::URI);

        $response->assertOk();
        $response->assertJsonPath('data.slack_configured', false);
    }

    public function test_admin_can_configure_slack(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_bot_token' => 'xoxb-abc-123',
            'slack_channel_id' => 'C12345',
        ]);

        $response->assertNoContent();

        $stored = Setting::where(['module_name' => 'core', 'key' => 'slack_bot_token'])->first();
        $this->assertNotNull($stored);
        $this->assertSame('xoxb-abc-123', Crypt::decryptString($stored->value));

        $index = $this->getJson(self::URI);
        $index->assertJsonPath('data.slack_configured', true);
        $index->assertJsonPath('data.slack_channel_id', 'C12345');
    }

    public function test_user_cannot_configure_slack(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_bot_token' => 'xoxb-abc-123',
            'slack_channel_id' => 'C12345',
        ]);

        $response->assertStatus(403);
        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_bot_token'])->first());
    }

    public function test_invalid_token_format_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::URI, ['slack_bot_token' => 'not-a-slack-token']);

        $response->assertStatus(422);
        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_bot_token'])->first());
    }

    public function test_omitting_token_on_update_keeps_existing_value(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson(self::URI, [
            'slack_bot_token' => 'xoxb-keep-me',
            'slack_channel_id' => 'C12345',
        ])->assertNoContent();

        $this->patchJson(self::URI, ['timezone' => 'UTC'])->assertNoContent();

        $stored = Setting::where(['module_name' => 'core', 'key' => 'slack_bot_token'])->first();
        $this->assertSame('xoxb-keep-me', Crypt::decryptString($stored->value));
    }

    public function test_empty_token_removes_it(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson(self::URI, [
            'slack_bot_token' => 'xoxb-remove-me',
            'slack_channel_id' => 'C12345',
        ])->assertNoContent();

        $this->patchJson(self::URI, ['slack_bot_token' => ''])->assertNoContent();

        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_bot_token'])->first());

        $index = $this->getJson(self::URI);
        $index->assertJsonPath('data.slack_configured', false);
    }

    public function test_undecryptable_token_is_reported_as_not_configured(): void
    {
        Setting::updateOrCreate(
            ['module_name' => 'core', 'key' => 'slack_bot_token'],
            ['value' => 'not-a-valid-encrypted-payload'],
        );
        Setting::updateOrCreate(
            ['module_name' => 'core', 'key' => 'slack_channel_id'],
            ['value' => 'C12345'],
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson(self::URI);

        $response->assertOk();
        $response->assertJsonPath('data.slack_configured', false);
    }

    public function test_admin_can_set_slack_user_mentions(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_user_mentions' => [
                ['email' => 'quyen@example.com', 'slack_user_id' => 'U0123ABCD'],
            ],
        ]);

        $response->assertNoContent();

        $stored = Setting::where(['module_name' => 'core', 'key' => 'slack_user_mentions'])->first();
        $this->assertNotNull($stored);
        $this->assertSame(
            [['email' => 'quyen@example.com', 'slack_user_id' => 'U0123ABCD']],
            json_decode($stored->value, true),
        );

        $index = $this->getJson(self::URI);
        $index->assertJsonPath('data.slack_user_mentions.0.email', 'quyen@example.com');
        $index->assertJsonPath('data.slack_user_mentions.0.slack_user_id', 'U0123ABCD');
    }

    public function test_slack_user_mentions_rejects_invalid_email(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_user_mentions' => [
                ['email' => 'not-an-email', 'slack_user_id' => 'U0123ABCD'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_user_mentions'])->first());
    }

    public function test_slack_user_mentions_rejects_invalid_slack_id_format(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_user_mentions' => [
                ['email' => 'quyen@example.com', 'slack_user_id' => 'not-a-slack-id'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_user_mentions'])->first());
    }

    public function test_empty_slack_user_mentions_clears_it(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->patchJson(self::URI, [
            'slack_user_mentions' => [['email' => 'quyen@example.com', 'slack_user_id' => 'U0123ABCD']],
        ])->assertNoContent();

        $this->patchJson(self::URI, ['slack_user_mentions' => []])->assertNoContent();

        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_user_mentions'])->first());

        $index = $this->getJson(self::URI);
        $index->assertJsonPath('data.slack_user_mentions', []);
    }

    public function test_user_cannot_set_slack_user_mentions(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->patchJson(self::URI, [
            'slack_user_mentions' => [['email' => 'quyen@example.com', 'slack_user_id' => 'U0123ABCD']],
        ]);

        $response->assertStatus(403);
        $this->assertNull(Setting::where(['module_name' => 'core', 'key' => 'slack_user_mentions'])->first());
    }
}
