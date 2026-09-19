<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ডিপোজিট অনুমোদনে গেল, আর সাথে গোটা বিক্রয়টা নিয়ে গেল।
 *
 * ── ⛔ মালিকের অভিযোগ, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"সরাসরি বিক্রয় থেকে ডিপোজিট যোগ করে নিশ্চিত করলে ডিপোজিট হারিয়ে
 * যায়। অথচ ওটা বিক্রয়ের সাথে যোগ হওয়া ডিপোজিট — রসিদের তালিকায় আলাদা
 * একটা ট্যাবে থাকার কথা, আর অনুমোদনের পথে সামলানো হওয়ার কথা।"*
 *
 * ── ⓘ সন্দেহটা, ক্রয় বিলের মতোই ─────────────────────────────────────
 * ১৮ সেপ্টেম্বর [[CollectionService::confirm()]]-এ অনুমোদনের প্রশ্ন
 * বসেছে (`sales|collection`)। ⚠️ আর [[DirectSaleService::complete()]]
 * চালান, বিল আর আদায় — সব **একটাই** `DB::transaction`-এ করে।
 *
 * ⛔ তাই ছক বসানো থাকলে আদায়টা আটকায়, ব্যতিক্রম বাইরে ছোটে, আর বাইরের
 * লেনদেন **সবটা** রোলব্যাক করে: চালান, বিল, আদায়, আর ইঞ্জিন এইমাত্র যে
 * অনুমোদনের অনুরোধ লিখেছিল সেটাও।
 *
 * ── ⭐ এই ফাইল কী দাবি করে ───────────────────────────────────────────
 * দাবিগুলো **যেমন হওয়া উচিত** তেমন, এখন যেমন আছে তেমন নয় — তাই
 * সংশোধনের আগে এগুলো লাল, আর ঐ লালটাই ফাঁদের প্রমাণ।
 */
final class TheDepositWentForApprovalAndTookTheSaleWithItTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($user);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->orderBy('id')->firstOrFail();

        /*
         * ⛔ ছকটা ছাড়া এই ফাইলের একটা দাবিও কিছু মাপে না — ডেমো ডেটায়
         * কেবল `sales|discount` ছক থাকে। ⓘ সীমা নেই, অর্থাৎ **প্রতিটা**
         * আদায়ে সই লাগে।
         */
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => 'sales',
            'action' => 'collection',
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $user->id,
        ]);
    }

    /**
     * ⭐ বিক্রয়টা টিকে থাকে — ডিপোজিটের সইয়ের জন্য মাল-বিল ফেরত যায় না।
     */
    public function test_the_sale_survives_a_held_deposit(): void
    {
        $invoicesBefore = SalesInvoice::query()->count();

        $this->sellWithDeposit();

        $this->assertSame($invoicesBefore + 1, SalesInvoice::query()->count(),
            'বিলটাই উবে গেছে — ডিপোজিটের সই আটকে গোটা বিক্রয়টা রোলব্যাক হয়েছে।');
    }

    /**
     * ⭐ ডিপোজিট হারায় না — আর এখন সেটা রসিদ ভাউচার, আদায়ের কাগজ নয়।
     *
     * ── ⓘ দাবিটা বদলাল, ১৯ সেপ্টেম্বর ২০২৬ (একই দিনের পরের নকশা) ────────
     * মালিকের নিয়ম: কাউন্টারের **সব** ডিপোজিট রসিদ ভাউচার, আর তার সই
     * কাউন্টারের **নিজের** ছকে (`counter_deposit`) —
     * [[TheCounterDepositWaitedForItsSignatureTest]]। ⚠️ তাই আদায়ের ছক
     * (`sales|collection`) কাউন্টারে আর খাটে না: বিক্রয় আর ডিপোজিট দুইটাই
     * সাথে সাথে খাতায়। ⓘ আগের দাবি ছিল "খসড়া আদায় হয়ে থাকে" — ঐ
     * কাগজটাই কাউন্টারে আর জন্মায় না।
     */
    public function test_the_deposit_is_a_posted_receipt_voucher(): void
    {
        $this->sellWithDeposit();

        $this->assertSame(0, Collection::query()->where('customer_id', $this->customer->id)->count(),
            'কাউন্টার আবার আদায়ের কাগজ বানাচ্ছে।');

        $voucher = Voucher::query()
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('party_id', $this->customer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($voucher, 'ডিপোজিটটা কোথাও নেই — ঠিক যেটার অভিযোগ মালিক করেছেন।');
        $this->assertSame('1000.0000', (string) $voucher->amount);
        $this->assertTrue($voucher->isPosted(), 'আদায়ের ছকে কাউন্টারের ডিপোজিট আটকে গেছে।');
    }

    /**
     * ⭐ আদায়ের ছক কাউন্টার আটকায় না — ক্যাশিয়ার সোজা রসিদে।
     */
    public function test_the_cashier_goes_straight_to_the_receipt(): void
    {
        $response = $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposit' => '1000',
            'lines' => [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100']],
        ]);

        $response->assertRedirectContains('/print/invoice/');
        $response->assertSessionHas('saved');
    }

    /**
     * ১০ পিস × ১০০ টাকা, আর কাউন্টারে ১০০০ টাকা জমা।
     *
     * ⓘ আটকানোটা এখানে গিলে ফেলা হয়: প্রশ্নটা ব্যতিক্রম আসে কি না নয়,
     * **তারপর কী টিকে থাকে**।
     */
    private function sellWithDeposit(): void
    {
        try {
            app(DirectSaleService::class)->complete(
                [
                    'customer_id' => $this->customer->id,
                    'warehouse_id' => $this->warehouse->id,
                    'deposit' => '1000',
                ],
                [['product_id' => $this->product->id, 'qty' => '10', 'rate' => '100', 'free_qty' => '0']],
            );
        } catch (ValidationException) {
            // ⓘ আটকানো প্রত্যাশিত হতে পারে — দাবিগুলো উপরে।
        }
    }
}
