<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

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
use App\Modules\Sales\Services\PosService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * কাউন্টারে টাকা মানে কেবল নগদ নয়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `PosService` কেবল নগদের খাত চিনত। বিকাশে টাকা নিলেও খাতায় বসত
 * "নগদ" — দিনশেষে ড্রয়ারে কম, খাতায় বেশি, আর গণনায় ঘাটতি দেখাত এমন
 * টাকার জন্য যেটা আসলে বিকাশের অ্যাকাউন্টে নিরাপদে বসে আছে।
 *
 * ── উপায়গুলো সারি, enum নয় ─────────────────────────────────────────
 * প্রতিটা কোম্পানির তালিকা আলাদা: কারও বিকাশ আছে নগদ নেই, কারও দুইটা
 * কার্ড মেশিন দুই ব্যাংকের। কোডে লিখলে নতুন উপায় যোগ করতে ডেভেলপার
 * লাগত।
 */
class MoneyAtTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    private Account $bkash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($user);

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
    }

    private function method(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BKASH',
            'name_en' => 'bKash',
            'name_bn' => 'বিকাশ',
            'account_id' => $this->bkash->id,
            'needs_reference' => true,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** কাউন্টারে একটা বিক্রয়, দেওয়া উপায়ে। */
    private function sell(array $extra = []): array
    {
        return app(PosService::class)->checkout([
            'warehouse_id' => $this->warehouse->id,
            'paid' => '100.00',
            ...$extra,
        ], [
            ['product_id' => $this->product->id, 'qty' => '1', 'rate' => '100.00'],
        ]);
    }

    private function balanceOf(Account $account): string
    {
        return $account->fresh()->balanceOn();
    }

    /**
     * নগদ কোথায় বসে — প্রধান নগদ কাউন্টারের খাতে।
     *
     * ১১০১ "হাতে নগদ" নয়: ওটা একটা মাথা, আর মাথায় বসানো টাকা কোনো
     * ব্যালেন্সে দেখায় না। এই পরীক্ষাটা লিখতে গিয়েই ধরা পড়েছিল যে
     * আদায় ঠিক ওখানেই টাকা বসাচ্ছিল।
     */
    private function cashAccount(): Account
    {
        return app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    // ── টাকাটা সঠিক খাতে যায় ────────────────────────────────────────

    /**
     * বিকাশে নেওয়া টাকা বিকাশের খাতেই বসে।
     *
     * এটাই পুরো কাজটা: আগে ওটা নগদে বসত, আর দিনশেষের গণনা মিথ্যা
     * ঘাটতি দেখাত।
     */
    public function test_money_taken_through_bkash_lands_in_the_bkash_account(): void
    {
        $method = $this->method();

        $this->sell(['payment_method_id' => $method->id, 'reference' => 'CDA7XY9K21']);

        $this->assertSame(0, bccomp($this->balanceOf($this->bkash), '100', 4),
            'বিকাশে নেওয়া টাকা বিকাশের খাতে পৌঁছায়নি।');
    }

    /** আর সেটা নগদের খাতে বসে না — ড্রয়ারে ওটা কোনোদিন আসেইনি। */
    public function test_it_does_not_land_in_cash(): void
    {
        $before = $this->balanceOf($this->cashAccount());

        $this->sell(['payment_method_id' => $this->method()->id, 'reference' => 'CDA7XY9K21']);

        $this->assertSame(0, bccomp($this->balanceOf($this->cashAccount()), $before, 4),
            'বিকাশে নেওয়া টাকা নগদের খাতে বসেছে — গণনায় মিথ্যা ঘাটতি দেখাবে।');
    }

    /**
     * উপায় না বাছলে নগদ, আর সেটাই ঠিক।
     *
     * কাউন্টারে বেশিরভাগ বিক্রয় নগদেই; প্রতিবার বাছতে বলা মানে
     * দ্রুততা নষ্ট করা।
     */
    public function test_choosing_nothing_still_means_cash(): void
    {
        $before = $this->balanceOf($this->cashAccount());

        $this->sell();

        $this->assertSame(0, bccomp(bcsub($this->balanceOf($this->cashAccount()), $before, 4), '100', 4),
            'নগদ বিক্রয়ের টাকা প্রধান নগদ কাউন্টারে পৌঁছায়নি।');
    }

    // ── লেনদেনের নম্বর ──────────────────────────────────────────────

    /** যে উপায়ে নম্বর লাগে, সেখানে নম্বর ছাড়া বিক্রয় হয় না। */
    public function test_a_method_that_needs_a_reference_refuses_without_one(): void
    {
        $method = $this->method();

        $this->expectException(ValidationException::class);

        $this->sell(['payment_method_id' => $method->id]);
    }

    /** নগদে নম্বর চাওয়া হয় না — নগদের কোনো TrxID নেই। */
    public function test_cash_never_asks_for_a_reference(): void
    {
        // ⓘ কোড seed-করা 'CASH' নয় — এই টেস্ট একটা নগদ-*ধরনের* উপায় নিয়ে
        // কাজ করে, ঠিক seed-এর সারিটা নয়। 'CASH' দিলে company+code unique-এ
        // সংঘর্ষ (TDEPOT-এ CASH আগেই আছে), আর firstOrCreate দিলে টেস্টটা
        // seed-এর সারির উপর নির্ভরশীল হয়ে অর্থ নীরবে বদলাত।
        $cash = $this->method([
            'code' => 'CASH2',
            'name_en' => 'Counter Cash',
            'name_bn' => 'কাউন্টার নগদ',
            'account_id' => $this->cashAccount()->id,
            'needs_reference' => false,
        ]);

        $result = $this->sell(['payment_method_id' => $cash->id]);

        $this->assertNotEmpty($result['invoice']->document_no);
    }

    /** নম্বরটা আদায়ের রেকর্ডে থেকে যায় — নাহলে পরে মেলানোর কিছু নেই। */
    public function test_the_reference_is_kept_on_the_collection(): void
    {
        $this->sell(['payment_method_id' => $this->method()->id, 'reference' => 'CDA7XY9K21']);

        $this->assertDatabaseHas('sal_collections', [
            'company_id' => $this->company->id,
            'instrument_no' => 'CDA7XY9K21',
        ]);
    }

    // ── বন্ধ করা উপায় ───────────────────────────────────────────────

    /**
     * বন্ধ করা উপায়ে নতুন বিক্রয় হয় না।
     *
     * কেউ "কার্ড" বন্ধ করলে সেটা আর কাউন্টারে আসা উচিত নয়; পুরনো
     * বিক্রয়গুলোয় অবশ্য থেকেই যায়, কারণ ওগুলো সত্যিই ওভাবে হয়েছিল।
     */
    public function test_a_switched_off_method_cannot_be_used(): void
    {
        $method = $this->method(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->sell(['payment_method_id' => $method->id, 'reference' => 'X1']);
    }

    // ── খাতা মেলে ───────────────────────────────────────────────────

    /** ডেবিট ও ক্রেডিট সমান — উপায় যাই হোক। */
    public function test_the_books_balance_whichever_way_the_money_came(): void
    {
        $this->sell(['payment_method_id' => $this->method()->id, 'reference' => 'CDA7XY9K21']);

        $row = LedgerEntry::query()
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $this->assertSame(0, bccomp((string) $row->d, (string) $row->c, 4),
            "ডেবিট {$row->d}, ক্রেডিট {$row->c} — মিলছে না।");
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** উপায়ের তালিকাটা সেটিংসেই বসে, কোডে নয়। */
    public function test_the_list_of_methods_is_a_settings_screen(): void
    {
        $this->method();

        $this->get(route('master_data.payment_method.index'))
            ->assertOk()
            ->assertSee('বিকাশ');
    }

    /** নতুন একটা উপায় ওখান থেকেই যোগ করা যায়। */
    public function test_a_company_can_add_its_own_method(): void
    {
        $this->post(route('master_data.payment_method.store'), [
            'code' => 'CARD',
            'name_en' => 'Card',
            'name_bn' => 'কার্ড',
            'account_id' => $this->bkash->id,
            'needs_reference' => '1',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('mdm_payment_methods', [
            'company_id' => $this->company->id,
            'code' => 'CARD',
        ]);
    }
}
