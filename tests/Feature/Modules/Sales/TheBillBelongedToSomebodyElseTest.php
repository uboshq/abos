<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * সার্ভার কেবল তাঁর খোলা কাগজ পাঠায় (`sales.collection.open_items`)। তাই দাবি দুইটা: গ্রাহকের ঘরের বদল সত্যিই শোনা হয়
     * (ঘরটা ফর্মের নিজের `x-data`-র ভেতরে), আর সার্ভারের উত্তরে অন্যের বিল আসে না।
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

        $html = (string) $this->actingAs($this->user)
            ->get(route('sales.collection.create'))->getContent();

        /*
         * ── শোনার ঘরটা যেন সত্যিই শোনে ───────────────────────────────
         * প্রথম সারাইয়ের পর `@change` markup-এ ছিল, পরীক্ষাও সবুজ ছিল, আর তবু তালিকা খালিই থাকত — ঘরটার উপরে কোনো `x-data`
         * ছিল না। ⓘ এখন ঘরটা ফর্মের `collectionForm`-এর ভেতরে, আর বদলে `pickCustomer` ডাকে।
         */
        $form = strpos($html, 'x-data="collectionForm(');
        $select = null;

        if (preg_match('/<select[^>]*name="customer_id"[^>]*>/s', $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $select = $m[0];
        }

        $this->assertNotFalse($form, 'টাকা নেওয়ার ফর্মে `collectionForm` নেই।');
        $this->assertNotNull($select, 'গ্রাহকের ঘরটাই পাওয়া যায়নি।');
        $this->assertGreaterThan($form, $select[1], 'গ্রাহকের ঘর ফর্মের `x-data`-র বাইরে — বদলটা কেউ শোনে না।');
        $this->assertStringContainsString('pickCustomer($event.target.value)', $select[0],
            'গ্রাহক বদলালে তাঁর খোলা কাগজ আনার ডাক নেই — আগের গ্রাহকের বিল থেকে যেত।');

        $items = collect($this->getJson(route('sales.collection.open_items', ['customer' => $mine->id]))->assertOk()->json('items'));

        $this->assertTrue($items->contains('id', $own->public_id), 'নিজের খোলা বিলটাই আসেনি।');
        $this->assertFalse($items->contains('id', $foreign->public_id), '⛔ অন্য গ্রাহকের বিল এই গ্রাহকের তালিকায় — "বিলটা অন্য গ্রাহকের" আবার।');
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
     */
    public function test_the_server_still_refuses_a_bill_that_is_not_this_customers(): void
    {
        $a = app(CustomerService::class)->create([
            'name_en' => 'Customer A', 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $b = app(CustomerService::class)->create([
            'name_en' => 'Customer B', 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $product = Product::query()->firstOrFail();

        $invoice = app(SalesInvoiceService::class)->create(
            ['customer_id' => $a->id, 'warehouse_id' => $warehouse->id, 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '1', 'rate' => '100']],
        );

        $response = $this->actingAs($this->user)->post(route('sales.collection.store'), [
            'customer_id' => $b->id,
            'account_id' => $this->cashAccountId(),
            'trx_date' => now()->toDateString(),
            'amount' => '100.00',
            'lines' => [['sales_invoice_id' => $invoice->id, 'amount' => '100.00']],
        ]);

        $response->assertSessionHasErrors();
    }

    private function cashAccountId(): int
    {
        return (int) Account::query()
            ->postable()
            ->where('name_en', 'like', '%Cash%')
            ->value('id');
    }
}
