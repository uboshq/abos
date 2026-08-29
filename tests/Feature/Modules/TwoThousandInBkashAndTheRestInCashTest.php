<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ২,০০০ বিকাশে, বাকিটা নগদে।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * বাংলাদেশে রোজকার ঘটনা, কারণ বিকাশের ব্যালেন্স গোল অঙ্কে থাকে —
 * ২,৩০০ টাকার বিলে ক্রেতা ২,০০০ পাঠিয়ে বাকি ৩০০ হাতে দেন।
 *
 * এক উপায়ে বাধ্য করলে ক্যাশিয়ার পুরোটা "নগদ" লিখে দিতেন, আর দিনশেষে
 * ড্রয়ারে ২,০০০ কম পড়ত — ঠিক সেই মিথ্যা ঘাটতি, যেটা সারাতেই উপায়ের
 * তালিকাটা বানানো হয়েছিল। অর্ধেক সমাধান পুরো সমস্যাটা ফিরিয়ে আনে।
 */
class TwoThousandInBkashAndTheRestInCashTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    private Account $bkash;

    private PaymentMethod $bkashMethod;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->firstOrFail();

        $this->bkash = Account::query()->create([
            'company_id' => $this->company->id,
            'code' => '1102-BKASH',
            'name_en' => 'bKash Merchant',
            'name_bn' => 'বিকাশ মার্চেন্ট',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'is_bank' => true,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $this->bkashMethod = PaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BKASH', 'name_en' => 'bKash', 'name_bn' => 'বিকাশ',
            'account_id' => $this->bkash->id,
            'needs_reference' => true, 'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'code' => 'CASH', 'name_en' => 'Cash', 'name_bn' => 'নগদ',
            'account_id' => $this->tillAccount()->id,
            'needs_reference' => false, 'is_active' => true,
        ]);
    }

    private function tillAccount(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    private function balanceOf(Account $account): string
    {
        return $account->fresh()->balanceOn();
    }

    /** কাউন্টারে একটা বিক্রয় — দেওয়া দর ও দেওয়া ভাগে। */
    private function sell(string $rate, array $extra = []): array
    {
        return app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            ...$extra,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => $rate],
        ]);
    }

    // ── টাকাটা দুই খাতে যায় ─────────────────────────────────────────

    /**
     * ২,৩০০ টাকার বিল — ২,০০০ বিকাশে, ৩০০ নগদে।
     *
     * এটাই পুরো কাজটা: দুইটা খাতে দুইটা অঙ্ক, আর প্রতিটা নিজের জায়গায়।
     */
    public function test_the_money_lands_in_two_accounts(): void
    {
        $cashBefore = $this->balanceOf($this->tillAccount());

        $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'CDA7XY9K21'],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '300'],
        ]]);

        $this->assertSame(0, bccomp($this->balanceOf($this->bkash), '2000', 4),
            'বিকাশের খাতে ২,০০০ পৌঁছায়নি।');

        $this->assertSame(0, bccomp(bcsub($this->balanceOf($this->tillAccount()), $cashBefore, 4), '300', 4),
            'ড্রয়ারে ৩০০ পৌঁছায়নি।');
    }

    /** প্রতিটা ভাগ আলাদা আদায় — নাহলে খাত ধরে মেলানোর উপায় থাকত না। */
    public function test_each_part_is_its_own_collection(): void
    {
        $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'CDA7XY9K21'],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '300'],
        ]]);

        $this->assertDatabaseHas('sal_collections', ['amount' => '2000.0000', 'instrument_no' => 'CDA7XY9K21']);
        $this->assertDatabaseHas('sal_collections', ['amount' => '300.0000']);
    }

    /** খাতা মেলে — ভাগ যতই হোক। */
    public function test_the_books_still_balance(): void
    {
        $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'CDA7XY9K21'],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '300'],
        ]]);

        $row = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4));
    }

    // ── ফেরত ────────────────────────────────────────────────────────

    /**
     * বেশি নিলে ফেরতটা নগদের ভাগ থেকেই যায়।
     *
     * ── কেন এটা সবচেয়ে সহজে ভুল হয় ─────────────────────────────────
     * সমান ভাগে কাটলে বিকাশের খাতে ১,৯১৩ বসত, অথচ ব্যাংকের বিবরণীতে
     * ২,০০০ — আর মাসের শেষে ওই তফাতটা কেউ মেলাতে পারত না। বিকাশে
     * পাঠানো টাকা ফেরত দেওয়া যায় না; ফেরত সবসময় ড্রয়ার থেকে।
     */
    public function test_change_comes_out_of_the_cash_part(): void
    {
        $cashBefore = $this->balanceOf($this->tillAccount());

        // বিল ২,৩০০; দেওয়া হলো ২,০০০ বিকাশ + ৫০০ নগদ = ২,৫০০, ফেরত ২০০
        $result = $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'CDA7XY9K21'],
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '500'],
        ]]);

        $this->assertSame(0, bccomp($result['change'], '200', 4), "ফেরত {$result['change']}, ২০০ নয়।");

        $this->assertSame(0, bccomp($this->balanceOf($this->bkash), '2000', 4),
            'ফেরতটা বিকাশের খাত থেকে কাটা হয়েছে — ব্যাংকের বিবরণীর সাথে আর মিলবে না।');

        $this->assertSame(0, bccomp(bcsub($this->balanceOf($this->tillAccount()), $cashBefore, 4), '300', 4),
            'ড্রয়ারে ৩০০ থাকার কথা (৫০০ নেওয়া, ২০০ ফেরত)।');
    }

    /**
     * নগদের ভাগ ছাড়া বেশি টাকা নেওয়াই যায় না।
     *
     * ফেরত দেওয়ার কিছু নেই — বিকাশে পাঠানো টাকা হাতে ফেরত দেওয়া যায় না।
     */
    public function test_you_cannot_overpay_without_a_cash_part(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell('2000', ['payments' => [
            ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2500', 'reference' => 'CDA7XY9K21'],
        ]]);
    }

    // ── যোগফল মেলা ──────────────────────────────────────────────────

    /**
     * পর্দার পাঠানো যোগফল ভাগগুলোর সাথে না মিললে বিক্রয় হয় না।
     *
     * পর্দাটা যে সংখ্যাটা দেখিয়েছে সেটাই ক্রেতাকে বলা হয়েছে। নীরবে
     * বদলে দিলে ক্রেতা এক অঙ্ক শুনতেন আর খাতায় বসত আরেকটা।
     */
    public function test_a_split_that_does_not_add_up_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->sell('2300', [
            'paid' => '2300',
            'payments' => [
                ['payment_method_id' => $this->bkashMethod->id, 'amount' => '2000', 'reference' => 'X1'],
                ['payment_method_id' => $this->cashMethod->id, 'amount' => '100'],
            ],
        ]);
    }

    /**
     * সব ভাগ শূন্য মানে বাকিতে বিক্রয় — ভুল নয়।
     *
     * ── কেন এটা আটকানো হয় না ────────────────────────────────────────
     * `paid` খালি রেখে বিক্রয় করা আগে থেকেই চলে, আর সেটাই বাকিতে বেচা।
     * ভাগের ঘরগুলো খালি রাখাও ঠিক একই কথা, তাই আচরণটাও একই হতে হবে।
     * এখানে আটকালে একই জিনিস দুই পথে দুই রকম হত — আর ক্যাশিয়ার বুঝতেন
     * না কেন একবার চলে আর একবার চলে না।
     */
    public function test_all_empty_rows_mean_a_credit_sale(): void
    {
        $result = $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '0'],
            ['payment_method_id' => '', 'amount' => ''],
        ]]);

        $this->assertSame(0, Collection::query()->count(), 'টাকা না নিয়েই একটা আদায় বসেছে।');
        $this->assertSame(0, bccomp((string) $result['invoice']->total, '2300', 2));
    }

    /** খালি সারিগুলো চুপচাপ বাদ যায় — পর্দায় একটা ঘর ফাঁকা থাকা স্বাভাবিক। */
    public function test_an_empty_row_beside_a_real_one_is_ignored(): void
    {
        $this->sell('2300', ['payments' => [
            ['payment_method_id' => $this->cashMethod->id, 'amount' => '2300'],
            ['payment_method_id' => '', 'amount' => ''],
        ]]);

        $this->assertSame(1, Collection::query()->count());
    }

    // ── পুরনো পথটা ভাঙেনি ───────────────────────────────────────────

    /** ভাগ না দিলে আগের মতোই — টিল, ইমপোর্ট, পুরনো পরীক্ষা সবই চলে। */
    public function test_a_plain_single_payment_still_works(): void
    {
        $cashBefore = $this->balanceOf($this->tillAccount());

        $this->sell('500', ['paid' => '500']);

        $this->assertSame(0, bccomp(bcsub($this->balanceOf($this->tillAccount()), $cashBefore, 4), '500', 4));
    }

    // ── ছাড় ─────────────────────────────────────────────────────────

    /**
     * কাউন্টারে দেওয়া ছাড় বিলে বসে।
     *
     * ছাড়ের সীমা ও অনুমোদন আগে থেকেই `SalesInvoiceService`-এ; কাউন্টারে
     * কেবল ঘরটাই ছিল না। এখানে আলাদা কোনো নিয়ম বসানো হয়নি — দুই জায়গায়
     * দুইটা সীমা থাকলে একদিন তারা আলাদা হত।
     */
    public function test_a_discount_given_at_the_counter_reaches_the_bill(): void
    {
        $result = app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '900',
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '1000', 'discount' => '100'],
        ]);

        $this->assertSame(0, bccomp((string) $result['invoice']->total, '900', 2),
            'ছাড়টা বিলের মোট থেকে কাটা হয়নি।');

        $this->assertSame(0, bccomp((string) $result['invoice']->discount, '100', 2));
    }

    /**
     * কাউন্টারের পর্দা খোলা — সুইচটা চালু করে।
     *
     * `sales.screen_pos` ডিফল্টে বন্ধ (ডিপো কাউন্টার ব্যবহার করে না),
     * আর বন্ধ পর্দার রুটও বন্ধ। তাই পর্দার পরীক্ষায় আগে সুইচটা চালু
     * করতে হয় — নাহলে ৪০৪, আর পরীক্ষাটা কিছুই প্রমাণ করত না।
     */
    private function openTheCounter(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', true);
    }

    /** ছাড়ের ঘরটা কাউন্টারের পর্দায় আছে। */
    public function test_the_counter_screen_offers_a_discount_box(): void
    {
        $this->openTheCounter();

        $html = $this->get(route('sales.pos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('[discount]', $html,
            'কাউন্টারে ছাড়ের ঘরটা নেই।');
    }

    // ── কাউন্টার থেকেই ফেরত ─────────────────────────────────────────

    /**
     * বিল ধরে ফেরত — মাল গুদামে ফেরে, পাওনা কমে।
     *
     * ── কেন কাউন্টার থেকেই ─────────────────────────────────────────
     * ক্রেতা দোকানে দাঁড়িয়ে আছেন, হাতে বিল আর মাল। অন্য পর্দায় যেতে
     * বললে ক্যাশিয়ার হয় লাইন থামান, নয় কাগজে টুকে রাখেন — আর ওই
     * কাগজটা রাতে হারায়। মালটা তখন গুদামে ফেরে না।
     */
    public function test_a_bill_can_be_taken_back_at_the_counter(): void
    {
        $sale = $this->sell('1000', ['paid' => '1000']);

        $result = app(PosService::class)->takeBack([
            'document_no' => $sale['invoice']->document_no,
            'warehouse_id' => $this->warehouse->id,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1',
                'sales_invoice_line_id' => $sale['invoice']->lines->first()->id],
        ]);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['return']->status);
        $this->assertSame(0, bccomp((string) $result['return']->total, '1000', 2));
        $this->assertNull($result['refund'], 'টাকা ফেরত চাওয়া হয়নি, তবু ভাউচার বসেছে।');
    }

    /**
     * টাকা ফেরত চাইলে ড্রয়ার থেকে যায়।
     *
     * ── কেন ভাউচার, ঋণাত্মক আদায় নয় ────────────────────────────────
     * ঋণাত্মক একটা আদায় লিখলে "আজ কত আদায় হয়েছে" সংখ্যাটা নীরবে কমে
     * যেত, অথচ ওই টাকাটা কেউ কোনোদিন দেয়ইনি।
     */
    public function test_the_cash_goes_back_out_of_the_drawer(): void
    {
        $sale = $this->sell('1000', ['paid' => '1000']);

        $afterSale = $this->balanceOf($this->tillAccount());

        $result = app(PosService::class)->takeBack([
            'document_no' => $sale['invoice']->document_no,
            'warehouse_id' => $this->warehouse->id,
            'refund' => true,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1',
                'sales_invoice_line_id' => $sale['invoice']->lines->first()->id],
        ]);

        $this->assertNotNull($result['refund'], 'টাকা ফেরতের কোনো কাগজ বসেনি।');

        $this->assertSame(0, bccomp(bcsub($afterSale, $this->balanceOf($this->tillAccount()), 4), '1000', 4),
            'ড্রয়ার থেকে টাকাটা বেরোয়নি।');

        // আদায়ের সংখ্যাটা কমেনি — ফেরত আর না-দেওয়া আলাদা ঘটনা
        $this->assertSame(1, Collection::query()->count());
    }

    /** খসড়া বা অচেনা নম্বরে ফেরত হয় না — যে মাল বেরোয়নি তা ফেরে না। */
    public function test_an_unknown_bill_number_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(PosService::class)->takeBack([
            'document_no' => 'INV-DOES-NOT-EXIST',
            'warehouse_id' => $this->warehouse->id,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1'],
        ]);
    }

    /** বিক্রির চেয়ে বেশি ফেরত নেওয়া যায় না — সীমাটা আগের কোডেই। */
    public function test_you_cannot_take_back_more_than_was_sold(): void
    {
        $sale = $this->sell('1000', ['paid' => '1000']);

        $this->expectException(ValidationException::class);

        app(PosService::class)->takeBack([
            'document_no' => $sale['invoice']->document_no,
            'warehouse_id' => $this->warehouse->id,
        ], [
            ['product_id' => $this->product->id, 'qty' => '5',
                'sales_invoice_line_id' => $sale['invoice']->lines->first()->id],
        ]);
    }

    /** পর্দাটা বলে আর কতটুকু ফেরত নেওয়া যায়। */
    public function test_the_screen_says_how_much_room_is_left(): void
    {
        $this->openTheCounter();

        $sale = $this->sell('1000', ['paid' => '1000']);

        $this->getJson(route('sales.pos.bill', ['no' => $sale['invoice']->document_no]))
            ->assertOk()
            // `Money::quantity()` শূন্য দশমিক ছেঁটে দেয় — "0.000" নয়, "0"
            ->assertJsonPath('lines.0.returned', '0')
            ->assertJsonPath('lines.0.qty', '1');
    }

    // ── কি-বোর্ড ────────────────────────────────────────────────────

    /**
     * দশটা কি-ই বসানো, আর প্রতিটার একটা কাজ আছে।
     *
     * ── কেন "আছে কি না" নয়, "কাজ করে কি না" ─────────────────────────
     * একটা কি যেটা কিছুই করে না, সেটা না থাকার চেয়ে খারাপ: ক্যাশিয়ার
     * চেপে দেখেন কিছু হলো না, আর তারপর বাকিগুলোও আর বিশ্বাস করেন না।
     */
    public function test_every_function_key_is_wired(): void
    {
        $this->openTheCounter();

        $html = $this->get(route('sales.pos.index'))->assertOk()->getContent();

        foreach (['f1', 'f2', 'f3', 'f4', 'f6', 'f7', 'f8', 'f9', 'f10'] as $key) {
            $this->assertStringContainsString("keydown.window.{$key}.prevent=\"", $html,
                strtoupper($key).' কি-টা বসানো নেই।');
        }
    }

    /** কোন কি কী করে, সেটা পর্দাতেই লেখা — নাহলে কি-গুলোর মানে নেই। */
    public function test_the_keys_are_written_down_on_the_screen(): void
    {
        $this->openTheCounter();

        $this->get(route('sales.pos.index'))
            ->assertOk()
            ->assertSee(__('sales::message.pos_keys'))
            ->assertSee(__('sales::message.key_return'))
            ->assertSee(__('sales::message.key_checkout'));
    }

    /**
     * উপায় বাছার ঘরটাও পর্দায় আছে।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ───────────────────────────────────
     * `PosService` বিকাশ/কার্ড আগে থেকেই বুঝত, কিন্তু পর্দায় বাছার কোনো
     * ঘর ছিল না — সুবিধাটা কোডে ছিল, কাউন্টারে ছিল না। যিনি ব্যবহার
     * করবেন তিনি পৌঁছাতে না পারলে জিনিসটা থাকা আর না থাকা সমান।
     */
    public function test_the_counter_screen_offers_the_payment_methods(): void
    {
        $this->openTheCounter();

        $html = $this->get(route('sales.pos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('payments[', $html, 'ভাগ করে দেওয়ার ঘরগুলো নেই।');
        $this->assertStringContainsString('বিকাশ', $html, 'উপায়ের তালিকায় বিকাশ নেই।');
    }
}
