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
    public function test_the_screen_is_told_which_customer_each_open_bill_belongs_to(): void
    {
        $mine = app(CustomerService::class)->create([
            'name_en' => 'Mine', 'credit_limit' => 0, 'credit_days' => 0,
        ]);

        $html = (string) $this->actingAs($this->user)
            ->get(route('sales.collection.create'))->getContent();

        $this->assertStringContainsString('customer_id', $html,
            'বিলের তালিকায় গ্রাহকের নম্বর নেই — তাহলে ব্রাউজার ছাঁকবে কী দিয়ে?');

        $this->assertStringContainsString('customer-picked', $html, implode("\n", [
            'গ্রাহক বদলানোর ঘটনাটা আর পাঠানো হয় না।',
            '',
            'ওটা ছাড়া আগের গ্রাহকের বিলটা বাছা অবস্থায় থেকে যায়, আর',
            'সেভ টেপার পর "বিলটা অন্য গ্রাহকের" — ঠিক সেই একত্রিশ বার।',
        ]));

        $this->assertStringNotContainsString('@foreach ($openInvoices', $html);
        $this->assertSame(1, substr_count($html, 'x-for="o in mine"'),
            'বিলের তালিকা আর ছাঁকা তালিকা থেকে আঁকা হচ্ছে না।');

        $this->assertNotNull($mine->id);
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
