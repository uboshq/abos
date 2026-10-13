<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleOverview;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ কাউন্টারের সারাংশ বিলে যোগ করা ভাড়া বাদ দিত — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা ([[DirectSaleOverview]])।
 *
 * ⓘ "আমরা দিই, বিলে যোগ" হলে বিলের মোটে ভাড়া বসে ([[DirectSaleService::billFigures()]]), অথচ সারাংশের মোট আর তার সীমার হিসাব
 * ভাড়া ছাড়া — সীমার ঠিক নিচে থাকা বিলে সারাংশ "জায়গা আছে" বলত, দরজা আটকাত।
 */
final class TheCounterSummaryCarriesTheFareOnTheBillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fare_added_to_the_bill_is_in_the_total_and_the_limit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->create(['code' => 'FARE-1', 'name_en' => 'Fare Traders', 'name_bn' => 'Fare Traders', 'is_active' => true]);
        $customer->forceFill(['credit_limit' => '1000000'])->save();

        $data = [
            'customer_id' => $customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
        ];
        $lines = [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']];

        $without = app(DirectSaleOverview::class)->build($data, $lines)->toArray();
        $bare = $this->strongTotal($without);

        $fare = [...$data, 'fare_paid_by' => 'us_add_to_bill', 'transport_cost' => '150'];

        // ⓘ সীমা ভাড়া-ছাড়া বিলের চেয়ে ১০০ বেশি — ভাড়াসহ ৫০ পার
        $customer->forceFill(['credit_limit' => bcadd($bare, '100', 4)])->save();

        $with = app(DirectSaleOverview::class)->build($fare, $lines)->toArray();

        $this->assertSame(0, bccomp($this->strongTotal($with), bcadd($bare, '150', 4), 2), '⛔ সারাংশের মোটে বিলে যোগ করা ভাড়া নেই: '.json_encode($with['totals']));
        $this->assertTrue($with['blocks'], '⛔ ভাড়াসহ বিল সীমা পার, অথচ সারাংশ "নিশ্চিত হবে না" বলল না');
        $this->assertFalse(app(DirectSaleOverview::class)->build($data, $lines)->toArray()['blocks'], 'ভাড়া ছাড়া বিল সীমার ভিতরে।');
    }

    /** @param  array<string, mixed>  $overview */
    private function strongTotal(array $overview): string
    {
        $amount = (string) (collect($overview['totals'])->firstWhere('strong', true)['amount'] ?? '');
        $digits = strtr($amount, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return bcadd((string) preg_replace('/[^0-9.\-]/', '', $digits) ?: '0', '0', 4);
    }
}
