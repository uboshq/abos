<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Models\Meeting;
use App\Modules\Messaging\Models\MeetingEvent;
use App\Modules\Messaging\Models\MeetingPerson;
use App\Modules\Messaging\Services\LiveKitToken;
use App\Modules\Messaging\Services\MeetingService;
use App\Modules\Messaging\Services\MessengerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Meetings, server half: off by default; the link alone gives the lobby, an invited colleague enters; the eleventh is refused
 * in words; every power is asked on the server; a removed person stays out; the host's going passes the meeting on; the
 * webhook is believed only when signed; the token is short and says what may be sent; a group has one running call.
 */
final class MeetingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-that-is-long-enough-for-hs256';

    private Company $company;

    private User $host;

    private User $guest;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        config(['services.livekit' => ['url' => 'wss://rtc.example.test', 'key' => 'APItestkey', 'secret' => self::SECRET]]);
        $this->switch(true);

        $this->host = $this->person('Host One');
        $this->guest = $this->person('Guest Two');
        $this->outsider = $this->person('Third Person');

        Queue::fake();
        Http::fake(['rtc.example.test/*' => Http::response([])]);
    }

    public function test_it_is_off_until_switched_on(): void
    {
        $this->assertFalse((bool) collect(require base_path('app/Modules/Messaging/module.php'))->get('settings')[0]['default']);

        $this->switch(false);
        $this->web($this->host, 'POST', route('messaging.x.meetings.start', ['key' => (string) Str::uuid()]), [])->assertStatus(503)
            ->assertJsonPath('error', 'meetings_off');
        $this->assertSame(0, Meeting::query()->count());
        $this->web($this->host, 'GET', route('messaging.index'))->assertOk()->assertDontSee('data-act="new-meeting"', false);

        $this->switch(true);
        $this->web($this->host, 'GET', route('messaging.index'))->assertOk()->assertSee('data-act="new-meeting"', false);
    }

    public function test_an_invited_colleague_enters_and_the_link_alone_gives_the_lobby(): void
    {
        $meeting = $this->started([$this->guest]);

        $in = $this->door($this->guest, $meeting, 'join')->assertOk()->json('data');
        $this->assertSame('in', $in['state']);
        $claims = json_decode(base64_decode(strtr(explode('.', $in['token'])[1], '-_', '+/')), true);
        $this->assertSame(LiveKitToken::MEETING_TTL, $claims['exp'] - $claims['nbf'], '⛔ the meeting token is not short');
        $this->assertSame('meet-'.$meeting->public_id, $claims['video']['room']);
        $this->assertContains('screen_share', $claims['video']['canPublishSources']);
        $this->assertArrayNotHasKey('roomAdmin', $claims['video']);

        $out = $this->door($this->outsider, $meeting, 'join')->assertOk()->json('data');
        $this->assertSame('waiting', $out['state']);
        $this->assertArrayNotHasKey('token', $out, '⛔ a token given in the lobby');
        $this->assertSame([], $out['people'], '⛔ the lobby sees who is inside');
        $this->door($this->outsider, $meeting, 'renew')->assertForbidden();
        // ⓘ asking again from the lobby does not open it
        $this->assertSame('waiting', $this->door($this->outsider, $meeting, 'join')->json('data.state'));

        $this->door($this->guest, $meeting, 'lobby', ['person' => $this->outsider->id, 'yes' => true])->assertForbidden();
        $this->door($this->host, $meeting, 'lobby', ['person' => $this->outsider->id, 'yes' => true])->assertNoContent();
        $this->assertSame('in', $this->door($this->outsider, $meeting, 'join')->json('data.state'));
    }

    public function test_the_eleventh_is_refused_in_words(): void
    {
        $meeting = $this->started([$this->guest]);

        foreach (range(2, Meeting::MAX_PEOPLE) as $i) {
            $meeting->people()->create(['company_id' => $meeting->company_id, 'user_id' => $this->person('Filler '.$i)->id,
                'state' => MeetingPerson::IN, 'joined_at' => now(), 'seen_at' => now()]);
        }

        $this->door($this->guest, $meeting, 'join')->assertStatus(409)->assertJsonPath('error', 'meeting_full')
            ->assertJsonPath('message', __('messaging::meeting.refused.meeting_full', ['max' => 10]));
        $this->assertSame(MeetingPerson::INVITED, $this->row($meeting, $this->guest)->state);
    }

    public function test_every_power_is_asked_on_the_server(): void
    {
        $meeting = $this->started([$this->guest, $this->outsider]);
        $this->door($this->guest, $meeting, 'join');

        foreach ([['mute', []], ['mute', ['person' => $this->host->id]], ['remove', ['person' => $this->host->id]], ['set', ['locked' => true]], ['end', []],
            ['invite', ['people' => [$this->outsider->id]]], ['stop-share', ['person' => $this->host->id]], ['role', ['person' => $this->guest->id, 'role' => 'cohost']]] as [$door, $body]) {
            $this->door($this->guest, $meeting, $door, $body)->assertForbidden();
        }
        Http::assertNotSent(fn ($r) => ! str_ends_with($r->url(), 'CreateRoom'));

        // ⓘ a host who is not inside runs nothing
        $this->door($this->host, $meeting, 'leave')->assertNoContent();
        $this->door($this->host, $meeting, 'mute')->assertForbidden();
    }

    public function test_a_removed_person_is_put_out_and_stays_out_until_called_back(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->guest, $meeting, 'join');

        $this->door($this->host, $meeting, 'remove', ['person' => $this->guest->id])->assertNoContent();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/twirp/livekit.RoomService/RemoveParticipant') && str_starts_with($r->url(), 'https://')
            && $r['identity'] === (string) $this->guest->id && $r['room'] === $meeting->room());
        $this->door($this->guest, $meeting, 'renew')->assertForbidden();
        $this->door($this->guest, $meeting, 'join')->assertForbidden()->assertJsonPath('error', 'meeting_removed');

        // an old token used again: the webhook says they came, and they are put out
        Http::fake(['rtc.example.test/*' => Http::response([])]);
        $this->hook(['event' => 'participant_joined', 'room' => ['name' => $meeting->room()], 'participant' => ['identity' => (string) $this->guest->id, 'sid' => 'PA_old']])->assertNoContent();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'RemoveParticipant'));
        $this->assertSame(MeetingPerson::REMOVED, $this->row($meeting, $this->guest)->state);

        $this->door($this->host, $meeting, 'invite', ['people' => [$this->guest->id]])->assertNoContent();
        $this->assertSame('in', $this->door($this->guest, $meeting, 'join')->json('data.state'));
    }

    public function test_a_call_server_that_does_not_do_it_is_said_and_the_wall_still_stands(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->guest, $meeting, 'join');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['rtc.example.test/*' => Http::response([], 500)]);

        $this->door($this->host, $meeting, 'remove', ['person' => $this->guest->id])->assertStatus(502)->assertJsonPath('error', 'meeting_server');
        $this->door($this->guest, $meeting, 'join')->assertForbidden();
    }

    public function test_mute_all_skips_the_one_who_asks_and_the_muted(): void
    {
        $meeting = $this->started([$this->guest]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/ListParticipants' => Http::response(['participants' => [
                ['identity' => (string) $this->host->id, 'tracks' => [['sid' => 'TR_h', 'source' => 'MICROPHONE']]],
                ['identity' => (string) $this->guest->id, 'tracks' => [['sid' => 'TR_g', 'source' => 'MICROPHONE'], ['sid' => 'TR_c', 'source' => 'CAMERA']]],
                ['identity' => '999999', 'tracks' => [['sid' => 'TR_m', 'source' => 'MICROPHONE', 'muted' => true]]],
            ]]),
            '*' => Http::response([]),
        ]);

        $this->door($this->host, $meeting, 'mute')->assertNoContent();

        $muted = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), 'MutePublishedTrack'));
        $this->assertSame(['TR_g'], $muted->map(fn ($r) => $r['track_sid'])->values()->all());
        $this->assertTrue($muted->first()['muted']);
        $this->assertTrue(MeetingEvent::query()->where('meeting_id', $meeting->id)->where('what', 'muted_all')->where('actor_id', $this->host->id)->exists());
    }

    public function test_sharing_can_be_switched_off_and_the_next_token_says_so(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->guest, $meeting, 'join');
        $this->door($this->host, $meeting, 'set', ['allow_share' => 'hosts'])->assertNoContent();

        $sources = fn (User $u): array => json_decode(base64_decode(strtr(explode('.', $this->door($u, $meeting, 'renew')->json('data.token'))[1], '-_', '+/')), true)['video']['canPublishSources'];
        $this->assertSame(['microphone', 'camera'], $sources($this->guest));
        $this->assertContains('screen_share', $sources($this->host));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'UpdateParticipant') && $r['identity'] === (string) $this->guest->id
            && $r['permission']['can_publish_sources'] === ['MICROPHONE', 'CAMERA']);
    }

    public function test_a_locked_meeting_takes_nobody_new(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->host, $meeting, 'set', ['locked' => true])->assertNoContent();
        $this->door($this->guest, $meeting, 'join')->assertForbidden()->assertJsonPath('error', 'meeting_locked');
    }

    public function test_the_host_goes_and_the_meeting_passes_on_then_ends_when_empty(): void
    {
        $meeting = $this->started([$this->guest, $this->outsider]);
        $this->door($this->guest, $meeting, 'join');
        $this->travel(5)->seconds();
        $this->door($this->outsider, $meeting, 'join');
        $this->door($this->host, $meeting, 'role', ['person' => $this->outsider->id, 'role' => 'cohost'])->assertNoContent();

        $this->door($this->host, $meeting, 'leave')->assertNoContent();
        $this->assertSame((int) $this->outsider->id, (int) $meeting->fresh()->host_id, '⛔ the co-host did not take over');
        $this->assertFalse($meeting->fresh()->isOver());

        $this->door($this->guest, $meeting, 'leave');
        $this->door($this->outsider, $meeting, 'leave');
        $this->assertNotNull($meeting->fresh()->empty_since);
        $this->travel(Meeting::EMPTY_SECONDS + 1)->seconds();
        $this->assertTrue((bool) $this->door($this->guest, $meeting, '')->json('data.over'), '⛔ an empty meeting never ended');
        $this->door($this->guest, $meeting, 'join')->assertStatus(409);
    }

    public function test_a_closed_tab_leaves_by_the_heartbeat_and_by_the_webhook_but_an_old_session_does_not_put_out_a_new_one(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->guest, $meeting, 'join');
        $joined = ['event' => 'participant_joined', 'room' => ['name' => $meeting->room()], 'participant' => ['identity' => (string) $this->guest->id, 'sid' => 'PA_new']];
        $this->hook($joined)->assertNoContent();

        $left = fn (string $sid) => $this->hook(['event' => 'participant_left', 'room' => ['name' => $meeting->room()], 'participant' => ['identity' => (string) $this->guest->id, 'sid' => $sid]]);
        $left('PA_old');
        $this->assertSame(MeetingPerson::IN, $this->row($meeting, $this->guest)->state, '⛔ an old session put out a new one');
        $this->travel(40)->seconds();
        $left('PA_new');
        $row = $this->row($meeting, $this->guest);
        $this->assertSame(MeetingPerson::LEFT, $row->state);
        $this->assertEqualsWithDelta(40, $row->seconds, 2);

        // LiveKit brings them back by itself (the line dropped)
        $this->hook($joined);
        $this->assertSame(MeetingPerson::IN, $this->row($meeting, $this->guest)->state);

        $this->travel(MeetingService::SEEN_SECONDS + 1)->seconds();
        $this->door($this->host, $meeting, '');
        $this->assertSame(MeetingPerson::LEFT, $this->row($meeting, $this->guest)->state, '⛔ a ghost stayed inside');
    }

    public function test_the_webhook_is_believed_only_when_signed_by_us_over_this_body(): void
    {
        $meeting = $this->started([$this->guest]);
        $this->door($this->guest, $meeting, 'join');
        $event = ['event' => 'participant_left', 'room' => ['name' => $meeting->room()], 'participant' => ['identity' => (string) $this->guest->id, 'sid' => '']];
        $this->hook(['event' => 'participant_joined'] + ['participant' => ['identity' => (string) $this->guest->id, 'sid' => 'PA_1']] + $event)->assertNoContent();
        $event['participant']['sid'] = 'PA_1';
        $body = json_encode($event);

        $bad = [
            'no proof' => '',
            'other secret' => $this->proof($body, 'another-secret-another-secret-12345'),
            'other body' => $this->proof('{"event":"x"}', self::SECRET),
            'old' => $this->proof($body, self::SECRET, exp: -10),
            'other issuer' => $this->proof($body, self::SECRET, iss: 'APIother'),
            'alg none' => LiveKitToken::b64('{"alg":"none"}').'.'.explode('.', $this->proof($body, self::SECRET))[1].'.',
        ];
        foreach ($bad as $what => $proof) {
            $this->post_($body, $proof)->assertStatus(401);
            $this->assertSame(MeetingPerson::IN, $this->row($meeting, $this->guest)->state, "⛔ believed: {$what}");
        }

        $this->post_($body, $this->proof($body, self::SECRET))->assertNoContent();
        $this->assertSame(MeetingPerson::LEFT, $this->row($meeting, $this->guest)->state, 'the setup is wrong: a good one is not believed');
    }

    public function test_a_group_has_one_running_call_and_the_chat_is_only_for_its_members(): void
    {
        $thread = app(MessengerService::class)->createGroup($this->host, 'Sales group', collect([$this->guest]));
        $start = fn (User $u) => $this->web($u, 'POST', route('messaging.x.meetings.start', ['key' => (string) Str::uuid()]), ['thread' => $thread->public_id]);

        $first = $start($this->host)->assertCreated()->json('data.id');
        $this->assertSame($first, $start($this->guest)->assertCreated()->json('data.id'), '⛔ a second call in the same group');
        $start($this->outsider)->assertNotFound();

        $meeting = Meeting::query()->wherePublicId($first)->firstOrFail();
        $this->assertSame((string) $thread->public_id, $this->door($this->host, $meeting, 'join')->json('data.thread'));
        $this->door($this->host, $meeting, 'invite', ['people' => [$this->outsider->id]]);
        $this->assertNull($this->door($this->outsider, $meeting, 'join')->json('data.thread'), '⛔ a colleague called in got the group\'s chat');
    }

    public function test_the_others_ring_when_the_host_is_inside_and_one_press_is_one_meeting(): void
    {
        $key = (string) Str::uuid();
        $body = ['people' => [$this->guest->id]];
        $a = $this->web($this->host, 'POST', route('messaging.x.meetings.start', ['key' => $key]), $body)->json('data.id');
        $this->assertSame($a, $this->web($this->host, 'POST', route('messaging.x.meetings.start', ['key' => $key]), $body)->json('data.id'));
        $meeting = Meeting::query()->wherePublicId($a)->firstOrFail();

        $ringing = fn (User $u) => $this->web($u, 'GET', route('messaging.x.unread'))->assertOk()->json('meeting.id');
        $this->assertNull($ringing($this->guest), '⛔ rang before anyone was inside');
        $this->travel(MeetingService::RING_SECONDS + 5)->seconds();
        $this->door($this->host, $meeting, 'join');
        $this->assertSame($a, $ringing($this->guest));
        $this->assertNull($ringing($this->outsider));
        $this->door($this->guest, $meeting, 'decline');
        $this->assertNull($ringing($this->guest));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param  list<User>  $people */
    private function started(array $people): Meeting
    {
        $id = $this->web($this->host, 'POST', route('messaging.x.meetings.start', ['key' => (string) Str::uuid()]),
            ['title' => 'Month meeting', 'people' => array_map(fn (User $u) => $u->id, $people)])->assertCreated()->json('data.id');
        $meeting = Meeting::query()->wherePublicId($id)->firstOrFail();
        $this->assertSame('in', $this->door($this->host, $meeting, 'join')->assertOk()->json('data.state'));

        return $meeting;
    }

    private function row(Meeting $meeting, User $user): MeetingPerson
    {
        return MeetingPerson::query()->where('meeting_id', $meeting->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function door(User $user, Meeting $meeting, string $door, array $body = []): TestResponse
    {
        return $this->web($user, $door === '' ? 'GET' : 'POST', route('messaging.x.meetings.state', ['meeting' => $meeting->public_id]).($door === '' ? '' : '/'.$door), $body);
    }

    private function web(User $user, string $method, string $url, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user->fresh());

        return $method === 'GET' ? $this->getJson($url) : $this->postJson($url, $body);
    }

    private function hook(array $event): TestResponse
    {
        $body = (string) json_encode($event);

        return $this->post_($body, $this->proof($body, self::SECRET));
    }

    private function post_(string $body, string $proof): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', route('api.messaging.livekit.webhook'), server: ['CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => $proof, 'HTTP_ACCEPT' => 'application/json'], content: $body);
    }

    private function proof(string $body, string $secret, int $exp = 300, string $iss = 'APItestkey'): string
    {
        $signing = LiveKitToken::b64('{"alg":"HS256","typ":"JWT"}').'.'.LiveKitToken::b64((string) json_encode([
            'iss' => $iss, 'nbf' => now()->getTimestamp() - 5, 'exp' => now()->getTimestamp() + $exp,
            'sha256' => base64_encode(hash('sha256', $body, true))]));

        return $signing.'.'.LiveKitToken::b64(hash_hmac('sha256', $signing, $secret, true));
    }

    private function switch(bool $on): void
    {
        $settings = app(SettingsService::class);
        $settings->set(MeetingService::SWITCH, $on);
        $settings->flush();
    }

    private function person(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        foreach (['messaging.use', 'messaging.group.create'] as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
