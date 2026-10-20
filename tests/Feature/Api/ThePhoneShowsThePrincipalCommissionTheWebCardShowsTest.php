<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Dashboard\Listing;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Api\AuthController;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Dashboard\SupplierDashboard;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ প্রিন্সিপালের কমিশন ফোনে — পর্দা, মালিকের হোমের ঘর আর উইজেট — ওয়েবের বাক্সের হুবহু অঙ্ক (মালিক, fe-র মারফত, ২০ অক্টোবর
 * ২০২৬: *"app e dekhate hobe"*; [[PrincipalCommissionFigures]], [[PrincipalCommissionApiController]], [[PrincipalCommissionSummary]])।
 *
 * ⓘ চার প্রিন্সিপাল, চার শাখায়, একই চক্র (২৬ → ২৫; আজ ২০ অক্টোবর ২০২৬ → ২৬ সেপ্টেম্বর – ২৫ অক্টোবর)। নাম বর্ণক্রমে
 * Amber · Bloom · Crest · Delta (রিপোর্টের ক্রম)।
 *   Crest (MMS, মার্জিন ২.৫%) — আদায় ৮,৪৫,২১০.৩৫, পাঠানো ১৫,০০০.১০ → "দিতে হবে"
 *   Delta (NTK, মার্কআপ ৪%)  — আদায় ১,০০,০০০.৫৫, পাঠানো ৯০,০০,০০০ → "পাব" (বেশি পাঠানো)
 *   Bloom (KDA, মার্জিন ৩%) — আদায় ৫০,০০০;  Amber (DMD, মার্জিন ২%) — আদায় ১,০০০
 *   আগের চক্রে (২০ সেপ্টেম্বর) Crest-এর শাখায় আরও ১,০০০ — মাস বাছার দাবির জন্য।
 *
 * দাবি:
 *  ক) পর্দার সারি আর মোট = ওয়েবের রিপোর্ট আর বাক্সের সর্বমোট, পয়সায়; দুই দিকের জের চিহ্নসহ
 *  খ) উইজেটের আর আজকের পাতার `principals` সারি = পর্দার সারি; `principalsTotal` = পর্দার মোট = বাক্সের সর্বমোট
 *  গ) `supplier.report` ছাড়া পর্দা ৪০৩, আজকের পাতার দুই ঘর আর উইজেট উত্তরে নেই
 *  ঘ) এক শাখায় আটকানো মানুষ কেবল নিজের শাখার প্রিন্সিপাল পান — তিন জায়গাতেই
 *  ঙ) `month` সেই মাসে শেষ হওয়া চক্র বাছে
 *  চ) তিন দরজাতেই `Cache-Control: no-store`
 *  ছ) তবু ফোনের নিজের ETag চলে — মিললে খালি ৩০৪, নতুন আদায়ে নতুন ETag
 */
