<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFacts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ অনুমোদনের অপেক্ষায় বিক্রয় বিল থাকলে হোম ৫০০ — ওয়েবের মান ধাপ ২-এর ছবি তুলতে গিয়ে ধরা (২০ অক্টোবর ২০২৬)।
 *
 * ⓘ [[ApprovalFacts::about()]] খালি বিবরণে বিলের প্রথম পণ্যের নাম পড়ে, কিন্তু `lines` আর `product` প্রতিটা কাগজে আলাদা
 * করে টানা হত (N+1)। স্থানীয় পরিবেশে অলস-টানা নিষেধ ([[AppServiceProvider]]), তাই সেখানে হোম, অনুমোদনের ড্যাশবোর্ড আর
 * ইনবক্স ভেঙে পড়ত; লাইভে নীরবে বাড়তি কোয়েরি।
 *
 * দাবি: অলস-টানা বন্ধ রেখেও অপেক্ষমাণ বিক্রয় বিলের তথ্য আসে, আর "কী বাবদ"-এ বিলের পণ্যের নাম।
 */
final class TheFactsLoadTheLinesProductsAtOnceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_a_waiting_sales_bill_reads_its_product_without_a_lazy_load(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        /*
         * ⓘ দুই পণ্যের দুই লাইন — Laravel অলস-টানা ধরে কেবল একাধিক মডেলের সংগ্রহে; এক লাইনের বিলে `product` চুপচাপ টানা হত,
         * আর eager-load তুলে দিলেও দাবি সবুজ থাকত (Mac, ২১ অক্টোবর ২০২৬: মিউট্যান্ট m_no_products বেঁচে ছিল)।
         */
        [$product, $second] = Product::query()->whereNull('tax_id')->orderBy('id')->limit(2)->get()->all();
        $this->assertNotNull($second, 'প্রস্তুতিটাই ভুল — ডেমোতে কর ছাড়া দুইটা পণ্য নেই।');
        $sales = app(SalesInvoiceService::class);
        $invoice = $sales->create(
            ['customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()],
            [['product_id' => $product->id, 'qty' => '2', 'rate' => '500'], ['product_id' => $second->id, 'qty' => '1', 'rate' => '300']],
        );
        $this->assertSame(2, $invoice->lines()->count(), 'প্রস্তুতিটাই ভুল — বিলে দুই লাইন নেই।');
        $approval = Approval::query()->create([
            'company_id' => $company->id, 'approvable_type' => $invoice::class, 'approvable_id' => $invoice->id,
            'module' => 'sales', 'action' => 'invoice', 'amount' => '1000', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $owner->id, 'requested_at' => now(),
        ]);

        Model::preventLazyLoading(true);
        $facts = app(ApprovalFacts::class)->of(collect([$approval->fresh()]), false);

        $this->assertArrayHasKey($approval->id, $facts, '⛔ অপেক্ষমাণ বিলের তথ্যই আসেনি।');
        $this->assertStringContainsString($product->name(), (string) ($facts[$approval->id]['about'] ?? ''),
            '⛔ "কী বাবদ"-এ বিলের পণ্যের নাম নেই।');
    }
}
