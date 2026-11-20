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
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Supplier\Services\LabourCost;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\HiresTheLabour;
use Tests\Concerns\PrintsTheStandardPaper;
use Tests\TestCase;

/**
 * ⭐ চালান আর গেটপাসের কাগজে লোডিং এক লাইনে, ভাড়ার পাশে — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"; ২০ নভেম্বর ২০২৬ দাবি)।
 *
 * ⓘ মালিকের কারণ: গেটে আর গ্রাহকের হাতে কাগজেই দেখা যায় মাল কে তুলল আর কত গেল ([[SalesPrintController::loadingLine()]])।
 *   লেখা না থাকলে ঘরটা খালি — কাগজে বসে না।
 */
final class TheChallanPaperSaysWhoLoadedTheLorryTest extends TestCase
{
    use HiresTheLabour;
    use PrintsTheStandardPaper;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        /* ⓘ সাধারণ কাগজের তথ্য মাপা — নকশা নয় ([[PrintsTheStandardPaper]]) */
        $this->printTheStandardPaper();
    }

    public function test_the_challan_and_the_gatepass_carry_the_loading_line(): void
    {
        $contractor = $this->labourContractor();
        $challan = $this->confirmed(['loading_mode' => 'due', 'loading_amount' => '55', 'loading_contractor_id' => $contractor->id]);
        $expected = app(LabourCost::class)->describe($challan, 'loading_');

        // ⓘ দামসহ চালান (অফিসের কপি) আর গেটপাস — দুটোতেই লোডিং
        foreach (['sales.print.challan' => ['prices' => 1], 'sales.print.gatepass' => []] as $route => $query) {
            $line = (string) ($this->printed($route, $challan, $query)->meta['supplier::labour.loading'] ?? '');

            $this->assertSame($expected, $line, "⛔ {$route}: কাগজে লোডিংয়ের লাইন নেই।");
            $this->assertStringContainsString($contractor->name(), $line, "⛔ {$route}: ঠিকাদারের নাম নেই।");
            $this->assertStringContainsString('55', $line, "⛔ {$route}: লোডিংয়ের অঙ্ক নেই।");
        }
    }

    /** ⛔ দাম ছাড়া চালান গ্রাহকের হাতে যায় — সেখানে লোডিংয়ের অঙ্ক নয় (সমন্বয়ক, ২০ নভেম্বর ২০২৬) */
    public function test_the_customers_copy_without_prices_carries_no_loading_amount(): void
    {
        $contractor = $this->labourContractor();
        $challan = $this->confirmed(['loading_mode' => 'due', 'loading_amount' => '55', 'loading_contractor_id' => $contractor->id]);

        $doc = $this->printed('sales.print.challan', $challan, ['prices' => 0]);

        $this->assertSame('', (string) ($doc->meta['supplier::labour.loading'] ?? ''), '⛔ গ্রাহকের দামহীন কপিতে লোডিং বসল।');
    }

    public function test_a_challan_without_loading_prints_no_loading_line(): void
    {
        $challan = $this->confirmed([]);

        $this->assertSame('', (string) ($this->printed('sales.print.challan', $challan, ['prices' => 1])->meta['supplier::labour.loading'] ?? ''),
            'লোডিং লেখা নেই, তবু চালানের কাগজে লোডিংয়ের লেখা বসল।');
    }

    // ── যন্ত্রপাতি ──

    /** @param  array<string, mixed>  $loading */
    private function confirmed(array $loading): DeliveryChallan
    {
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->create(
            // ⓘ মাল কীভাবে যাবে — ছাপার আগে লাগে ([[RequireTransportBeforePrint]])
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id,
                'trx_date' => now()->toDateString(), 'own_transport' => true] + $loading,
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        );

        return $challans->confirm($challan->fresh(['lines']));
    }

    /** @param  array<string, mixed>  $query */
    private function printed(string $route, DeliveryChallan $challan, array $query = []): \App\Core\Engines\Print\PrintableDocument
    {
        $seen = [];
        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route($route, ['challan' => $challan->id] + $query))->assertOk();
        $this->assertArrayHasKey('doc', $seen, "{$route} সাধারণ কাগজে আঁকা হয়নি — দাবি অন্ধ।");

        return $seen['doc'];
    }
}
