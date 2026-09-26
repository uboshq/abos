<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গৃহীত চেক খাতায় ওঠে কেবল পাশ হয়ে ব্যাংকে জমা পড়ার পরে।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"ক্লিয়ারিং এর পরে একাউন্টে জমা হলে তার পর"*। আর চেক ঢোকার দরজা
 * একটাই — চেকের খাতা (*"চেক নেবে কেবল হিসাব বিভাগ"*)।
 *
 * ── ⛔ আগে যা হত ─────────────────────────────────────────────────────
 * চেক হাতে আসতেই Dr ১১০৪ / Cr গ্রাহক — গ্রাহকের বকেয়া সেদিনই কমত, আর
 * বাকির সীমাও সেদিনই খুলে যেত। ⚠️ তিন সপ্তাহ পরে চেক ফেরত এলে ততদিনে
 * ঐ খোলা সীমায় আরো মাল বেরিয়ে গেছে। মার্জিন ৩.৮২%, তাই একটা ফেরত চেক
 * কয়েক মাসের লাভ খেয়ে ফেলে।
 *
 * ── ⓘ এখনকার পথ ───────────────────────────────────────────────────────
 *   হাতে এল         রেজিস্টারে একটা সারি — খাতায় কিছু নয়
 *   পাশ হলো         Dr ব্যাংক / Cr গ্রাহক
 *   পাশের আগে ফেরত  রেজিস্টারে অবস্থা — খাতায় কিছু নয় (উল্টানোর কিছু নেই)
 *   পাশের পরে ফেরত  Dr গ্রাহক / Cr ব্যাংক — নতুন ঘটনা, বাতিল নয়
 *
 * ── ⚠️ পুরনো চেক ─────────────────────────────────────────────────────
 * আগের নিয়মে তোলা চেক (লাইভে কিছু খোলা আছে) ইতিমধ্যে ১১০৪-এ বসে
 * আছে। ⓘ কোনো মাইগ্রেশন নয় — চেকটা পুরনো কি না তা ডেটাই বলে (তার
 * নিজের `cheque` দাখিলা, বা ভাউচার বা আদায়ের জোড়া), আর পুরনোগুলো
 * নিজেদের পুরনো পথেই শেষ হয়। ⛔ না মানলে পুরনো চেক পাশ হওয়ার দিন
 * গ্রাহকের বকেয়া **দ্বিতীয়বার** কমত।
 */
