<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Jobs\SendPushToUser;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendCallEnd;
use App\Modules\Messaging\Models\Call;
use App\Modules\Messaging\Services\LiveKitToken;
use App\Modules\Messaging\Services\MessengerAccess;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Messenger calls, server half (contract §2, §4, §5): the LiveKit token, the ring push, who may answer, the
 * call-over race, hang-up → missed / ended, the 45 s sweep, the 404 wall, calls_off and the allow-list.
 */
final class CallsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-that-is-long-enough-for-hs256';

    private Company $company;

    private User $caller;

    private User $callee;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->set(PhoneModules::key('messaging'), true);

        config(['services.livekit' => ['url' => 'wss://rtc.example.test', 'key' => 'APItestkey', 'secret' => self::SECRET]]);

        $this->caller = $this->person('Caller One');
        $this->callee = $this->person('Callee Two');
        $this->stranger = $this->person('Third Person');

        Queue::fake();
    }

    public function test_the_token_carries_the_claims_and_its_signature_verifies(): void
    {
        $token = app(LiveKitToken::class)->for($this->caller, 'call-ABC');
        [$h, $c, $s] = explode('.', $token);

        $this->assertStringNotContainsString('=', $token, 'base64url must have no padding');
        $this->assertSame(LiveKitToken::b64(hash_hmac('sha256', $h.'.'.$c, self::SECRET, true)), $s, 'signature does not verify');

        $header = json_decode($this->unb64($h), true);
        $claims = json_decode($this->unb64($c), true);

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        $this->assertSame('APItestkey', $claims['iss']);
        $this->assertSame((string) $this->caller->id, $claims['sub']);
        $this->assertSame('Caller One', $claims['name']);
        $this->assertSame(7200, $claims['exp'] - $claims['nbf']);
        $this->assertSame(['roomJoin' => true, 'room' => 'call-ABC', 'canPublish' => true, 'canSubscribe' => true], $claims['video']);
    }

    public function test_start_rings_the_callee_and_gives_the_caller_a_room(): void
    {
        $data = $this->phone($this->caller, 'POST', '/api/v1/messenger/calls', ['person' => (string) $this->callee->id, 'video' => true])
            ->assertCreated()->json('data');

        $call = $this->findCall($data['call_id']);
        $this->assertSame(Call::RINGING, $call->status);
        $this->assertSame('call-'.$call->public_id, $data['room']);
        $this->assertSame('wss://rtc.example.test', $data['url']);
        $this->assertTrue($data['video']);
        $this->assertSame((string) $this->callee->id, $data['peer']['id']);
        $this->assertNotSame('', (string) $data['token']);

        Queue::assertPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $this->callee->id
            && $job->title === 'Caller One'
            && $job->data['type'] === 'call'
            && $job->data['call_id'] === $call->public_id
            && $job->data['video'] === '1'
            && $job->data['from'] === (string) $this->caller->id
            && $job->data['thread_id'] !== '');
    }

    public function test_only_the_callee_answers_and_a_second_answer_is_too_late(): void
    {
        $id = $this->start();

        $this->phone($this->caller, 'POST', "/api/v1/messenger/calls/{$id}/answer")->assertForbidden();

        $data = $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/answer")->assertOk()->json('data');
        $this->assertSame((string) $this->caller->id, $data['peer']['id']);
        $this->assertSame(Call::ANSWERED, $this->findCall($id)->status);

        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/answer")
            ->assertStatus(409)->assertJsonPath('error', 'call_over');

        Queue::assertPushed(SendCallEnd::class, fn (SendCallEnd $job): bool => $job->userId === (int) $this->callee->id
            && $job->callId === $id && $job->status === Call::ANSWERED);
    }

    public function test_the_caller_hanging_up_a_ringing_call_makes_it_missed(): void
    {
        $id = $this->start();

        $this->phone($this->caller, 'POST', "/api/v1/messenger/calls/{$id}/end")->assertNoContent();

        $this->assertSame(Call::MISSED, $this->findCall($id)->status);
        Queue::assertPushed(SendCallEnd::class, fn (SendCallEnd $job): bool => $job->userId === (int) $this->callee->id
            && $job->status === Call::MISSED && $job->data()['type'] === 'call_end');
        Queue::assertPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => $job->userId === (int) $this->callee->id
            && ($job->data['status'] ?? null) === Call::MISSED);

        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/answer")->assertStatus(409);
    }

    public function test_ending_an_answered_call_keeps_its_length(): void
    {
        $id = $this->start();
        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/answer")->assertOk();

        $this->travel(95)->seconds();
        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/end")->assertNoContent();

        $call = $this->findCall($id);
        $this->assertSame(Call::ENDED, $call->status);
        $this->assertSame(95, $call->duration_s);
        Queue::assertPushed(SendCallEnd::class, fn (SendCallEnd $job): bool => $job->userId === (int) $this->caller->id
            && $job->status === Call::ENDED);

        $row = $this->phone($this->caller, 'GET', '/api/v1/messenger/calls')->assertOk()->json('data.0');
        $this->assertSame($id, $row['call_id']);
        $this->assertSame('out', $row['direction']);
        $this->assertSame(Call::ENDED, $row['status']);
        $this->assertSame(95, $row['duration_s']);
        $this->assertSame('in', $this->phone($this->callee, 'GET', '/api/v1/messenger/calls')->json('data.0.direction'));
    }

    public function test_the_callee_declines(): void
    {
        $id = $this->start();

        $this->phone($this->caller, 'POST', "/api/v1/messenger/calls/{$id}/decline")->assertForbidden();
        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/decline")->assertNoContent();

        $this->assertSame(Call::DECLINED, $this->findCall($id)->status);
        Queue::assertPushed(SendCallEnd::class, fn (SendCallEnd $job): bool => $job->userId === (int) $this->caller->id
            && $job->status === Call::DECLINED);
    }

    public function test_a_ring_nobody_answers_is_missed_after_45_seconds(): void
    {
        $id = $this->start();

        $this->travel(30)->seconds();
        $this->artisan('messenger:sweep-calls')->assertSuccessful();
        $this->assertSame(Call::RINGING, $this->findCall($id)->status, 'missed too early');

        $this->travel(16)->seconds();
        $this->artisan('messenger:sweep-calls')->assertSuccessful();
        $this->assertSame(Call::MISSED, $this->findCall($id)->status);

        Queue::assertPushed(SendCallEnd::class, fn (SendCallEnd $job): bool => $job->userId === (int) $this->callee->id
            && $job->status === Call::MISSED);
        Queue::assertPushed(SendPushToUser::class, fn (SendPushToUser $job): bool => ($job->data['status'] ?? null) === Call::MISSED);
    }

    public function test_an_overdue_ring_is_settled_when_it_is_read(): void
    {
        $id = $this->start();
        $this->travel(50)->seconds();

        // no sweep ran — the answer itself finds the ring is over
        $this->phone($this->callee, 'POST', "/api/v1/messenger/calls/{$id}/answer")->assertStatus(409);
        $this->assertSame(Call::MISSED, $this->findCall($id)->status);
    }

    public function test_a_third_person_gets_404(): void
    {
        $id = $this->start();

        foreach (['answer', 'decline', 'end'] as $step) {
            $this->phone($this->stranger, 'POST', "/api/v1/messenger/calls/{$id}/{$step}")->assertNotFound();
        }

        $this->assertSame([], $this->phone($this->stranger, 'GET', '/api/v1/messenger/calls')->assertOk()->json('data'));
        $this->assertSame(Call::RINGING, $this->findCall($id)->status);
    }

    public function test_calls_are_off_while_livekit_is_not_set_up(): void
    {
        config(['services.livekit' => ['url' => '', 'key' => '', 'secret' => '']]);

        $this->phone($this->caller, 'POST', '/api/v1/messenger/calls', ['person' => (string) $this->callee->id, 'video' => false])
            ->assertStatus(503)->assertJsonPath('error', 'calls_off');

        $this->assertSame(0, Call::query()->count());
        Queue::assertNotPushed(SendPushToUser::class);
    }

    public function test_the_allow_list_stops_a_call(): void
    {
        // ⓘ a non-empty list where the caller's roles appear nowhere → may start talking to nobody
        app(SettingsService::class)->set(MessengerAccess::SETTING, json_encode(['Somebody Else' => ['*']]));

        $this->phone($this->caller, 'POST', '/api/v1/messenger/calls', ['person' => (string) $this->callee->id])
            ->assertForbidden()->assertJsonPath('error', 'may_not_talk');

        $this->phone($this->caller, 'POST', '/api/v1/messenger/calls', ['person' => (string) $this->caller->id])
            ->assertForbidden()->assertJsonPath('error', 'may_not_talk');

        $this->assertSame(0, Call::query()->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function start(): string
    {
        return (string) $this->phone($this->caller, 'POST', '/api/v1/messenger/calls', ['person' => (string) $this->callee->id, 'video' => false])
            ->assertCreated()->json('data.call_id');
    }

    private function findCall(string $publicId): Call
    {
        return CompanyContext::forCompany($this->company->id, fn () => Call::query()->wherePublicId($publicId)->firstOrFail());
    }

    private function person(string $name): User
    {
        $branch = $this->company->defaultBranch()?->id;
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'current_branch_id' => $branch, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('messaging.use', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @param  array<string, mixed>  $body */
    private function phone(User $user, string $method, string $url, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->json($method, $url, $body);
    }

    private function unb64(string $part): string
    {
        return (string) base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
    }
}
