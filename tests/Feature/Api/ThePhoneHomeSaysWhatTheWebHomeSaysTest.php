<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Dashboard\Widget;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Dashboard\SalesWidgets;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⭐ ফোনের হোমের প্রতিটা ঘর = ওয়েবের একই উৎস — সমন্বয়ক, ৬ অক্টোবর ২০২৬ (a5-এর ড্যাশবোর্ড যাচাইয়ের সাথে মিলিয়ে)।
 *
 * ⓘ একই মানুষ (মালিক), একই দিন, একই দেখার শাখা: ফোনের `GET /dashboard/today` আর ওয়েবের হোমের উইজেট বা বাক্স যা
 * দেখায়, পাশাপাশি — উইজেটের আঁকা মানটাই ধরা, তার ভেতরের ফাংশন নয়, যাতে কেউ ওয়েবের উৎস বদলালে এখানে লাল হয়।
 *
 *   · বিক্রি আর আদায় — ওয়েবের হোমের "আজ" কার্ড ([[SalesWidgets]])
 *   · হাতে ও ব্যাংকে মোট আর চার ভাগ — ওয়েবের হোমের ডান-উপরের বাক্স ([[AccountsWidgets]])
 *   · প্রিন্সিপাল — সরবরাহকারীর ড্যাশবোর্ডের বাক্স, একই রিপোর্ট একই শাখায় ([[SupplierDashboard::principals()]])
 *   · দেনা — ওয়েবের হোমে এর জোড়া নেই (মালিকের ফোনের চাওয়া: "যাকেই আমার পেমেন্ট করতে হবে"); তাই খাতের ছকের
 *     দায়ের দল (২০০০) + হাতধারের পাতার "আমরা দেব"
 *   · ইনফ্লো — ওয়েবের হোমে নেই; অর্থের ড্যাশবোর্ডের টাকা-প্রবাহের একই নিয়ম — টাকা এলে বাড়ে, নিজের মধ্যে
 *     স্থানান্তরে নয়
 *
 *   · পাওনা — ওয়েবের হোমের "বাজারে বকেয়া" ([[CustomerWidgets]]): দোকানের বকেয়ার মোট, অগ্রিম কাটা নয় (সমন্বয়কের সিদ্ধান্ত,
 *     IAS 1; a5-এর 0e5ff110)
 */
