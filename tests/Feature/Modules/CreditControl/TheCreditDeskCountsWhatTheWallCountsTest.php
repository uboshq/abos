<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DealerScope;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\UserDataScope;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Http\Controllers\CreditReportController;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বাকির ড্যাশবোর্ড, তালিকা আর গ্রাহকের পাতা — দেয়ালের হিসাবেই, আর দেয়ালের ভিতরে (বাকি নিয়ন্ত্রণ ধাপ ১.২–১.৩, ১১ অক্টোবর ২০২৬)।
 *
 *   · মালিকের CC-০০১: সীমা ৫,০০,০০০, ব্যবহৃত ৩,০০,০০০ → অবশিষ্ট ২,০০,০০০ — আর দেয়াল ঠিক ২,০০,০০০-এর কাগজ ছাড়ে, এক টাকা
 *     বেশি নয়। ⛔ দ্বিতীয় হিসাব থাকলে কোনোদিন দুইটা আলাদা হত।
 *   · ক্লিয়ার না হওয়া চেক জমা নয় — ব্যবহৃত আর অবশিষ্টে গোনা।
 *   · অবস্থা: সীমা পার, বাকি বন্ধ, মেয়াদ পেরোনো বাকিতে বন্ধ, চালু।
 *   · ড্যাশবোর্ডের যোগফল = গ্রাহক ধরে অঙ্কের যোগ; তালিকার সারি = গ্রাহকের অঙ্ক; বয়সের ধাপ বিল ধরে।
 *   · বিক্রয়কর্মী কেবল নিজের ডিলার দেখেন; অন্য শাখার গ্রাহক চাবি ছাড়া অদৃশ্য — পাতায় ৪০৪।
 */
