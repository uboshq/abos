<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\RentalContractService;
use App\Modules\Finance\Services\WithdrawalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ব্যাংক একটা নম্বর চাইত, আর পর্দার কোনো পথ সেটা পাঠাত না।
 *
 * ── কী ভাঙা ছিল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * মালিক নিজে আটকে গেছেন: মূলধনের টাকা ব্যাংকে ঢোকাতে গিয়ে বার্তা এল
 * *"লেনদেন নম্বর দিন"* — অথচ পর্দায় ওই ঘরটাই ছিল না।
 *
 * [[App\Modules\Accounts\Services\VoucherService]] নম্বরটা চিরকাল
 * নিয়েছে (`$data['instrument_no']`), আর `post()`-এ
 * `assertBankReferenceIsFree()` টাকার খাত ব্যাংক বা MFS হলে সেটা
 * **বাধ্যতামূলক** করেছে। কিন্তু অর্থ মডিউলের **একটাও সেবা সেটা পাঠাত
 * না** — দশটা পোস্ট-পথের একটাতেও নয়।
 *
 * ⛔ ফল: ব্যাংক বা বিকাশ দিয়ে **কোনো টাকা ঢোকানো বা বের করাই যেত না**।
 * নগদে সব কাজ করত, তাই ফাঁকটা রোজকার ব্যবহারে দেখা যেত না — কেবল
 * ব্যাংকের দিন বাজত।
 *
 * ── ⚠️ কেন "আটকায়" দাবিটা একা যথেষ্ট নয় ─────────────────────────────
 * "ব্যাংক খাতে নম্বর ছাড়া আটকায়" — এই দাবিটা **আজকের ভাঙা কোডেও সবুজ
 * ছিল**, আর সেটাই সবচেয়ে জরুরি কথা। কারণ ওটা সবুজ হত ভুল কারণে: কোনো
 * নম্বর **দেওয়াই যেত না**, তাই ব্যতিক্রমটা সবসময় পড়ত।
 *
 * ⭐ তাই আসল দাবিটা উল্টো দিকের: **নম্বর দিলে ঢোকে, আর নম্বরটা সত্যিই
 * ভাউচারে বসে**। `$data`-তে নম্বর থাকলেও কোনো সেবা যদি সেটা
 * `create()`-এ না পাঠায়, তবে ভাউচার খালি নম্বর নিয়ে পোস্ট হত — আর নগদ
 * খাতে কেউ কোনোদিন টের পেত না।
 *
 * ── দশটা পথ, আর প্রতিটা অন্তত একবার ─────────────────────────────────
 * ⓘ সংখ্যাটা **দশ, এগারো নয়** — গুনে দেখা (`vouchers->create(` ডাক):
 * মূলধন ১ · উত্তোলন ১ · হাতে-ধার ১ · আমানত ৩ · ভাড়া ৪।
 *
 * ⚠️ আর নিচে প্রতিটা পথ ঘোরানো হয় একটা তালিকা ধরে, শেষে গোনা হয় কতটা
 * চলল — নাহলে একটা পথ বাদ পড়লে **কোনো দাবিই লাল হত না**, আর ঠিক ওই
 * পথ দিয়েই লাইভে টাকা আটকে থাকত।
 */
class TheBankWantedANumberTheScreenNeverSentTest extends TestCase
{
    use RefreshDatabase;

    /** দশটা পোস্ট-পথ — সেবায় `vouchers->create(` ডাকের সংখ্যা। */
    private const PATHS = 10;

    private Company $company;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        /*
         * আমানতের ধরনগুলো — FDR, DPS, MIS।
         *
         * ⓘ `DemoSeeder` এগুলো বসায় না, তাই আলাদা করে ডাকতে হয়
         * ([[MoneyPutAwayIsNotMoneyLentTest]]-ও একই কাজ করে)। ছাড়া দিলে
         * নিচের তিনটা আমানতের পথ `DepositKind` খুঁজে না পেয়ে থেমে যেত —
         * আর ব্যর্থতাটা দেখতে প্লাম্বিং ভাঙার মতো লাগত, যা নয়।
         */
        app(DepositKindInstaller::class)->install();

