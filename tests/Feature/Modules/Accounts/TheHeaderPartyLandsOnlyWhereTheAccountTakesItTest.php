<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মাথার পক্ষ এমন খাতে নামতে গেল যে খাত ঐ পক্ষ রাখে না — cb-র খাতের "পক্ষ রাখে" ধর্ম (9a5d265e) ভাউচারে, ৭ অক্টোবর ২০২৬।
 *
 * ⭐ এক সত্য: খাতের ধর্মই বলে কোন খাত কার নামে বসে ([[VoucherService::accountsThatHoldAParty()]])। মাথার পক্ষ নামে কেবল যে খাত
 * ঐ ধরন নেয়; হাতে লেখা জাবেদায় পক্ষ-রাখা খাতে পক্ষ লাগে, ধর্ম তুলে দিলে লাগে না।
 */
final class TheHeaderPartyLandsOnlyWhereTheAccountTakesItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_customer_on_the_header_lands_on_receivables_and_not_on_capital(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $receivable = $this->leaf(StandardChart::RECEIVABLE);
        $capital = $this->leaf(StandardChart::OWNER_CAPITAL);

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাথায় গ্রাহক',
                'party_type' => 'customer', 'party_id' => $customer->id],
            [['account_id' => $receivable->id, 'debit' => '400', 'credit' => '0'],
                ['account_id' => $capital->id, 'debit' => '0', 'credit' => '400']],
        );
        app(VoucherService::class)->post($voucher);

        $rows = LedgerEntry::query()->where('document_no', $voucher->document_no)->get()->keyBy('account_id');
        $this->assertSame('customer', $rows[$receivable->id]->party_type, '⛔ পাওনার সারিতে মাথার গ্রাহক নামেনি।');
        $this->assertSame((int) $customer->id, (int) $rows[$receivable->id]->party_id);
        $this->assertNull($rows[$capital->id]->party_type, '⛔ মূলধনের সারিতে গ্রাহক বসল — খাতটা কেবল মানুষ রাখে।');
    }

    public function test_the_journal_rule_reads_the_accounts_own_property(): void
    {
        $profit = $this->leaf(StandardChart::PROFIT_PAYABLE);
        $expense = StandardChart::find(StandardChart::HAMMALI);
        $lines = [['account_id' => $expense->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $profit->id, 'debit' => '0', 'credit' => '100']];
        $data = ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মুনাফা প্রদেয়'];

        // ⓘ ২১৯০ মানুষের নামে বসে (ধর্ম) — পক্ষ ছাড়া হাতে লেখা জাবেদা থামে
        try {
            app(VoucherService::class)->create($data, $lines, byHand: true);
            $this->fail('⛔ পক্ষ-রাখা খাতে পক্ষ ছাড়া জাবেদা জমা হলো।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines', $e->errors());
        }

        // ⓘ মালিক ধর্ম তুলে দিলে — আর পক্ষ লাগে না (একই খাত, একই মানুষ, বন্ধ তারপর চালু নয়: ধর্ম নিজেই নিয়ম)
        Account::query()->whereKey($profit->id)->update(['party_types' => null]);
        $this->assertTrue(app(VoucherService::class)->create($data, $lines, byHand: true)->exists, '⛔ ধর্ম তোলার পরেও পক্ষ চাইল।');
    }

    private function leaf(string $code): Account
    {
        $root = StandardChart::find($code);

        return $root->is_group
            ? Account::query()->postable()->whereKey($root->selfAndDescendants()->pluck('id'))->orderBy('code')->firstOrFail()
            : $root;
    }
}