final class ThePhoneHomeSaysWhatTheWebHomeSaysTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->web();
        app(StandardChart::class)->install();
    }

    public function test_sales_and_collection_are_the_web_homes_today_cards(): void
    {
        // ⓘ এ মাসের আগের দিনের একটা বিলও — "আজ" আর "এ মাস" আলাদা না হলে আজকের ঘর মাসের সংখ্যা দেখালেও সবুজ হত
        $this->billToday(Carbon::today()->isSameMonth(Carbon::yesterday()) ? Carbon::yesterday()->toDateString() : null);
        $this->billToday();
        $phone = $this->phone();

        $this->web();
        $this->assertSame($this->card(SalesWidgets::widgets(), 'today', 10)->value, Money::format($phone->json('sales.amount')),
            '⛔ আজকের বিক্রি ফোনে এক, ওয়েবে আরেক।');
        $this->assertSame($this->card(SalesWidgets::widgets(), 'today', 20)->value, Money::format($phone->json('collections.amount')),
            '⛔ আজকের আদায় ফোনে এক, ওয়েবে আরেক।');
        $this->assertNotSame(Money::format('0'), Money::format($phone->json('sales.amount')), 'দাবির ভিত্তি নেই — আজ বিক্রিই নেই।');
    }

    /**
     * ⭐ পাওনা — ওয়েবের হোমের "বাজারে বকেয়া" (a5, 0e5ff110: মোট, নিট নয় — IAS 1), একই উৎস ([[CustomerMetrics::dues()]])।
     * ⓘ বিপজ্জনক উপাত্ত: এক দোকানের বকেয়া আর আরেক দোকানের অগ্রিম — নিট হলে দুই সংখ্যা আলাদা হত।
     */
    public function test_recoverable_is_the_web_homes_owed_by_customers_and_advances_do_not_cut_it(): void
    {
        $this->billToday();
        $shop = Customer::query()->orderBy('id')->skip(1)->firstOrFail();
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->firstOrFail();
        $this->journal([
            ['account_id' => $cash->id, 'debit' => '5000', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => '5000',
                'party_type' => Customer::drillSourceType(), 'party_id' => $shop->id],
        ]);
        $phone = $this->phone()->json('dues');

        $this->web();
        $card = collect(\App\Modules\Customer\Dashboard\CustomerWidgets::widgets())
            ->first(fn (Widget $w) => $w->label === __('customer::dashboard.kpi_owed'));
        $this->assertNotNull($card, 'ওয়েবের "বাজারে বকেয়া" ঘর নেই।');
        $this->assertSame($card->value, Money::format($phone['amount']), '⛔ পাওনা ফোনে এক, ওয়েবে আরেক।');
        $this->assertNotNull($card->hint, 'দাবির ভিত্তি নেই — অগ্রিম নেই, তাই মোট আর নিট একই।');

        $net = StandardChart::find(StandardChart::RECEIVABLE)->balanceOn(null, ViewedBranch::one());
        $this->assertNotSame(Money::format($net), $card->value, '⛔ ঘরটা আবার ১১১০-এর নিট জের দেখায় — অগ্রিম বকেয়া কাটছে।');
    }

    public function test_money_is_the_web_homes_top_right_box_and_its_four_parts(): void
    {
        $this->putMoneyIn(Account::query()->money()->postable()->active()->firstOrFail(), '12345.67');
        // ⓘ MFS-এও টাকা — মোটে MFS বাদ পড়লেও শূন্যের যোগে সবুজ হত
        $mfs = Account::query()->where('money_kind', Account::MFS)->postable()->active()->first()
            ?? $this->leaf(Account::MFS, StandardChart::MOBILE_MONEY, 'TST-HPM');
        $this->putMoneyIn($mfs, '777.25');
        $phone = $this->phone()->json('money');

        $this->web();
        $box = collect(AccountsWidgets::widgets())->first(fn (Widget $w) => $w->label === __('accounts::dashboard.money_on_hand'));
        $this->assertNotNull($box);

        $this->assertSame($box->value, Money::format($phone['amount']), '⛔ "হাতে ও ব্যাংকে মোট" ফোনে এক, ওয়েবে আরেক।');
        $this->assertSame(array_values($box->parts), array_map(fn ($v) => Money::format($v),
            [$phone['cash'], $phone['mfs'], $phone['bank'], $phone['transit']]), '⛔ নগদ · MFS · ব্যাংক · পথে — ভাগ মেলে না।');
    }

    public function test_principals_are_the_supplier_dashboards_box_row_for_row(): void
    {
        // ⓘ একজন প্রিন্সিপাল — ডেমোতে কেউ নেই, আর খালি = খালি কিছুই মাপে না
        \App\Modules\Supplier\Models\Supplier::query()->orderBy('id')->firstOrFail()->forceFill([
            'principal_branch_id' => $this->company->defaultBranch()?->id, 'commission_basis' => 'margin',
            'commission_rate' => '3.850', 'cycle_start_day' => 2, 'cycle_close_day' => 1,
        ])->save();
        $phone = $this->phone()->json('principals') ?? [];

        $this->web();
        $web = app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, ['branch_id' => ViewedBranch::one()], 1, 50)->rows;

        $this->assertNotEmpty($web, 'দাবির ভিত্তি নেই — ডেমোতে প্রিন্সিপালের সারি নেই।');
        $this->assertSame(count($web), count($phone), '⛔ প্রিন্সিপালের সারির সংখ্যা ফোনে আর ওয়েবে আলাদা।');
        foreach ($web as $i => $row) {
            foreach (['inflow', 'commission', 'paid', 'balance'] as $key) {
                $this->assertSame(0, bccomp((string) $row[$key], (string) $phone[$i][$key], 4), "⛔ {$row['supplier_name']}-এর {$key} মেলে না।");
            }
            $this->assertSame((string) $row['supplier_name'], $phone[$i]['name']);
        }
    }

    /**
     * ⭐ "আসল" ভিত্তিতে কেনা দামের নিচে বিক্রি — কমিশন ঋণাত্মক, আর ফোন ওয়েবের বাক্সের লেখাটাই বলে ("লোকসান ৳… — কেনা
     * দামের নিচে বিক্রি"), খালি বিয়োগ নয় (সমন্বয়ক, ৬ অক্টোবর ২০২৬)।
     */
    public function test_a_loss_on_the_actual_basis_is_said_in_the_webs_words(): void
    {
        $principal = \App\Modules\Supplier\Models\Supplier::query()->orderBy('id')->firstOrFail();
        $principal->forceFill([
            'principal_branch_id' => $this->company->defaultBranch()?->id, 'commission_basis' => 'actual',
            'commission_rate' => null, 'cycle_start_day' => 2, 'cycle_close_day' => 1,
        ])->save();

        // ⓘ আজকের বিলের মাল এই প্রিন্সিপালের স্তর থেকে — আদায় নেই, তাই কমিশন = − কেনা দাম
        $this->billToday();
        $layers = \Illuminate\Support\Facades\DB::table('inv_cost_layer_uses')->where('source_type', 'sales_invoice')->pluck('cost_layer_id');
        $this->assertNotEmpty($layers, 'দাবির ভিত্তি নেই — বিলে কেনা-দামের স্তরই নেই।');
        \Illuminate\Support\Facades\DB::table('inv_cost_layers')->whereIn('id', $layers)->update(['supplier_id' => $principal->id]);

        $phone = collect($this->phone()->json('principals'))->firstWhere('name', \App\Modules\Supplier\Reports\PrincipalCommission::shortName($principal->short_name, $principal->name_bn, (string) $principal->name_en));
        $this->assertNotNull($phone, 'প্রিন্সিপালের সারি ফোনে নেই।');
        $this->assertSame(-1, bccomp((string) $phone['commission'], '0', 4), 'দাবির ভিত্তি নেই — কমিশন ঋণাত্মক হয়নি।');

        $this->web();
        $this->assertSame(\App\Modules\Supplier\Dashboard\SupplierDashboard::earned((string) $phone['commission']), $phone['commissionLabel'],
            '⛔ ফোনের লোকসানের লেখা ওয়েবের বাক্সের নয়।');
        $this->assertStringNotContainsString('-', $phone['commissionLabel'], '⛔ লোকসান খালি বিয়োগ চিহ্নে বলা হলো।');
    }

    public function test_payable_is_the_chart_liabilities_and_the_hand_loan_pages_we_owe(): void
    {
        // ⓘ ডেমোতে দায় নেই — একটা সরবরাহকারীর পাওনা বসানো, যাতে দাবিটা শূন্য = শূন্য না মাপে
        $this->journal([
            ['account_id' => StandardChart::find(StandardChart::INSURANCE_PREMIUM)->id, 'debit' => '5000', 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '0', 'credit' => '5000'],
        ]);
        $phone = $this->phone()->json('payable');

        $this->web();
        $books = StandardChart::find('2000')->balanceOn(null, ViewedBranch::one());
        $handLoans = app(HandLoanService::class)->standing()['we_owe'];

        $this->assertSame(0, bccomp((string) $books, (string) $phone['books'], 4), '⛔ দেনা খাতের ছকের দায়ের দলের জের নয়।');
        $this->assertSame(0, bccomp((string) $handLoans, (string) $phone['handLoans'], 4), '⛔ হাতধারের দেনা হাতধারের পাতার নয়।');
        $this->assertSame(0, bccomp(bcadd((string) $books, (string) $handLoans, 4), (string) $phone['amount'], 4));
        $this->assertNotSame(0, bccomp((string) $books, '0', 4), 'দাবির ভিত্তি নেই — ডেমোতে কোনো দায়ই নেই।');
    }

    public function test_inflow_grows_by_money_that_came_in_and_not_by_a_move_between_our_own_accounts(): void
    {
        $before = $this->phone()->json('inflow.amount');

        $this->web();
        $cash = Account::query()->where('money_kind', Account::CASH)->postable()->active()->firstOrFail();
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->first()
            ?? $this->leaf(Account::BANK, StandardChart::BANK, 'TST-HPB');
        $this->journal([
            ['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0'],
            ['account_id' => StandardChart::find('4300')->id, 'debit' => '0', 'credit' => '1000'],
        ]);
        $this->journal([
            ['account_id' => $bank->id, 'debit' => '400', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '400'],
        ]);

        // ⓘ আর একটা খরচের টাকা বেরোল — বেরোনো টাকা ইনফ্লোতে ঢুকলেও আগে সবুজ হত
        $this->journal([
            ['account_id' => StandardChart::find(StandardChart::INSURANCE_PREMIUM)->id, 'debit' => '300', 'credit' => '0'],
            ['account_id' => $cash->id, 'debit' => '0', 'credit' => '300'],
        ]);

        $after = $this->phone()->json('inflow.amount');
        $this->assertSame(0, bccomp(bcsub($after, $before, 4), '1000', 4),
            '⛔ ইনফ্লো এল টাকার সমান বাড়েনি — নিজের খাতে সরানোও গোনা হলো, বা আসা টাকা বাদ পড়ল।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────────────

    private function web(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function phone(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this->getJson('/api/v1/dashboard/today')->assertOk();
    }

    /** @param  list<Widget>  $widgets */
    private function card(array $widgets, string $group, int $sort): Widget
    {
        $card = collect($widgets)->first(fn (Widget $w) => $w->group === $group && $w->sort === $sort);
        $this->assertNotNull($card, "ওয়েবের {$group}/{$sort} কার্ড নেই।");

        return $card;
    }

    private function billToday(?string $on = null): void
    {
        if ($on === null && func_num_args() === 1) {
            return; // ⓘ মাসের প্রথম দিন — আগের দিন অন্য মাসে
        }

        $this->web();
        $service = app(SalesInvoiceService::class);
        $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => $on ?? Carbon::today()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        ));
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function journal(array $lines): void
    {
        $vouchers = app(VoucherService::class);
        $vouchers->post($vouchers->create([
            'type' => Voucher::JOURNAL, 'trx_date' => Carbon::today()->toDateString(), 'narration' => 'home parity',
            'instrument_no' => 'TST-HP-'.random_int(1, 999999),
        ], $lines));
    }

    private function leaf(string $kind, string $parent, string $code): Account
    {
        $till = Account::query()->where('money_kind', Account::CASH)->postable()->firstOrFail();
        $leaf = $till->replicate(['public_id']);
        $leaf->forceFill(['code' => $code, 'name_en' => $code, 'name_bn' => $code, 'money_kind' => $kind,
            'parent_id' => Account::query()->where('code', $parent)->value('id')])->save();

        return $leaf;
    }
}
