<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Dashboard\DashboardRegistry;
use App\Core\Dashboard\Widget;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
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
 * হোম পর্দা বাছা শাখাই দেখায় — মালিকের স্ক্রিনশট, ২৯ সেপ্টেম্বর ২০২৬:
 * *"demo 2" বাছা, অথচ হোম পর্দায় অন্য শাখার টাকা।*
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * কাগজের তালিকা বাছা শাখা মানত, কিন্তু হোম পর্দার টাকার ঘর খতিয়ান আর টিল
 * পড়ত গোটা কোম্পানি ধরে — তাই যে শাখাই বাছুন, হাতে-ব্যাংকে-MFS সবার যোগফল।
 *
 * ⭐ দাবি: একই মালিক, একই টাকা; কেবল হেডারের বাছাই বদলায়। প্রতিটা বাছাইয়ে
 * আগে-পরে মেপে **বাড়তিটা** মেলানো হয় — ডেমোর আগের টাকা যা-ই থাকুক।
 *
 *   শাখা ক  → কেবল ক-এর টাকা ও বিক্রি
 *   শাখা খ  → কেবল খ-এর
 *   সব শাখা → ক + খ + শাখাহীন (কোম্পানি-স্তরের টিল, ব্যাংকের সারি, বিল)
 */
final class TheDashboardShowsTheBranchYouPickedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $a;

    private Branch $b;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->a = $this->branch('MMS');
        $this->b = $this->branch('NTK');

        CompanyContext::set($this->company->id, $this->a->id);
        $this->actingAs($this->owner);
    }

    public function test_every_home_figure_follows_the_picked_branch_and_all_adds_up(): void
    {
        $before = [];

        foreach (['a', 'b', 'all'] as $pick) {
            $this->pick($pick);
            $before[$pick] = $this->figures();
        }

        $this->putMoneyAndSalesInEachBranch();

        $expected = [
            'a' => ['cash' => '100', 'mfs' => '10', 'bank' => '1000', 'money' => '1110', 'sales' => '700', 'receivable' => '700', 'accounts_head' => '100', 'finance_head' => '100'],
            'b' => ['cash' => '200', 'mfs' => '20', 'bank' => '2000', 'money' => '2220', 'sales' => '300', 'receivable' => '300', 'accounts_head' => '200', 'finance_head' => '200'],
            'all' => ['cash' => '340', 'mfs' => '34', 'bank' => '3400', 'money' => '3774', 'sales' => '1050', 'receivable' => '1050', 'accounts_head' => '340', 'finance_head' => '340'],
        ];

        foreach (['a', 'b', 'all'] as $pick) {
            $this->pick($pick);
            $after = $this->figures();

            foreach ($expected[$pick] as $figure => $want) {
                $grew = bcsub($after[$figure], $before[$pick][$figure], 2);

                $this->assertSame(0, bccomp($grew, $want, 2),
                    "⛔ '{$pick}' বাছা, অথচ হোম পর্দার '{$figure}' বাড়ল {$grew} — হওয়ার কথা {$want}।");
            }

            // ⓘ পাতাটা আসল দরজা দিয়েও খোলে — প্রতিটা বাছাইয়ে
            $this->get(route('dashboard'))->assertOk();
        }
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /** টিল, ব্যাংক, MFS আর বিক্রি — দুই শাখায় আলাদা অঙ্কে, আর কিছু শাখাহীন। */
    private function putMoneyAndSalesInEachBranch(): void
    {
        CompanyContext::set($this->company->id, $this->a->id);

        $tills = app(CashTillService::class);
        $tillA = $tills->create(['name_en' => 'Till A', 'holder_id' => $this->owner->id, 'branch_id' => $this->a->id]);
        $tillB = $tills->create(['name_en' => 'Till B', 'holder_id' => $this->owner->id, 'branch_id' => $this->b->id]);
        $tillHq = $tills->create(['name_en' => 'Head office till', 'holder_id' => $this->owner->id]);
        DB::table('cash_tills')->where('id', $tillHq->id)->update(['branch_id' => null]);

        $bank = app(AccountService::class)->create(['name_en' => 'Test bank', 'parent_id' => StandardChart::find(StandardChart::BANK)->id]);
        $mfs = app(AccountService::class)->create(['name_en' => 'Test wallet', 'parent_id' => StandardChart::find(StandardChart::MOBILE_MONEY)->id]);

        $this->deposit($tillA->account, '100', $this->a->id);
        $this->deposit($tillB->account, '200', $this->b->id);
        $this->deposit($tillHq->account, '40', null);

        $this->deposit($bank, '1000', $this->a->id);
        $this->deposit($bank, '2000', $this->b->id);
        $this->deposit($bank, '400', null);

        $this->deposit($mfs, '10', $this->a->id);
        $this->deposit($mfs, '20', $this->b->id);
        $this->deposit($mfs, '4', null);

        [$first, $second, $third] = Customer::query()->orderBy('id')->take(3)->get()->all();
        $this->sell($first, $this->a, '700');
        $this->sell($second, $this->b, '300');
        $unbranched = $this->sell($third, $this->a, '50');
        DB::table('sal_invoices')->where('id', $unbranched)->update(['branch_id' => null]);
        DB::table('ledger_entries')->where('source_type', SalesInvoice::drillSourceType())->where('source_id', $unbranched)->update(['branch_id' => null]);

        CompanyContext::set($this->company->id, $this->a->id);
    }

    /** টাকা ঢোকানো — মালিকের পুঁজি থেকে, খাতার আসল দরজা দিয়ে। */
    private function deposit(Account $into, string $amount, ?int $branch): void
    {
        CompanyContext::set($this->company->id, $branch);

        app(PostingEngine::class)->post(
            sourceType: 'test:dashboard-branch',
            sourceId: random_int(1, 999999),
            trxDate: now(),
            lines: [
                ['account_id' => $into->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => $amount],
            ],
            branchId: $branch,
        );

        CompanyContext::set($this->company->id, $this->a->id);
    }

    private function sell(Customer $customer, Branch $branch, string $amount): int
    {
        CompanyContext::set($this->company->id, $branch->id);

        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('branch_id', $branch->id)->orderBy('id')->value('id');

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse, 'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => $amount]],
        );
        app(SalesInvoiceService::class)->confirm($invoice);

        CompanyContext::set($this->company->id, $this->a->id);

        return (int) $invoice->id;
    }

    /** হেডারের বাছাই — আসল দরজা দিয়ে, তারপর পরের অনুরোধের মতো প্রসঙ্গ। */
    private function pick(string $which): void
    {
        $branch = match ($which) {
            'a' => (string) $this->a->id,
            'b' => (string) $this->b->id,
            default => 'all',
        };

        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->actingAs($this->owner);
    }

    /**
     * হোম পর্দার সংখ্যাগুলো — পর্দা যেখান থেকে নেয় ঠিক সেখান থেকে।
     *
     * @return array<string, string>
     */
    private function figures(): array
    {
        $groups = app(DashboardRegistry::class)->forUser($this->owner);

        $money = $this->widget($groups['today'], __('accounts::dashboard.money_on_hand'));
        $sales = $this->widgetBySort($groups['today'], 10);
        $receivable = $this->widget($groups['month'], __('core.accounting.receivable'));

        $heads = [];

        foreach (app(DashboardEngine::class)->overall($this->owner) as $row) {
            $heads[$row['module']] = self::number($row['stat']->value);
        }

        return [
            'money' => self::number($money->value),
            'cash' => self::number($money->parts[__('accounts::dashboard.cash_in_hand')]),
            'mfs' => self::number($money->parts[__('accounts::dashboard.mfs_balance')]),
            'bank' => self::number($money->parts[__('accounts::dashboard.bank_balance')]),
            'sales' => self::number($sales->value),
            'receivable' => self::number($receivable->value),
            'accounts_head' => $heads['accounts'] ?? 'missing',
            'finance_head' => $heads['finance'] ?? 'missing',
        ];
    }

    /** @param  list<Widget>  $widgets */
    private function widget(array $widgets, string $label): Widget
    {
        foreach ($widgets as $widget) {
            if ($widget->label === $label) {
                return $widget;
            }
        }

        $this->fail("হোম পর্দায় '{$label}' ঘরটাই নেই — দাবিটা তখন কিছুই মাপত না।");
    }

    /** @param  list<Widget>  $widgets */
    private function widgetBySort(array $widgets, int $sort): Widget
    {
        foreach ($widgets as $widget) {
            if ($widget->sort === $sort && $widget->tone === 'money') {
                return $widget;
            }
        }

        $this->fail("হোম পর্দার 'আজ' দলে sort {$sort}-এর টাকার ঘর নেই।");
    }

    /** সাজানো টাকা ("১,২৩৪.০০" বা "1,234.00") থেকে সংখ্যা। */
    private static function number(string $formatted): string
    {
        $western = strtr($formatted, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $clean = preg_replace('/[^0-9.\-]/', '', $western);

        return $clean === '' || $clean === null ? '0' : $clean;
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
