<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরবরাহকারীর বিলে শেষ তারিখ ছিল, কেউ পড়ত না — অর্থের মানচিত্র §৬
 * "পরিশোধের সময়সূচি"।
 *
 * ⭐ পাকা, এখনো বাকি থাকা বিল — শেষ তারিখ ধরে চার ভাগে। এই ফাইল দেখে:
 * পুরো শোধ হওয়া বিল নেই, আধা শোধে বাকিটাই দেখায়, ভাগগুলো ঠিক, আর
 * "পরিশোধ" বোতাম ঐ বিলটা বাছা অবস্থায় পরিশোধের পর্দা খোলে।
 */
final class TheSupplierBillsHadDatesNobodyReadTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        // ⓘ ডেমোর বিলগুলো বাদ — নাহলে ভাগের সংখ্যা ডেমোর উপর নির্ভর করত
        PurchaseBill::query()->update(['status' => DocumentStatus::CANCELLED]);
    }

    public function test_bills_fall_into_the_right_groups_and_paid_ones_leave(): void
    {
        $late = $this->bill('1000', now()->subDays(12), now()->subDays(5));
        $soon = $this->bill('2000', now(), now()->addDays(3));
        $month = $this->bill('3000', now(), now()->addDays(20));
        $later = $this->bill('4000', now(), now()->addDays(60));
        $paid = $this->bill('500', now(), now()->addDays(2));
        $half = $this->bill('800', now(), now()->addDays(5));

        $this->pay($paid, '500');
        $this->pay($half, '300');

        $page = $this->get(route('purchase.payment_schedule.index'))->assertOk();

        foreach ([$late, $soon, $month, $later, $half] as $bill) {
            $page->assertSee($bill->document_no);
        }

        $page->assertDontSee(route('purchase.bill.show', $paid).'"', escape: false);
        $page->assertSee(route('purchase.payment.create', ['purchase_bill_id' => $late->id]), escape: false);

        $row = collect($page->viewData('rows')->items())->firstWhere('id', $half->id);
        $this->assertNotNull($row, 'আধা শোধের বিলটা তালিকায় নেই।');
        $this->assertSame(0, bccomp($row->dueAmount(), '500', 2), 'আধা শোধের বিলে পুরো অঙ্ক দেখাচ্ছে।');

        $overdue = $this->get(route('purchase.payment_schedule.index', ['tab' => 'overdue']))->assertOk();
        $overdue->assertSee($late->document_no);
        $overdue->assertDontSee($soon->document_no);

        $week = $this->get(route('purchase.payment_schedule.index', ['tab' => 'week']))->assertOk();
        $week->assertSee($soon->document_no);
        $week->assertSee($half->document_no);
        $week->assertDontSee($month->document_no);

        $this->get(route('purchase.payment_schedule.index', ['tab' => 'later']))->assertOk()
            ->assertSee($later->document_no)
            ->assertDontSee($late->document_no);
    }

    private function bill(string $total, $date, $due): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        return $bills->confirm($bills->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => $date->toDateString(), 'due_on' => $due->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $total]],
        ));
    }

    private function pay(PurchaseBill $bill, string $amount): void
    {
        $payments = app(PaymentService::class);

        $payments->confirm($payments->create(
            ['supplier_id' => $this->supplier->id, 'trx_date' => now()->toDateString(), 'amount' => $amount],
            [['purchase_bill_id' => $bill->id, 'amount' => $amount]],
        ));
    }
}
