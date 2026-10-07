<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক বিল বাতিলে গোটা চালান ফিরত — পুরো ERP অডিট ⛔৩, ৬ অক্টোবর ২০২৬।
 *
 * ⛔ চালানের এক সারির ১০-এর ৬ বিল A-তে, ৪ বিল B-তে। বাতিল-ইনভয়েসের পাহারা কেবল সারি গুনত — দুই বিলই ঐ একটা সারি দেখায়,
 * তাই "নিজের = সব" মিলত, আর A বাতিলে গোটা চালান (১০) গুদামে ফিরত, অথচ B-র ৪-এর আয় আর খরচ খাতায় থেকে যেত।
 * ⭐ এখন চালানের কোনো সারিতে অন্য চালু বিল থাকলে বাতিল থামে — ভাগের চালান, ফেরত দিন। এক বিলের চালান আগের মতোই বাতিল হয়।
 */
final class OneBillCancelledTookBackTheWholeChallanTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $biscuit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        Customer::query()->whereKey(Customer::query()->value('id'))->update(['credit_limit' => '1000000000']);
    }

    public function test_a_challan_split_over_two_bills_is_not_cancelled_with_one_of_them(): void
    {
        $challan = $this->challan();
        $a = $this->bill($challan, '6');
        $b = $this->bill($challan, '4');
        $before = $this->onHand();
        $books = DB::table('ledger_entries')->count();

        try {
            app(SalesInvoiceCancellationService::class)->request($a, $this->owner(), 'ভুল দাম');
            $this->fail('⛔ ভাগের চালানের এক বিল বাতিল হলো — গোটা চালান ফিরত।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($challan->document_no, implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $a->fresh()->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $b->fresh()->status);
        $this->assertSame(DocumentStatus::CONFIRMED, $challan->fresh()->status, '⛔ চালান উল্টে গেল।');
        $this->assertSame(0, bccomp($before, $this->onHand(), 4), '⛔ মাল গুদামে ফিরল।');
        $this->assertSame($books, DB::table('ledger_entries')->count(), '⛔ খাতায় উল্টো সারি বসল।');
    }

    public function test_a_challan_on_one_bill_still_cancels(): void
    {
        $challan = $this->challan();
        $only = $this->bill($challan, '10');

        app(SalesInvoiceCancellationService::class)->request($only, $this->owner(), 'ভুল গ্রাহক');

        $this->assertSame(DocumentStatus::CANCELLED, $only->fresh()->status);
        $this->assertSame(DocumentStatus::CANCELLED, $challan->fresh()->status);
    }

    private function challan(): DeliveryChallan
    {
        $draft = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $this->biscuit->id, 'delivered_qty' => '10', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($draft);
    }

    private function bill(DeliveryChallan $challan, string $qty): SalesInvoice
    {
        $line = $challan->lines()->firstOrFail();
        $before = SalesInvoice::query()->max('id') ?? 0;

        $this->post(route('sales.invoice.store'), [
            'delivery_challan_id' => $challan->id,
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'lines' => [['product_id' => $line->product_id, 'delivery_challan_line_id' => $line->id, 'qty' => $qty, 'rate' => '10', 'discount' => '0']],
        ])->assertSessionHasNoErrors();

        $draft = SalesInvoice::query()->where('id', '>', $before)->latest('id')->firstOrFail();

        return app(SalesInvoiceService::class)->confirm($draft);
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function onHand(): string
    {
        return (string) DB::table('inv_stock_movements')
            ->where('product_id', $this->biscuit->id)->where('warehouse_id', $this->warehouse->id)->sum('floor_change');
    }
}
