<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Services\MessengerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The web messages page (sample M1UddRxK v7): it opens behind `messaging.use`, draws no description line, hands the
 * page script its words and doors, and the JSON doors under /messages/x answer a member and stay a 404 wall for
 * everyone else — the same doors as the phone.
 */
final class WebMessagesPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $me;

    private User $peer;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->set(PhoneModules::key('messaging'), true);
        Queue::fake();

        $this->me = $this->person('Web Me', true);
        $this->peer = $this->person('Web Peer', true);
        $this->stranger = $this->person('Web Stranger', true);
    }

    public function test_the_page_opens_for_a_person_with_the_key(): void
    {
        $this->actingAs($this->me)
            ->get(route('messaging.index'))
            ->assertOk()
            ->assertSee('data-msgr', false)
            ->assertSee('data-words', false)
            ->assertSee('data-routes', false)
            ->assertSee('data-csrf', false)
            ->assertSee(__('messaging::web.new_message'));
    }

    public function test_the_page_carries_the_thread_to_open(): void
    {
        $thread = app(MessengerService::class)->directWith($this->me, $this->peer);

        $this->actingAs($this->me)
            ->get(route('messaging.index', ['t' => $thread->public_id]))
            ->assertOk()
            ->assertSee('data-open="'.$thread->public_id.'"', false);
    }

    /** ⭐ owner, 24 Nov 2026: everyone talks to everyone — the page opens for every active account, key or not; a guest is sent to sign in */
    public function test_the_page_opens_for_every_active_account_and_never_for_a_guest(): void
    {
        $plain = $this->person('No Key', false);

        $this->actingAs($plain)->get(route('messaging.index'))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->get(route('messaging.index'))->assertRedirect();
    }

    public function test_the_page_draws_no_description_line(): void
    {
        $html = $this->actingAs($this->me)->get(route('messaging.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-page-subtitle', (string) $html, '⛔ no description line under the title (owner, 19 Nov)');
        $this->assertSame(1, preg_match_all('/<h1[\s>]/', (string) $html), 'one main heading — the list pane\'s own "বার্তা"');
    }

    public function test_the_json_doors_answer_a_member(): void
    {
        $thread = app(MessengerService::class)->directWith($this->me, $this->peer);
        $id = (string) $thread->public_id;

        $this->actingAs($this->me)
            ->postJson(route('messaging.x.send', ['thread' => $id]), ['kind' => 'text', 'text' => 'hello from the web', 'client_id' => 'web-1'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'text')
            ->assertJsonPath('data.mine', true);

        $this->actingAs($this->me)
            ->getJson(route('messaging.x.threads'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->actingAs($this->me)
            ->getJson(route('messaging.x.thread', ['thread' => $id]))
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->actingAs($this->peer)
            ->getJson(route('messaging.x.messages', ['thread' => $id]))
            ->assertOk()
            ->assertJsonPath('data.0.text', 'hello from the web')
            ->assertJsonPath('data.0.mine', false)
            ->assertJsonStructure(['data', 'meta' => ['typing', 'peer_online', 'states', 'has_more']]);

        $this->actingAs($this->me)
            ->getJson(route('messaging.x.members', ['thread' => $id]))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->me)
            ->getJson(route('messaging.x.people'))
            ->assertOk();
    }

    public function test_a_text_message_carries_no_attachment(): void
    {
        $thread = app(MessengerService::class)->directWith($this->me, $this->peer);

        $sent = $this->actingAs($this->me)
            ->postJson(route('messaging.x.send', ['thread' => $thread->public_id]), ['kind' => 'text', 'text' => 'no file', 'client_id' => 'web-2'])
            ->assertCreated()
            ->json('data');

        // ⓘ a text message has no attachment; the web reads `attachment.web_url` when there is one
        $this->assertNull($sent['attachment']);
    }

    public function test_a_non_member_gets_404_from_every_thread_door(): void
    {
        $thread = app(MessengerService::class)->directWith($this->me, $this->peer);
        $id = (string) $thread->public_id;

        $this->actingAs($this->stranger)->getJson(route('messaging.x.thread', ['thread' => $id]))->assertNotFound();
        $this->actingAs($this->stranger)->getJson(route('messaging.x.messages', ['thread' => $id]))->assertNotFound();
        $this->actingAs($this->stranger)->getJson(route('messaging.x.members', ['thread' => $id]))->assertNotFound();
        $this->actingAs($this->stranger)->getJson(route('messaging.x.media', ['thread' => $id]))->assertNotFound();
        $this->actingAs($this->stranger)
            ->postJson(route('messaging.x.send', ['thread' => $id]), ['kind' => 'text', 'text' => 'intruder', 'client_id' => 'x-1'])
            ->assertNotFound();
        $this->actingAs($this->stranger)->getJson(route('messaging.x.messages', ['thread' => 'NOPE-NOT-A-THREAD']))->assertNotFound();
    }

    /** ⭐ owner, 24 Nov 2026 — the JSON doors open for every active account; a guest is never answered */
    public function test_the_json_doors_open_for_every_active_account_and_never_for_a_guest(): void
    {
        $plain = $this->person('No Key Either', false);

        $this->actingAs($plain)->getJson(route('messaging.x.threads'))->assertOk();

        $this->app['auth']->forgetGuards();
        // ⓘ a web door: a guest is sent to the login page (302) or refused (401) — never answered
        $guest = $this->getJson(route('messaging.x.threads'));
        $this->assertContains($guest->status(), [302, 401], '⛔ a guest was let through the door.');
        $this->assertStringNotContainsString('unread_total', (string) $guest->getContent());
        $this->assertStringNotContainsString('"threads"', (string) $guest->getContent());
    }

    public function test_every_word_has_both_languages(): void
    {
        $keys = static function (array $words, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($words as $k => $v) {
                $out = is_array($v) && ! array_is_list($v) ? [...$out, ...$keys($v, $prefix.$k.'.')] : [...$out, $prefix.$k];
            }

            return $out;
        };

        $bn = $keys(require app_path('Modules/Messaging/Resources/lang/bn/web.php'));
        $en = $keys(require app_path('Modules/Messaging/Resources/lang/en/web.php'));
        sort($bn);
        sort($en);

        $this->assertSame($bn, $en, 'messaging web words: bn and en must carry the same keys');
    }

    private function person(string $name, bool $withKey): User
    {
        $branch = $this->company->defaultBranch()?->id;
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'current_branch_id' => $branch, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        if ($withKey) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('messaging.use', 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
