<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PricingRule;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দামের সতর্কতা নিজের শতাংশ বলে — মালিকের ছবি, ৩ অক্টোবর ২০২৬ (কাউন্টার: "দরটা মান দাম থেকে % এর বেশি সরে গেছে")।
 *
 * ⛔ সীমা ০ বসানো থাকলে লেখার শূন্য-কাটা (`rtrim`) পুরো "0.0000"-ই কেটে ফেলত, আর বাক্যে কেবল "%" থাকত। এখন "0%"।
 */
final class ThePriceWarningNamesItsPercentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_zero_tolerance_reads_zero_percent(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $settings = app(SettingsService::class);
        $settings->set(PricingRule::POLICY, PricingRule::WARN);
        $settings->set(PricingRule::TOLERANCE, 0);
        $settings->set(PricingRule::BELOW, true);
        $settings->set(PricingRule::ABOVE, true);
        $settings->flush();

        $product = Product::query()->orderBy('id')->firstOrFail();
        $product->forceFill(['sale_price' => '1000'])->save();

        app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
        ], [['product_id' => $product->id, 'qty' => '1', 'rate' => '990']]);

        $warning = implode(' ', (array) session('price_warnings', []));

        $this->assertNotSame('', $warning, 'প্রস্তুতিটাই ভুল — সীমা ০, দর সরেছে, অথচ সতর্কতা আসেনি।');
        $this->assertStringContainsString('0%', $warning, "⛔ শতাংশটা হারিয়েছে — লেখা: {$warning}");
    }
}
