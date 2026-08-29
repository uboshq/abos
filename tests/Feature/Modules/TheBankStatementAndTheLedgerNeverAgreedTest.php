<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankReconciliation;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;
use App\Modules\Accounts\Services\BankReconciliationService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যাংকের কাগজ আর খাতা কোনোদিন মিলত না, আর কেউ মেলাতও না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ABOS-এ ব্যাংক হিসাব ছিল, চেক ছিল, ভাউচার ছিল — কিন্তু ব্যাংকের কাগজ
 * হাতে নিয়ে খাতার সাথে মেলানোর কোনো উপায় ছিল না। ফলে তফাতটা কেউ
 * দেখত না, আর তফাতই একমাত্র জিনিস যেটা ভুল বা চুরির দিকে আঙুল তোলে।
 *
 * ── এই ফাইলের পরীক্ষাগুলোর ক্রম ─────────────────────────────────────
 * প্রথম তিনটা অঙ্ক ও দরজা। শেষ তিনটা **গুণ্ডা-পরীক্ষা** — এগুলো ফিচারটা
 * থাকা সত্ত্বেও ভুল আচরণ ধরে, আর ঠিক এই ধরনের পাহারাই এ প্রকল্পে
 * বারবার অনুপস্থিত ছিল।
 */
class TheBankStatementAndTheLedgerNeverAgreedTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $cash;

    private BankReconciliationService $recons;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1102-CITY',
            'name_en' => 'City Bank',
            'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
        ]);

        /*
         * নগদেরও একটা পাতার খাত লাগে।
         *
         * `1101` নিজে একটা গ্রুপ, আর গ্রুপে সরাসরি লেনদেন বসে না —
         * ঠিকই, কারণ গ্রুপ কেবল যোগফল ধরে। তাই ব্যাংকের মতো এখানেও
         * নিচের একটা আসল খাত বানানো হয়।
         */
        $this->cash = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1101-MAIN',
            'name_en' => 'Main till',
            'name_bn' => 'প্রধান ক্যাশ',
            'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_cash' => true,
        ]);

        $this->recons = app(BankReconciliationService::class);
    }

    /**
     * নগদ থেকে ব্যাংকে বা ব্যাংক থেকে নগদে — একটা বসানো ও পাশ করা ভাউচার।
     *
     * `instrument_no` বাধ্যতামূলক, আর সেটা এখানে ঘটনাচক্রে নয়: ব্যাংকে
     * টাকা গেলে VoucherService লেনদেন নম্বর চায়, আর ভাষার ফাইলে কারণ
     * হিসেবে লেখাই আছে "ওটা ছাড়া পরে ব্যাংকের সাথে মেলানো যাবে না"।
     * অর্থাৎ পাহারাটা ঠিক এই ফিচারটার জন্যই আগে থেকে বসানো ছিল।
     */
    private function move(string $date, string $amount, bool $intoBank): Voucher
    {
        $vouchers = app(VoucherService::class);

        $voucher = $vouchers->create(
            [
                'type' => Voucher::CONTRA,
                'trx_date' => $date,
                'instrument' => 'transfer',
                'instrument_no' => 'REF-'.$date.'-'.$amount.'-'.($intoBank ? 'IN' : 'OUT'),
            ],
            $vouchers->twoLineEntry(
                Voucher::CONTRA,
                $intoBank ? $this->cash->id : $this->bank->id,
                $intoBank ? $this->bank->id : $this->cash->id,
                $amount,
            ),
        );

        return $vouchers->post($voucher);
    }

    private function open(string $statementDate, string $balance): BankReconciliation
    {
        return $this->recons->open([
            'bank_account_id' => $this->bank->id,
            'statement_date' => $statementDate,
            'statement_balance' => $balance,
        ]);
    }

    /** ওই মিলকরণের ব্যাংক-লাইনগুলোর আইডি। */
    private function lineIds(BankReconciliation $recon): array
    {
        return $this->recons->candidates($recon)->pluck('id')->all();
    }

    /**
     * সব টিক পড়লে দুইটা সংখ্যা মেলে।
     *
     * সবচেয়ে সরল অবস্থা: ব্যাংকের কাগজে সব আছে, খাতাতেও সব আছে।
     */
    public function test_when_the_bank_has_seen_everything_the_two_agree(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);
        $this->move('2026-08-05', '20000', intoBank: false);

        $recon = $this->open('2026-08-31', '30000');
        $this->recons->mark($recon, $this->lineIds($recon));

        $summary = $this->recons->summary($recon);

        $this->assertSame('30000.0000', $summary['ledger']);
        $this->assertTrue($summary['agrees'], 'সব টিক পড়ার পরেও তফাত রয়ে গেছে।');
    }

    /**
     * টিক না পড়া জমা ও না-ভাঙানো চেক মিলিয়ে অঙ্কটা মেলে।
     *
     * এটাই মিলকরণের আসল অঙ্ক: ব্যাংক এখনো যা দেখেনি সেটা বাদ দিয়ে,
     * আর আমরা যে চেক দিয়েছি অথচ কেউ ভাঙায়নি সেটা যোগ করে।
     */
    public function test_the_arithmetic_explains_what_the_bank_has_not_seen(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);   // ব্যাংক দেখেছে
        $this->move('2026-08-28', '8000', intoBank: true);    // জমা, কাগজে ওঠেনি
        $this->move('2026-08-29', '3000', intoBank: false);   // চেক, ভাঙানো হয়নি

        $recon = $this->open('2026-08-31', '50000');

        // কেবল প্রথমটায় টিক
        $first = $this->recons->candidates($recon)
            ->first(fn (VoucherLine $l) => bccomp((string) $l->debit, '50000', 4) === 0);
        $this->recons->mark($recon, [$first->id]);

        $summary = $this->recons->summary($recon);

        $this->assertSame('8000.0000', $summary['deposits']);
        $this->assertSame('3000.0000', $summary['cheques']);

        // খাতা: ৫০০০০ + ৮০০০ − ৩০০০ = ৫৫০০০
        $this->assertSame('55000.0000', $summary['ledger']);

        // কাগজ সমন্বয়: ৫০০০০ − ৮০০০ + ৩০০০ = ৪৫০০০ ... মেলে না
        $this->assertFalse($summary['agrees']);

        /*
         * সংখ্যাগুলো হাতে মিলিয়ে দেখা:
         *
         * খাতা ৫৫০০০, আর কাগজ যা বলার কথা তা ৪৫০০০ — তফাত ১০০০০।
         * এটাই সঠিক, কারণ কাগজের জেরটা (৫০০০০) এখানে ইচ্ছা করে ভুল
         * দেওয়া হয়েছে; আসলে হওয়ার কথা ছিল ৫০০০০ − ০ = ৫০০০০ নয়,
         * বরং খাতা ৫৫০০০ থেকে অদেখা ৮০০০ বাদ ও অভাঙা ৩০০০ যোগ =
         * ৬০০০০। নিচের পরীক্ষায় সেই সঠিক জেরটা দিয়েই মিলিয়ে দেখা হয়।
         */
        $this->assertSame('10000.0000', $summary['difference']);
    }

    /** সঠিক জের দিলে ওই একই অবস্থাতেই মিলে যায়। */
    public function test_with_the_right_statement_balance_the_same_case_agrees(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);
        $this->move('2026-08-28', '8000', intoBank: true);
        $this->move('2026-08-29', '3000', intoBank: false);

        // ব্যাংক কেবল প্রথমটা দেখেছে, তাই কাগজে জের ৫০০০০ নয় — ৬০০০০
        $recon = $this->open('2026-08-31', '60000');

        $first = $this->recons->candidates($recon)
            ->first(fn (VoucherLine $l) => bccomp((string) $l->debit, '50000', 4) === 0);
        $this->recons->mark($recon, [$first->id]);

        $summary = $this->recons->summary($recon);

        $this->assertSame('0.0000', $summary['difference']);
        $this->assertTrue($summary['agrees']);
    }

    /**
     * অঙ্ক না মিললে বন্ধ করা আটকায়।
     *
     * এটাই পুরো ফিচারটার একমাত্র শক্ত দরজা। এটা না থাকলে যে কেউ একটা
     * মিলকরণ খুলে, কিছু না মিলিয়ে, "হয়ে গেছে" বলে বন্ধ করে দিতে
     * পারতেন — আর কাগজে দেখাত কাজটা হয়েছে।
     */
    public function test_a_reconciliation_that_does_not_agree_cannot_be_closed(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);

        $recon = $this->open('2026-08-31', '12345');

        $this->expectException(ValidationException::class);
        $this->recons->confirm($recon);
    }

    /**
     * কাগজের তারিখের পরের কোনো লাইন তালিকায় আসে না।
     *
     * এলে অঙ্কটা মিলে যেতে পারত, কিন্তু ভুলভাবে — কারণ ওই লেনদেনটা
     * ব্যাংকের ওই কাগজে থাকতেই পারে না।
     */
    public function test_a_line_after_the_statement_date_is_not_on_the_list(): void
    {
        $this->move('2026-08-20', '5000', intoBank: true);
        $this->move('2026-09-02', '9999', intoBank: true);

        $recon = $this->open('2026-08-31', '5000');

        $amounts = $this->recons->candidates($recon)
            ->map(fn (VoucherLine $l) => (string) $l->debit)->all();

        $this->assertContains('5000.0000', $amounts);
        $this->assertNotContains('9999.0000', $amounts,
            'কাগজের তারিখের পরের লেনদেন মিলকরণের তালিকায় এসেছে।');
    }

    /**
     * খসড়া ভাউচার তালিকায় আসে না।
     *
     * খসড়া খাতাতেই বসেনি, তাই ব্যাংকের কাগজেও থাকার কথা নয়। এলে
     * মিলকরণ এমন জিনিসে টিক দিত যা এখনো ঘটেইনি।
     */
    public function test_a_draft_voucher_is_not_on_the_list(): void
    {
        $vouchers = app(VoucherService::class);

        // পাশ করা হয়নি — ইচ্ছাকৃতভাবে post() ডাকা হয়নি
        $vouchers->create(
            ['type' => Voucher::CONTRA, 'trx_date' => '2026-08-10'],
            $vouchers->twoLineEntry(Voucher::CONTRA, $this->cash->id, $this->bank->id, '7777'),
        );

        $recon = $this->open('2026-08-31', '0');

        $amounts = $this->recons->candidates($recon)
            ->map(fn (VoucherLine $l) => (string) $l->debit)->all();

        $this->assertNotContains('7777.0000', $amounts,
            'খসড়া ভাউচারের লাইন মিলকরণের তালিকায় এসেছে।');
    }

    /**
     * বন্ধ করা মিলকরণের টিক আর নড়ে না।
     *
     * নাহলে গত মাসের টিক নীরবে উঠে যেত, আর গত মাসের বন্ধ করা অঙ্কটা
     * আজ আর মিলত না — অথচ কেউ টের পেত না, কারণ পুরনো মিলকরণ কেউ আর
     * খুলে দেখে না।
     */
    public function test_a_closed_reconciliation_does_not_let_its_ticks_move(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);

        $recon = $this->open('2026-08-31', '50000');
        $this->recons->mark($recon, $this->lineIds($recon));
        $this->recons->confirm($recon);

        $this->assertTrue($recon->isConfirmed());

        $this->expectException(ValidationException::class);
        $this->recons->mark($recon, []);
    }

    /** একই হিসাবের একই তারিখে দুইটা মিলকরণ বসে না। */
    public function test_the_same_account_and_date_cannot_be_reconciled_twice(): void
    {
        $this->open('2026-08-31', '1000');

        $this->expectException(QueryException::class);
        $this->open('2026-08-31', '2000');
    }

    /**
     * সবচেয়ে জরুরি পরীক্ষাটা — মিলকরণ খাতা বদলায় না।
     *
     * এটা না থাকলে কেউ একদিন "সুবিধার জন্য" একটা সমন্বয় এন্ট্রি যোগ
     * করে দিতেন, আর তখন তফাতটাই হারিয়ে যেত। যে যন্ত্র অমিলটাকে গিলে
     * ফেলে, সে অমিল ধরার যন্ত্র নয় — সে অমিল লুকানোর যন্ত্র।
     *
     * তাই এখানে খতিয়ানের সারি ও ব্যাংকের জের — দুইটাই আগে-পরে হুবহু
     * এক কি না দেখা হয়।
     */
    public function test_reconciling_never_touches_the_books(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);
        $this->move('2026-08-05', '20000', intoBank: false);

        $recon = $this->open('2026-08-31', '30000');

        $entriesBefore = LedgerEntry::query()->count();
        $balanceBefore = (string) (LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->sum(\DB::raw('debit - credit')) ?: '0');

        $this->recons->mark($recon, $this->lineIds($recon));
        $this->recons->confirm($recon);

        $entriesAfter = LedgerEntry::query()->count();
        $balanceAfter = (string) (LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->sum(\DB::raw('debit - credit')) ?: '0');

        $this->assertSame($entriesBefore, $entriesAfter,
            'মিলকরণ খতিয়ানে নতুন সারি বসিয়েছে — ওর কাজ কেবল চিহ্ন দেওয়া।');
        $this->assertSame($balanceBefore, $balanceAfter,
            'মিলকরণের পরে ব্যাংকের জের বদলে গেছে।');
    }

    /** টিক তোলাও কাজ করে — শুধু বসানো নয়। */
    public function test_a_tick_can_be_taken_back(): void
    {
        $this->move('2026-08-01', '50000', intoBank: true);

        $recon = $this->open('2026-08-31', '50000');
        $ids = $this->lineIds($recon);

        $this->recons->mark($recon, $ids);
        $this->assertTrue($this->recons->summary($recon)['agrees']);

        $this->recons->mark($recon, []);
        $this->assertFalse($this->recons->summary($recon)['agrees'],
            'টিক তোলার পরেও অঙ্কটা মিলে গেছে — অর্থাৎ তোলা কাজ করেনি।');
    }

    /** ব্যাংক নয় এমন হিসাবে মিলকরণ খোলা যায় না। */
    public function test_only_a_bank_account_can_be_reconciled(): void
    {
        $this->expectException(ValidationException::class);

        $this->recons->open([
            'bank_account_id' => $this->cash->id,
            'statement_date' => '2026-08-31',
            'statement_balance' => '0',
        ]);
    }
}
