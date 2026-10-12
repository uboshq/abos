<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Http\Controllers\CreditReportController;
use App\Modules\CreditControl\Reports\CreditControlLists;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Customer\Services\DealerBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ মালিকের রিপোর্টের তালিকা — প্রতিটা রিপোর্ট দেয়ালের হিসাবই বলে, আর দেয়াল মানে (বাকি নিয়ন্ত্রণ ধাপ ৩.২)।
 *
 *   · বয়সের ছক: ১০ দিনের পুরনো ১,৫০০ ৮–১৫ ধাপে, মোটসহ। · বেশি ঝুঁকি: চেক ফেরতের গ্রাহক আছেন, পরিষ্কার জন নেই।
 *   · পরিশোধের আচরণ: মাত্রার কাঁচা উপকরণ। · সীমা পারের চেষ্টা কেবল থামা; দেয়ালের সাক্ষ্যে দুইটাই।
 *   · সীমা বদলের নিরীক্ষা, অনুরোধের ইতিহাস ("না"-সহ), চলতি অস্থায়ী সীমা (শেষ হয়ে যাওয়াটা নয়)।
 *   · শাখা আর বিক্রয়কর্মী ধরে যোগ = দেয়ালের অবস্থার যোগ। · বিক্রয়কর্মী কেবল নিজের ডিলারের সারি দেখেন। · প্রতিটা পাতা খোলে।
 */
final class TheCreditReportsAnswerTheOwnersListTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_ageing_risk_and_behaviour_read_the_walls_own_figures(): void
    {
        $late = $this->customer('RL-LATE', '10000');
        $this->bill($late, 'INV-RL1', '1500', daysPastDue: 10);
        $this->bouncedCheque($late, '1000');
        $this->bouncedCheque($late, '2000');
        $clean = $this->customer('RL-CLEAN', '10000');
        $this->owes($clean, '500');

        $ageing = $this->rows(CreditControlLists::AGEING)->firstWhere('customer_code', 'RL-LATE');
        $this->assertSame(0, bccomp((string) $ageing['d8_15'], '1500', 2));
        $this->assertSame(0, bccomp((string) $ageing['total'], '1500', 2));

        $risky = $this->rows(CreditControlLists::HIGH_RISK)->pluck('customer_code')->all();
        $this->assertContains('RL-LATE', $risky);
        $this->assertNotContains('RL-CLEAN', $risky, '⛔ পরিষ্কার গ্রাহক বেশি ঝুঁকিতে।');

        $row = $this->rows(CreditControlLists::BEHAVIOUR)->firstWhere('customer_code', 'RL-LATE');
        $this->assertSame(2, (int) $row['bounces']);
        $this->assertSame(10, (int) $row['oldest']);
    }

    public function test_breach_attempts_are_the_stops_and_the_evidence_is_everything(): void
    {
        $c = $this->customer('RL-WALL', '10000');
        $this->wallSaid($c, 'stop');
        $this->wallSaid($c, 'pass', 'within_limit');

        $range = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
        $this->assertCount(1, $this->rows(CreditControlLists::BREACHES, $range)->where('customer_code', 'RL-WALL'), '⛔ "গেল"-ও সীমা পারের চেষ্টায় উঠল।');
        $this->assertCount(2, $this->rows(CreditControlLists::EVIDENCE, $range)->where('customer_code', 'RL-WALL'));
    }

    public function test_changes_requests_and_temporary_limits(): void
    {
        $c = $this->customer('RL-HIST', '10000');
        app(CustomerService::class)->update($c->fresh(), ['credit_limit' => '8000']);

        CustomerCreditRequest::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $c->id, 'kind' => 'limit', 'current_limit' => '8000',
            'requested_limit' => '20000', 'reason' => 'চাই', 'status' => CustomerCreditRequest::REJECTED, 'requested_by' => $this->owner->id,
        ]);

        $soon = $this->customer('RL-TEMP', '1000');
        $soon->forceFill(['temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->addDays(2)->toDateString()])->save();
        $gone = $this->customer('RL-GONE', '1000');
        $gone->forceFill(['temp_credit_limit' => '5000', 'temp_credit_limit_until' => now()->subDay()->toDateString()])->save();

        $range = ['from' => now()->subDays(1)->toDateString(), 'to' => now()->toDateString()];
        $this->assertTrue($this->rows(CreditControlLists::CHANGES, $range)->contains(fn ($r) => $r['customer_code'] === 'RL-HIST'
            && bccomp((string) $r['new_value'], '8000', 2) === 0));
        $this->assertSame(__('customer::credit_request.status_rejected'),
            $this->rows(CreditControlLists::REQUESTS, $range)->firstWhere('customer_code', 'RL-HIST')['status'], '⛔ "না"-এর অনুরোধ ইতিহাসে নেই।');

        $temp = $this->rows(CreditControlLists::TEMPORARY);
        $this->assertSame(2, (int) $temp->firstWhere('customer_code', 'RL-TEMP')['days_left']);
        $this->assertNull($temp->firstWhere('customer_code', 'RL-GONE'), '⛔ শেষ হয়ে যাওয়া অস্থায়ী সীমা চলতি তালিকায়।');
    }

    public function test_the_group_totals_are_the_sum_of_the_walls_positions(): void
    {
        $a = $this->customer('RL-G1', '10000');
        $this->owes($a, '4000');
        $b = $this->customer('RL-G2', '5000');
        $this->owes($b, '1000');

        // ⓘ ড্যাশবোর্ডের যোগ — দেয়ালের অবস্থার যোগ ([[CreditDashboard::figures()]])
        $exposure = CreditDashboard::figures()['totals']['exposure'];

        foreach ([CreditControlLists::BY_BRANCH, CreditControlLists::BY_SALESPERSON] as $key) {
            $sum = $this->rows($key)->reduce(fn (string $s, array $r) => bcadd($s, (string) $r['exposure'], 4), '0');
            $this->assertSame(0, bccomp($sum, $exposure, 2), "⛔ {$key}-এর যোগ দেয়ালের যোগের সাথে মেলে না।");
        }
    }

    public function test_a_salesperson_sees_only_his_own_dealers_rows_and_every_page_opens(): void
    {
        $mine = $this->customer('RL-MINE', '10000');
        $theirs = $this->customer('RL-THEIRS', '10000');
        $this->wallSaid($mine, 'stop');
        $this->wallSaid($theirs, 'stop');

        foreach (array_keys(CreditReportController::SLUGS) as $slug) {
            $this->get(route('credit_control.report.show', ['slug' => $slug]))->assertOk();
        }

        $sr = $this->staffWith(['credit_control.view', DealerScope::OWN]);
        app(DealerBindingService::class)->bind((int) $sr->id, [(int) $mine->id], now()->toDateString());
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();
        $this->actingAs($sr);

        $range = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
        $this->assertSame(['RL-MINE'], $this->rows(CreditControlLists::BREACHES, $range)->pluck('customer_code')->unique()->values()->all(),
            '⛔ বিক্রয়কর্মী অন্যের ডিলারের দেয়ালের সাক্ষ্য দেখলেন।');
    }

    /** @param  array<string, mixed>  $filters */
    private function rows(string $key, array $filters = []): Collection
    {
        return collect(app(ReportEngine::class)->run($key, $filters, perPage: 1000)->rows);
    }
}