final class AChequeReachesTheBooksOnlyWhenItClearsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Account $bank;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1102-DUTCH',
            'name_en' => 'Dutch-Bangla Bank',
            'name_bn' => 'ডাচ-বাংলা ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    // ── ১ · হাতে এল — খাতায় কিছুই না ──────────────────────────────────

    public function test_receiving_a_cheque_writes_nothing_to_the_books(): void
    {
        $dueBefore = $this->due();

        $cheque = $this->receive('40000');

        $this->assertSame(0, $this->entriesFor($cheque),
            'চেক হাতে আসতেই খাতায় দাখিলা বসেছে — মালিকের নিয়মে বসার কথা কেবল পাশের দিন।');

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2),
            'চেক হাতে আসতেই গ্রাহকের বকেয়া কমে গেছে — টাকা এখনো আসেনি।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'নতুন চেক ১১০৪-এ বসেছে — হাতে-চেকের খাতটা এখন কেবল পুরনো চেকের।');
    }

    public function test_a_received_cheque_must_name_whose_it_is(): void
    {
        /*
         * ⛔ পক্ষ ছাড়া পাশের দিনের Cr কারও নামে বসত না — টাকা এসেছে,
         * অথচ কারও বকেয়া কমেনি।
         */
        try {
            app(ChequeService::class)->create([
                'direction' => Cheque::RECEIVED,
                'cheque_no' => 'NOPARTY-1',
                'bank_name' => 'Sonali Bank',
                'cheque_date' => now()->toDateString(),
                'amount' => '1000',
                'bank_account_id' => $this->bank->id,
            ]);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('party', $e->errors());
            $this->assertSame(0, Cheque::query()->where('cheque_no', 'NOPARTY-1')->count());

            return;
        }

        $this->fail('পক্ষ ছাড়া একটা গৃহীত চেক খাতায় উঠে গেছে।');
    }

    // ── ২ · পাশ হলো — এখন টাকা ────────────────────────────────────────

    public function test_clearing_moves_the_money_and_the_debt_together(): void
    {
        $dueBefore = $this->due();

        $cheque = app(ChequeService::class)->clear($this->receive('40000'));

        $this->assertSame(Cheque::CLEARED, $cheque->status);

        $this->assertSame(0, bccomp($this->bankBalance(), '40000', 2),
            'পাশ হওয়ার পরেও ব্যাংকে টাকা ঢোকেনি।');

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '40000', 2),
            'পাশ হওয়ার পরেও গ্রাহকের বকেয়া ৪০,০০০ কমেনি।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'নতুন চেক পাশ হতে গিয়ে ১১০৪ ছুঁয়েছে — ওখানে তো কিছু বসেইনি।');
    }

    public function test_the_credit_limit_does_not_open_until_the_cheque_clears(): void
    {
        /*
         * ⭐ এটাই পুরো নিয়মের কারণ: ফেরত আসতে পারে এমন কাগজে সীমা খোলে না।
         *
         * ⓘ গ্রাহকের ১,০০০ টাকা বাকি, সীমাও ঠিক ১,০০০ — এক টাকার মালও
         * আর যায় না। ১,০০০ টাকার চেক হাতে এল, তবু দেয়াল দাঁড়িয়ে থাকে;
         * পাশ হলে তবেই খোলে।
         */
        $settings = app(SettingsService::class);
        $settings->set('customer.credit_limit_enabled', true);
        $settings->set('customer.zero_limit_blocks', false);

        $this->dealer->forceFill(['credit_limit' => '100000000'])->save();

        app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->dealer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'deposit' => '0',
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']],
        );

        $this->dealer->forceFill(['credit_limit' => $this->dealer->fresh()->outstanding()])->save();

        $this->assertTrue($this->walled('500'), 'প্রস্তুতিটাই ভুল — সীমায় পৌঁছানো গ্রাহককেও দেয়াল আটকায়নি।');

        $cheque = $this->receive('1000');

        $this->assertTrue($this->walled('500'),
            '⛔ চেক হাতে আসতেই বাকির সীমা খুলে গেছে — ফেরত আসতে পারে এমন কাগজে মাল বেরোচ্ছে।');

        app(ChequeService::class)->clear($cheque);

        $this->assertFalse($this->walled('500'),
            'চেক পাশ হওয়ার পরেও সীমা খোলেনি — টাকা এসেছে, তবু গ্রাহক আটকে।');
    }

    // ── ৩ · ফেরত আর বাতিল ─────────────────────────────────────────────

    public function test_a_bounce_before_clearing_touches_no_books(): void
    {
        $dueBefore = $this->due();

        $cheque = $this->receive('25000');
        app(ChequeService::class)->deposit($cheque);
        $bounced = app(ChequeService::class)->bounce($cheque->fresh(), 'তহবিল অপর্যাপ্ত');

        $this->assertSame(Cheque::BOUNCED, $bounced->status);
        $this->assertSame('তহবিল অপর্যাপ্ত', $bounced->bounce_reason);

        $this->assertSame(0, $this->entriesFor($cheque),
            'পাশের আগে ফেরত এসেছে, অথচ খাতায় দাখিলা বসেছে — উল্টানোর মতো কিছুই তো ছিল না।');

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2));
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2));
    }

    public function test_cancelling_before_clearing_touches_no_books(): void
    {
        $cheque = $this->receive('25000');
        $cancelled = app(ChequeService::class)->cancel($cheque, 'গ্রাহক চেক বদলে দিয়েছেন');

        $this->assertSame(Cheque::CANCELLED, $cancelled->status);
        $this->assertSame(0, $this->entriesFor($cheque),
            'বাতিল করা চেক খাতায় দাখিলা রেখে গেছে।');
    }

    public function test_a_bounce_after_clearing_puts_the_debt_back_and_takes_the_bank_down(): void
    {
        /*
         * ⓘ ব্যাংক টাকা দিয়ে পরে ফিরিয়ে নিতে পারে। ⭐ এটা একটা **নতুন
         * ঘটনা** — পাশের দাখিলাটা মোছা বা উল্টানো হয় না, কারণ চেকটা
         * সত্যিই পাশ হয়েছিল, আর পরে সত্যিই ফেরত এসেছে।
         */
        $dueBefore = $this->due();

        $cheque = app(ChequeService::class)->clear($this->receive('25000'));
        $bounced = app(ChequeService::class)->bounce($cheque, 'ব্যাংক টাকা ফিরিয়ে নিয়েছে');

        $this->assertSame(Cheque::BOUNCED, $bounced->status);

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2),
            'পাশের পরে ফেরত এসেছে, অথচ গ্রাহকের বকেয়া আগের জায়গায় ফেরেনি।');

        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2),
            'পাশের পরে ফেরত এসেছে, অথচ ব্যাংকে টাকাটা রয়ে গেছে।');

        $this->assertEqualsCanonicalizing(['cheque:cleared', 'cheque:bounced'], $this->sourcesOf($cheque),
            'পাশ আর ফেরত — দুইটা ঘটনাই খাতায় আলাদা করে থাকার কথা।');
    }

    public function test_a_cleared_cheque_cannot_be_cancelled(): void
    {
        /* ⓘ পাশ হওয়া চেক "ছেঁড়া" যায় না — টাকা ফেরত এলে সেটা ফেরত, বাতিল নয় */
        $cheque = app(ChequeService::class)->clear($this->receive('5000'));

        $this->expectException(ValidationException::class);

        app(ChequeService::class)->cancel($cheque, 'ভুল করে');
    }

    // ── ৪ · পুরনো চেক — আগের নিয়মে ১১০৪-এ বসা ────────────────────────

    public function test_an_old_cheque_clears_out_of_1104_and_the_debt_falls_only_once(): void
    {
        $dueBefore = $this->due();

        $old = $this->receiveTheOldWay('30000');

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '30000', 2),
            'প্রস্তুতিটাই ভুল — পুরনো চেকের দাখিলা বসেনি।');

        app(ChequeService::class)->clear($old);

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '30000', 2),
            '⛔ পুরনো চেক পাশ হতেই বকেয়া দ্বিতীয়বার কমেছে — টাকা এসেছে একবার।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'পুরনো চেক পাশ হওয়ার পরেও ১১০৪-এ পড়ে আছে।');

        $this->assertSame(0, bccomp($this->bankBalance(), '30000', 2));
    }

    public function test_an_old_and_a_new_cheque_cleared_on_the_same_day_each_take_their_own_road(): void
    {
        $dueBefore = $this->due();

        $old = $this->receiveTheOldWay('30000');
        $new = $this->receive('20000');

        app(ChequeService::class)->clear($old);
        app(ChequeService::class)->clear($new);

        $this->assertSame(0, bccomp($this->bankBalance(), '50000', 2),
            'একই দিনে দুইটা চেক পাশ হলো, ব্যাংকে ৫০,০০০ ঢোকেনি।');

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '50000', 2),
            'দুইটা চেক মিলিয়ে বকেয়া ঠিক ৫০,০০০ কমার কথা — একটা দুইবার গোনা হয়েছে বা একটা বাদ পড়েছে।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'দুইটা পাশের পরে ১১০৪ শূন্য নয় — একটা চেক ভুল পথে গেছে।');
    }

    public function test_an_old_cheque_bounced_after_clearing_leaves_everything_as_before(): void
    {
        $dueBefore = $this->due();

        $old = app(ChequeService::class)->clear($this->receiveTheOldWay('30000'));
        app(ChequeService::class)->bounce($old, 'ব্যাংক টাকা ফিরিয়ে নিয়েছে');

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2), 'পুরনো চেক ফেরতের পরে বকেয়া ফেরেনি।');
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2), 'পুরনো চেক ফেরতের পরে ব্যাংকে টাকা রয়ে গেছে।');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2));
    }

    public function test_an_old_cheque_bounced_before_clearing_still_restores_the_debt(): void
    {
        $dueBefore = $this->due();

        $old = $this->receiveTheOldWay('30000');
        app(ChequeService::class)->bounce($old, 'সই মেলেনি');

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2),
            'পুরনো চেক ফেরত এল, বকেয়া আগের জায়গায় ফেরেনি — ১১০৪-এর দাখিলা উল্টায়নি।');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2));
    }

    public function test_an_old_counter_voucher_cheque_bounced_after_clearing_leaves_everything_as_before(): void
    {
        /*
         * ⓘ পুরনো কাউন্টারের চেক: রসিদ ভাউচার (Dr ১১০৪ / Cr ডিলার) আর
         * `voucher_id` সহ রেজিস্টারের সারি — হুবহু লাইভে যেভাবে আছে।
         */
        $dueBefore = $this->due();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString()],
            [
                ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => '15000', 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => '15000',
                    'party_type' => 'customer', 'party_id' => $this->dealer->id],
            ],
        );
        app(VoucherService::class)->post($voucher);

        $cheque = app(ChequeService::class)->record([
            'amount' => '15000', 'cheque_no' => 'OLD-V-1', 'cheque_date' => now()->toDateString(),
            'bank_name' => 'Sonali Bank', 'party_type' => 'customer', 'party_id' => $this->dealer->id,
            'voucher_id' => $voucher->id,
        ]);

        app(ChequeService::class)->clear($cheque, $this->bank->id);

        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '15000', 2),
            'প্রস্তুতি বা পাশ ভুল — পুরনো ভাউচার-চেকে বকেয়া ঠিক একবার কমার কথা।');

        app(ChequeService::class)->bounce($cheque->fresh(), 'ব্যাংক টাকা ফিরিয়ে নিয়েছে');

        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2), 'পুরনো ভাউচার-চেক পাশের পরে ফেরত — বকেয়া ফেরেনি।');
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2), 'পুরনো ভাউচার-চেক পাশের পরে ফেরত — ব্যাংকে টাকা রয়ে গেছে।');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2));
    }

    public function test_an_old_collection_cheque_bounced_after_clearing_leaves_everything_as_before(): void
    {
        /*
         * ⓘ পুরনো আদায়ের কাগজের চেক: আদায় ১১০৪-এ (Dr ১১০৪ / Cr ডিলার), আর
         * `collection_id` সহ রেজিস্টারের সারি। ফেরত যায় Sales-এর দরজা দিয়ে।
         */
        $dueBefore = $this->due();

        $collection = app(CollectionService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(), 'amount' => '8000',
                'account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'allows_holding' => true],
            [],
        );
        $collection = app(CollectionService::class)->confirm($collection);

        $cheque = app(ChequeService::class)->record([
            'amount' => '8000', 'cheque_no' => 'OLD-C-1', 'cheque_date' => now()->toDateString(),
            'bank_name' => 'Sonali Bank', 'party_type' => 'customer', 'party_id' => $this->dealer->id,
            'collection_id' => $collection->id,
        ]);

        app(ChequeService::class)->clear($cheque, $this->bank->id);

        app(CollectionService::class)->bounceReceivedCheque($cheque->fresh(), 'ব্যাংক টাকা ফিরিয়ে নিয়েছে');

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status);
        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2), 'পুরনো আদায়-চেক পাশের পরে ফেরত — বকেয়া ফেরেনি।');
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2), 'পুরনো আদায়-চেক পাশের পরে ফেরত — ব্যাংকে টাকা রয়ে গেছে।');
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'পুরনো আদায়-চেক পাশের পরে ফেরত — ১১০৪ শূন্যে ফেরেনি।');
    }

    public function test_only_a_collection_cheque_may_be_marked_bounced_from_sales(): void
    {
        /* ⛔ নতুন নিয়মের চেকে ডাকলে পাশ-ফেরত ১১০৪ ধরে নিত, আর ১১০৪ ঋণাত্মক হত */
        $cheque = app(ChequeService::class)->clear($this->receive('3000'));

        $this->assertRefusedOn('status', fn () => app(ChequeService::class)->markBounced($cheque, 'ভুল পথ'));

        $this->assertSame(Cheque::CLEARED, $cheque->fresh()->status);
        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2));
    }

    // ── ৫ · চেক ঢোকার একমাত্র দরজা ─────────────────────────────────────

    public function test_a_receipt_voucher_refuses_a_cheque(): void
    {
        /*
         * ⛔ আগে রসিদ ভাউচারে "চেক" বাছলে টাকা সেদিনই সরাসরি ব্যাংকে বসত,
         * রেজিস্টারে কোনো সারি ছাড়া — ফেরত লেখার কোনো উপায়ও থাকত না।
         */
        $this->assertRefusedOn('instrument', fn () => app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'instrument' => 'cheque'],
            $this->receiptLines('1000'),
        ));
    }

    public function test_an_old_receipt_draft_with_a_cheque_cannot_be_posted(): void
    {
        /*
         * ⚠️ নিয়মের আগের খসড়া — পোস্টের মুহূর্তেই টাকা খাতায় বসে, তাই পাহারা ওখানেও।
         *
         * ⓘ লেনদেন নম্বরটা ইচ্ছাকৃত: ওটা ছাড়া ব্যাংকের রসিদ অন্য নিয়মে
         * (ব্যাংক-মিলকরণ) এমনিতেই আটকাত, আর তখন চেকের পাহারা না থাকলেও
         * এই দাবি সবুজ থাকত — মিউট্যান্টে ঠিক এটাই ধরা পড়েছিল।
         */
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'instrument' => 'transfer',
                'instrument_no' => 'RCV-TRX-5001'],
            $this->receiptLines('1000'),
        );
        $voucher->forceFill(['instrument' => 'cheque'])->save();

        $this->assertRefusedOn('instrument', fn () => app(VoucherService::class)->post($voucher->fresh(['lines'])));

        $this->assertSame(0, LedgerEntry::query()->where('account_id', $this->bank->id)->count(),
            'চেকের রসিদ ভাউচার পোস্ট হয়ে ব্যাংকে টাকা বসিয়েছে।');
    }

    public function test_a_payment_voucher_may_still_say_cheque(): void
    {
        /* ⓘ নিয়মটা **গৃহীত** চেকের — নিজের দেওয়া চেকের পথ অপরিবর্তিত */
        $expense = Account::query()->postable()->where('type', Account::EXPENSE)->orderBy('id')->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'instrument' => 'cheque',
                // ⓘ ব্যাংক থেকে টাকা গেলে নম্বর বাধ্যতামূলক — ব্যাংক-মিলকরণের নিয়ম, এই কাজের নয়
                'instrument_no' => 'OUR-CHQ-7001'],
            [
                ['account_id' => $expense->id, 'debit' => '700', 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '700'],
            ],
        );

        $this->assertTrue(app(VoucherService::class)->post($voucher)->isPosted());
    }

    public function test_a_collection_refuses_a_cheque_however_it_is_spelled(): void
    {
        foreach (['cheque', 'Cheque', ' CHQ ', 'চেক'] as $spelling) {
            $this->assertRefusedOn('instrument', fn () => app(CollectionService::class)->create(
                ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(),
                    'amount' => '500', 'instrument' => $spelling],
                [],
            ), "'{$spelling}'");
        }

        /* ⓘ দাবিটা যেন সবকিছুই ফেরাচ্ছে না — নগদ আদায় চলে */
        $cash = app(CollectionService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(),
                'amount' => '500', 'instrument' => 'cash'],
            [],
        );

        $this->assertSame(DocumentStatus::CONFIRMED, app(CollectionService::class)->confirm($cash)->status);
    }

    public function test_a_collection_refuses_a_cheque_method_by_its_name_as_the_counter_sends_it(): void
    {
        /*
         * ⛔ POS এই ঘরে পদ্ধতির **নাম** পাঠায়, কোড নয় ([[PosService]])।
         * ⓘ নামটা ইচ্ছে করে "চেক" শব্দ ছাড়া নয় — কিন্তু কোনো শব্দ-তালিকায়
         * নেই, তাই কেবল পদ্ধতির ধরন দেখেই ধরা যায়।
         */
        PaymentMethod::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'PDC',
            'name_en' => 'Post-dated cheque',
            'name_bn' => 'আগাম তারিখের চেক',
            'kind' => 'cheque',
            'account_id' => $this->bank->id,
            'is_active' => true,
        ]);

        foreach (['Post-dated cheque', 'আগাম তারিখের চেক', 'pdc'] as $said) {
            $this->assertRefusedOn('instrument', fn () => app(CollectionService::class)->create(
                ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(),
                    'amount' => '500', 'instrument' => $said],
                [],
            ), "'{$said}'");
        }
    }

    public function test_an_old_collection_draft_with_a_cheque_cannot_be_confirmed(): void
    {
        $collection = app(CollectionService::class)->create(
            ['customer_id' => $this->dealer->id, 'trx_date' => now()->toDateString(),
                'amount' => '500', 'instrument' => 'cash'],
            [],
        );
        $collection->forceFill(['instrument' => 'cheque'])->save();

        $this->assertRefusedOn('instrument', fn () => app(CollectionService::class)->confirm($collection->fresh()));

        $this->assertSame(DocumentStatus::DRAFT, $collection->fresh()->status);
    }

    // ── ৬ · পর্দার দরজা ──────────────────────────────────────────────────

    public function test_the_screens_follow_the_same_rule_end_to_end(): void
    {
        $dueBefore = $this->due();

        $this->post(route('accounts.cheque.store'), [
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'SCREEN-9',
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '12000',
            'party' => 'customer:'.$this->dealer->id,
            'bank_account_id' => $this->bank->id,
        ])->assertSessionHasNoErrors();

        $cheque = Cheque::query()->where('cheque_no', 'SCREEN-9')->firstOrFail();

        $this->assertSame(0, $this->entriesFor($cheque), 'পর্দা থেকে তোলা চেক সেদিনই খাতায় বসেছে।');

        $this->from(route('accounts.cheque.index'))
            ->post(route('accounts.cheque.clear', $cheque))
            ->assertSessionHasNoErrors();

        $this->assertSame(Cheque::CLEARED, $cheque->fresh()->status);
        $this->assertSame(0, bccomp(bcsub($dueBefore, $this->due(), 4), '12000', 2));

        $this->from(route('accounts.cheque.index'))
            ->post(route('accounts.cheque.bounce', $cheque), ['bounce_reason' => 'ব্যাংক ফিরিয়েছে'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Cheque::BOUNCED, $cheque->fresh()->status);
        $this->assertSame(0, bccomp($this->due(), $dueBefore, 2));
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2));
    }

    public function test_the_screen_refuses_a_received_cheque_without_a_party(): void
    {
        $this->from(route('accounts.cheque.index'))->post(route('accounts.cheque.store'), [
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'SCREEN-NOPARTY',
            'cheque_date' => now()->toDateString(),
            'amount' => '12000',
            'bank_account_id' => $this->bank->id,
        ])->assertSessionHasErrors('party');

        $this->assertSame(0, Cheque::query()->where('cheque_no', 'SCREEN-NOPARTY')->count());
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function receive(string $amount): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'N'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    /**
     * আগের নিয়মে তোলা চেক — হুবহু লাইভে যেভাবে আছে।
     *
     * ⓘ রেজিস্টারের সারি, আর তার নিজের নামে (`cheque`) Dr ১১০৪ / Cr গ্রাহক।
     * আগের `create()` ঠিক এই দাখিলাটাই বসাত।
     */
    private function receiveTheOldWay(string $amount): Cheque
    {
        $cheque = $this->receive($amount);

        app(PostingEngine::class)->post(
            sourceType: Cheque::STOCK_SOURCE,
            sourceId: $cheque->id,
            trxDate: $cheque->received_on,
            lines: [
                ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => $amount],
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => $amount,
                    'party_type' => 'customer', 'party_id' => $this->dealer->id],
            ],
            documentNo: $cheque->document_no,
        );

        return $cheque->fresh();
    }

    /** @return list<array<string, string|int>> */
    private function receiptLines(string $amount): array
    {
        return [
            ['account_id' => $this->bank->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '0', 'credit' => $amount,
                'party_type' => 'customer', 'party_id' => $this->dealer->id],
        ];
    }

    private function walled(string $adding): bool
    {
        try {
            app(CreditExposure::class)->assertRoom($this->dealer->fresh(), $adding);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    private function assertRefusedOn(string $field, callable $work, string $case = ''): void
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), "{$case} ফেরানো হয়েছে, কিন্তু অন্য ঘরে: "
                .implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail("{$case} চেক ঢুকে গেছে — চেকের খাতা ছাড়া অন্য দরজা দিয়ে।");
    }

    private function entriesFor(Cheque $cheque): int
    {
        return LedgerEntry::query()
            ->where('source_id', $cheque->id)
            ->where('source_type', 'like', Cheque::STOCK_SOURCE.'%')
            ->count();
    }

    /** @return list<string> */
    private function sourcesOf(Cheque $cheque): array
    {
        return LedgerEntry::query()
            ->where('source_id', $cheque->id)
            ->where('source_type', 'like', Cheque::STOCK_SOURCE.'%')
            ->pluck('source_type')->unique()->values()->all();
    }

    private function due(): string
    {
        return (string) (LedgerEntry::query()
            ->where('party_type', 'customer')->where('party_id', $this->dealer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');
    }

    private function bankBalance(): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }

    private function balanceOf(string $code): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }
}
