<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Reports\MonthlyCashReport;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মাসওয়ারি টাকা আসা-যাওয়া — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬ ([[MonthlyCashReport]])।
 *
 * এক মাসে পাঁচটা ঘটনা — গ্রাহকের আদায় ১,০০০, মূলধন ২,০০০ ব্যাংকে, সরবরাহকারীকে ৪০০ ব্যাংক থেকে, ভাড়া
 * ৫০ নগদে, আর নগদ থেকে ব্যাংকে ৩০০ জমা — সাথে একটা বাতিল হওয়া ৫০০-র আদায়, আর অন্য শাখার একটা আদায়। ⓘ ডেমোর নিজের সারিও এই মাসে
 * থাকে, তাই দাবিগুলো আগে-পরের **পার্থক্যে**: ঘটনাগুলো যা যোগ করল, ঠিক তাই।
 */
final class TheMonthsMoneyInAndOutTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $bank;

    private Branch $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $company->defaultBranch();
        CompanyContext::set($company->id, $this->home->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->cash = Account::query()->ofMoneyKind(Account::CASH)->postable()->active()->orderBy('id')->firstOrFail();
        $this->bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first()
            ?? tap($this->cash->replicate(['public_id']), fn (Account $a) => $a->forceFill([
                'code' => 'BANK-MCR', 'name_en' => 'BANK-MCR', 'name_bn' => 'BANK-MCR', 'money_kind' => Account::BANK,
            ])->save());
    }

    public function test_one_month_splits_its_money_and_nets_the_transfer_inside_the_view(): void
    {
        $before = $this->row([]);
        $this->happen();
        $after = $this->row([]);

        $this->assertMoved($before, $after, [
            'in_collections' => '1000', 'in_other' => '0', 'in_finance' => '2000', 'in_transfer' => '0', 'total_in' => '3000',
            'out_suppliers' => '400', 'out_expenses' => '50', 'out_salaries' => '0', 'out_finance' => '0', 'out_transfer' => '0',
            'total_out' => '450', 'net' => '2550', 'closing' => '2550', 'opening' => '0',
        ], 'সব টাকার খাত');
    }

    /** ⭐ একটা খাত বাছলে বাইরের খাতে যাওয়া টাকা স্থানান্তর — নগদ থেকে ব্যাংকে ৩০০ তখন "স্থানান্তর গেল"। */
    public function test_one_account_shows_the_transfer_to_the_other_account(): void
    {
        $before = $this->row(['account_id' => $this->cash->id]);
        $this->happen();
        $after = $this->row(['account_id' => $this->cash->id]);

        $this->assertMoved($before, $after, [
            'in_collections' => '1000', 'in_finance' => '0', 'in_transfer' => '0', 'total_in' => '1000',
            'out_suppliers' => '0', 'out_expenses' => '50', 'out_transfer' => '300', 'total_out' => '350', 'net' => '650',
        ], 'কেবল নগদ');
    }

    /** ⛔ শাখার দেয়াল — অন্য শাখার আদায় এই শাখার মাসে নেই। */
    public function test_another_branchs_collection_stays_out(): void
    {
        $before = $this->row(['branch_id' => $this->home->id]);
        $this->happen();
        $after = $this->row(['branch_id' => $this->home->id]);

        $this->assertMoved($before, $after, ['in_collections' => '1000', 'total_in' => '3000'], 'নিজের শাখা');
    }

    /** ⭐ পর্দা খোলে, মাসের নাম আর মেনুর দরজা সহ। */
    public function test_the_screen_opens_from_the_menu(): void
    {
        $this->happen();

        $this->get(route('accounts.report.show', ['slug' => 'monthly-cash']))
            ->assertOk()
            ->assertSee(now()->locale(app()->getLocale())->translatedFormat('F'));

        $this->assertStringContainsString('monthly-cash', (string) $this->get(route('accounts.report.show', ['slug' => 'day-book']))->getContent());
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function happen(): void
    {
        $on = now()->toDateString();
        $post = fn (array $lines, ?int $branch = null) => app(PostingEngine::class)->post(
            sourceType: 'test:month', sourceId: random_int(1, 9_999_999), trxDate: $on, lines: $lines, branchId: $branch ?? $this->home->id,
        );

        $receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $payable = StandardChart::find(StandardChart::PAYABLE);

        $post([['account_id' => $this->cash->id, 'debit' => '1000'], ['account_id' => $receivable->id, 'credit' => '1000', 'party_type' => 'customer', 'party_id' => 1]]);
        $post([['account_id' => $this->bank->id, 'debit' => '2000'], ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '2000']]);
        $post([['account_id' => $payable->id, 'debit' => '400', 'party_type' => 'supplier', 'party_id' => 1], ['account_id' => $this->bank->id, 'credit' => '400']]);
        $post([['account_id' => StandardChart::find(StandardChart::RENT)->id, 'debit' => '50'], ['account_id' => $this->cash->id, 'credit' => '50']]);
        $post([['account_id' => $this->bank->id, 'debit' => '300'], ['account_id' => $this->cash->id, 'credit' => '300']]);

        // ⓘ একটা আদায় ৫০০, তারপর বাতিল — আদায়ে কিছুই যোগ হয় না, আর খরচেও বসে না
        $id = random_int(1, 9_999_999);
        app(PostingEngine::class)->post(sourceType: 'test:month', sourceId: $id, trxDate: $on, branchId: $this->home->id, lines: [
            ['account_id' => $this->cash->id, 'debit' => '500'], ['account_id' => $receivable->id, 'credit' => '500', 'party_type' => 'customer', 'party_id' => 1],
        ]);
        app(PostingEngine::class)->post(sourceType: 'test:month:reversal', sourceId: $id, trxDate: $on, branchId: $this->home->id, lines: [
            ['account_id' => $receivable->id, 'debit' => '500', 'party_type' => 'customer', 'party_id' => 1], ['account_id' => $this->cash->id, 'credit' => '500'],
        ]);

        // ⓘ অন্য শাখার আদায় — শাখার দাবিতে বাদ পড়ার কথা
        $other = $this->home->replicate(['public_id']);
        $other->forceFill(['code' => 'MCR-2', 'name_en' => 'MCR-2', 'name_bn' => 'MCR-2', 'is_default' => false])->save();
        $post([['account_id' => $this->cash->id, 'debit' => '700'], ['account_id' => $receivable->id, 'credit' => '700', 'party_type' => 'customer', 'party_id' => 1]], $other->id);

        // ⓘ সব-খাতের দাবিতে অন্য শাখাও আছে — তাই আদায় ১,০০০ নয়, ১,৭০০ হত; আলাদা রাখতে ঐ সারিটা বাদ দিয়ে মাপা হয়
        $this->otherBranch = $other->id;
    }

    private ?int $otherBranch = null;

    /** @param  array<string, mixed>  $filters */
    private function row(array $filters): array
    {
        $from = now()->startOfMonth()->toDateString();
        $result = app(ReportEngine::class)->run(MonthlyCashReport::KEY, [...$filters, 'from' => $from, 'to' => now()->toDateString()], perPage: 500);

        // ⓘ অন্য শাখার ৭০০ সব-খাতের দৃশ্যে ঢোকে — শাখা না বাছা দাবিগুলোতে ঐ অঙ্কটা ফেরত বাদ
        foreach ($result->rows as $row) {
            if ((string) $row['month'] === $from) {
                $row = array_map(fn ($v) => $v, $row);

                if (empty($filters['branch_id']) && $this->otherBranch !== null) {
                    foreach (['in_collections', 'total_in', 'net', 'closing'] as $k) {
                        $row[$k] = bcsub((string) $row[$k], '700', 4);
                    }
                }

                return $row;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, string>  $want
     */
    private function assertMoved(array $before, array $after, array $want, string $view): void
    {
        $this->assertNotSame([], $after, "প্রস্তুতিটাই ভুল — {$view}: এই মাসের সারিই নেই।");

        foreach ($want as $key => $amount) {
            $moved = bcsub((string) ($after[$key] ?? '0'), (string) ($before[$key] ?? '0'), 4);

            $this->assertSame(0, bccomp($moved, $amount, 4), "⛔ {$view}: '{$key}' বদলাল {$moved}, হওয়ার কথা {$amount}।");
        }
    }
}