final class ThePhoneShowsThePrincipalCommissionTheWebCardShowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** @var array<string, Branch> */
    private array $branch = [];

    /** @var array<string, Supplier> ছোট নাম ধরে */
    private array $principal = [];

    private Account $bank;

    private Customer $dealer;

    private int $source = 81000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        foreach (['MMS', 'NTK', 'DMD', 'KDA'] as $code) {
            $this->branch[$code] = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
        }

        $this->home();
        app(StandardChart::class)->install();

        $this->bank = app(AccountService::class)->create(['name_en' => 'Principal phone test bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $this->dealer = Customer::query()->orderBy('id')->firstOrFail();

        $suppliers = Supplier::query()->orderBy('id')->take(3)->get()->all();
        $suppliers[] = app(SupplierService::class)->create(['name_en' => 'Delta Feed Mills', 'name_bn' => 'ডেল্টা ফিড মিলস', 'phone' => '+8801711000099']);
        $this->assertCount(4, $suppliers, 'প্রস্তুতি: চারজন সরবরাহকারী নেই।');

        foreach ([['Crest', 'MMS', 'margin', '2.500'], ['Delta', 'NTK', 'markup', '4.000'], ['Amber', 'DMD', 'margin', '2.000'], ['Bloom', 'KDA', 'margin', '3.000']] as $i => [$name, $code, $basis, $rate]) {
            $suppliers[$i]->forceFill([
                'short_name' => $name,
                'principal_branch_id' => $this->branch[$code]->id,
                'commission_basis' => $basis,
                'commission_rate' => $rate,
                'cycle_start_day' => 26,
                'cycle_close_day' => 25,
            ])->save();
            $this->principal[$name] = $suppliers[$i];
        }

        $this->receipt('MMS', '845210.35', '2026-10-03');
        $this->receipt('NTK', '100000.55', '2026-10-03');
        $this->receipt('KDA', '50000.00', '2026-10-03');
        $this->receipt('DMD', '1000.00', '2026-10-03');
        $this->receipt('MMS', '1000.00', '2026-09-20');
        $this->pay($this->principal['Crest'], '15000.10', '2026-10-04');
        $this->pay($this->principal['Delta'], '9000000.00', '2026-10-04');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ক) */
    public function test_the_screen_gives_the_web_cards_rows_and_grand_total_to_the_paisa(): void
    {
        $answer = $this->phone($this->owner, '/api/v1/supplier/principal-commission')->assertOk()->json();

        $this->home();
        $report = $this->report();
        $this->assertCount(4, $report, 'প্রস্তুতি: চার প্রিন্সিপালের সারি নেই।');
        $this->assertCount(4, $answer['rows'], '⛔ পর্দার সারি রিপোর্টের সারির সমান নয়।');
        $this->assertNull($answer['month'], 'মাস না দিলে চলতি চক্র — নির্দিষ্ট মাস নয়।');

        foreach ($report as $i => $r) {
            $row = $answer['rows'][$i];
            $this->assertSame((int) $r['supplier_id'], $row['supplierId']);
            $this->assertSame((string) $r['supplier_name'], $row['name'], '⛔ নাম ওয়েবের বাক্সের নাম নয়।');
            $this->assertSame((string) $r['branch_name'], $row['branch']);
            $this->assertSame([(string) $r['period_from'], (string) $r['period_to']], [$row['periodFrom'], $row['periodTo']]);
            $this->assertSame((string) $r['basis_rate'], $row['basisRate']);
            foreach (['inflow', 'commission', 'share', 'paid', 'balance'] as $key) {
                $this->assertSame(bcadd((string) $r[$key], '0', 4), $row[$key], "⛔ {$key} ওয়েবের সাথে পয়সায় মেলে না।");
            }
            $this->assertSame(SupplierDashboard::earned((string) $r['commission']), $row['commissionLabel']);
        }

        $byName = collect($answer['rows'])->keyBy('name');
        $this->assertSame(1, bccomp($byName['Crest']['balance'], '0', 4), '⛔ Crest-এর জের "দিতে হবে" দিকে নয়।');
        $this->assertSame(-1, bccomp($byName['Delta']['balance'], '0', 4), '⛔ বেশি পাঠানো প্রিন্সিপালের জের চিহ্ন হারিয়েছে।');
        $this->assertSame('2026-09-26', $byName['Crest']['periodFrom']);

        // ⓘ মোট — রিপোর্টের সারির bcmath যোগফল (জের চিহ্নসহ), আর ওয়েবের বাক্সের সর্বমোটের একই অঙ্ক ও লেখা
        $sum = fn (string $key): string => array_reduce($report, fn (string $c, array $r): string => bcadd($c, (string) $r[$key], 4), '0');
        $total = $answer['total'];
        $this->assertSame(['inflow', 'commission', 'commissionLabel', 'share', 'paid', 'balance'], array_keys($total));
        foreach (['inflow', 'commission', 'share', 'paid', 'balance'] as $key) {
            $this->assertSame($sum($key), $total[$key], "⛔ মোট {$key} সারিগুলোর যোগফল নয়।");
        }

        $card = $this->cardTotal();
        $this->assertSame($card['inflow'], Money::format($total['inflow']), '⛔ মোট আদায় ওয়েবের বাক্সের সর্বমোটের সাথে মেলে না।');
        $this->assertSame($card['paid'], Money::format($total['paid']), '⛔ মোট পাঠানো ওয়েবের বাক্সের সর্বমোটের সাথে মেলে না।');
        $this->assertSame($card['commission'], $total['commissionLabel'], '⛔ মোট কমিশনের লেখা ওয়েবের বাক্সের লেখা নয়।');
        $this->assertStringContainsString(Money::format(ltrim($total['balance'], '-')), $card['balance'], '⛔ মোট জের ওয়েবের বাক্সের সাথে মেলে না।');
    }

    /** খ) */
    public function test_the_widget_and_todays_principals_carry_the_screens_rows_and_todays_total_is_the_cards_grand_total(): void
    {
        $screen = $this->phone($this->owner, '/api/v1/supplier/principal-commission')->assertOk()->json();
        $widget = $this->widget($this->owner);
        $today = $this->phone($this->owner, '/api/v1/dashboard/today')->assertOk()->json();

        $this->assertNotNull($widget, '⛔ মালিকের উইজেটের উত্তরে principal_commission নেই।');
        $this->assertSame($screen['rows'], $widget['rows'], '⛔ উইজেট আর পর্দা আলাদা সারি বলে।');
        $this->assertSame($screen['total'], $widget['total'], '⛔ উইজেট আর পর্দা আলাদা মোট বলে।');
        $this->assertSame('/home/principal-commission', $widget['link']);

        $this->assertArrayNotHasKey('principalCommission', $today, 'চুক্তি সংশোধন ১: নতুন ঘর নয় — principals-এর পাশে মোট।');
        $this->assertSame($screen['rows'], $today['principals'], '⛔ আজকের পাতার principals আর পর্দার সারি আলাদা।');
        $this->assertSame($screen['total'], $today['principalsTotal'], '⛔ আজকের পাতার মোট আর পর্দার মোট আলাদা।');

        $this->home();
        $card = $this->cardTotal();
        $this->assertSame($card['inflow'], Money::format($today['principalsTotal']['inflow']), '⛔ principalsTotal ওয়েবের সর্বমোট নয়।');
        $this->assertSame($card['paid'], Money::format($today['principalsTotal']['paid']));
        $this->assertSame($card['commission'], $today['principalsTotal']['commissionLabel']);
    }

    /** গ) */
    public function test_without_the_report_key_the_screen_is_refused_and_the_keys_are_absent(): void
    {
        $clerk = $this->person(['supplier.view']);

        $this->phone($clerk, '/api/v1/supplier/principal-commission')->assertForbidden();
        $this->phone($clerk, '/api/v1/dashboard/today')->assertOk()->assertJsonMissingPath('principals')->assertJsonMissingPath('principalsTotal');
        $this->assertNull($this->widget($clerk), '⛔ চাবি ছাড়াই উইজেটে প্রিন্সিপালের কমিশন গেল।');

        $this->grant($clerk, 'supplier.report');

        $this->phone($clerk, '/api/v1/supplier/principal-commission')->assertOk();
        $this->assertNotNull($this->phone($clerk, '/api/v1/dashboard/today')->json('principalsTotal'), 'প্রস্তুতি: চাবি দিয়েও আজকের মোট নেই।');
        $this->assertNotNull($this->widget($clerk), 'প্রস্তুতি: চাবি দিয়েও উইজেট নেই।');
    }

    /** ঘ) */
    public function test_a_user_held_to_one_branch_sees_only_that_branchs_principal_everywhere(): void
    {
        $clerk = $this->person(['supplier.report'], $this->branch['MMS']);
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $clerk->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $this->branch['MMS']->id]);
        app(DataScope::class)->forget();

        $crest = $this->principal['Crest']->id;

        $screen = $this->phone($clerk, '/api/v1/supplier/principal-commission')->assertOk()->json();
        $this->assertSame([$crest], array_column($screen['rows'], 'supplierId'), '⛔ শাখায় আটকানো মানুষ অন্য শাখার প্রিন্সিপাল পেলেন।');
        $this->assertSame($screen['rows'][0]['inflow'], $screen['total']['inflow'], '⛔ মোটে অন্য শাখার আদায়।');
        $this->assertSame($screen['rows'][0]['balance'], $screen['total']['balance']);

        $today = $this->phone($clerk, '/api/v1/dashboard/today')->assertOk()->json();
        $this->assertSame([$crest], array_column($today['principals'], 'supplierId'), '⛔ আজকের পাতায় অন্য শাখার প্রিন্সিপাল।');
        $this->assertSame($screen['total'], $today['principalsTotal']);

        $widget = $this->widget($clerk);
        $this->assertSame([$crest], array_column($widget['rows'], 'supplierId'), '⛔ উইজেটে অন্য শাখার প্রিন্সিপাল।');
        $this->assertSame($screen['total'], $widget['total']);
    }

    /** ঙ) */
    public function test_the_month_picks_the_cycle_closing_in_that_month(): void
    {
        $now = collect($this->phone($this->owner, '/api/v1/supplier/principal-commission')->assertOk()->json('rows'))
            ->firstWhere('supplierId', $this->principal['Crest']->id);
        $answer = $this->phone($this->owner, '/api/v1/supplier/principal-commission?month=2026-09')->assertOk()->json();
        $then = collect($answer['rows'])->firstWhere('supplierId', $this->principal['Crest']->id);

        $this->assertSame('2026-09', $answer['month']);
        $this->assertSame(['2026-08-26', '2026-09-25'], [$then['periodFrom'], $then['periodTo']], '⛔ মাস বাছায় চক্র বদলায়নি।');
        $this->assertStringEndsWith('principal-commission?month=2026-09', $answer['web_url']);

        $this->home();
        $web = collect($this->report(['month' => '2026-09']))->firstWhere('supplier_id', $this->principal['Crest']->id);
        $this->assertSame(bcadd((string) $web['inflow'], '0', 4), $then['inflow'], '⛔ বাছা মাসের আদায় ওয়েবের সাথে মেলে না।');
        $this->assertNotSame($now['inflow'], $then['inflow'], 'প্রস্তুতি: দুই চক্রের আদায় এক — মাস বাছা দেখা গেল না।');

        $this->phone($this->owner, '/api/v1/supplier/principal-commission?month=2026-13')->assertStatus(422);
    }

    /** চ) */
    public function test_the_three_money_doors_say_no_store(): void
    {
        foreach (['/api/v1/supplier/principal-commission', '/api/v1/dashboard/today', '/api/v1/widgets/summary?keys=principal_commission'] as $url) {
            $header = (string) $this->phone($this->owner, $url)->assertOk()->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $header, "⛔ {$url}: কমিশনের অঙ্ক ক্যাশে থাকতে পারে।");
        }
    }

    /**
     * ছ) ⭐ no-store তবু ফোনের নিজের ETag চলে ([[UnchangedAnswer]]): প্রথম ডাকে ২০০ + ETag + no-store; একই ETag ফেরত দিলে ৩০৪,
     * শরীর খালি, তবু no-store; প্রিন্সিপালের শাখায় নতুন আদায় এলে একই ETag-এ ২০০ আর নতুন ETag — তিন দরজাতেই।
     */
    public function test_the_phone_keeps_its_own_etag_a_match_is_an_empty_304_and_a_new_collection_breaks_it(): void
    {
        $doors = ['/api/v1/supplier/principal-commission', '/api/v1/dashboard/today', '/api/v1/widgets/summary?keys=principal_commission'];
        $first = [];

        foreach ($doors as $url) {
            $answer = $this->phone($this->owner, $url)->assertOk();
            $first[$url] = (string) $answer->headers->get('ETag');
            $this->assertNotSame('', $first[$url], "⛔ {$url}: ETag নেই — ফোন প্রতিবার পুরো উত্তর টানে।");
            $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'), "⛔ {$url}: no-store নেই।");

            $same = $this->phone($this->owner, $url, ['If-None-Match' => $first[$url]])->assertStatus(304);
            $this->assertSame('', (string) $same->getContent(), "⛔ {$url}: ৩০৪-এ শরীর গেছে।");
            $this->assertStringContainsString('no-store', (string) $same->headers->get('Cache-Control'), "⛔ {$url}: ৩০৪-এ no-store নেই।");
        }

        $this->home();
        $this->receipt('MMS', '1234.56', '2026-10-19');

        foreach ($doors as $url) {
            $fresh = $this->phone($this->owner, $url, ['If-None-Match' => $first[$url]])->assertOk();
            $this->assertNotSame($first[$url], (string) $fresh->headers->get('ETag'), "⛔ {$url}: অঙ্ক বদলাল, ETag বদলাল না — ফোন পুরনো কমিশন দেখাত।");
        }
    }

    /** @return array<string, string> ওয়েবের বাক্সের সর্বমোট ([[SupplierDashboard::principals()]]) */
    private function cardTotal(): array
    {
        $card = collect(SupplierDashboard::dashboard()->listings)
            ->first(fn (Listing $l) => $l->label === __('supplier::principal.dashboard_title'))?->total;
        $this->assertNotNull($card, 'প্রস্তুতি: ওয়েবের বাক্সে সর্বমোট নেই।');

        return $card;
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function home(): void
    {
        CompanyContext::set($this->company->id, $this->branch['MMS']->id);
        $this->actingAs($this->owner->fresh());
    }

    /**
     * ওয়েবের বাক্স যা চালায় ([[SupplierDashboard::principals()]]) — একই ছাঁকনি, একই মাপ।
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function report(array $filters = []): array
    {
        return app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, ['branch_id' => ViewedBranch::one(), ...$filters], 1, 50)->rows;
    }

    /** @param array<string, string> $headers */
    private function phone(User $user, string $url, array $headers = []): TestResponse
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url, $headers);
    }

    /** @return array<string, mixed>|null */
    private function widget(User $user): ?array
    {
        return $this->phone($user, '/api/v1/widgets/summary?keys=principal_commission')->assertOk()->json('widgets.principal_commission');
    }

    /** @param list<string> $keys */
    private function person(array $keys, ?Branch $only = null): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'is_active' => true,
            ...($only === null ? [] : ['current_branch_id' => $only->id, 'view_all_branches' => false])]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** গ্রাহকের রসিদ — টাকা ঐ শাখার ব্যাংকে */
    private function receipt(string $branch, string $amount, string $date): void
    {
        $this->book('collection', $date, [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'branch_id' => $this->branch[$branch]->id],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id, 'branch_id' => $this->branch[$branch]->id],
        ]);
    }

    /** প্রিন্সিপালকে পাঠানো — প্রধান শাখার ব্যাংক থেকে */
    private function pay(Supplier $to, string $amount, string $date): void
    {
        $this->book('payment_voucher', $date, [
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => $amount,
                'party_type' => 'supplier', 'party_id' => $to->id],
            ['account_id' => $this->bank->id, 'credit' => $amount],
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function book(string $type, string $date, array $lines): void
    {
        $id = ++$this->source;

        app(PostingEngine::class)->post($type, $id, $date, $lines, 'PCP-'.$id, $this->branch['MMS']->id);
    }
}
