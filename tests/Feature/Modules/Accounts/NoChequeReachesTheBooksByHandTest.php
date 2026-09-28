<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
 * ১১০৪ "হাতে চেক" হাতের ভাউচারে ভরে না — ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ ফাঁকটা ─────────────────────────────────────────────────────────
 * [[VoucherService::assertNoChequeReceived()]] কেবল আদায়ের "চেক" মাধ্যম
 * দেখত। জাবেদায় সরাসরি Dr ১১০৪ লিখলে, বা অন্য মাধ্যম বেছে আদায়ে ১১০৪-এ
 * টাকা নিলে খাতায় "হাতে চেক" বাড়ত — অথচ চেকের রেজিস্টারে কোনো সারি
 * নেই, তাই জমা, পাশ বা ফেরতের কোনো বোতাম ওই টাকা কোনোদিন ছুঁতে পারত না।
 *
 * ── ⭐ নিয়ম ([[VoucherService::assertNoChequeInHandByHand()]]) ─────────
 * ১১০৪-এ ডেবিট ঢোকে কেবল দুই পথে:
 *   • চেকের রেজিস্টার ([[ChequeService]]) — PostingEngine দিয়ে, এই দরজা নয়;
 *   • কাউন্টার (`origin` = counter) — পোস্টের পরেই চেকটা রেজিস্টারে ওঠে
 *     ([[DirectSaleService::postCounterVoucher()]])।
 *
 * ⓘ ক্রেডিটের দিক ইচ্ছাকৃতভাবে খোলা — আদেশ ছিল কেবল ডেবিটের
 * ([[test_crediting_cheques_in_hand_is_not_this_guards_business]])।
 */
final class NoChequeReachesTheBooksByHandTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $chequesInHand;

    private Account $capital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->chequesInHand = StandardChart::find(StandardChart::CHEQUES_IN_HAND);
        $this->capital = StandardChart::find(StandardChart::OWNER_CAPITAL);

        // ⚠️ প্রস্তুতি নিজেই যেন মিথ্যা না বলে — খাত দুইটা পোস্টযোগ্য আর সক্রিয়
        foreach ([$this->chequesInHand, $this->capital] as $account) {
            $this->assertNotNull($account, 'ছকে খাতটা নেই — প্রস্তুতিই ভুল।');
            $this->assertFalse((bool) $account->is_group, "{$account->code} গ্রুপ খাত — অন্য পাহারা আগে উত্তর দিত।");
            $this->assertTrue((bool) $account->is_active, "{$account->code} নিষ্ক্রিয় — অন্য পাহারা আগে উত্তর দিত।");
        }
    }

    // ── ১ · জাবেদায় Dr ১১০৪ ────────────────────────────────────────────

    /**
     * ⛔ জাবেদায় Dr ১১০৪ / Cr ৩১০০ — ফেরে, খসড়াই থাকে, খাতায় একটা সারিও নয়।
     */
    public function test_a_journal_that_debits_cheques_in_hand_is_refused(): void
    {
        $voucher = $this->journalIntoChequesInHand(origin: null);

        $this->assertRefusedByTheRegisterRule($voucher, 'জাবেদায় হাতে Dr ১১০৪ পোস্ট হয়ে গেছে');
        $this->assertStillADraftWithNothingInTheBooks($voucher);
    }

    // ── ২ · আদায়ে ১১০৪, মাধ্যম "চেক" নয় ─────────────────────────────────

    /**
     * ⛔ আদায়ের "টাকার খাত" ১১০৪, মাধ্যম ব্যাংক-স্থানান্তর — ফেরে।
     *
     * ⓘ মাধ্যম "চেক" হলে [[assertNoChequeReceived()]] তৈরির সময়েই আটকাত
     * (`instrument` ঘরে) — তাই এখানে চেক ছাড়া অন্য মাধ্যম, আর ভাউচার সরাসরি
     * সেবা দিয়ে, যাতে উত্তরটা পোস্টের এই পাহারাই দেয় (`lines` ঘরে)।
     * ⚠️ ১১০৪-এর `money_kind` নেই, তাই মাধ্যম-খাত মেলানোর পাহারা এখানে
     * চুপ থাকত — ঠিক এই ফাঁকটাই বন্ধ হয়েছে।
     */
    public function test_a_receipt_into_cheques_in_hand_without_the_cheque_way_is_refused(): void
    {
        foreach (['transfer', 'cash', 'card'] as $way) {
            $voucher = $this->receiptIntoChequesInHand(origin: null, instrument: $way);

            $this->assertSame($way, $voucher->instrument, 'প্রস্তুতিই ভুল — মাধ্যম বসেনি।');

            $this->assertRefusedByTheRegisterRule($voucher,
                "মাধ্যম '{$way}' বেছে আদায়ে হাতে ১১০৪-এ টাকা নেওয়া হয়েছে");
            $this->assertStillADraftWithNothingInTheBooks($voucher);
        }
    }

    // ── ৩ · কাউন্টারের পথ খোলা ─────────────────────────────────────────

    /**
     * ✅ কাউন্টারের রসিদ (Dr ১১০৪ / Cr ১১১০) পোস্ট হয় — পাল্টা দাবি।
     *
     * ⓘ ভাউচারটা বানানো [[DirectSaleService::counterVoucher()]]-এর মতো:
     * রসিদ, পক্ষ গ্রাহক, মাধ্যমে পেমেন্ট-পদ্ধতির কোড (`CHQ`), `origin` =
     * counter। ⚠️ রেজিস্টারের সারিটা `postCounterVoucher()` বসায় (private) —
     * এখানে মাপা হয় কেবল এই দরজাটা খোলা কি না।
     */
    public function test_a_counter_receipt_into_cheques_in_hand_still_posts(): void
    {
        $voucher = $this->receiptIntoChequesInHand(origin: Voucher::ORIGIN_COUNTER, instrument: 'CHQ');

        $posted = app(VoucherService::class)->post($voucher);

        $this->assertSame(DocumentStatus::CONFIRMED, $posted->status, '⛔ কাউন্টারের চেক-রসিদ পোস্ট হয়নি — কাউন্টারের পথ বন্ধ হয়ে গেছে।');
        $this->assertSame(0, bccomp($this->debitsInto($posted), '7500', 4),
            'কাউন্টারের রসিদ পোস্ট হয়েছে, কিন্তু ১১০৪-এ ৭,৫০০ ডেবিট বসেনি।');
    }

    // ── ৪ · ক্রেডিটের দিক এই পাহারার নয় ──────────────────────────────────

    /**
     * ⓘ জাবেদায় Cr ১১০৪ — এই পাহারা আটকায় না। ইচ্ছাকৃত: আদেশ ছিল কেবল
     * ডেবিটের দিকের (হাতে চেক **বাড়ানো**)। ⚠️ হাতে ১১০৪ কমানোও রেজিস্টারের
     * সাথে অমিল ঘটায় — সেটা বন্ধ করা হবে কি না মালিকের সিদ্ধান্ত; এই দাবি
     * কেবল আজকের সীমানা লিখে রাখে, যাতে কেউ ভুল করে ধরে না নেন যে দুই দিকই
     * বন্ধ।
     */
    public function test_crediting_cheques_in_hand_is_not_this_guards_business(): void
    {
        $voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'NO-CHQ-BY-HAND-CR',
        ], [
            ['account_id' => $this->capital->id, 'debit' => '3000', 'credit' => '0'],
            ['account_id' => $this->chequesInHand->id, 'debit' => '0', 'credit' => '3000'],
        ]);

        $posted = app(VoucherService::class)->post($voucher);

        $this->assertSame(DocumentStatus::CONFIRMED, $posted->status,
            'Cr ১১০৪-এর জাবেদা ফিরেছে — পাহারাটা ক্রেডিটেও লেগেছে, যা আদেশে ছিল না।');
    }

    // ── ৫ · এক মানুষ, এক আকার, কেবল উৎস আলাদা ─────────────────────────

    /**
     * ⭐ একই মালিক, একই জাবেদা (Dr ১১০৪ / Cr ৩১০০, একই অঙ্ক) — কেবল `origin`
     * আলাদা: counter → পোস্ট, null → ফেরে।
     *
     * ⛔ দুই আলাদা মানুষ বা দুই আলাদা আকারে ফেরানোটা অনুমতি বা অন্য পাহারার
     * কারণেও হতে পারত; এখানে ফারাক কেবল একটা চাবির।
     */
    public function test_only_the_origin_decides_one_actor_one_shape(): void
    {
        $counter = $this->journalIntoChequesInHand(origin: Voucher::ORIGIN_COUNTER);
        $byHand = $this->journalIntoChequesInHand(origin: null);

        $this->assertSame($this->shapeOf($counter), $this->shapeOf($byHand), 'প্রস্তুতিই ভুল — দুই ভাউচারের আকার আলাদা।');

        $posted = app(VoucherService::class)->post($counter);
        $this->assertSame(DocumentStatus::CONFIRMED, $posted->status, '⛔ counter উৎসের একই জাবেদা পোস্ট হয়নি।');

        $this->assertRefusedByTheRegisterRule($byHand, 'একই মানুষের একই জাবেদা, উৎস ছাড়া, পোস্ট হয়ে গেছে');
        $this->assertStillADraftWithNothingInTheBooks($byHand);
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function journalIntoChequesInHand(?string $origin): Voucher
    {
        return app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'NO-CHQ-BY-HAND',
            'origin' => $origin,
        ], [
            ['account_id' => $this->chequesInHand->id, 'debit' => '5000', 'credit' => '0'],
            ['account_id' => $this->capital->id, 'debit' => '0', 'credit' => '5000'],
        ]);
    }

    private function receiptIntoChequesInHand(?string $origin, string $instrument): Voucher
    {
        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $receivable = StandardChart::find(StandardChart::RECEIVABLE);

        return app(VoucherService::class)->create([
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'party_type' => 'customer',
            'party_id' => $dealer->id,
            'instrument' => $instrument,
            'instrument_no' => 'CHQ'.random_int(100000, 999999),
            'instrument_date' => now()->toDateString(),
            'from_bank' => 'Sonali Bank',
            'narration' => 'NO-CHQ-BY-HAND-RCV',
            'origin' => $origin,
        ], [
            ['account_id' => $this->chequesInHand->id, 'debit' => '7500', 'credit' => '0'],
            ['account_id' => $receivable->id, 'debit' => '0', 'credit' => '7500'],
        ]);
    }

    private function assertRefusedByTheRegisterRule(Voucher $voucher, string $case): void
    {
        try {
            app(VoucherService::class)->post($voucher);
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('accounts::validation.cheque_only_through_register')],
                $e->errors()['lines'] ?? [],
                "{$case} — ফেরানো হয়েছে, কিন্তু অন্য কারণে: ".json_encode($e->errors(), JSON_UNESCAPED_UNICODE),
            );

            return;
        }

        $this->fail("⛔ {$case}।");
    }

    private function assertStillADraftWithNothingInTheBooks(Voucher $voucher): void
    {
        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status, 'ফেরানো ভাউচার আর খসড়া নেই।');
        $this->assertSame(0, LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->count(), '⛔ ফেরানো ভাউচার খাতায় দাখিলা রেখে গেছে।');
    }

    private function debitsInto(Voucher $voucher): string
    {
        return (string) LedgerEntry::query()
            ->where('source_type', Voucher::SOURCE_TYPES[$voucher->type])
            ->where('source_id', $voucher->id)
            ->where('account_id', $this->chequesInHand->id)
            ->sum('debit');
    }

    /** @return list<array{int, string, string}> */
    private function shapeOf(Voucher $voucher): array
    {
        return $voucher->fresh(['lines'])->lines
            ->map(fn ($l) => [(int) $l->account_id, bcadd((string) $l->debit, '0', 4), bcadd((string) $l->credit, '0', 4)])
            ->values()->all();
    }
}
