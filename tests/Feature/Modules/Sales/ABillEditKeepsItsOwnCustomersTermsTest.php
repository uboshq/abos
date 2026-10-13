<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খসড়া বিলের সম্পাদনা বিলের নিজের গ্রাহকের বাকির দিন ধরে — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ আগে বকেয়ার তারিখ গোনা হত পাঠানো `customer_id` ধরে, অথচ সম্পাদনা গ্রাহক বদলায় না — বিল থাকত এক গ্রাহকের, বকেয়ার দিন
 * আরেকজনের; মেয়াদোত্তীর্ণ আর আদায়ের রিপোর্ট ভুল তারিখ পড়ত, কোনো ভুল-বার্তা ছাড়াই।
 */
final class ABillEditKeepsItsOwnCustomersTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_due_date_follows_the_bills_own_customer(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        [$own, $other] = Customer::query()->orderBy('id')->take(2)->get()->all();
        $own->forceFill(['credit_days' => 10])->save();
        $other->forceFill(['credit_days' => 60])->save();

        $paper = ['customer_id' => $own->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'trx_date' => now()->toDateString()];
        $lines = [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']];

        $service = app(SalesInvoiceService::class);
        $draft = $service->create($paper, $lines);

        $service->update($draft, ['customer_id' => $other->id] + $paper, $lines);

        $this->assertSame((int) $own->id, (int) $draft->fresh()->customer_id, 'দৃশ্যটাই ভুল — সম্পাদনায় গ্রাহক বদলাল।');
        $this->assertSame(now()->addDays(10)->toDateString(), $draft->fresh()->due_on?->toDateString() ?? (string) $draft->fresh()->due_on,
            '⛔ বকেয়ার তারিখ অন্য গ্রাহকের বাকির দিন (৬০) ধরে বসল।');
    }
}
