<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Contracts\SettledByAVoucher;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Models\RentalAdjustment;
use App\Modules\Finance\Models\RentalContract;
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

    /**
     * ⛔ বানানো নম্বর চলে না — `voucher_id`-তে আসল বিদেশি চাবি আছে।
     *
     * ⓘ প্রথমে একটা বানানো নম্বর লেখা হয়েছিল, আর MySQL ফিরিয়ে দিল:
     * *"foreign key constraint fails"*। ⭐ ওটা পরীক্ষার ব্যর্থতা নয়,
     * ডাটাবেজের একটা **সত্যিকারের পাহারা** — অস্তিত্বহীন ভাউচারের সাথে
     * কোনো সারি বাঁধা যাবে না।
     *
     * তাই ডেমোর দুইটা আসল ভাউচার ধার করা হয়: একটা "নিজের", একটা "অন্যের"।
     */
    private int $mine;

    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);

        /*
         * ⛔ প্রথমে ডেমোর ভাউচার ধার করা হয়েছিল, আর নয়টা দাবিই লাল হলো:
         * *"ডেমোতে দুইটা ভাউচারও নেই"*। ⓘ ডেমো সিডার কোনো ভাউচার বসায় না।
         *
         * ⭐ তাই দুইটা নিজেই বানানো — একটা “নিজের”, একটা “অন্যের”।
         * ⚠️ দুইটা লাগে, কারণ সবচেয়ে জরুরি দাবিটাই হলো **অন্য ভাউচারের
         * বাতিল এই সারিটা খুলতে পারে না**, আর সেটা একটা ভাউচার দিয়ে
         * মাপাই যায় না।
         */
        $year = DB::table('financial_years')->where('company_id', $this->company->id)->value('id');

        $this->assertNotNull($year, 'কোম্পানির কোনো অর্থবছর নেই — ভাউচার বানানো যাচ্ছে না।');

        $make = fn (string $no): int => (int) Voucher::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => $year,
            'type' => 'payment',
            'document_no' => $no,
            'trx_date' => now()->toDateString(),
            'amount' => '1.0000',
            'status' => DocumentStatus::CONFIRMED,
        ])->id;

        $this->mine = $make('PV-TEST-0001');
        $this->other = $make('PV-TEST-0002');
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

        $row->settleWith($this->mine);

        $this->assertSame(DocumentStatus::CONFIRMED, $row->status);
        $this->assertSame($this->mine, (int) $row->voucher_id);
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

        $row->settleWith($this->mine);
        $first = $row->posted_at;

        $row->settleWith($this->other);

        $this->assertSame($this->mine, (int) $row->voucher_id, 'দ্বিতীয় ভাউচার সারিটা ছিনিয়ে নিয়েছে।');
        $this->assertEquals($first, $row->posted_at, 'পোস্টের তারিখ নড়েছে।');
    }

    public function test_another_vouchers_cancellation_leaves_the_withdrawal_alone(): void
    {
        $row = $this->withdrawal();
        $row->settleWith($this->mine);

        $row->unsettle($this->other);

        $this->assertSame(DocumentStatus::CONFIRMED, $row->status, 'অন্য ভাউচারের বাতিল এই সারিটা খুলে দিয়েছে।');
        $this->assertSame($this->mine, (int) $row->voucher_id);
    }

    public function test_cancelling_its_own_voucher_opens_the_withdrawal_again(): void
    {
        $row = $this->withdrawal();
        $row->settleWith($this->mine);

        $row->unsettle($this->mine);

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
            $row->settleWith($this->mine);
            $this->assertSame($this->mine, (int) $row->voucher_id, "{$label}: প্রথম জোড়াই লাগেনি।");

            $row->settleWith($this->other);
            $this->assertSame($this->mine, (int) $row->voucher_id, "{$label}: দ্বিতীয় ভাউচার সারিটা ছিনিয়ে নিয়েছে।");
        }
    }

    public function test_only_its_own_voucher_can_unbind_a_movement(): void
    {
        foreach ($this->movements() as $label => $row) {
            $row->settleWith($this->mine);

            $row->unsettle($this->other);
            $this->assertSame($this->mine, (int) $row->voucher_id, "{$label}: অন্য ভাউচারের বাতিল জোড়াটা খুলে দিয়েছে।");

            $row->unsettle($this->mine);
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

            $row->settleWith($this->mine);
            $row->unsettle($this->mine);

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
        /*
         * ⚠️ `DB::table()->insertGetId()` নয় — Eloquent।
         *
         * ⛔ সরাসরি ঢোকাতে গিয়ে MySQL বলল: *"Field 'public_id' doesn't
         * have a default value"*। ⓘ কারণ [[HasPublicId]] একটা **মডেলের**
         * ঘটনায় ঘরটা ভরে; query builder ঐ ঘটনাগুলো একেবারেই চালায় না।
         *
         * ⭐ অর্থাৎ পরীক্ষার নমুনাও মডেলের পথেই বানাতে হয়, নাহলে
         * বাস্তবের চেয়ে আলাদা সারি তৈরি হয় আর ব্যর্থতাটা বিভ্রান্ত করে।
         */
        $branchId = $this->company->defaultBranch()?->id;

        $kind = DepositKind::query()->create([
            'company_id' => $this->company->id,
            'code' => 'FDR-T',
            'name_en' => 'Fixed Deposit (test)',
            'name_bn' => 'স্থায়ী আমানত (পরীক্ষা)',
            'shape' => 'lump',
            'issuer' => 'bank',
            'personal_only' => false,
            'is_active' => true,
            'sort' => 1,
        ]);

        $depositId = Deposit::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'document_no' => 'DEP-TEST-0001',
            'kind_id' => $kind->id,
            'institution' => 'Islami Bank Bangladesh PLC',
            'principal' => '1000000.0000',
            'opened_on' => now()->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
        ])->id;

        $loanId = HandLoanAccount::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'person_id' => DB::table('mdm_people')->where('company_id', $this->company->id)->value('id'),
            'status' => DocumentStatus::CONFIRMED,
        ])->id;

        /*
         * ⚠️ ভাড়ার চুক্তিতে দুইটা খাত **বাধ্যতামূলক** (`->constrained()`,
         * nullable নয়): টাকা কোথা থেকে যায়, আর খরচ কোন খাতে বসে।
         *
         * ⭐ নমুনাতেও ওগুলো দিতে হয় — নাহলে MySQL বলে *"Field 'account_id'
         * doesn't have a default value"*, আর সেটা পরীক্ষার ব্যর্থতা নয়,
         * স্কিমার একটা সত্যিকারের দাবি।
         */
        $accountId = DB::table('accounts')->where('company_id', $this->company->id)->value('id');

        $this->assertNotNull($accountId, 'কোম্পানির চার্টে একটাও খাত নেই।');

        $contractId = RentalContract::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $branchId,
            'document_no' => 'RNT-TEST-0001',
            'counterparty' => 'হাজী মোহাম্মদ আলী',
            'subject' => 'দোকান — ময়মনসিংহ',
            'account_id' => $accountId,
            'expense_account_id' => $accountId,
            'deposit_amount' => '200000.0000',
            'monthly_rent' => '45000.0000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'term_months' => 36,

            /*
             * ⚠️ `ends_on`-ও বাধ্যতামূলক, যদিও শুরু ও মেয়াদ থেকে ওটা
             * হিসাব করা যায়। ⓘ স্কিমা ইচ্ছাকৃতভাবে সংখ্যাটা **সংরক্ষণ**
             * করে, হিসাব করে না — নাহলে চুক্তির মেয়াদ বাড়ালে পুরনো
             * কাগজে লেখা শেষ তারিখটাও নীরবে বদলে যেত।
             */
            'ends_on' => now()->startOfMonth()->addMonths(36)->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
        ])->id;

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
