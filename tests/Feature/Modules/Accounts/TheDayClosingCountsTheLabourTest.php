<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\DayClosingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HiresTheLabour;
use Tests\TestCase;

/**
 * ⭐ দিন শেষের হিসাবে হাম্মালি — ৪খ অংশ (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; ২০ নভেম্বর ২০২৬ দাবি; [[DayClosingService::labour()]])।
 *
 * ⓘ মালিকের কারণ: নামানোর মজুরি মজুদের দামে বসে (খরচে নয়), তাই দিনের খরচের অংশে চোখে পড়ে না — দিন শেষে আলাদা সারিতে দেখা
 *   চাই: কোন কাগজ, নামানো না তোলা, কার, কীভাবে, কত; আর ঠিকাদারদের কাছে মোট দেনা (২১১৭)।
 *
 * দাবি: নগদে নামানো মাল গ্রহণ আর বাকিতে তোলা চালান — দুটোই সারিতে, যোগফল আলাদা আর মোট; দেনা ঠিক বাকির অঙ্কে বাড়ে;
 *       ডেলিভারির সারিতে চালানের লোডিং; "লাগেনি" বা "সরবরাহকারী দিলেন" সারিতে আসে না; পাতায় অংশের নাম।
 */
final class TheDayClosingCountsTheLabourTest extends TestCase
{
    use HiresTheLabour;
    use RefreshDatabase;

    public function test_unloading_and_loading_of_the_day_are_listed_with_totals_and_the_payable(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($owner->fresh());
        app()->setLocale('en');
        app(StandardChart::class)->install();

        $day = Carbon::today();
        $before = app(DayClosingService::class)->build($day)['labour'];

        $till = $this->labourTill('DAY-T');
        $contractor = $this->labourContractor('LAB-DAY', 'Day Labour Gang');
        $product = Product::query()->whereNull('tax_id')->where('track_batch', false)->where('track_serial', false)
            ->where('qc_required', false)->orderBy('id')->firstOrFail();

        // ⓘ নগদে নামানো ৭৫ — মাল গ্রহণ
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => $day->toDateString(), 'unload_mode' => 'cash', 'unload_amount' => '75', 'unload_account_id' => $till->id],
            [['product_id' => $product->id, 'received_qty' => '10', 'rate' => '100']],
        )->fresh());

        // ⓘ "সরবরাহকারী দিলেন" — আমাদের খরচ নেই, সারিতে আসে না
        $free = $receipts->confirm($receipts->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => $day->toDateString(), 'unload_mode' => 'supplier'],
            [['product_id' => $product->id, 'received_qty' => '5', 'rate' => '100']],
        )->fresh());

        // ⓘ বাকিতে তোলা ৬০ — চালান
        $challans = app(DeliveryChallanService::class);
        $challan = $challans->confirm($challans->create(
            ['customer_id' => Customer::query()->firstOrFail()->id, 'warehouse_id' => Warehouse::query()->firstOrFail()->id,
                'trx_date' => $day->toDateString(), 'own_transport' => true,
                'loading_mode' => 'due', 'loading_amount' => '60', 'loading_contractor_id' => $contractor->id],
            [['product_id' => Product::query()->firstOrFail()->id, 'delivered_qty' => '1', 'rate' => '50']],
        )->fresh(['lines']));

        $r = app(DayClosingService::class)->build($day);
        $labour = collect($r['labour']['rows'])->keyBy('no');

        $this->assertTrue($labour->has($receipt->document_no), '⛔ নগদে নামানো মাল গ্রহণ দিনের হাম্মালিতে নেই।');
        $this->assertSame(['unloading', '75.0000'], [$labour[$receipt->document_no]['kind'], $labour[$receipt->document_no]['amount']]);
        $this->assertStringContainsString($till->name(), $labour[$receipt->document_no]['how'], 'কীভাবে দেওয়া — টিলের নাম নেই।');

        $this->assertTrue($labour->has($challan->document_no), '⛔ বাকিতে তোলা চালান দিনের হাম্মালিতে নেই।');
        $this->assertSame(['loading', '60.0000'], [$labour[$challan->document_no]['kind'], $labour[$challan->document_no]['amount']]);
        $this->assertStringContainsString('Day Labour Gang', $labour[$challan->document_no]['how'], 'কীভাবে দেওয়া — ঠিকাদারের নাম নেই।');

        $this->assertFalse($labour->has($free->document_no), '⛔ সরবরাহকারীর দেওয়া আনলোডিং আমাদের খরচ হয়ে সারিতে এল।');

        $this->assertSame(0, bccomp($r['labour']['unloading'], bcadd($before['unloading'], '75', 4), 4), 'নামানোর যোগফল ভুল।');
        $this->assertSame(0, bccomp($r['labour']['loading'], bcadd($before['loading'], '60', 4), 4), 'তোলার যোগফল ভুল।');
        $this->assertSame(0, bccomp($r['labour']['total'], bcadd($r['labour']['unloading'], $r['labour']['loading'], 4), 4), 'মোট = নামানো + তোলা নয়।');
        $this->assertSame(0, bccomp($r['labour']['payable'], bcadd($before['payable'], '60', 4), 4),
            '⛔ দিনের শেষে ঠিকাদারদের দেনা ঠিক বাকির ৬০ বাড়েনি (নগদেরটা দেনা নয়)।');

        // ⓘ ডেলিভারির সারিতেও চালানের লোডিং
        $delivery = collect($r['delivery']['rows'])->keyBy('no');
        $this->assertSame('60.0000', $delivery[$challan->document_no]['loading'] ?? null, '⛔ ডেলিভারির সারিতে চালানের লোডিং নেই।');

        $this->get(route('executive.dayclose', ['date' => $day->toDateString()]))
            ->assertOk()
            ->assertSee(__('supplier::labour.day_title'))
            ->assertSee('data-sec="labour"', false)
            ->assertSee($receipt->document_no)
            ->assertSee($challan->document_no);
    }
}
