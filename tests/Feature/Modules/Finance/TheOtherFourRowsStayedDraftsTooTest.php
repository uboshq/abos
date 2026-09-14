<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Contracts\SettledByAVoucher;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\Withdrawal;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * বাকি চারটা সারিও খসড়াই থেকে যেত — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী মেপে পাওয়া গেল ─────────────────────────────────────────────
 * [[CapitalEntry]]-তে নিষ্পত্তির শিকলটা বসানোর পর অর্থের বাকি চারটা
 * নথি গুনে দেখা হলো:
 *
 *     মডেল                voucher_id   settleWith()
 *     CapitalEntry            ✅           ✅
 *     Withdrawal              ✅           ⛔ নেই
 *     DepositMovement         ✅           ⛔ নেই
 *     HandLoanMovement        ✅           ⛔ নেই
 *     RentalAdjustment        ✅           ⛔ নেই
 *
 * ⓘ **পাঁচটার পাঁচটাতেই ঘরটা বসানো ছিল, সংযোগ ছিল একটায়।** ⚠️ এটাই এই
 * রিপোর সবচেয়ে চেনা রোগ: ঘর বানানো হয়েছিল, কেউ জোড়া লাগায়নি।
 *
 * ── ⚠️ না থাকলে যা হত, আর কেন কেউ ধরত না ────────────────────────────
 * ভাউচার পোস্ট হত, টাকা খতিয়ানে বসত, আর অর্থের সারিটা **চিরকাল খসড়া**
 * থেকে যেত। ⛔ কোনো ত্রুটি নয় — দুইটা পাতাই খুলত, একটা বলত "টাকা
 * যায়নি", অন্যটা বলত গেছে।
 *
 * ── ⭐ তাই এই ফাইলের প্রতিটা দাবি শিকলের একটা কড়ি ধরে ────────────────
 * ⓘ প্রথমে `drill_sources`, কারণ ওটা খসে পড়লে বাকি সব দাবি **সবুজ
 * থাকতে পারত** — হুকটা নীরবে `return` করত।
 */