final class TheCreditDeskCountsWhatTheWallCountsTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    public function test_the_available_figure_is_exactly_the_room_the_wall_leaves(): void
    {
        $c = $this->customer('CD-ROOM', '500000');
        $this->owes($c, '280000');
        $this->unclearedCheque($c, '20000');
        $this->owes($c, '20000'); // ⓘ চেকের জমা খাতায় ২০,০০০ কমিয়েছে; চেক ক্লিয়ার নয়, তাই ব্যবহৃত ৩,০০,০০০ই

        $p = app(CreditExposure::class)->positionOf($c->fresh());

        $this->assertSame(0, bccomp($p['exposure'], '300000', 4), 'ব্যবহৃত: '.$p['exposure']);
        $this->assertSame(0, bccomp($p['uncleared'], '20000', 4), '⛔ ক্লিয়ার না হওয়া চেক জমা ধরা হয়েছে।');
        $this->assertSame(0, bccomp($p['available'], '200000', 4), 'অবশিষ্ট: '.$p['available']);
        $this->assertSame('60.00', $p['used_percent']);
        $this->assertSame('active', $p['state']);

        // ⭐ দেয়াল ঠিক অবশিষ্টটুকু ছাড়ে, এক টাকা বেশি নয়
        app(CreditExposure::class)->assertRoom($c->fresh(), $p['available']);

        $this->expectException(ValidationException::class);
        app(CreditExposure::class)->assertRoom($c->fresh(), bcadd($p['available'], '1', 4));
    }

    /**
     * ⭐ গ্রাহকের পাতার "ব্যবহারযোগ্য বাকি" = বাকি-নিয়ন্ত্রণের পাতা = দেয়াল — পূর্ণ নিরীক্ষা §৫, ১৮ অক্টোবর ২০২৬
     * ([[Customer::availableLimit()]])। ⛔ আগে গ্রাহকের পাতা ক্লিয়ার না হওয়া চেক বাদ দিত না: এখানে ২,২০,০০০, ওখানে ২,০০,০০০।
     */
    public function test_the_customer_page_leaves_the_same_room_as_the_credit_page(): void
    {
        $c = $this->customer('CD-SAME', '500000');
        $this->owes($c, '280000');
        $this->unclearedCheque($c, '20000');
        $this->owes($c, '20000');

        $desk = app(CreditExposure::class)->positionOf($c->fresh())['available'];
        $this->assertSame(0, bccomp((string) $c->fresh()->availableLimit(), $desk, 4),
            '⛔ গ্রাহকের পাতা '.$c->fresh()->availableLimit().', বাকি-নিয়ন্ত্রণের পাতা '.$desk);
        $this->assertSame(0, bccomp($desk, '200000', 4));
    }

    public function test_each_state_is_the_walls_own_answer(): void
    {
        app(SettingsService::class)->set('customer.overdue_stop', true);
        app(SettingsService::class)->set('customer.overdue_block_days', 30);

        $over = $this->customer('CD-OVER', '1000');
        $this->owes($over, '1500');

        $blocked = $this->customer('CD-BLK', '100000');
        app(CustomerService::class)->blockCredit($blocked, 'পরীক্ষা');

        $late = $this->customer('CD-LATE', '100000');
        $this->bill($late, 'INV-CD-LATE', '3000', daysPastDue: 45);

        $fine = $this->customer('CD-FINE', '100000');
        $this->owes($fine, '500');

        $positions = app(CreditExposure::class)->positionsFor(Customer::query()->whereIn('code', ['CD-OVER', 'CD-BLK', 'CD-LATE', 'CD-FINE'])->get());
        $state = fn (Customer $c) => $positions[$c->id]['state'];

        $this->assertSame(['over_limit', 'blocked', 'overdue', 'active'], [$state($over), $state($blocked), $state($late), $state($fine)]);
    }

    public function test_the_dashboard_totals_are_the_sum_of_the_customers_and_its_lists_agree(): void
    {
        // ⓘ এখানে মাপা হয় সীমা — মেয়াদের দেয়াল বন্ধ, নইলে বি-র ১০ দিনের পুরনো বিল তাঁকে "মেয়াদ পেরোনো"-তে বসাত (ধাপ ২.১)
        app(SettingsService::class)->set('customer.overdue_stop', false);
        $a = $this->customer('CD-A', '10000');
        $this->owes($a, '4000');
        $b = $this->customer('CD-B', '1000');
        $this->bill($b, 'INV-CD-B', '1500', daysPastDue: 10);

        $figures = CreditDashboard::figures();
        $sum = fn (string $k) => array_reduce($figures['positions'], fn (string $s, array $p) => bcadd($s, $p[$k], 4), '0');

        $this->assertSame(0, bccomp($figures['totals']['limit'], $sum('limit'), 4));
        $this->assertSame(0, bccomp($figures['totals']['available'], $sum('available'), 4));
        $this->assertSame(0, bccomp($figures['positions'][$a->id]['exposure'], '4000', 4));
        $this->assertSame(0, bccomp($figures['ageing']['d8_15'], '1500', 4) >= 0 ? 0 : 1, 'বি-র ১০ দিনের পুরনো বিল ৮–১৫ ধাপে নেই।');
        $this->assertGreaterThanOrEqual(1, $figures['totals']['over_limit']);

        $row = collect(app(ReportEngine::class)->run(CreditPositionReports::POSITIONS, [], perPage: 1000)->rows)->firstWhere('customer_code', 'CD-A');
        $this->assertSame(0, bccomp((string) $row['exposure'], '4000', 2));
        $this->assertSame(0, bccomp((string) $row['available'], '6000', 2));

        $bills = collect(app(ReportEngine::class)->run(CreditPositionReports::OVERDUE_BILLS, [], perPage: 1000)->rows);
        $this->assertSame(10, (int) $bills->firstWhere('document_no', 'INV-CD-B')['days_overdue']);

        $over = collect(app(ReportEngine::class)->run(CreditPositionReports::OVER_LIMIT, [], perPage: 1000)->rows)->pluck('customer_code');
        $this->assertContains('CD-B', $over);
        $this->assertNotContains('CD-A', $over);

        $this->get(route('module.dashboard', ['module' => 'credit_control']))->assertOk()
            ->assertSee(__('credit_control::dashboard.ageing'))
            ->assertSee(route('credit_control.report.show', ['slug' => 'over-limit']), false);

        foreach (array_keys(CreditReportController::SLUGS) as $slug) {
            $this->get(route('credit_control.report.show', ['slug' => $slug]))->assertOk();
        }
    }

    public function test_the_profile_shows_the_figures_the_bills_and_the_history(): void
    {
        // ⓘ মেয়াদের দেয়াল বন্ধ — এখানে পাতার অঙ্ক আর ইতিহাস মাপা হয় (ধাপ ২.১-এ নতুন কোম্পানিতে দেয়াল চালু)
        app(SettingsService::class)->set('customer.overdue_stop', false);
        $c = $this->customer('CD-PROF', '10000');
        $this->bill($c, 'INV-CD-PROF', '2500', daysPastDue: 3);
        $this->bill($c, 'INV-CD-PROF2', '1250.50', daysPastDue: 0);
        app(CustomerService::class)->update($c->fresh(), ['credit_limit' => '8000']);

        $page = $this->get(route('credit_control.customer.show', $c->id))->assertOk();
        // ⭐ খোলা বিলের নিচে মোট বাকি — দুটো বিলের যোগ (পূর্ণ নিরীক্ষা §৫, ১৮ অক্টোবর ২০২৬)
        $this->assertMatchesRegularExpression('/data-open-bills-total[^>]*>.*?'.preg_quote(\App\Core\Support\Money::format('3750.50'), '/').'/s',
            $page->getContent(), '⛔ খোলা বিলে মোট নেই, বা ভুল।');
        $page
            ->assertSee('INV-CD-PROF')
            ->assertSee('data-credit-state="active"', false)
            ->assertSee(__('customer::credit_history.credit_limit'));
    }

    public function test_a_salesperson_sees_only_his_own_dealers(): void
    {
        $mine = $this->customer('CD-MINE', '10000');
        $theirs = $this->customer('CD-THEIRS', '10000');
        $sr = $this->staffWith(['credit_control.view', DealerScope::OWN]);

        app(DealerBindingService::class)->bind((int) $sr->id, [(int) $mine->id], now()->toDateString());
        app(SettingsService::class)->set(DealerScope::SWITCH, true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DealerScope::class)->forget();

        $this->actingAs($sr);

        $this->assertSame([(int) $mine->id], CreditDashboard::figures()['ids'], '⛔ বিক্রয়কর্মীর ড্যাশবোর্ডে অন্যের ডিলার।');

        $codes = collect(app(ReportEngine::class)->run(CreditPositionReports::POSITIONS, [], perPage: 1000)->rows)->pluck('customer_code')->all();
        $this->assertSame(['CD-MINE'], $codes);

        $this->get(route('credit_control.customer.show', $mine->id))->assertOk();
        $this->get(route('credit_control.customer.show', $theirs->id))->assertNotFound();
    }

    public function test_another_branchs_customers_are_invisible_without_access(): void
    {
        $home = $this->company->defaultBranch();
        $other = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($home->id)->first()
            ?? Branch::create(['company_id' => $this->company->id, 'code' => 'CD-B2', 'name_en' => 'Second', 'is_active' => true]);

        $here = $this->customer('CD-HERE', '10000', $home->id);
        $there = $this->customer('CD-THERE', '10000', $other->id);

        $clerk = $this->staffWith(['credit_control.view']);
        UserDataScope::query()->create([
            'company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $home->id,
        ]);

        $this->actingAs($clerk->fresh());
        CompanyContext::set($this->company->id, $home->id);

        $ids = CreditDashboard::figures()['ids'];
        $this->assertContains((int) $here->id, $ids);
        $this->assertNotContains((int) $there->id, $ids, '⛔ নাগালের বাইরের শাখার গ্রাহক ড্যাশবোর্ডে।');

        $codes = collect(app(ReportEngine::class)->run(CreditPositionReports::POSITIONS, [], perPage: 1000)->rows)->pluck('customer_code');
        $this->assertNotContains('CD-THERE', $codes);

        $this->get(route('credit_control.customer.show', $there->id))->assertNotFound();
    }
}
