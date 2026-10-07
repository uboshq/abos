<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * আবার ছাপা বিল একই কথা বলে — অডিট, ৬ অক্টোবর ২০২৬ (সমন্বয়ক): "আগের বকেয়া" আর শেষ জের আসত গ্রাহকের আজকের মোট
 * পাওনা থেকে, তাই পরের প্রতিটা বিল পুরনো বিলের অঙ্ক বদলে দিত।
 *
 * দাবি — একই গ্রাহক, একই বিল: প্রথম বিল ছাপা; তারপর আরেকটা বিল বসল; প্রথম বিল আবার ছাপলে আগের বকেয়া আর শেষ জের
 * হুবহু আগের মতো; আর দ্বিতীয় বিলের আগের বকেয়া = প্রথম বিলের শেষ জের (খাতা ধরে চলে, লাফ নেই)।
 */
final class AReprintedBillSaysWhatItSaidTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '10000000'])->save();
    }

    public function test_a_later_bill_does_not_change_an_earlier_bills_figures(): void
    {
        $first = $this->bill('100');
        $before = $this->figures($first);

        $second = $this->bill('250');

        $again = $this->figures($first);
        $this->assertSame($before['previous'], $again['previous'], '⛔ পরের বিল বসতেই পুরনো বিলের "আগের বকেয়া" বদলে গেল।');
        $this->assertSame($before['outstanding'], $again['outstanding'], '⛔ পরের বিল বসতেই পুরনো বিলের শেষ জের বদলে গেল।');

        // ⭐ খাতা ধরে চলে — দ্বিতীয় বিলের আগের জের = প্রথম বিলের শেষ জের, আর শেষে = আজকের আসল জের
        $next = $this->figures($second);
        $this->assertSame($before['outstanding'], $next['previous'], '⛔ দ্বিতীয় বিলের আগের বকেয়া প্রথম বিলের শেষ জের নয়।');
        $this->assertSame(0, bccomp($next['outstanding'], $this->customer->fresh()->outstanding(), 4), '⛔ শেষ বিলের শেষ জের খাতার আসল জের নয়।');
    }

    /** @return array{previous: string, outstanding: string} */
    private function figures(SalesInvoice $bill): array
    {
        $facts = (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))
            ->invoke(app(SalesPrintController::class), SalesInvoice::query()->with('customer')->findOrFail($bill->id));

        return [
            'previous' => bcadd(str_replace(',', '', (string) $facts['signed_sums']['previous_due']), '0', 2),
            'outstanding' => bcadd(str_replace(',', '', (string) $facts['signed_sums']['outstanding']), '0', 2),
        ];
    }

    private function bill(string $rate): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);

        return $service->confirm($service->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '1', 'rate' => $rate]],
        ));
    }
}