final class TheOtherFourRowsStayedDraftsTooTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);
    }

    /**
     * ⛔ চারটা নামই ক্লাসে পৌঁছায় — `drill_sources` ব্লকটা সম্পূর্ণ।
     *
     * ⚠️ এই দাবিটা আগে, কারণ নাম না থাকলে
     * [[App\Modules\Accounts\Services\VoucherService]] কিছুই খুঁজে পায় না
     * আর **চুপচাপ ফিরে যায়** — নিচের দাবিগুলো তখনও সবুজ থাকত, কারণ
     * ওগুলো মডেলের পদ্ধতি সরাসরি ডাকে।
     */
    public function test_all_four_type_names_resolve_to_their_classes(): void
    {
        $map = app(DrillResolver::class)->map();

        $expected = [
            'withdrawal' => Withdrawal::class,
            'deposit_movement' => DepositMovement::class,
            'hand_loan_movement' => HandLoanMovement::class,
            'rental_adjustment' => RentalAdjustment::class,
        ];

        foreach ($expected as $type => $class) {
            $this->assertArrayHasKey($type, $map, "`{$type}` নামটা drill_sources-এ নেই — হুকটা নীরবে কিছুই করবে না।");
            $this->assertSame($class, $map[$type]);
        }
    }

    /**
     * চারটাই চুক্তিটা মানে — নাহলে VoucherService ওদের ছুঁতই না।
     *
     * ⓘ [[App\Modules\Accounts\Services\VoucherService]] দুইটা শর্ত দেখে:
     * ক্লাসটা একটা Eloquent মডেল, **আর** [[SettledByAVoucher]]-এর
     * প্রয়োগকারী। ⚠️ একটা না মিললে সে চুপচাপ ফিরে যায়।
     */
    public function test_all_four_implement_the_contract(): void
    {
        foreach ([Withdrawal::class, DepositMovement::class, HandLoanMovement::class, RentalAdjustment::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, SettledByAVoucher::class),
                "{$class} চুক্তিটা মানে না — VoucherService ওটাকে ছুঁয়েও দেখবে না।"
            );
        }
    }

    /* ── উত্তোলন: অবস্থা বদলায় ─────────────────────────────────── */

    public function test_settling_a_withdrawal_marks_it_confirmed_and_keeps_the_voucher(): void
    {
        $row = $this->withdrawal();

        $row->settleWith(4242);

        $this->assertSame(DocumentStatus::CONFIRMED, $row->status);
        $this->assertSame(4242, (int) $row->voucher_id);
        $this->assertNotNull($row->posted_at);
    }

    /**
     * ⭐ দুইবার পোস্ট করলে তারিখটা নড়ে না।
     *
     * ⚠️ একটা ভাউচার বাতিল করে আবার পোস্ট করা যায়। শর্ত ছাড়া লিখলে
     * `posted_at` বদলে যেত — অর্থাৎ **টাকাটা কবে গিয়েছিল সেই তারিখটাই
     * মিথ্যা হত**, আর ওটা ফিরে পাওয়ার কোনো উপায় থাকত না।
     */
    public function test_settling_a_withdrawal_twice_does_not_move_the_date(): void
    {
        $row = $this->withdrawal();

        $row->settleWith(4242);
        $first = $row->posted_at;

        $row->settleWith(9999);

        $this->assertSame(4242, (int) $row->voucher_id, 'দ্বিতীয় ভাউচার সারিটা ছিনিয়ে নিয়েছে।');
        $this->assertEquals($first, $row->posted_at, 'পোস্টের তারিখ নড়েছে।');
    }

    public function test_another_vouchers_cancellation_leaves_the_withdrawal_alone(): void
    {
        $row = $this->withdrawal();
        $row->settleWith(4242);

        $row->unsettle(7777);

        $this->assertSame(DocumentStatus::CONFIRMED, $row->status, 'অন্য ভাউচারের বাতিল এই সারিটা খুলে দিয়েছে।');
        $this->assertSame(4242, (int) $row->voucher_id);
    }

    public function test_cancelling_its_own_voucher_opens_the_withdrawal_again(): void
    {
        $row = $this->withdrawal();
        $row->settleWith(4242);

        $row->unsettle(4242);

        $this->assertSame(DocumentStatus::DRAFT, $row->status);
        $this->assertNull($row->voucher_id);
        $this->assertNull($row->posted_at);
    }

    /* ── তিনটা গতিবিধি: অবস্থার ঘর নেই, তাই চিহ্ন `voucher_id` ──── */

    /**
     * ⚠️ এই তিনটায় `status` কলাম **নেই** — সারিটা জন্মায়ই ঘটনা হিসেবে।
     * ⭐ তাই নিষ্পন্ন হওয়ার একমাত্র চিহ্ন `voucher_id`, আর idempotency-র
     * পাহারা `whereNull()`। দাবিটা তাই আলাদা করে লেখা।
     */
    public function test_a_movement_binds_once_and_a_second_voucher_cannot_take_it(): void
    {
        foreach ($this->movements() as $label => $row) {
            $row->settleWith(555);
            $this->assertSame(555, (int) $row->voucher_id, "{$label}: প্রথম জোড়াই লাগেনি।");

            $row->settleWith(666);
            $this->assertSame(555, (int) $row->voucher_id, "{$label}: দ্বিতীয় ভাউচার সারিটা ছিনিয়ে নিয়েছে।");
        }
    }

    public function test_only_its_own_voucher_can_unbind_a_movement(): void
    {
        foreach ($this->movements() as $label => $row) {
            $row->settleWith(555);

            $row->unsettle(888);
            $this->assertSame(555, (int) $row->voucher_id, "{$label}: অন্য ভাউচারের বাতিল জোড়াটা খুলে দিয়েছে।");

            $row->unsettle(555);
            $this->assertNull($row->voucher_id, "{$label}: নিজের ভাউচার বাতিল হলেও জোড়াটা খোলেনি।");
        }
    }

    /**
     * ⭐ জোড়া খুললেও সারিটা থাকে — ঘটনাটা সত্যিই ঘটেছিল।
     *
     * ⓘ [[DepositMovement::voucher()]]-এর টীকায় কথাটা আগে থেকেই লেখা;
     * এখানে সেটা **মাপা** হয়। ⚠️ মুছে ফেলার ছাঁদে লিখলে ভুল করে কাটা
     * একটা ভাউচার বাতিল করলে আমানতের কিস্তির ইতিহাসটাই উধাও হত।
     */
    public function test_unbinding_never_deletes_the_row(): void
    {
        foreach ($this->movements() as $label => $row) {
            $key = $row->getKey();

            $row->settleWith(555);
            $row->unsettle(555);

            $this->assertNotNull(
                $row->newQuery()->withoutGlobalScopes()->find($key),
                "{$label}: সারিটা মুছে গেছে।"
            );
        }
    }

    /* ── নমুনা ──────────────────────────────────────────────────── */

    private function withdrawal(): Withdrawal
    {
        $person = DB::table('mdm_people')->where('company_id', $this->company->id)->value('id');

        return Withdrawal::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'document_no' => 'WDR-TEST-0001',
            'person_id' => $person,
            'amount' => '50000.0000',
            'trx_date' => now()->toDateString(),
            'reason' => 'পরীক্ষার সারি',
            'status' => DocumentStatus::DRAFT,
        ]);
    }

    /**
     * তিনটা গতিবিধির একটা করে সারি।
     *
     * ⓘ প্রতিটা `foreach`-এ নতুন করে বানানো হয়, কারণ দাবিগুলো সারিটা
     * বদলে দেয় — একটাই সারি ভাগ করলে দ্বিতীয় দাবিটা প্রথমটার ফল
     * দেখত, আর ব্যর্থতার কারণ খুঁজে পাওয়া যেত না।
     *
     * @return array<string, DepositMovement|HandLoanMovement|RentalAdjustment>
     */
    private function movements(): array
    {
        /*
         * ⛔ প্রথমে ডেমোর সারি খোঁজা হয়েছিল, আর তিনটা দাবিই লাল হলো:
         * *"ডেমোতে একটাও আমানত নেই"*। ⓘ ডেমো সিডার অর্থের এই তিনটা
         * খাতায় কিছুই বসায় না।
         *
         * ⭐ তাই মা-সারিগুলো এখানেই বানানো হয় — পরীক্ষাটা ডেমোর
         * বিষয়বস্তুর উপর দাঁড়াবে না। ⚠️ দাঁড়ালে ডেমো বদলালেই এটা লাল
         * হত, অথচ কোডে কিছুই ভাঙত না।
         */
        $branchId = $this->company->defaultBranch()?->id;

        $kindId = DB::table('fin_deposit_kinds')->insertGetId([
            'company_id' => $this->company->id,
            'code' => 'FDR-T',
            'name_en' => 'Fixed Deposit (test)',
            'name_bn' => 'স্থায়ী আমানত (পরীক্ষা)',
            'shape' => 'lump',
            'issuer' => 'bank',
            'personal_only' => 0,
            'is_active' => 1,
            'sort' => 1,
        ]);

        $depositId = DB::table('fin_deposits')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'document_no' => 'DEP-TEST-0001',
            'kind_id' => $kindId,
            'institution' => 'Islami Bank Bangladesh PLC',
            'principal' => '1000000.0000',
            'opened_on' => now()->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $loanId = DB::table('fin_hand_loan_accounts')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'person_id' => DB::table('mdm_people')->where('company_id', $this->company->id)->value('id'),
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $contractId = DB::table('fin_rental_contracts')->insertGetId([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'document_no' => 'RNT-TEST-0001',
            'counterparty' => 'হাজী মোহাম্মদ আলী',
            'subject' => 'দোকান — ময়মনসিংহ',
            'deposit_amount' => '200000.0000',
            'monthly_rent' => '45000.0000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'term_months' => 36,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        return [
            'আমানতের গতিবিধি' => DepositMovement::query()->create([
                'company_id' => $this->company->id,
                'deposit_id' => $depositId,
                'kind' => DepositMovement::INSTALMENT,
                'amount' => '25000.0000',
                'moved_on' => now()->toDateString(),
            ]),
            'ধারের গতিবিধি' => HandLoanMovement::query()->create([
                'company_id' => $this->company->id,
                'account_id' => $loanId,
                'direction' => HandLoanMovement::IN,
                'amount' => '15000.0000',
                'moved_on' => now()->toDateString(),
            ]),
            'ভাড়ার কিস্তি' => RentalAdjustment::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $this->company->defaultBranch()?->id,
                'rental_contract_id' => $contractId,
                'for_month' => now()->startOfMonth()->toDateString(),
                'rent' => '45000.0000',
                'paid_cash' => '45000.0000',
                'from_deposit' => '0.0000',
            ]),
        ];
    }
}
