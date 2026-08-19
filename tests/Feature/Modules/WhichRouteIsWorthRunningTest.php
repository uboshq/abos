<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CostCenter;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোন রুট চালানোর মতো।
 *
 * ── কোন প্রশ্নের উত্তর ছিল না ───────────────────────────────────────
 * ডিপো জ্বালানি কেনে, চালকের বেতন দেয়, গাড়ি সারায়। খাতায় ওগুলো বসে
 * "জ্বালানি ও পরিবহন", "বেতন", "মেরামত" — খাত ধরে ঠিকই আছে।
 *
 * কিন্তু মালিকের প্রশ্নটা অন্য: **"নেত্রকোনার রুটে মাসে কত খরচ হয়, আর
 * ওখান থেকে কত আসে?"** ৪% মার্জিনের ব্যবসায় একটা রুটের খরচ তার আয়ের
 * চেয়ে বেশি হওয়া খুবই সম্ভব — আর মোট হিসাবে সেটা দেখাই যায় না, কারণ
 * অন্য রুটগুলো টেনে নেয়।
 */
class WhichRouteIsWorthRunningTest extends TestCase
{
    use RefreshDatabase;

    private CostCenter $netrakona;

    private CostCenter $kendua;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->netrakona = $this->centre('NTK', 'Netrakona route', 'নেত্রকোনা রুট');
        $this->kendua = $this->centre('KDA', 'Kendua route', 'কেন্দুয়া রুট');
    }

    private function centre(string $code, string $en, string $bn): CostCenter
    {
        return CostCenter::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $en,
            'name_bn' => $bn,
            'is_active' => true,
        ]);
    }

    private function till(): int
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account->id;
    }

    /** একটা খরচ — কেন্দ্র ধরে, বা কেন্দ্র ছাড়া। */
    private function spend(string $amount, ?CostCenter $centre): Voucher
    {
        return app(VoucherService::class)->post(
            app(VoucherService::class)->create(
                ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString()],
                [
                    [
                        'account_id' => StandardChart::find('5204')->id,   // জ্বালানি ও পরিবহন
                        'debit' => $amount,
                        'cost_center_id' => $centre?->id,
                    ],
                    ['account_id' => $this->till(), 'credit' => $amount],
                ],
            ),
        );
    }

    /** @return array<string, object> কেন্দ্রের নাম ধরে সারিগুলো */
    private function byCentre(): array
    {
        $result = app(ReportEngine::class)->run('accounts.by_cost_centre', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);

        $rows = [];

        foreach ($result->rows as $row) {
            $row = (object) $row;
            $rows[(string) $row->centre_name] = $row;
        }

        return $rows;
    }

    /**
     * খরচটা কেন্দ্র ধরে খতিয়ানে পৌঁছায়।
     *
     * এটাই পুরো কাজের ভিত্তি: ঘরটা ভাউচারের সারিতে, আর ওখান থেকে
     * খতিয়ানের সারিতে। মাঝপথে হারালে রিপোর্টে কিছুই দেখা যেত না।
     */
    public function test_the_centre_reaches_the_ledger(): void
    {
        $this->spend('3000', $this->netrakona);

        $this->assertDatabaseHas('ledger_entries', [
            'cost_center_id' => $this->netrakona->id,
            'debit' => '3000.0000',
        ]);
    }

    /**
     * দুই রুটের খরচ আলাদা করে দেখা যায়।
     *
     * ── কেন এক ভাউচারে দুই কেন্দ্র ───────────────────────────────────
     * ঘরটা সারিতে, ডকুমেন্টের মাথায় নয় — তাই "নেত্রকোনায় ২,০০০ আর
     * কেন্দুয়ায় ১,৫০০ জ্বালানি" এক কাগজেই লেখা যায়। মাথায় রাখলে
     * দুইটা ভাউচার লাগত, আর মানুষ তখন একটাতেই লিখে দিতেন।
     */
    public function test_two_routes_are_counted_apart(): void
    {
        app(VoucherService::class)->post(
            app(VoucherService::class)->create(
                ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString()],
                [
                    ['account_id' => StandardChart::find('5204')->id, 'debit' => '2000',
                        'cost_center_id' => $this->netrakona->id],
                    ['account_id' => StandardChart::find('5204')->id, 'debit' => '1500',
                        'cost_center_id' => $this->kendua->id],
                    ['account_id' => $this->till(), 'credit' => '3500'],
                ],
            ),
        );

        $rows = $this->byCentre();

        $this->assertSame(0, bccomp((string) $rows['নেত্রকোনা রুট']->spent, '2000', 2));
        $this->assertSame(0, bccomp((string) $rows['কেন্দুয়া রুট']->spent, '1500', 2));
    }

    /**
     * কেন্দ্র বসানো হয়নি এমন খরচও নিজের সারিতে আসে।
     *
     * বাদ দিলে যোগফল মিলত না, আর মালিক ভাবতেন খরচটা কম। সারিটা বড়
     * হলে সেটাই সবচেয়ে দরকারি খবর: অভ্যাসটা এখনো গড়ে ওঠেনি।
     */
    public function test_untagged_spending_is_not_swallowed(): void
    {
        $this->spend('900', null);

        $rows = $this->byCentre();

        $this->assertArrayHasKey(__('accounts::field.no_cost_center'), $rows,
            'কেন্দ্রহীন খরচের সারিটা রিপোর্টে নেই — যোগফল কম দেখাত।');

        $this->assertSame(0, bccomp(
            (string) $rows[__('accounts::field.no_cost_center')]->spent, '900', 2,
        ));
    }

    /**
     * টাকা সরানো খরচ নয়।
     *
     * ব্যাংক থেকে টিলে টাকা আনলে দুইটা সারিই সম্পদের খাতে বসে। "রুটে
     * কত খরচ" প্রশ্নে ওটার কোনো জায়গা নেই, তাই রিপোর্ট কেবল আয় ও
     * ব্যয়ের খাত দেখে।
     */
    public function test_moving_money_is_not_spending(): void
    {
        app(VoucherService::class)->post(
            app(VoucherService::class)->create(
                ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString()],
                [
                    ['account_id' => $this->till(), 'debit' => '5000',
                        'cost_center_id' => $this->netrakona->id],
                    ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '5000'],
                ],
            ),
        );

        $this->assertSame([], $this->byCentre(),
            'টাকা সরানোর সারিটা খরচ হিসেবে গোনা হয়েছে।');
    }

    /** বাতিল করলে কেন্দ্রটাও উল্টো সারিতে যায় — নাহলে খরচ ফেরত আসত না। */
    public function test_a_reversal_carries_the_centre(): void
    {
        $voucher = $this->spend('4000', $this->netrakona);

        app(VoucherService::class)->cancel($voucher, 'ভুল রুটে বসেছিল');

        $rows = $this->byCentre();

        $this->assertSame(0, bccomp((string) ($rows['নেত্রকোনা রুট']->spent ?? '0'), '0', 2),
            'বাতিলের পরেও রুটের খরচ রয়ে গেছে — উল্টো সারিতে কেন্দ্রটা যায়নি।');
    }

    // ── পর্দা ও তালিকা ──────────────────────────────────────────────

    /** মাস্টার তালিকায় কেন্দ্র যোগ করা যায়। */
    public function test_the_master_list_holds_cost_centres(): void
    {
        $this->get(route('master_data.cost_center.index'))
            ->assertOk()
            ->assertSee('নেত্রকোনা রুট');
    }

    /** জাবেদার ফর্মে কেন্দ্র বাছার ঘরটা আছে। */
    public function test_the_journal_form_offers_the_centre(): void
    {
        $this->get(route('accounts.voucher.create', ['type' => 'journal']))
            ->assertOk()
            ->assertSee(__('accounts::field.cost_center'))
            ->assertSee('name="lines[0][cost_center_id]"', false);
    }

    /** রিপোর্টের পাতাটা খোলে। */
    public function test_the_report_screen_opens(): void
    {
        $this->spend('1200', $this->kendua);

        $this->get(route('accounts.report.show', ['slug' => 'by-cost-centre']))
            ->assertOk()
            ->assertSee('কেন্দুয়া রুট');
    }
}
