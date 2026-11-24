<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ Live, 24 Nov 2026: the owner (open company 5) called Abu Kawser (ADI, company 3) — the call rang nowhere and both logs
 * were empty, because every read of a call or a chat was walled by the reader's open company ([[FollowsThePerson]]).
 *
 * Claims:
 *   · a call started from company A rings the callee whose open company is B (web `unread`), and sits in both call logs;
 *   · a chat started from company A shows in the other person's list while their open company is B;
 *   · ⛔ a third person is still kept out (404) — the wall that matters is the person, not the company;
 *   · ⛔ the audit page stays inside the open company.
 */
final class ACallRingsAcrossCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $owner;

    private User $clerk;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->a = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->b = Company::query()->whereKeyNot($this->a->id)->firstOrFail();

        foreach ([$this->a, $this->b] as $c) {
            CompanyContext::forCompany($c->id, fn () => app(SettingsService::class)->set(PhoneModules::key('messaging'), true));
        }

        config(['services.livekit' => ['url' => 'wss://rtc.example.test', 'key' => 'APItestkey', 'secret' => 'test-secret-that-is-long-enough-for-hs256']]);

        // the owner sits in both companies with A open; the clerk is in B only
        $this->owner = $this->person('Group Owner', [$this->a, $this->b], $this->a);
        $this->clerk = $this->person('B Clerk', [$this->b], $this->b);
        $this->stranger = $this->person('B Stranger', [$this->b], $this->b);

        Queue::fake();
    }

    public function test_a_call_from_another_company_rings_and_stays_in_both_logs(): void
    {
        $this->as($this->owner);
        $id = $this->postJson('/api/v1/messenger/calls', ['person' => (string) $this->clerk->id, 'video' => false])
            ->assertCreated()->json('data.call_id');

        $this->as($this->clerk, web: true);
        $ringing = $this->getJson(route('messaging.x.unread'))->assertOk()->json('ringing');
        $this->assertSame($id, $ringing['call_id'] ?? null, '⛔ the call from the other company did not ring');

        $this->as($this->clerk);
        $this->assertContains($id, collect($this->getJson('/api/v1/messenger/calls')->assertOk()->json('data'))->pluck('call_id')->all(),
            '⛔ the callee\'s log does not show the call');

        $this->as($this->owner);
        $this->assertContains($id, collect($this->getJson('/api/v1/messenger/calls')->assertOk()->json('data'))->pluck('call_id')->all(),
            '⛔ the caller\'s log does not show the call');

        $this->as($this->stranger);
        $this->postJson('/api/v1/messenger/calls/'.$id.'/answer')->assertNotFound();
    }

    public function test_a_chat_from_another_company_shows_in_the_other_persons_list(): void
    {
        $this->as($this->owner, web: true);
        $thread = $this->postJson(route('messaging.x.direct'), ['person' => (string) $this->clerk->id])->assertOk()->json('data.id')
            ?? $this->postJson(route('messaging.x.direct'), ['person' => (string) $this->clerk->id])->json('data.thread.id');

        $this->as($this->clerk, web: true);
        $html = (string) $this->getJson(route('messaging.x.threads'))->assertOk()->getContent();
        $this->assertStringContainsString('Group Owner', $html, '⛔ the chat started from the other company is missing');

        $this->as($this->stranger, web: true);
        $this->assertStringNotContainsString('Group Owner', (string) $this->getJson(route('messaging.x.threads'))->getContent(),
            '⛔ a third person sees a chat that is not theirs');
        $this->assertNotNull($thread);
    }

    /** @param list<Company> $companies */
    private function person(string $name, array $companies, Company $open): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $open->id,
            'current_branch_id' => $open->defaultBranch()?->id, 'is_active' => true]);

        foreach ($companies as $c) {
            $user->companies()->attach($c->id, ['is_active' => true]);
            CompanyContext::forCompany($c->id, fn () => $user->givePermissionTo(Permission::findOrCreate('messaging.use', 'web')));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function as(User $user, bool $web = false): void
    {
        $this->app['auth']->forgetGuards();
        $fresh = $user->fresh();
        CompanyContext::set((int) $fresh->current_company_id, $fresh->current_branch_id);

        if ($web) {
            $this->actingAs($fresh);
        } else {
            Sanctum::actingAs($fresh, [AuthController::APP]);
        }
    }
}
