<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Models\Call;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The call history's server half (owner, 24 Nov 2026): pages, filters and per-person totals.
 *
 * Claims: the plain door answers as before · only my own calls, under every filter · a page neither repeats nor skips, also when a
 * call comes in between and when many calls share one second · missed / in / out / one person · the totals add up · the number
 * of questions does not grow with the rows · a foreign cursor is refused
 */
final class TheCallHistoryIsPagedAndOnlyMineTest extends TestCase
{
    use RefreshDatabase;

    private const DOOR = '/api/v1/messenger/calls';

    private Company $company;

    private User $me;

    private User $ann;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        app(SettingsService::class)->set(PhoneModules::key('messaging'), true);

        [$this->me, $this->ann, $this->bob] = [$this->person('Me'), $this->person('Ann'), $this->person('Bob')];
    }

    public function test_the_plain_door_answers_as_before(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->call_($this->me, $this->ann, Call::MISSED, $i);
        }

        $json = $this->ask()->assertOk()->json();
        $this->assertSame(['data'], array_keys($json), 'the app reads { data } — nothing may be added to the plain answer');
        $this->assertCount(50, $json['data']);
        $this->assertSame(['call_id', 'video', 'direction', 'peer', 'status', 'at', 'answered_at', 'ended_at', 'duration_s'], array_keys($json['data'][0]));
    }

    public function test_every_call_comes_once_over_the_pages_even_within_one_second_and_while_a_new_one_lands(): void
    {
        $made = [];
        for ($i = 0; $i < 25; $i++) {
            $made[] = (string) $this->call_($this->me, $this->ann, Call::MISSED, $i < 10 ? 100 : $i)->public_id;
        }

        $seen = [];
        $cursor = null;
        $turn = 0;
        do {
            $page = $this->ask(array_filter(['limit' => 7, 'cursor' => $cursor]))->assertOk()->json();
            $this->assertLessThanOrEqual(7, count($page['data']));
            array_push($seen, ...array_column($page['data'], 'call_id'));
            $cursor = $page['next'];

            if ($turn++ === 0) {
                $this->call_($this->ann, $this->me, Call::MISSED, 0);   // a new call while scrolling
            }
        } while ($cursor !== null && $turn < 20);

        $this->assertSame(count($seen), count(array_unique($seen)), 'a row came twice');
        $this->assertEqualsCanonicalizing($made, $seen);
    }

    public function test_missed_in_out_and_one_person(): void
    {
        $missed = (string) $this->call_($this->ann, $this->me, Call::MISSED, 5)->public_id;
        $declined = (string) $this->call_($this->bob, $this->me, Call::DECLINED, 4)->public_id;
        $unanswered = (string) $this->call_($this->me, $this->ann, Call::MISSED, 3)->public_id;
        $talked = (string) $this->call_($this->me, $this->bob, Call::ENDED, 2, 60)->public_id;
        $ids = fn (array $q): array => array_column($this->ask($q)->assertOk()->json('data'), 'call_id');

        $this->assertSame([$missed], $ids(['kind' => 'missed']));
        $this->assertSame([$declined, $missed], $ids(['kind' => 'in']));
        $this->assertSame([$talked, $unanswered], $ids(['kind' => 'out']));
        $this->assertSame([$unanswered, $missed], $ids(['person' => $this->ann->id]));
        $this->assertSame([$unanswered], $ids(['person' => $this->ann->id, 'kind' => 'out']));
        $this->assertSame([$unanswered, $missed], $ids(['q' => 'Ann']));
        $this->assertSame([], $ids(['q' => 'Me']), 'my own name is not a match');
        $this->assertSame([], $ids(['q' => '%']));
    }

    public function test_only_my_own_calls_under_every_filter_and_in_the_totals(): void
    {
        $theirs = (string) $this->call_($this->ann, $this->bob, Call::MISSED, 1)->public_id;

        foreach ([['limit' => 50], ['q' => 'Ann'], ['kind' => 'missed'], ['kind' => 'in'], ['kind' => 'out'], ['person' => $this->ann->id], ['person' => $this->bob->id, 'kind' => 'missed']] as $query) {
            $this->assertNotContains($theirs, array_column($this->ask($query)->assertOk()->json('data'), 'call_id'), json_encode($query));
        }
        $this->assertSame([], $this->ask([], '/people')->assertOk()->json('data'));
        $this->assertSame([], $this->ask(['person' => $this->ann->id], '/people')->assertOk()->json('data'));
    }

    public function test_the_totals_add_up_per_person_the_latest_first(): void
    {
        $this->call_($this->me, $this->ann, Call::ENDED, 60, 40);
        $this->call_($this->ann, $this->me, Call::ENDED, 50, 20);
        $this->call_($this->ann, $this->me, Call::MISSED, 40);
        $this->call_($this->me, $this->ann, Call::MISSED, 30);      // mine, nobody took it — not "missed" by me
        $this->call_($this->bob, $this->me, Call::DECLINED, 10);

        $rows = $this->ask([], '/people')->assertOk()->json('data');
        $this->assertSame([(string) $this->bob->id, (string) $this->ann->id], array_map(fn (array $r) => $r['peer']['id'], $rows));
        $this->assertSame([1, 0, 0, 0], [$rows[0]['calls'], $rows[0]['answered'], $rows[0]['missed'], $rows[0]['seconds']]);
        $this->assertSame([4, 2, 1, 60], [$rows[1]['calls'], $rows[1]['answered'], $rows[1]['missed'], $rows[1]['seconds']]);
        $this->assertSame([$rows[1]], $this->ask(['person' => $this->ann->id], '/people')->json('data'));
        $this->assertSame(['missed', 'out'], [$rows[1]['last']['status'], $rows[1]['last']['direction']], 'the latest call with Ann');
    }

    public function test_more_calls_and_more_people_ask_no_more_questions(): void
    {
        $count = function (array $query, string $tail = ''): int {
            $n = 0;
            $on = true;
            DB::listen(function () use (&$n, &$on): void { $on && $n++; });
            $this->ask($query, $tail)->assertOk();
            $on = false;

            return $n;
        };

        $this->call_($this->me, $this->ann, Call::ENDED, 5, 10);
        $this->call_($this->bob, $this->me, Call::MISSED, 4);
        $this->ask(['limit' => 50]);
        $this->ask([], '/people');
        [$page, $people] = [$count(['limit' => 50]), $count([], '/people')];

        for ($i = 0; $i < 12; $i++) {
            $this->call_($this->me, $this->person('P'.$i), Call::ENDED, $i + 10, 10);
        }

        $this->assertLessThanOrEqual($page + 1, $count(['limit' => 50]), 'a question per row or per person');
        $this->assertLessThanOrEqual($people + 1, $count([], '/people'), 'a question per person');
    }

    public function test_wrong_parameters_are_refused(): void
    {
        foreach ([['kind' => 'all'], ['limit' => 0], ['limit' => 101], ['person' => 'x'], ['cursor' => 'x'], ['cursor' => "1.1' or 1=1"]] as $query) {
            $this->ask($query)->assertUnprocessable();
        }
    }

    public function test_the_doors_need_the_key(): void
    {
        $this->me->revokePermissionTo('messaging.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ask(['kind' => 'missed'])->assertForbidden();
        $this->ask([], '/people')->assertForbidden();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function ask(array $query = [], string $tail = ''): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->me->fresh(), [AuthController::APP]);

        return $this->getJson(self::DOOR.$tail.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function call_(User $from, User $to, string $status, int $secondsAgo, ?int $talked = null): Call
    {
        $call = new Call(['caller_id' => $from->id, 'callee_id' => $to->id, 'video' => false, 'status' => $status]);
        $at = now()->startOfSecond()->subSeconds($secondsAgo);
        $call->forceFill(['company_id' => $this->company->id, 'answered_at' => $talked === null ? null : $at, 'ended_at' => $at, 'duration_s' => $talked]);
        $call->created_at = $at;
        $call->save();

        return $call;
    }

    private function person(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id,
            'current_branch_id' => $this->company->defaultBranch()?->id, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true, 'default_branch_id' => $this->company->defaultBranch()?->id]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('messaging.use', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
