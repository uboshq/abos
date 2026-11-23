<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ The phone's polled doors answer an empty 304 when nothing changed — and only to the same user (speed work,
 * 23 Nov 2026; [[AnswersUnchanged]], [[UnchangedAnswer]]).
 *
 * Claims, for every door: a weak `W/"…"` ETag with `no-store, private`; the same tag back → 304 with no body; the
 * same tag sent by another user → a full 200 with that user's own, different tag (never a 304 built on someone
 * else's answer).
 */
final class PhoneDoorsAnswer304OnlyToTheSameUserTest extends TestCase
{
    use RefreshDatabase;

    private const DOORS = [
        '/api/v1/notifications',
        '/api/v1/notices/bar',
        '/api/v1/dashboard/today',
        '/api/v1/widgets/summary',
    ];

    private User $owner;

    private User $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->accounts = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
    }

    public function test_the_same_answer_is_a_bodiless_304_for_the_same_user(): void
    {
        foreach ([...self::DOORS, '/api/v1/approvals/pending', '/api/v1/accounts/net-worth/summary', '/api/v1/messenger/threads'] as $door) {
            $first = $this->phone($this->owner)->getJson($door);

            if ($first->getStatusCode() !== 200) {
                // ⓘ a door the demo owner may not open on the phone (module off) proves nothing here
                continue;
            }

            $etag = (string) $first->headers->get('ETag');
            $this->assertStringStartsWith('W/"', $etag, "⛔ {$door}: no weak ETag.");
            $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'), "⛔ {$door}: personal answer may be stored.");

            $again = $this->phone($this->owner)->getJson($door, ['If-None-Match' => $etag]);
            $again->assertStatus(304);
            $this->assertSame('', $again->getContent(), "⛔ {$door}: a 304 with a body.");
        }
    }

    public function test_another_users_tag_never_gets_a_304(): void
    {
        foreach (self::DOORS as $door) {
            $mine = $this->phone($this->owner)->getJson($door);

            if ($mine->getStatusCode() !== 200) {
                continue;
            }

            $tag = (string) $mine->headers->get('ETag');
            $theirs = $this->phone($this->accounts)->getJson($door, ['If-None-Match' => $tag]);

            if ($theirs->getStatusCode() === 403) {
                continue;
            }

            $theirs->assertOk();
            $this->assertNotSame($tag, (string) $theirs->headers->get('ETag'), "⛔ {$door}: two users share one ETag.");
        }
    }

    public function test_a_tag_in_a_list_or_without_the_weak_mark_still_matches(): void
    {
        $tag = (string) $this->phone($this->owner)->getJson('/api/v1/notifications')->assertOk()->headers->get('ETag');
        $strong = substr($tag, 2);

        $this->phone($this->owner)->getJson('/api/v1/notifications', ['If-None-Match' => '"other", '.$strong])->assertStatus(304);
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }
}
