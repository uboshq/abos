<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই ব্যাংক লেনদেন খাতায় একবারই ওঠে।
 *
 * ── যেটা আটকানো হচ্ছে ───────────────────────────────────────────────
 * হিসাবরক্ষক বিকাশের ৳৫০,০০০ পরিশোধ তুললেন। ম্যানেজার পরে দেখলেন
 * "এটা তো তোলা হয়নি" — তিনিও তুললেন। দুইটা ভাউচার, একই TrxID, আর
 * খাতায় ৳১,০০,০০০ গেল। মাস শেষে ব্যাংক মেলাতে গিয়ে পার্থক্যটা ধরা
 * পড়ত, কিন্তু ততদিনে হিসাব বন্ধ।
 *
 * ── কেন নম্বরটা লেজারে বসানোর সময় চাওয়া হয় ─────────────────────────
 * লেখার মুহূর্তে TrxID জন্মায়ইনি। তখন বাধ্যতামূলক করলে মানুষ `0`
 * বসাতেন — আর বানানো নম্বর কোনো নম্বর না থাকার চেয়ে খারাপ, কারণ
 * ব্যাংক মেলানোর সময় ওটা দেখে সবাই ভাবে মিলে গেছে।
 */
class BankMoneyIsBookedOnceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private int $cash;

    private int $bank;

    private int $otherBank;

    private int $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        $this->cash = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
        $this->rent = (int) Account::query()->where('code', '5202')->firstOrFail()->id;

        $this->bank = $this->makeBank('1102-BKASH', 'bKash Merchant', 'বিকাশ মার্চেন্ট');
        $this->otherBank = $this->makeBank('1102-CITY', 'City Bank', 'সিটি ব্যাংক');
    }

    private function makeBank(string $code, string $en, string $bn): int
    {
        return (int) Account::query()->create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name_en' => $en,
            'name_bn' => $bn,
            'parent_id' => StandardChart::find(StandardChart::BANK_AND_MFS)->id,
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

    /** খসড়া — লেখার সময় নম্বর দেওয়া যায়, না দেওয়াও যায়। */
    private function draft(int $from, int $to, ?string $reference = null, string $amount = '5000.00'): Voucher
    {
        $svc = $this->service();

        return $svc->create(
            [
                'type' => Voucher::PAYMENT,
                'trx_date' => '2026-08-10',
                'narration' => 'test',
                'instrument_no' => $reference,
            ],
            $svc->twoLineEntry(Voucher::PAYMENT, $from, $to, $amount, 'test'),
        );
    }

    // ── নম্বরটা লাগে ────────────────────────────────────────────────────

    public function test_bank_money_cannot_be_posted_without_a_transaction_number(): void
    {
        $voucher = $this->draft($this->bank, $this->rent);

        try {
            $this->service()->post($voucher);
            $this->fail('ব্যাংকের টাকা লেনদেন নম্বর ছাড়াই বসে গেল।');
        } catch (ValidationException $e) {
            // বার্তাটা কোন খাতের কথা বলছে সেটা থাকতে হবে — একাধিক
            // ব্যাংক থাকলে "নম্বর লাগবে" শুনে কেউ বুঝত না কোনটার।
            // কোড ধরে দেখা হয়, নাম ধরে নয়: নাম ভাষার সাথে বদলায়।
            $this->assertStringContainsString(
                '1102-BKASH',
                implode(' ', $e->errors()['instrument_no'] ?? []),
            );
        }

        $this->assertTrue($voucher->fresh()->isDraft(), 'ভাউচারটা খসড়াই থাকা উচিত ছিল।');
    }

    public function test_a_blank_number_counts_as_no_number(): void
    {
        $this->expectException(ValidationException::class);

        // ফাঁকা জায়গা দিয়ে পাহারা পেরোনো যায় না
        $this->service()->post($this->draft($this->bank, $this->rent, '   '));
    }

    public function test_cash_needs_no_transaction_number(): void
    {
        // নগদের কোনো TrxID নেই। চাইলে প্রতিটা নগদ ভাউচারে একটা বানানো
        // নম্বর বসত — আর সেটাই মেলানোর কাজটা নষ্ট করত।
        $posted = $this->service()->post($this->draft($this->cash, $this->rent));

        $this->assertTrue($posted->isPosted());
        $this->assertNull($posted->money_account_id);
    }

    public function test_many_cash_vouchers_post_freely(): void
    {
        // ইউনিক ইনডেক্সটা `(কোম্পানি, খাত, নম্বর)` — তিনটাই NULL হলে
        // MySQL-এ কোনো সংঘাত হয় না। এই পরীক্ষাটা সেটাই ধরে রাখে,
        // কারণ ওখানে ভুল হলে গোটা নগদ পরিশোধই থেমে যেত।
        foreach (range(1, 3) as $ignored) {
            $this->assertTrue($this->service()->post($this->draft($this->cash, $this->rent))->isPosted());
        }
    }

    // ── একই নম্বর দুইবার নয় ─────────────────────────────────────────────

    public function test_the_same_transaction_number_cannot_be_booked_twice(): void
    {
        $first = $this->service()->post($this->draft($this->bank, $this->rent, 'CDA7XY9K21'));

        $this->assertSame($this->bank, (int) $first->money_account_id);

        $second = $this->draft($this->bank, $this->rent, 'CDA7XY9K21');

        try {
            $this->service()->post($second);
            $this->fail('একই TrxID দিয়ে দ্বিতীয়বার খাতায় উঠে গেল।');
        } catch (ValidationException $e) {
            // কোন ভাউচারে আগে বসেছে সেটা বলা না থাকলে ব্যবহারকারী
            // খুঁজে দেখতে পারতেন না যে টাকাটা সত্যিই আগে গেছে কি না
            $this->assertStringContainsString(
                $first->document_no,
                implode(' ', $e->errors()['instrument_no'] ?? []),
            );
        }

        $this->assertTrue($second->fresh()->isDraft());
    }

    public function test_the_same_number_at_two_different_banks_is_two_different_transactions(): void
    {
        // ব্যাংকের রেফারেন্স নম্বর ছোট ও পুনরাবৃত্ত — `001234` দুই
        // ব্যাংকেই থাকতে পারে। কেবল নম্বর ধরে আটকালে বৈধ এন্ট্রি আটকে
        // যেত, আর মানুষ নম্বরের শেষে অক্ষর জুড়ে কাজ চালাত।
        $this->service()->post($this->draft($this->bank, $this->rent, '001234'));

        $second = $this->service()->post($this->draft($this->otherBank, $this->rent, '001234'));

        $this->assertTrue($second->isPosted());
        $this->assertSame($this->otherBank, (int) $second->money_account_id);
    }

    public function test_cancelling_frees_the_number_for_a_corrected_entry(): void
    {
        // ভুল অঙ্কে তোলা হয়েছিল — বাতিল করে একই TrxID নিয়ে সঠিকটা
        // তোলা রোজকার কাজ। ধরে রাখলে ওই টাকাটা আর কখনো খাতায় উঠত না।
        $wrong = $this->service()->post($this->draft($this->bank, $this->rent, 'TRX55', '500.00'));
        $this->service()->cancel($wrong, 'ভুল অঙ্ক');

        $right = $this->service()->post($this->draft($this->bank, $this->rent, 'TRX55', '5000.00'));

        $this->assertTrue($right->isPosted());
        $this->assertNull($wrong->fresh()->money_account_id);
        // নম্বরটা রেকর্ডে থেকেই যায় — শুধু জোড়াটা ছাড়া পায়
        $this->assertSame('TRX55', $wrong->fresh()->instrument_no);
    }

    public function test_the_database_itself_refuses_the_pair(): void
    {
        // সার্ভিসের যাচাই বন্ধুসুলভ বার্তার জন্য; আসল পাহারা ইনডেক্স।
        // দুইজন একসাথে "পোস্ট" চাপলে যাচাই দুইটাই পাশ করতে পারত।
        $this->service()->post($this->draft($this->bank, $this->rent, 'RACE1'));

        $this->expectException(QueryException::class);

        DB::table('vouchers')->insert([
            'company_id' => $this->company->id,
            'type' => Voucher::PAYMENT,
            'document_no' => 'PAY-RACE',
            'trx_date' => '2026-08-10',
            'amount' => '100.0000',
            'status' => DocumentStatus::CONFIRMED,
            'money_account_id' => $this->bank,
            'instrument_no' => 'RACE1',
            'public_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── পর্দা ───────────────────────────────────────────────────────────

    public function test_the_screen_asks_for_the_number_at_posting_time(): void
    {
        $voucher = $this->draft($this->bank, $this->rent);

        // খসড়ার পর্দায় ঘরটা থাকে…
        $this->get(route('accounts.voucher.show', $voucher))
            ->assertOk()
            ->assertSee('name="instrument_no"', false);

        // …আর সেখান থেকে দিলেই বসে যায়
        $this->post(route('accounts.voucher.post', $voucher), ['instrument_no' => 'SCREEN01'])
            ->assertRedirect();

        $voucher->refresh();

        $this->assertTrue($voucher->isPosted());
        $this->assertSame('SCREEN01', $voucher->instrument_no);
        $this->assertSame($this->bank, (int) $voucher->money_account_id);
    }

    public function test_a_cash_voucher_screen_does_not_ask(): void
    {
        // নগদে ঘরটা দেখালে ব্যবহারকারী ভাবতেন কিছু একটা লিখতে হবে
        $this->get(route('accounts.voucher.show', $this->draft($this->cash, $this->rent)))
            ->assertOk()
            ->assertDontSee('name="instrument_no"', false);
    }
}
