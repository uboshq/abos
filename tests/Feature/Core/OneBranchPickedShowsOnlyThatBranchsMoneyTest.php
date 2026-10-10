<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * একটা শাখা বাছলে খাতার পর্দাগুলো কেবল সেই শাখার টাকা দেখায় — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ মালিকের প্রশ্ন, আবার ─────────────────────────────────────────────
 * "এক শাখার হিসাব কি এখনো আরেক শাখায় দেখায়?" ২৯ সেপ্টেম্বরে তালিকা আর রিপোর্ট
 * বাছা শাখা মানতে শিখেছিল ([[TheBranchYouChoseIsTheBranchYouSeeTest]]), কিন্তু
 * খাতা-পড়া ২১টা পর্দা নিজের মতো কোয়েরি লিখত — হিসাবের ছক, স্থিতিপত্র, গ্রাহকের
 * বকেয়া — আর ওরা তখনও গোটা কোম্পানির জের দেখাত। লাইভে ADI-র তিন শাখা, আর মালিক
 * মাপেন ঠিক এভাবেই: হেডারে শাখা বেছে।
 *
 * ── ⭐ মাপ: মালিক যেভাবে মাপেন ────────────────────────────────────────────
 * একই মালিক, একই ডেটা, কেবল হেডারের বাছাই বদলায় — ময়মনসিংহ, নেত্রকোনা, সব শাখা।
 *   ময়মনসিংহ → কেবল ৭০০ · নেত্রকোনা → কেবল ৩০০ · সব শাখা → ৭০০ + ৩০০ + শাখাহীন ৫০
 *
 * ⓘ ডেমোতে আগে থেকেই খাতা আছে, তাই অঙ্ক মাপা হয় **বাড়তির** হিসাবে: বিক্রির আগে
 * প্রতিটা বাছাইয়ের সংখ্যা, বিক্রির পরে আবার — পার্থক্যটাই এই পরীক্ষার টাকা।
 *
 * ⛔ আর বাকির সীমা: গ্রাহকের পাতায় "সব শাখা মিলিয়ে" অঙ্কটা শাখা বাছলেও গোটা থাকে,
 * কারণ সীমা ওটা দিয়েই মাপা হয় (মালিকের "সীমা পরম")।
 */
