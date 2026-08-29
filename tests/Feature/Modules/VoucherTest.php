<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ভাউচার — পাঁচ ধরন, এক পথ।
 *
 * সবচেয়ে গুরুত্বপূর্ণ পরীক্ষাগুলো দিক নিয়ে। DMS-এ "প্রতিটা কন্ট্রা উল্টো
 * দিকে বসত" — পাঁচটা আলাদা কন্ট্রোলারে পাঁচবার দিক লেখার ফল। এখানে
 * পাঁচটাই একটা পথে যায়, আর সেই পথটাই এখানে যাচাই করা হয়।
 */
class VoucherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private int $cash;

    private int $bank;

    private int $receivable;

    private int $rent;

    /** ব্যাংক লেনদেনের নম্বর যেন প্রতিবার আলাদা হয়। */
    private int $reference = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        $till = app(CashTillService::class)->ensurePrimaryTill();

        $this->cash = (int) $till->account_id;
        $this->receivable = (int) StandardChart::find(StandardChart::RECEIVABLE)->id;
        $this->rent = (int) Account::query()->where('code', '5202')->firstOrFail()->id;

        $this->bank = (int) Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-TEST',
            'name_en' => 'Test Bank',
            'name_bn' => 'পরীক্ষা ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ])->id;
    }

    private function service(): VoucherService
    {
        return app(VoucherService::class);
    }

    /**
     * ব্যাংক ছুঁলে লেনদেনের নম্বরটা এখানেই বসে।
     *
     * নম্বরটা ছাড়া পোস্ট হয় না — সেটা নিজের পরীক্ষায় প্রমাণিত
     * (`test_bank_money_cannot_be_posted_without_a_transaction_number`)।
     * তাই এখানে বসানোটা নিয়মটাকে আড়াল করছে না, শুধু বাকি পরীক্ষাগুলোকে
     * তাদের নিজের প্রশ্নে থাকতে দিচ্ছে। প্রতিটা ডাকে নতুন নম্বর, কারণ
     * একই নম্বর দুইবার বসানোও আটকানো।
     */
    private function simple(string $type, int $from, int $to, string $amount = '1000.00'): Voucher
    {
        $svc = $this->service();

        $touchesBank = in_array($this->bank, [$from, $to], true);

        return $svc->create(
            [
                'type' => $type,
                'trx_date' => '2026-08-10',
                'narration' => 'test',
                'instrument_no' => $touchesBank ? 'TRX'.++$this->reference : null,
            ],
            $svc->twoLineEntry($type, $from, $to, $amount, 'test'),
        );
    }

    private function balance(int $accountId): string
    {
        return Account::query()->findOrFail($accountId)->balanceOn();
    }

    /**
     * এই ভাউচারটার খতিয়ানের সারি — সবার নয়।
     *
     * "খতিয়ানে একটাও সারি নেই" আগে সত্যি ছিল, এখন নয়: সিডার খোলা মজুদের
     * দাখিলা বসায়। খসড়া কিছু বসায়নি — এটা যাচাই করার প্রশ্নটা সবসময়ই
     * "এই কাগজটা কী বসাল", "মোট কয়টা সারি আছে" নয়।
     */
    private function entriesFor(Voucher $voucher): Builder
    {
        return LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id);
    }

    // ── দিক — সবচেয়ে জরুরি অংশ ────────────────────────────────────────

    /**
     * চারটা ধরনেই টাকা সঠিক দিকে যায়।
     *
     * প্রতিটার জন্য আলাদা পরীক্ষা লেখার বদলে একটাই, কারণ নিয়মটাও একটাই:
     * "to" ডেবিট, "from" ক্রেডিট। একটা ধরনে ভুল হলে বাকি চারটাতেও হত —
     * আর ঠিক সেটাই DMS-এ ঘটেছিল, উল্টো দিক থেকে।
     */
    #[DataProvider('directions')]
    public function test_money_moves_in_the_right_direction(string $type, string $from, string $to): void
    {
        $fromId = $this->{$from};
        $toId = $this->{$to};

        // দুই খাতেই শুরুতে যথেষ্ট টাকা, যাতে চিহ্ন দেখে বিভ্রান্তি না হয়
        $this->seedOpening($fromId, '50000');

        $before = [$this->balance($fromId), $this->balance($toId)];

        $this->service()->post($this->simple($type, $fromId, $toId, '1000.00'));

        $after = [$this->balance($fromId), $this->balance($toId)];

        // যেখান থেকে এল সেটা কমল, যেখানে গেল সেটা বাড়ল — দুই দিকেই
        // ১০০০, ঠিক ততটাই
        $this->assertSame('-1000.0000', bcsub($after[0], $before[0], 4), "{$type}: from-side moved wrong");
        $this->assertSame('1000.0000', bcsub($after[1], $before[1], 4), "{$type}: to-side moved wrong");
    }

    public static function directions(): array
    {
        return [
            // আদায় — গ্রাহকের পাওনা কমে, ক্যাশ বাড়ে
            'receipt' => ['receipt', 'receivable', 'cash'],
            // পরিশোধ — ক্যাশ কমে, খরচের খাত বাড়ে
            'payment' => ['payment', 'cash', 'rent'],
            'expense' => ['expense', 'cash', 'rent'],
            // কন্ট্রা — ক্যাশ কমে, ব্যাংক বাড়ে। DMS-এ এটাই উল্টো ছিল।
            'contra' => ['contra', 'cash', 'bank'],
        ];
    }

    public function test_a_deposit_into_the_bank_leaves_cash_and_reaches_the_bank(): void
    {
        $this->seedOpening($this->cash, '20000');

        $this->service()->post($this->simple('contra', $this->cash, $this->bank, '7500.00'));

        // দুইটাই সম্পদ, দুইটাই ডেবিট প্রকৃতির — তাই উল্টো লিখলে যোগফল
        // তবু মিলত, আর ভুলটা ট্রায়াল ব্যালেন্সে ধরা পড়ত না। শুধু
        // ব্যালেন্স দুইটা দেখলেই বোঝা যায়।
        $this->assertSame('12500.0000', $this->balance($this->cash));
        $this->assertSame('7500.0000', $this->balance($this->bank));
    }

    public function test_the_same_account_cannot_be_on_both_sides(): void
    {
        $this->expectException(ValidationException::class);

        $this->simple('contra', $this->cash, $this->cash);
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        foreach (['0', '-500'] as $amount) {
            try {
                $this->simple('receipt', $this->receivable, $this->cash, $amount);
                $this->fail("{$amount} টাকার ভাউচার তৈরি হয়ে গেল।");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    // ── খসড়া ও পোস্ট ───────────────────────────────────────────────────

    public function test_a_draft_touches_no_ledger(): void
    {
        $voucher = $this->simple('receipt', $this->receivable, $this->cash);

        $this->assertTrue($voucher->isDraft());
        $this->assertSame(0, $this->entriesFor($voucher)->count());
        $this->assertSame('0.0000', $this->balance($this->cash));
    }

    public function test_posting_writes_exactly_the_lines_that_were_saved(): void
    {
        $voucher = $this->service()->post($this->simple('receipt', $this->receivable, $this->cash, '2500.00'));

        $entries = LedgerEntry::query()
            ->where('source_type', 'receipt_voucher')
            ->where('source_id', $voucher->id)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame($voucher->document_no, $entries->first()->document_no);
        $this->assertEqualsWithDelta(2500, (float) $entries->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(2500, (float) $entries->sum('credit'), 0.001);
    }

    public function test_a_voucher_cannot_be_posted_twice(): void
    {
        $voucher = $this->service()->post($this->simple('receipt', $this->receivable, $this->cash));

        $this->expectException(ValidationException::class);

        $this->service()->post($voucher);
    }

    public function test_a_posted_voucher_cannot_be_edited(): void
    {
        $voucher = $this->service()->post($this->simple('receipt', $this->receivable, $this->cash));

        $this->expectException(ValidationException::class);

        // সংশোধনের পথ বাতিল করে নতুন ভাউচার — কাগজে-কলমেও তাই
        $this->service()->update($voucher, ['trx_date' => '2026-08-11'], []);
    }

    public function test_posting_refuses_a_group_account(): void
    {
        $group = StandardChart::find(StandardChart::CASH_IN_HAND);

        $voucher = $this->service()->create(
            ['type' => 'journal', 'trx_date' => '2026-08-10'],
            [
                ['account_id' => $group->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => $this->rent, 'debit' => '0', 'credit' => '100'],
            ],
        );

        $this->expectException(ValidationException::class);

        $this->service()->post($voucher);
    }

    public function test_posting_refuses_an_unbalanced_journal(): void
    {
        $voucher = $this->service()->create(
            ['type' => 'journal', 'trx_date' => '2026-08-10'],
            [
                ['account_id' => $this->rent, 'debit' => '500', 'credit' => '0'],
                ['account_id' => $this->cash, 'debit' => '0', 'credit' => '400'],
            ],
        );

        $this->expectException(ValidationException::class);

        $this->service()->post($voucher);
    }

    // ── বাতিল (নিয়ম ৫) ────────────────────────────────────────────────

    public function test_cancelling_reverses_the_ledger_and_keeps_the_record(): void
    {
        $this->seedOpening($this->cash, '20000');

        $voucher = $this->service()->post($this->simple('expense', $this->cash, $this->rent, '3000.00'));

        $this->assertSame('17000.0000', $this->balance($this->cash));

        $this->service()->cancel($voucher, 'ভুল খাতে লেখা হয়েছিল');

        // ব্যালেন্স ফিরে এল
        $this->assertSame('20000.0000', $this->balance($this->cash));

        // কিন্তু মূল এন্ট্রিগুলো মুছে যায়নি — দুইটা মূল, আর তার পাশে
        // দুইটা বিপরীত (":reversal" উপসর্গে, যাতে একই ডকুমেন্ট দুইবার
        // পোস্ট হয়েছে বলে ভুল না হয়)। মুছে দিলে ছাপা কাগজের নম্বরটা
        // কোনো রেকর্ডের সাথে মিলত না।
        $this->assertSame(2, LedgerEntry::query()
            ->where('source_type', 'expense_voucher')
            ->where('source_id', $voucher->id)
            ->count());

        $this->assertSame(2, LedgerEntry::query()
            ->where('source_type', 'expense_voucher:reversal')
            ->where('source_id', $voucher->id)
            ->count());

        // আর বিপরীত সারিটাও তার মূল ভাউচারে ফেরত যেতে পারে — নিয়ম ১।
        // উপসর্গটা কোনো মডিউল ঘোষণা করে না, তাই DrillResolver সেটা
        // ছেঁটে নেয়; নাহলে ডে বুকে উল্টো এন্ট্রিগুলো ক্লিক করা যেত না।
        $reversal = LedgerEntry::query()
            ->where('source_type', 'expense_voucher:reversal')
            ->firstOrFail();

        $described = $reversal->drill();

        $this->assertTrue($described['resolved']);
        $this->assertSame($voucher->document_no, $described['document_no']);

        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status);
        $this->assertSame('ভুল খাতে লেখা হয়েছিল', $voucher->fresh()->cancel_reason);
    }

    public function test_cancelling_a_draft_needs_no_reversal(): void
    {
        $voucher = $this->simple('receipt', $this->receivable, $this->cash);

        $this->service()->cancel($voucher, 'আর দরকার নেই');

        $this->assertSame(DocumentStatus::CANCELLED, $voucher->fresh()->status);
        $this->assertSame(0, $this->entriesFor($voucher)->count());
    }

    public function test_cancelling_without_a_reason_is_refused(): void
    {
        $voucher = $this->simple('receipt', $this->receivable, $this->cash);

        $this->expectException(ValidationException::class);

        $this->service()->cancel($voucher, '   ');
    }

    // ── নম্বর সিরিজ ─────────────────────────────────────────────────────

    public function test_every_declared_document_type_has_a_number_series(): void
    {
        // খরচ ভাউচার ঘোষিত ছিল অথচ সিরিজ ছিল না, তাই প্রথম খরচ
        // ভাউচারটা লিখতে গিয়েই আটকে গিয়েছিল
        $this->assertSame([], app(NumberSeriesProvisioner::class)->missing());
    }

    public function test_each_type_draws_from_its_own_series(): void
    {
        $receipt = $this->simple('receipt', $this->receivable, $this->cash);
        $payment = $this->simple('payment', $this->cash, $this->rent);

        $this->assertStringStartsWith('RCV-', $receipt->document_no);
        $this->assertStringStartsWith('PAY-', $payment->document_no);
    }

    // ── স্ক্রিন ─────────────────────────────────────────────────────────

    public function test_saving_from_the_screen_posts_unless_draft_is_asked_for(): void
    {
        $this->post(route('accounts.voucher.store', 'receipt'), [
            'type' => 'receipt',
            'trx_date' => '2026-08-10',
            'from_account_id' => $this->receivable,
            'to_account_id' => $this->cash,
            'amount' => '1500.00',
            'narration' => 'screen test',
        ])->assertRedirect();

        $this->assertTrue(Voucher::query()->latest('id')->first()->isPosted());

        $this->post(route('accounts.voucher.store', 'receipt'), [
            'type' => 'receipt',
            'trx_date' => '2026-08-10',
            'from_account_id' => $this->receivable,
            'to_account_id' => $this->cash,
            'amount' => '900.00',
            'save_as_draft' => '1',
        ])->assertRedirect();

        $this->assertTrue(Voucher::query()->latest('id')->first()->isDraft());
    }

    public function test_the_journal_screen_refuses_an_unbalanced_form(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => 'journal',
            'trx_date' => '2026-08-10',
            'lines' => [
                ['account_id' => $this->rent, 'debit' => '500', 'credit' => ''],
                ['account_id' => $this->cash, 'debit' => '', 'credit' => '400'],
            ],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_an_unknown_voucher_type_is_a_404_not_a_crash(): void
    {
        $this->get(route('accounts.voucher.index', 'nonsense'))->assertNotFound();
    }

    public function test_the_five_menu_rows_all_lead_somewhere(): void
    {
        foreach (Voucher::TYPES as $type) {
            $this->get(route('accounts.voucher.index', $type))->assertOk();
            $this->get(route('accounts.voucher.create', $type))->assertOk();
        }
    }

    public function test_a_user_without_create_permission_cannot_write_a_voucher(): void
    {
        $reader = User::factory()->create();
        $reader->companies()->attach($this->company, ['is_active' => true]);
        $reader->forceFill(['current_company_id' => $this->company->id])->save();
        $reader->givePermissionTo(Permission::findOrCreate('accounts.report', 'web'));

        $this->actingAs($reader)->get(route('accounts.voucher.index', 'receipt'))->assertOk();
        $this->actingAs($reader)->get(route('accounts.voucher.create', 'receipt'))->assertForbidden();
    }

    // ── টেন্যান্ট ───────────────────────────────────────────────────────

    public function test_one_company_never_sees_another_companys_vouchers(): void
    {
        $this->simple('receipt', $this->receivable, $this->cash);

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $this->assertSame(0, Voucher::query()->count());
    }

    /** খোলা ব্যালেন্স বসানো — চিহ্ন দেখে বিভ্রান্তি এড়াতে। */
    private function seedOpening(int $accountId, string $amount): void
    {
        Account::query()->whereKey($accountId)->update([
            'opening_balance' => $amount,
            'opening_date' => '2026-07-01',
        ]);
    }
}
