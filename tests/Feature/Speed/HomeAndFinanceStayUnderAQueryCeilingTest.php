<?php

declare(strict_types=1);

namespace Tests\Feature\Speed;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ The home page and the finance dashboard stay under a query ceiling (speed work, 23 Nov 2026).
 *
 * ⓘ Measured on live as the owner before the work: home 922 queries, /dashboard/finance 1,266 — the same chart codes
 * looked up hundreds of times, the whole chart reloaded per group, the same permission asked 50 times, and every
 * module's figures counted again on every open.
 *
 * Claims (seeded demo data, as the owner):
 *   1. a first open stays under the ceiling (per-page memories: [[GateMemo]], [[AccountLookups]], [[ReadWindow]]);
 *   2. a second open within five minutes asks far fewer — the figures come from [[DashboardFigureCache]];
 *   3. a posting in between makes the next open count afresh (the figures are not stale).
 *
 * ⓘ The ceilings are generous on purpose: they catch a return of the N+1 (hundreds of queries), not a single new
 * query. Calibrate from the first Mac run if the seed grows.
 */
final class HomeAndFinanceStayUnderAQueryCeilingTest extends TestCase
{
    use RefreshDatabase;

    // ⓘ Calibrated from the first Mac run of the 1.3.10 candidate (23 Nov 2026): home measured 529 on the merged seed
    // (search + salesperson add their own reads); 600 keeps ~13% headroom and stays far below the 922 of the N+1 days.
    private const HOME_FIRST = 600;

    private const FINANCE_FIRST = 500;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_the_home_page_stays_under_its_ceiling_and_a_second_open_reads_the_remembered_figures(): void
    {
        $first = $this->queriesOf('/');
        $this->assertLessThanOrEqual(self::HOME_FIRST, $first, "⛔ home asked {$first} queries — an N+1 came back.");

        $second = $this->queriesOf('/');
        $this->assertLessThan($first, $second, '⛔ the second open counted every figure again — the five-minute memory is not used.');
    }

    public function test_the_finance_dashboard_stays_under_its_ceiling_and_a_second_open_reads_the_remembered_figures(): void
    {
        $first = $this->queriesOf('/dashboard/finance');
        $this->assertLessThanOrEqual(self::FINANCE_FIRST, $first, "⛔ /dashboard/finance asked {$first} queries — an N+1 came back.");

        $second = $this->queriesOf('/dashboard/finance');
        $this->assertLessThan(intdiv($first, 2), $second, '⛔ the second open of the finance board counted its rows again.');
    }

    public function test_a_posting_between_two_opens_makes_the_next_open_count_afresh(): void
    {
        $this->queriesOf('/dashboard/finance');
        $warm = $this->queriesOf('/dashboard/finance');

        // ⓘ any ledger row moves the company's figure generation ([[DashboardFigureCache::bust()]])
        \App\Core\Services\DashboardFigureCache::bust(CompanyContext::id());

        $after = $this->queriesOf('/dashboard/finance');
        $this->assertGreaterThan($warm, $after, '⛔ a posting did not drop the remembered figures — the board would show the old money.');
    }

    private function queriesOf(string $path): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner->fresh())->get($path)->assertOk();

        $seen = $count;
        // ⓘ the listener keeps counting after this request; later calls start their own counter
        $count = -PHP_INT_MAX;

        return $seen;
    }
}
