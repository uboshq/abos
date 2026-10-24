<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SlipIsNotUsedTwice;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ একই খাত থেকে একই TrxID/রেফারেন্সে একবারই পরিশোধ — সরবরাহকারী যে-ই হোন (নিরীক্ষা ৮, fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে [[SlipIsNotUsedTwice]] পক্ষ ধরে দেখত: একই বিকাশ TrxID দুই সরবরাহকারীর নামে বসত — টাকা গেছে একবার, খাতায় দুবার। আর
 * কোনো ইউনিক ছিল না, তাই একই মুহূর্তে দুই হাতও পেরোত।
 *
 * দাবি:
 *   ১. একই খাত, একই নম্বর, অন্য সরবরাহকারী — থামে, নিজের বার্তায় (ছাঁটা-ছোট হাতেও একই নম্বর)
 *   ২. অন্য খাতে একই নম্বর — চলে (দুই ব্যাংকের একই `000123` আলাদা টাকা)
 *   ৩. বাতিলের পরে একই নম্বর আবার — চলে
 *   ৪. ডেটাবেসের শেষ পাহারা: পাহারা এড়িয়ে লেখা দ্বৈত ইউনিক ইনডেক্সে থামে, আর [[SlipIsNotUsedTwice::guarded()]] তা একই বার্তায় বলে
 *   ৫. পর্দার তাৎক্ষণিক উত্তর খাত ধরে — অন্য সরবরাহকারীর জন্যও "ব্যবহৃত"
 */
final class OneTrxIdPaysOnceFromOneAccountTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $one;

    private Supplier $two;

    private Account $bank;

    private Account $otherBank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        [$this->one, $this->two] = Supplier::query()->orderBy('id')->limit(2)->get()->all();
        $this->bank = $this->bank('-81', 'Slip bank');
        $this->otherBank = $this->bank('-82', 'Second slip bank');
    }

    public function test_the_ground_is_there(): void
    {
        $this->assertNotSame($this->one->id, $this->two->id, 'দৃশ্যটাই ঠিক নয় — দুইজন সরবরাহকারী নেই');
        $this->assertNotSame($this->bank->id, $this->otherBank->id, 'দৃশ্যটাই ঠিক নয় — দুইটা ব্যাংক খাত নেই');
    }

    /** ১ — একই খাত, অন্য সরবরাহকারী */
    public function test_the_same_trx_id_from_the_same_account_cannot_pay_another_supplier(): void
    {
        $this->pay($this->one, $this->bank, 'BK7XQ2LM9');

        try {
            $this->pay($this->two, $this->bank, ' bk7xq2lm9 ');
            $this->fail('⛔ একই খাতের একই TrxID অন্য সরবরাহকারীর নামে বসে গেল — টাকা একবার গেছে, খাতায় দুবার');
        } catch (ValidationException $e) {
            $said = $e->errors()['instrument_no'][0] ?? json_encode($e->errors());
        }

        $this->assertContains($said, $this->slipMessages('bk7xq2lm9'), '⛔ থামল, কিন্তু স্লিপের কারণে নয়: '.$said);
        $this->assertSame(1, Payment::query()->where('instrument_no', 'like', '%k7xq2lm9%')->count(), '⛔ থামা পরিশোধের সারি রয়ে গেল');
    }

    /** ২ — অন্য খাতে একই নম্বর চলে */
    public function test_the_same_number_on_another_account_is_another_payment(): void
    {
        $this->pay($this->one, $this->bank, '000123');
        $this->pay($this->two, $this->otherBank, '000123');

        $this->assertSame(2, Payment::query()->where('instrument_no', '000123')->count(), '⛔ দুই ব্যাংকের একই নম্বর আটকে গেল — বৈধ পরিশোধ থামত');
    }

    /** ৩ — বাতিলের পরে আবার */
    public function test_a_cancelled_payment_frees_its_number(): void
    {
        $first = $this->pay($this->one, $this->bank, 'TT-REUSE');
        // ⓘ বাতিলের নিয়ম এখানে মাপা নয় — কেবল অবস্থা; গড়া ঘর `live_ref` অবস্থা দেখে NULL হয়
        DB::table('pur_payments')->where('id', $first->id)->update(['status' => DocumentStatus::CANCELLED]);

        try {
            $this->pay($this->two, $this->bank, 'TT-REUSE');
        } catch (ValidationException $e) {
            $this->fail('⛔ বাতিল পরিশোধের নম্বর আবার লিখতে গিয়ে থামল — ভুল ঠিক করার স্বাভাবিক পথ বন্ধ: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(2, Payment::query()->where('instrument_no', 'TT-REUSE')->count(), '⛔ বাতিল পরিশোধের নম্বর আবার লেখা গেল না');
    }

    /** ৪ — ডেটাবেসের শেষ পাহারা */
    public function test_the_index_stops_a_duplicate_that_slipped_past_the_check(): void
    {
        $first = $this->pay($this->one, $this->bank, 'RACE-1');

        try {
            app(SlipIsNotUsedTwice::class)->guarded(PaymentService::LIVE_REF_INDEX, 'একই স্লিপ', function () use ($first) {
                // ⓘ দুই হাত একই মুহূর্তে পাহারা পেরোলে যা ঘটে — পাহারা ছাড়া সরাসরি দ্বিতীয় সারি
                $copy = $first->replicate(['live_ref']);
                $copy->document_no = 'SP-RACE-2';
                $copy->supplier_id = $this->two->id;
                $copy->save();
            });
            $this->fail('⛔ পাহারা এড়িয়ে একই খাতের একই TrxID-র দ্বিতীয় সারি ডেটাবেসে বসে গেল — ইউনিক ইনডেক্স নেই');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // ⓘ যেকোনো থামা ধরে তারপর মাপা — ডেটাবেসের কাঁচা ভুল হলে ৫০০ হত, সেটাও দাবির নিজের বার্তায় ধরা পড়ে
            $this->assertInstanceOf(ValidationException::class, $e, '⛔ ইনডেক্স থামাল, কিন্তু মানুষের বার্তায় নয় — পর্দায় ৫০০ হত: '.get_class($e));
            $this->assertSame('একই স্লিপ', $e->errors()['instrument_no'][0] ?? null, '⛔ ইনডেক্স থামাল, কিন্তু স্লিপের বার্তায় নয়');
        }

        $this->assertSame(1, Payment::query()->where('instrument_no', 'RACE-1')->count(), '⛔ দ্বৈত সারি রয়ে গেল');
    }

    /** ৫ — পর্দার উত্তর খাত ধরে */
    public function test_the_screens_answer_asks_by_account(): void
    {
        $this->pay($this->one, $this->bank, 'SCR-9');

        $answer = $this->getJson(route('purchase.payment.slip_check', [
            'supplier_id' => $this->two->id, 'instrument_no' => 'SCR-9', 'account_id' => $this->bank->id,
        ]))->assertOk()->json('state');
        $this->assertSame('used', $answer, '⛔ পর্দা অন্য সরবরাহকারীর জন্য "আগে ব্যবহার হয়নি ✓" বলে, অথচ জমা থামবে');

        $other = $this->getJson(route('purchase.payment.slip_check', [
            'supplier_id' => $this->two->id, 'instrument_no' => 'SCR-9', 'account_id' => $this->otherBank->id,
        ]))->assertOk()->json('state');
        $this->assertSame('free', $other, '⛔ অন্য খাতের একই নম্বরকেও পর্দা "ব্যবহৃত" বলে');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function pay(Supplier $supplier, Account $account, string $no): Payment
    {
        return app(PaymentService::class)->create([
            'supplier_id' => $supplier->id, 'account_id' => $account->id, 'trx_date' => now()->toDateString(),
            'amount' => '100', 'instrument' => 'transfer', 'instrument_no' => $no,
        ], []);
    }

    /** @return list<string> */
    private function slipMessages(string $no): array
    {
        return array_map(fn (string $l) => trans('purchase::validation.slip_used_twice', ['no' => $no], $l), ['bn', 'en']);
    }

    private function bank(string $suffix, string $name): Account
    {
        return app(AccountService::class)->create([
            'code' => StandardChart::BANK.$suffix,
            'name_en' => $name,
            'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
        ]);
    }
}
