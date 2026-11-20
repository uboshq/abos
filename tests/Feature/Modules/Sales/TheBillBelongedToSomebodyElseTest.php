<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CollectionInput;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * আদায়ের পর্দায় বিলের তালিকাটা কার।
 *
 * ── কী ঘটেছিল, ২৯ আগস্ট ২০২৬ ─────────────────────────────────────────
 * মালিক বললেন সব গ্রাহকের নামে বিক্রি করে টাকা নিতে। বত্রিশ জনের মধ্যে
 * একত্রিশ বার ফেরত এল **"বিলটা অন্য গ্রাহকের।"**
 *
 * পর্দার বিলের তালিকায় ছিল **সব গ্রাহকের সব খোলা বিল**, আর গ্রাহক
 * বদলালেও তালিকাটা বদলাত না। সার্ভার ঠিকই আটকাত — কিন্তু আটকানোটা
 * শেষ পর্দা, প্রথম নয়। ভুল পছন্দটা আগে দেখানোই উচিত নয়।
 *
 * ── কেন সার্ভারের বাধাটা যথেষ্ট প্রমাণ নয় ────────────────────────────
 * ওই বাধাটা আগে থেকেই ছিল, আর তার জন্য পরীক্ষাও ছিল। তবু ডিপোর লোক
 * একত্রিশবার ভুল করতেন, কারণ পর্দা তাঁকে ভুলটাই দিচ্ছিল। "সার্ভার
 * ধরে ফেলে" আর "মানুষ ভুল করতে পারে না" — দুইটা আলাদা দাবি, আর
 * এতদিন কেবল প্রথমটার পরীক্ষা ছিল।
 */
class TheBillBelongedToSomebodyElseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * পর্দাটা বিলগুলোকে **গ্রাহকের নম্বরসহ** পাঠায়।
     *
     * ── কেন দাবিটা এভাবে লেখা ────────────────────────────────────────
     * ছাঁকাটা ব্রাউজারে হয়, আর PHPUnit ব্রাউজার চালায় না। কিন্তু
     * ছাঁকার **কাঁচামাল** সার্ভার থেকেই যায়: প্রতিটা বিলের সাথে তার
     * গ্রাহক। ওই জোড়াটা না গেলে ব্রাউজারে ছাঁকা অসম্ভব — কেউ
     * `customer_id` বাদ দিলে তালিকা আবার সবার হয়ে যেত, আর ঠিক সেই
     * দিনটাই ফিরে আসত।
     */
    /**
     * ⓘ টাকা নেওয়ার নকশা ১০০-এর পর্দা (a6, ২১ অক্টোবর ২০২৬): বিলের তালিকা আর পাতায় সবার বিল নিয়ে আসে না — গ্রাহক বাছলে
     * সার্ভার কেবল তাঁর খোলা কাগজ পাঠায়।
     *
     * ⓘ ২০ নভেম্বর ২০২৬ — মালিকের চূড়ান্ত সিদ্ধান্ত ("one money-in paper"): টাকা নেওয়া হয় কেবল রসিদ ভাউচারে; আদায়ের ফর্ম আর
     *   আঁকা হয় না (তাই `pickCustomer`-এর শোনার ঘরের দাবি গেল), তার খোলা কাগজের দরজা (`sales.collection.open_items`) ৪২২ দেয়।
     *   দাবিটা এখন রসিদের পর্দার উৎসে (`accounts.voucher.due` → [[SalesPartyOpenBills]]): এই গ্রাহকের বিল আসে, অন্যের আসে না।
     *   আর পুরনো ঠিকানা রসিদেই পাঠায়।
     */
    public function test_the_screen_is_told_which_customer_each_open_bill_belongs_to(): void
    {
        $mine = app(CustomerService::class)->create([
            'name_en' => 'Mine', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
        $theirs = app(CustomerService::class)->create([
            'name_en' => 'Theirs', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
        $own = $this->confirmedBill($mine->id, 'INV-MINE-1');
        $foreign = $this->confirmedBill($theirs->id, 'INV-THEIRS-1');

        $this->actingAs($this->user)->get(route('sales.collection.create'))
            ->assertRedirect(route('accounts.voucher.create', ['type' => 'receipt']));
        $this->assertSame(422, $this->getJson(route('sales.collection.open_items', ['customer' => $mine->id]))->status(),
            '⛔ পুরনো আদায়ের খোলা কাগজের দরজা এখনো উত্তর দেয়।');

        $bills = collect($this->getJson(route('accounts.voucher.due', ['party_type' => 'customer', 'party_id' => $mine->id]))
            ->assertOk()->json('bills'));

        $this->assertTrue($bills->contains('id', (int) $own->id), 'নিজের খোলা বিলটাই রসিদের তালিকায় আসেনি।');
        $this->assertFalse($bills->contains('id', (int) $foreign->id), '⛔ অন্য গ্রাহকের বিল এই গ্রাহকের রসিদের তালিকায় — "বিলটা অন্য গ্রাহকের" আবার।');
    }

    private function confirmedBill(int $customerId, string $no): \App\Modules\Sales\Models\SalesInvoice
    {
        return \App\Modules\Sales\Models\SalesInvoice::query()->create(['document_no' => $no, 'customer_id' => $customerId,
            'branch_id' => CompanyContext::branchId(), 'trx_date' => now()->subDays(2)->toDateString(),
            'due_on' => now()->addDays(10)->toDateString(), 'subtotal' => '100', 'discount' => '0', 'tax' => '0', 'total' => '100',
            'status' => \App\Core\Support\DocumentStatus::CONFIRMED]);
    }

    /**
     * আর সার্ভারের বাধাটা আগের মতোই থাকে।
     *
     * পর্দার ছাঁকনি সুবিধা, প্রহরী নয়। কেউ সরাসরি অনুরোধ পাঠালে —
     * বা ব্রাউজারে JS বন্ধ থাকলে — টাকাটা যেন ভুল বিলে না বসে।
     *
     * ⓘ ২০ নভেম্বর ২০২৬ — মালিকের চূড়ান্ত সিদ্ধান্ত ("one money-in paper"): ওয়েবের আদায়ের দরজা আর কিছুই লেখে না, তাই দাবি
     *   তিন জায়গায়: (১) পুরনো দরজা ফেরায়, কিছু লেখে না; (২) টাকা নেওয়ার আসল দরজা — রসিদ ভাউচার — অন্যের বিলে বাঁধা ফেরায়
     *   (`against_wrong_party`); (৩) আদায়ের সেবা (কাউন্টারের POS এখনো ডাকে) নিজেও অন্যের বিল ফেরায়।
     */
    public function test_the_server_still_refuses_a_bill_that_is_not_this_customers(): void
    {
        $this->actingAs($this->user);

        $a = app(CustomerService::class)->create([
            'name_en' => 'Customer A', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
        // ⓘ বিলটা পাকা হতে ঋণসীমা লাগে (সীমা অলঙ্ঘ্য) — প্রস্তুতি কেবল
        $a->forceFill(['credit_limit' => '1000000000'])->save();

        $b = app(CustomerService::class)->create([
            'name_en' => 'Customer B', 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();

        $invoice = app(SalesInvoiceService::class)->confirm(app(SalesInvoiceService::class)->create(
            ['customer_id' => $a->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '100']],
        ));

        $form = [
            'customer_id' => $b->id,
            'account_id' => $this->cashAccountId(),
            'trx_date' => now()->toDateString(),
            'amount' => '100.00',
            'lines' => [['sales_invoice_id' => $invoice->id, 'amount' => '100.00']],
        ];

        // (১) পুরনো দরজা
        $this->post(route('sales.collection.store'), $form)->assertSessionHasErrors('collection');
        $this->assertSame(0, Collection::query()->where('customer_id', $b->id)->count(), '⛔ পুরনো আদায়ের দরজা কাগজ লিখল।');

        // (২) রসিদ ভাউচার — B-র নামে A-র বিল
        $vouchers = Voucher::query()->count();
        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'narration' => 'অন্যের বিলে টাকা',
            'amount' => '100.00',
            'party_type' => 'customer',
            'party_id' => $b->id,
            'from_account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id,
            'to_account_id' => $this->cashAccountId(),
            'against_type' => SalesInvoice::drillSourceType(),
            'against_id' => $invoice->id,
        ])->assertSessionHasErrors('against_id');
        $this->assertSame($vouchers, Voucher::query()->count(), '⛔ অন্যের বিলে বাঁধা রসিদ লেখা হয়ে গেল।');

        // (৩) সেবা নিজে
        try {
            app(CollectionService::class)->createFrom(CollectionInput::fromRequest($form));
            $this->fail('⛔ আদায়ের সেবা অন্য গ্রাহকের বিলে টাকা বসাল।');
        } catch (ValidationException) {
            $this->assertSame(0, Collection::query()->where('customer_id', $b->id)->count());
        }
    }

    private function cashAccountId(): int
    {
        return (int) Account::query()
            ->postable()
            ->where('name_en', 'like', '%Cash%')
            ->value('id');
    }
}
