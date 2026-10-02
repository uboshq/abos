<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * নগদের পূর্বাভাস প্রতিটা শোধ হওয়া বিলও তুলত — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ২ অক্টোবর ২০২৬।
 *
 * ⓘ মেয়াদোত্তীর্ণ সব কাগজ "এখন" ঘরে আসে, তাই আগে কোম্পানির প্রতিটা পুরনো পোস্ট করা বিল মেমরিতে উঠত — শোধ
 * হয়ে যাওয়াগুলোও। যোগফলে শূন্য দিত বলে সংখ্যা ঠিক থাকত, কিন্তু বছর গড়ালে পাতাটাই থামত।
 * ⭐ দাবি: যা তোলা হয় তার প্রতিটায় বাকি আছে, আর বাকি থাকা কোনো কাগজ বাদ পড়ে না (পূর্বাভাসের সংখ্যা বদলায়নি)।
 */
final class TheForecastLoadedEveryPaidBillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_only_papers_with_money_still_due_are_loaded_and_none_is_missed(): void
    {
        $allInvoices = SalesInvoice::query()->posted()->withCollected()->get();
        $allBills = PurchaseBill::query()->posted()->withPaid()->get();

        $dueInvoices = $allInvoices->filter(fn ($i) => bccomp($i->dueAmount(), '0', 4) > 0)->count();
        $dueBills = $allBills->filter(fn ($b) => bccomp($b->dueAmount(), '0', 4) > 0)->count();

        $loaded = ['invoice' => [], 'bill' => []];
        Event::listen('eloquent.retrieved: '.SalesInvoice::class, function (SalesInvoice $i) use (&$loaded): void {
            $loaded['invoice'][] = $i->dueAmount();
        });
        Event::listen('eloquent.retrieved: '.PurchaseBill::class, function (PurchaseBill $b) use (&$loaded): void {
            $loaded['bill'][] = $b->dueAmount();
        });

        $forecast = app(CashForecast::class)->build(now()->addYears(5));

        foreach ($loaded as $kind => $dues) {
            foreach ($dues as $due) {
                $this->assertGreaterThan(0, bccomp($due, '0', 4), "⛔ পূর্বাভাস শোধ হয়ে যাওয়া {$kind} তুলেছে — পুরনো সব বিল মেমরিতে।");
            }
        }

        // ⓘ পাঁচ বছর পরের দিন থেকে দেখা — সব কাগজই মেয়াদোত্তীর্ণ, তাই বাকি থাকা প্রতিটা "এখন" ঘরে
        $this->assertCount($dueInvoices, $loaded['invoice'], '⛔ বাকি থাকা কোনো বিক্রয়-বিল পূর্বাভাসে আসেনি।');
        $this->assertCount($dueBills, $loaded['bill'], '⛔ বাকি থাকা কোনো ক্রয়-বিল পূর্বাভাসে আসেনি।');
        $this->assertNotEmpty($forecast['rows']);
    }
}