final class OneBranchPickedShowsOnlyThatBranchsMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $mymensingh;

    private Branch $netrakona;

    private User $owner;

    private Customer $customer;

    private Account $receivable;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->mymensingh = $this->branch('MMS');
        $this->netrakona = $this->branch('NTK');
        $this->day = now()->toDateString();

        CompanyContext::set($this->company->id, $this->mymensingh->id);
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->receivable = StandardChart::find(StandardChart::RECEIVABLE);
    }

    public function test_each_screen_shows_the_picked_branch_and_all_adds_up(): void
    {
        $before = $this->readEverything();

        /*
         * ⓘ গ্রাহকের কাছে পাওনা = বিলের মোট (ভ্যাটসহ), তাই প্রত্যাশা বিলের নিজের মোট
         * থেকে — হাতে লেখা ৭০০ নয়।
         */
        $mmsBill = $this->sell($this->mymensingh, '700');
        $ntkBill = $this->sell($this->netrakona, '300');
        $headBill = $this->sell($this->mymensingh, '50');

        // ⓘ শাখাহীন একটা বিল — প্রধান অফিসের, কোম্পানি-স্তরের
        DB::table('sal_invoices')->where('id', $headBill->id)->update(['branch_id' => null]);
        $moved = DB::table('ledger_entries')->where('document_no', $headBill->document_no)->update(['branch_id' => null]);
        $this->assertGreaterThan(0, $moved, 'শাখাহীন বিলের খাতার সারি খুঁজে পাওয়া যায়নি — দাবিটা কিছু মাপছে না।');

        $expect = [
            'mms' => bcadd((string) $mmsBill->total, '0', 2),
            'ntk' => bcadd((string) $ntkBill->total, '0', 2),
            'all' => bcadd(bcadd((string) $mmsBill->total, (string) $ntkBill->total, 4), (string) $headBill->total, 2),
        ];

        $after = $this->readEverything();

        /*
         * ⓘ গ্রাহকের তালিকা — মালিকের পরের সিদ্ধান্ত (42ec6cef, ১ অক্টোবর ২০২৬): এক শাখা বাছলে কেবল সেই শাখার পক্ষ, শাখাহীন পক্ষ
         * কেবল "সব শাখা"-য়। এই গ্রাহক শাখাহীন (ডেমো), তাই এক শাখায় তালিকায় নেই — বিক্রির আগে-পরে দুবারই; "সব শাখা"-য় আছে আর
         * যোগফলের নিয়মে মেলে (নিচে)। ⛔ এক শাখায় দেখা দিলে দাবি লাল (main-এর লাল সারাই, ১০ অক্টোবর ২০২৬)।
         */
        $list = 'গ্রাহকের তালিকা — বকেয়া';
        foreach (['mms', 'ntk'] as $one) {
            $this->assertSame(['missing', 'missing'], [$before[$one][$list], $after[$one][$list]],
                "⛔ শাখাহীন গ্রাহক এক শাখার তালিকায় দেখা দিল ({$one})।");
            unset($before[$one][$list], $after[$one][$list]);
        }
        $this->assertSame($expect['all'], bcsub($after['all'][$list], $before['all'][$list], 2), "⛔ {$list}: \"সব শাখা\"-য় পুরো বকেয়া নেই।");
        unset($before['all'][$list], $after['all'][$list]);

        foreach (array_keys($after['all']) as $screen) {
            $mms = bcsub($after['mms'][$screen], $before['mms'][$screen], 2);
            $ntk = bcsub($after['ntk'][$screen], $before['ntk'][$screen], 2);
            $all = bcsub($after['all'][$screen], $before['all'][$screen], 2);

            $this->assertSame($expect['mms'], $mms, "⛔ {$screen}: ময়মনসিংহ বেছে অন্য শাখার বা শাখাহীন টাকা দেখা গেল ({$mms})।");
            $this->assertSame($expect['ntk'], $ntk, "⛔ {$screen}: নেত্রকোনা বেছে অন্য শাখার টাকা দেখা গেল ({$ntk})।");
            $this->assertSame($expect['all'], $all, "⛔ {$screen}: \"সব শাখা\" = ময়মনসিংহ + নেত্রকোনা + শাখাহীন হলো না ({$all})।");
        }
    }

    public function test_the_credit_limit_still_reads_the_whole_company(): void
    {
        $this->sell($this->mymensingh, '700');
        $this->sell($this->netrakona, '300');

        $this->choose($this->mymensingh->id);
        $page = $this->actingAs($this->owner)->get(route('customer.show', $this->customer))->assertOk();

        /* ⛔ সীমার অঙ্ক এক শাখার নয় — "সব শাখা মিলিয়ে" গোটা থাকে */
        $this->assertSame(0, bccomp((string) $page->viewData('outstandingAll'), $this->customer->fresh()->outstanding(), 2),
            '⛔ এক শাখা বাছতেই "সব শাখা মিলিয়ে বকেয়া" এক শাখার হয়ে গেল — সীমা ভুল অঙ্কে মাপা হত।');
        $page->assertSee('data-due-all-branches', false);

        $this->choose('all');
        $this->actingAs($this->owner)->get(route('customer.show', $this->customer))->assertOk()
            ->assertDontSee('data-due-all-branches', false);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array{mms: array<string, string>, ntk: array<string, string>, all: array<string, string>} */
    private function readEverything(): array
    {
        $out = [];

        foreach (['mms' => $this->mymensingh->id, 'ntk' => $this->netrakona->id, 'all' => 'all'] as $key => $pick) {
            $this->choose($pick);
            $out[$key] = $this->screens();
        }

        $this->choose('all');

        return $out;
    }

    /** @return array<string, string> পর্দা => অঙ্ক */
    private function screens(): array
    {
        /*
         * ⚠️ `LedgerBalances` প্রতি অনুরোধে নতুন (`scoped`) — কিন্তু পরীক্ষার ভেতরে
         * পরপর অনুরোধে একই বস্তু থেকে যায়, আর তখন বিক্রির আগের জের ফিরত।
         * লাইভে প্রতিটা অনুরোধ নতুন বস্তু পায়; এখানে সেটাই হাতে করা হয়।
         */
        $this->app->forgetScopedInstances();

        $show = $this->actingAs($this->owner)->get(route('accounts.coa.show', $this->receivable))->assertOk();
        $chart = $this->actingAs($this->owner)->get(route('accounts.coa.index'))->assertOk();
        $sheet = $this->actingAs($this->owner)->get(route('accounts.balance_sheet'))->assertOk();
        $due = $this->actingAs($this->owner)->get(route('customer.show', $this->customer))->assertOk();
        $list = $this->actingAs($this->owner)->get(route('customer.index', ['q' => $this->customer->code]))->assertOk();

        $row = collect($list->viewData('customers')?->items() ?? [])->firstWhere('id', $this->customer->id);

        return [
            'হিসাবের ছক — খাতের পাতা' => (string) $show->viewData('balance'),
            'হিসাবের ছক — তালিকার জের' => (string) ($chart->viewData('balances')[$this->receivable->id] ?? '0'),
            'স্থিতিপত্র — প্রাপ্যের সারি' => $this->sheetLine($sheet->viewData('sheet'), StandardChart::RECEIVABLE),
            'গ্রাহকের পাতা — বকেয়া' => (string) $due->viewData('outstanding'),
            'গ্রাহকের তালিকা — বকেয়া' => (string) ($row?->outstanding_in_view ?? 'missing'),
        ];
    }

    /** স্থিতিপত্রে একটা খাতের সারি — না থাকলে শূন্য (শূন্য সারি পাতায় বসে না) */
    private function sheetLine(array $sheet, string $code): string
    {
        foreach ($sheet['assets'] as $head) {
            foreach ($head['lines'] as $line) {
                if ($line['account']->code === $code) {
                    return (string) $line['amount'];
                }
            }
        }

        return '0';
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function sell(Branch $branch, string $amount): SalesInvoice
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => $this->day],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        CompanyContext::set($this->company->id, $this->owner->current_branch_id ?? $this->mymensingh->id);

        return SalesInvoice::query()->withoutGlobalScopes()->findOrFail($invoice->id);
    }
}