        $this->person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'P-OWNER',
            'name_en' => 'মালিক',
        ]);
    }

    /**
     * ⭐ দশটা পথের প্রতিটাতে নম্বরটা ভাউচারে গিয়ে বসে।
     *
     * ── কেন এক টেস্টে দশটা, দশটা আলাদা টেস্টে নয় ─────────────────────
     * দাবিটা আসলে একটাই — *"প্লাম্বিং কোথাও ছেঁড়া নেই"* — আর সেটা
     * প্রমাণ করতে **সবগুলো একসাথে** দেখা দরকার। আলাদা করলে কেউ একটা
     * নতুন পোস্ট-পথ যোগ করে তার টেস্ট লিখতে ভুলে যেত, আর কিছুই লাল হত
     * না। এখানে নিচের গোনাটা সেটা ধরবে।
     */
    public function test_every_post_path_carries_the_number_to_the_voucher(): void
    {
        $bank = $this->bank();
        $covered = [];

        foreach ($this->paths() as $name => $run) {
            /*
             * প্রতিটা পথে **আলাদা নম্বর**, আর সেটা বাধ্যতামূলক:
             * `vouchers_bank_reference_unique` একই খাতে একই নম্বর দুইবার
             * বসতে দেয় না। একই নম্বর দিলে দ্বিতীয় পথটা ডুপ্লিকেট বলে
             * ব্যর্থ হত, আর কারণটা পড়ে মনে হত প্লাম্বিং ভাঙা — যা নয়।
             */
            $reference = 'CHQ-'.mb_strtoupper(mb_substr(md5($name), 0, 8));

            /*
             * ⚠️ ব্যতিক্রমটা ধরা হয় পথের নাম সহ, আর এটা যোগ করা হয়েছে
             * একটা সত্যিকারের অসুবিধার পর।
             *
             * প্রথম খসড়ায় `$run()` সোজা ডাকা হত। একটা পথে নম্বরটা
             * পৌঁছায়নি, আর `assertBankReferenceIsFree()` ছুঁড়ে দিল —
             * কিন্তু ব্যতিক্রমটা লুপের বাইরে বেরিয়ে যাওয়ায় ব্যর্থতার
             * বার্তায় **কোন পথ** তা লেখা ছিল না, কেবল খাতের নাম।
             *
             * ⓘ অর্থাৎ পরীক্ষাটা ঠিক কাজ করেছিল (ভাঙা জিনিস ধরেছে),
             * কিন্তু **কোথায় ভাঙা তা বলতে পারেনি** — আর দশটা পথের
             * মধ্যে খুঁজে বের করা তখন অনুমানের কাজ।
             */
            try {
                $voucher = $run($bank, $reference);
            } catch (ValidationException $e) {
                /*
                 * ⚠️ বার্তাটা **কারণ দাবি করে না**, কেবল কী ঘটেছে বলে।
                 *
                 * প্রথম খসড়ায় লেখা ছিল *"নম্বরটা পৌঁছায়নি"* — আর সেটা
                 * ছিল একটা অনুমান: যেকোনো `ValidationException` ধরা হত
                 * নম্বরের সমস্যা বলে। বাস্তবে একবার ব্যর্থতা এসেছিল
                 * সম্পূর্ণ অন্য কারণে (MIS আমানতে মুনাফার খাত বলা হয়নি),
                 * আর বার্তাটা আমাকে **ভুল দিকে** পাঠিয়েছিল।
                 *
                 * ⓘ এখন সে কেবল পথের নাম আর ডাটাবেজের নিজের ভুলগুলো
                 * দেখায় — নির্ণয়টা পড়ে করার কাজ, বার্তার নয়।
                 */
                $this->fail(
                    "পথ `{$name}` পোস্ট করা যায়নি: "
                    .implode(' · ', array_merge(...array_values($e->errors())))
                );
            }

            $this->assertInstanceOf(Voucher::class, $voucher,
                "পথ `{$name}` কোনো ভাউচারই ফেরত দেয়নি — দাবিটা তখন কিছুই মাপছে না।");

            $this->assertSame($reference, (string) $voucher->instrument_no,
                "পথ `{$name}`-এ নম্বরটা ভাউচারে পৌঁছায়নি — সেবাটা সেটা "
                .'`vouchers->create()`-এ পাঠাচ্ছে না।');

            $covered[] = $name;
        }

        /*
         * ⚠️ এই দাবিটা ছাড়া উপরের লুপটা **শূন্য পথেও সবুজ** হত।
         *
         * আজকের সবচেয়ে সাধারণ রোগ: পাহারা কিছু না দেখে পাশ করে। তাই
         * গোনাটা হার্ডকোড, আর সংখ্যাটা এসেছে কোড গুনে — নতুন পোস্ট-পথ
         * যোগ হলে এই টেস্ট লাল হবে, আর সেটাই উদ্দেশ্য: তখন কেউ ভাববে
         * নম্বরটা ওই পথেও পাঠাতে হবে কি না।
         */
        $this->assertCount(self::PATHS, $covered,
            'দশটা পোস্ট-পথের সবগুলো চলেনি — নতুন কোনো পথ যোগ হয়েছে কি? '
            .'তাহলে সেখানেও `instrument_no` পাঠাতে হবে, আর এখানে গোনা বাড়াতে হবে।');
    }

    /**
     * ব্যাংক খাতে নম্বর ছাড়া টাকা যায় না — মালিক যেখানে আটকেছিলেন।
     *
     * ⓘ এই দাবিটা ভাঙা কোডেও সবুজ ছিল (ভুল কারণে), তাই একা এটা কিছু
     * প্রমাণ করে না। উপরের দাবিটার সাথে জোড়া বেঁধেই এটা অর্থপূর্ণ:
     * একটা বলে "নম্বর ছাড়া আটকায়", অন্যটা বলে "নম্বর দিলে যায়"।
     */
    public function test_a_bank_account_without_a_number_is_still_refused(): void
    {
        $bank = $this->bank();

        $entry = $this->capitalEntry();

        try {
            app(CapitalService::class)->post($entry, $bank);
            $this->fail('ব্যাংক খাতে নম্বর ছাড়াই মূলধন বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrument_no', $e->errors(),
                'আটকেছে ঠিকই, কিন্তু ভুলটা `instrument_no` ঘরের নামে আসেনি — '
                .'পর্দায় তখন কোন ঘরটা ভরতে হবে তা দেখানো যেত না।');
        }
    }

    /**
     * নগদে নম্বর লাগে না — আর সেটাই `required` না করার কারণ।
     *
     * ⚠️ এই দাবিটা পাহারা দেয় উল্টো ভুলটা: কেউ যদি কন্ট্রোলারে
     * `required` বসিয়ে দেন, তবে কাউন্টারের প্রতিটা নগদ লেনদেন এমন একটা
     * নম্বর চাইত যা বাস্তবে নেই।
     */
    public function test_cash_needs_no_number(): void
    {
        $entry = $this->capitalEntry();

        $posted = app(CapitalService::class)->post($entry, $this->cash());

        $this->assertTrue($posted->fresh()->status === CapitalEntry::POSTED,
            'নগদে নম্বর ছাড়া মূলধন বসেনি — নিয়মটা নগদেও নম্বর চাইছে কি?');

        $this->assertNull($posted->voucher->instrument_no,
            'নগদের ভাউচারে একটা নম্বর বসে গেছে, অথচ কেউ দেয়নি।');
    }

    // ── দশটা পথ ───────────────────────────────────────────────────────

    /**
     * প্রতিটা পথ একটা ক্লোজার: টাকার খাত ও নম্বর নেয়, ভাউচার ফেরত দেয়।
     *
     * @return array<string, callable(Account, string): ?Voucher>
     */
    private function paths(): array
    {
        return [
            'capital' => function (Account $bank, string $ref): ?Voucher {
                $entry = app(CapitalService::class)->post($this->capitalEntry(), $bank, $ref);

                return $entry->fresh()->voucher;
            },

            'withdrawal' => function (Account $bank, string $ref): ?Voucher {
                $w = app(WithdrawalService::class)->request([
                    'person_id' => $this->person->id,
                    'amount' => '5000',
                    'trx_date' => now()->toDateString(),
                ]);

                return app(WithdrawalService::class)->post($w, $bank, $ref)->fresh()->voucher;
            },

            'hand loan' => function (Account $bank, string $ref): ?Voucher {
                $account = app(HandLoanService::class)->open(['person_id' => $this->person->id]);

                $movement = app(HandLoanService::class)->move($account, [
                    'direction' => HandLoanMovement::OUT,
                    'amount' => '3000',
                    'moved_on' => now()->toDateString(),
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return $movement->voucher;
            },

            'deposit open' => function (Account $bank, string $ref): ?Voucher {
                $deposit = $this->openDeposit('FDR', $bank, $ref);

                return $deposit->movements()->first()?->voucher;
            },

            'deposit instalment' => function (Account $bank, string $ref): ?Voucher {
                $dps = $this->openDeposit('DPS', $this->cash(), null, ['instalment_amount' => '1000']);

                $movement = app(DepositService::class)->instalment($dps, [
                    'amount' => '1000',
                    'moved_on' => now()->toDateString(),
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return $movement->voucher;
            },

            'deposit payout' => function (Account $bank, string $ref): ?Voucher {
                /*
                 * ⓘ MIS নিয়মিত মুনাফা দেয়, তাই খোলার সময়ই বলতে হয়
                 * **মুনাফাটা কোথায় আসবে** (`assertSane` সেটা চায়)।
                 * ছাড়া দিলে ব্যর্থতাটা আসত, কিন্তু নম্বরের কারণে নয় —
                 * আর সেটাই একবার ভুল রোগনির্ণয়ে পাঠিয়েছিল।
                 */
                $mis = $this->openDeposit('MIS', $this->cash(), null, [
                    'payout_account_id' => $this->cash()->id,
                ]);

                $movement = app(DepositService::class)->payout($mis, [
                    'amount' => '900',
                    'moved_on' => now()->toDateString(),
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return $movement->voucher;
            },

            'deposit close' => function (Account $bank, string $ref): ?Voucher {
                $fd = $this->openDeposit('FDR', $this->cash());

                $movement = app(DepositService::class)->close($fd, [
                    'amount' => '500000',
                    'moved_on' => now()->toDateString(),
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return $movement->voucher;
            },

            'rental open' => function (Account $bank, string $ref): ?Voucher {
                $contract = $this->openRental($bank, $ref);

                return $contract->adjustments()->first()?->voucher
                    ?? Voucher::query()->latest('id')->first();
            },

            'rental month' => function (Account $bank, string $ref): ?Voucher {
                $contract = $this->openRental($this->cash());

                app(RentalContractService::class)->adjustMonth($contract, [
                    'for_month' => now()->startOfMonth()->toDateString(),
                    'paid_on' => now()->toDateString(),
                    'rent' => '30000',
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return Voucher::query()->latest('id')->first();
            },

            'rental top-up' => function (Account $bank, string $ref): ?Voucher {
                $contract = $this->openRental($this->cash());

                app(RentalContractService::class)->addToDeposit($contract, [
                    'amount' => '10000',
                    'paid_on' => now()->toDateString(),
                    'money_account_id' => $bank->id,
                    'instrument_no' => $ref,
                ]);

                return Voucher::query()->latest('id')->first();
            },
        ];
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * একটা ব্যাংক খাত — ১১০২-এর নিচে, তাই `money_kind` ব্যাংক।
     *
     * ⓘ `AccountService` দিয়ে বানানো হয় না, কারণ এই টেস্টের দরকার কেবল
     * একটা পোস্টযোগ্য ব্যাংক খাত — আর সেটা হাতে বসানোই সরল। ⚠️ তাই
     * `money_kind`-ও হাতে দিতে হয়: বাবার খাত থেকে বসানোর কাজটা সেবার,
     * আর সেবা এখানে চলছে না।
     */
    private function bank(): Account
    {
        return Account::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => '1102-TEST'],
            [
                'name_en' => 'Test Bank Current',
                'parent_id' => StandardChart::find(StandardChart::BANK)?->id,
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'money_kind' => Account::BANK,
                'is_active' => true,
            ],
        );
    }

    /**
     * একটা **নগদ** খাত — ব্যাংক নয়।
     *
     * ── ⛔ কেন `money()` স্কোপটা এখানে ভুল ছিল ───────────────────────
     * প্রথমে লেখা ছিল `Account::query()->money()->postable()->firstOrFail()`।
     * কিন্তু `scopeMoney()` **যেকোনো** টাকার খাত মেলায় — `money_kind`
     * শূন্য নয় হলেই, অর্থাৎ নগদ, ব্যাংক আর MFS তিনটাই।
     *
     * ⚠️ ফল ছিল সূক্ষ্ম আর বিভ্রান্তিকর: উপরের লুপ আগে [[bank]] ডাকে,
     * যেটা `1102-TEST` বানায় — তারপর এই পদ্ধতিটা কোনো ক্রম ছাড়া
     * `firstOrFail()` করায় **ঐ ব্যাংক খাতটাই ফেরত দিতে পারত**। তখন
     * "নগদ থেকে খোলা" আমানতটা আসলে ব্যাংক থেকে খোলা হত, নম্বর ছাড়া,
     * আর `assertBankReferenceIsFree()` ছুঁড়ে দিত।
     *
     * ⭐ আর ব্যর্থতাটা আঙুল তুলত **ভুল পথের দিকে** (`deposit instalment`),
     * অথচ ভাঙা ছিল ওই পথের **সেটআপ**, পথটা নয়। প্রোডাকশন কোড সম্পূর্ণ
     * নির্দোষ — ভুলটা এই সহায়কের।
     *
     * ⓘ তাই এখন ধরনটা স্পষ্ট করে চাওয়া হয়, আর ক্রমও ঠিক করা — "যেকোনো
     * একটা" চাওয়া মানে ভবিষ্যতের যেকোনো নতুন খাত এটাকে বদলে দিতে পারে।
     */
    private function cash(): Account
    {
        return Account::query()
            ->where('money_kind', Account::CASH)
            ->postable()
            ->active()
            ->orderBy('code')
            ->firstOrFail();
    }

    /** একটা খসড়া মূলধনের সারি — পোস্ট করার জন্য তৈরি। */
    private function capitalEntry(): CapitalEntry
    {
        return app(CapitalService::class)->record([
            'person_id' => $this->person->id,
            'contributor_type' => CapitalEntry::OWNER,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(),
            'amount' => '100000',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function openDeposit(string $kind, Account $from, ?string $ref = null, array $extra = []): Deposit
    {
        return app(DepositService::class)->open(array_merge([
            'kind_id' => DepositKind::query()->where('code', $kind)->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'held_by' => Deposit::BUSINESS,
            'principal' => '500000',
            'return_word' => 'interest',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $from->id,
            'instrument_no' => $ref,
        ], $extra));
    }

    private function openRental(Account $from, ?string $ref = null): RentalContract
    {
        return app(RentalContractService::class)->open([
            'counterparty' => 'বাড়িওয়ালা',
            'subject' => 'দোকান',
            'deposit_amount' => '120000',
            'monthly_rent' => '30000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'term_months' => 12,
            'money_account_id' => $from->id,
            'instrument_no' => $ref,
        ]);
    }
}
