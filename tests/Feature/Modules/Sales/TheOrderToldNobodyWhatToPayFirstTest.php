<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Http\Controllers\Api\AuthController;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * অর্ডার পাঠানোর আগে কেউ বলত না আগে কত দিতে হবে — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ⭐ নিয়ম: অর্ডার পাঠানো আটকায় না; পাঠানোর মুহূর্তে দেখায় জমা কত, অনুমোদনের অপেক্ষায় কত, আর ডেলিভারির
 * আগে কত দিতে হবে (সীমা কেউ পার করাতে পারেন না)। আর প্রতিটা লাইনে "কয়টা কিনলে কয়টা ফ্রি"।
 *
 * ⓘ মাপ: বাকি ৪৮,২০০, সীমা ৫০,০০০, অনুমোদনের অপেক্ষায় ১,৫০০; ৪,৮২০ টাকার অর্ডারে দিতে হবে ৩,০২০।
 * সীমার ভেতরের অর্ডারে দিতে হবে ০। অগ্রিম থাকলে "জমা আছে"-তে আসে আর দিতে হয় কম।
 * একই সংখ্যা ওয়েবের দরজা আর ফোনের দরজা — দুই জায়গায়।
 */
final class TheOrderToldNobodyWhatToPayFirstTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        $this->customer = Customer::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => 50000])->saveQuietly();

        $this->product = Product::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    public function test_the_standing_says_what_to_pay_before_delivery_on_web_and_phone(): void
    {
        $this->ledger(debit: 48200);
        $this->claim(1500);

        foreach (['web' => fn () => $this->get(route('sales.order.standing', [$this->customer->id, 'total' => 4820])),
            'phone' => function () {
                Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

                return $this->getJson('/api/v1/sales/standing/'.$this->customer->public_id.'?total=4820');
            }] as $door => $call) {
            $json = $call()->assertOk()->json();

            $this->assertSame(0, bccomp('48200', (string) $json['due'], 4), "{$door}: বাকি ভুল।");
            $this->assertSame(0, bccomp('50000', (string) $json['limit'], 4), "{$door}: সীমা ভুল।");
            $this->assertSame(0, bccomp('1500', (string) $json['pending_claims'], 4), "{$door}: অনুমোদনের অপেক্ষার জমা ভুল।");
            $this->assertSame(0, bccomp('3020', (string) $json['to_pay'], 4), "⛔ {$door}: ডেলিভারির আগে দিতে হবে ৩,০২০ — পেলাম {$json['to_pay']}।");
            $this->assertTrue($json['over_limit'], "{$door}: সীমা পার বলা হয়নি।");
        }

        // সীমার ভেতরের অর্ডার — দিতে হবে ০
        $inside = $this->get(route('sales.order.standing', [$this->customer->id, 'total' => 1000]))->assertOk()->json();
        $this->assertSame(0, bccomp('0', (string) $inside['to_pay'], 4));
        $this->assertFalse($inside['over_limit']);
    }

    public function test_an_advance_shows_as_deposited_and_lowers_what_to_pay(): void
    {
        $this->ledger(credit: 2000);

        $json = $this->get(route('sales.order.standing', [$this->customer->id, 'total' => 51000]))->assertOk()->json();

        $this->assertSame(0, bccomp('2000', (string) $json['advance'], 4), 'অগ্রিম "জমা আছে"-তে আসেনি।');
        $this->assertSame(0, bccomp('0', (string) $json['due'], 4));
        $this->assertSame(0, bccomp('0', (string) $json['to_pay'], 4), '⛔ অগ্রিম ২,০০০ থাকতে ৫১,০০০-এর অর্ডারে সীমা (৫০,০০০) পার হয় না।');
    }

    public function test_each_line_says_buy_x_get_y_and_fills_the_free_box(): void
    {
        $this->buyGetFree(buy: 12, free: 1);

        $lines = $this->postJson(route('sales.order.offers'), [
            'customer' => (string) $this->customer->id,
            'lines' => [['product' => (string) $this->product->id, 'qty' => 12], ['product' => (string) $this->product->id, 'qty' => 5]],
        ])->assertOk()->json('lines');

        $this->assertSame(0, bccomp('1', (string) $lines[0]['free_qty'], 4), '⛔ ১২টায় ১টা ফ্রি বসেনি।');
        $this->assertStringContainsString('12', (string) $lines[0]['offer'], 'অফারের বাক্যে "কয়টা কিনলে" নেই।');
        $this->assertSame(0, bccomp('0', (string) $lines[1]['free_qty'], 4), '৫টায় ফ্রি বসে গেল।');
    }

    public function test_the_doors_need_the_order_key(): void
    {
        $nobody = User::query()->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))
            ->get()->first(fn (User $u) => ! $u->can('sales.order.create'));

        $this->assertNotNull($nobody, 'প্রস্তুতিটাই ভুল — অর্ডারের চাবি নেই এমন কেউ নেই।');

        $this->actingAs($nobody)->get(route('sales.order.standing', [$this->customer->id]))->assertForbidden();
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function ledger(int $debit = 0, int $credit = 0): void
    {
        DB::table('ledger_entries')->insert([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'financial_year_id' => (int) FinancialYear::query()->where('company_id', $this->company->id)->orderByDesc('starts_on')->value('id'),
            'account_id' => (int) Account::query()->where('is_group', false)->orderBy('id')->value('id'),
            'trx_date' => now()->toDateString(),
            'source_type' => 'zq_probe', 'source_id' => 1,
            'party_type' => 'customer', 'party_id' => $this->customer->id,
            'debit' => $debit, 'credit' => $credit,
        ]);
    }

    private function claim(int $amount): void
    {
        DB::table('sal_deposit_claims')->insert([
            'company_id' => $this->company->id, 'branch_id' => $this->customer->branch_id, 'customer_id' => $this->customer->id,
            'claimed_on' => now()->toDateString(), 'amount' => $amount, 'method' => 'bank', 'status' => 'pending',
        ]);
    }

    private function buyGetFree(int $buy, int $free): void
    {
        $c = $this->company->id;
        $promotion = DB::table('promotions')->insertGetId([
            'company_id' => $c, 'public_id' => (string) Str::uuid(), 'code' => 'ZQ-BXGY', 'name_en' => 'Buy and get free',
            'type' => 'buy_x_get_y', 'status' => 'active',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
        ]);
        DB::table('promotion_scopes')->insert(['company_id' => $c, 'promotion_id' => $promotion, 'kind' => 'product', 'target_id' => $this->product->id]);
        $condition = DB::table('promotion_conditions')->insertGetId(['company_id' => $c, 'promotion_id' => $promotion, 'kind' => 'quantity', 'value_from' => $buy]);
        DB::table('promotion_benefits')->insert(['company_id' => $c, 'promotion_id' => $promotion, 'promotion_condition_id' => $condition, 'kind' => 'goods', 'amount' => $free, 'gift_product_id' => $this->product->id]);
    }
}
