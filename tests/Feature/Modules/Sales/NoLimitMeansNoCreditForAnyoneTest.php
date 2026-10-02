<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * সীমা নেই মানে বাকি নেই — কারও জন্য, কোনো সুইচে নয়। মালিকের চূড়ান্ত কথা, ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ঘটেছিল ───────────────────────────────────────────────────────
 * ডেমোতে সরাসরি বিক্রয় S-0009: রুদ্র এন্টারপ্রাইজ, সীমা নেই, শর্ত নগদ, এক টাকাও জমা নেই, বাকি
 * ৳৪১,৬৫১.৭২ — বিলটা আটকানোর বদলে "অনুমোদনের অপেক্ষায়" গেল। কারণ: শূন্য সীমার মানে বাঁধা ছিল
 * `customer.zero_limit_blocks` সুইচে (বন্ধ থাকলে শূন্য = সীমাহীন), আর পুরো দেয়ালটা
 * `customer.credit_limit_enabled`-এ। দেয়াল পার হয়ে বিলটা সাধারণ সই-এর সারিতে চলে গিয়েছিল।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * দুই সুইচই বন্ধ রেখে মাপা হয় (ডেমোর অবস্থা) — তবু সীমা নেই আর টাকা নেই মানে বিল নয়, কোনো
 * অনুমোদনের সারিও নয়। সীমার ভিতরে, বা পুরো টাকা গুনে দিলে, বিক্রি চলে। আর একমাত্র পথ: সুপার
 * অ্যাডমিন আগে সীমা বাড়ান — একই মানুষ, একই বিল, তখন চলে।
 */
final class NoLimitMeansNoCreditForAnyoneTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; সীমা তাঁর জন্যও পরম
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        // ⛔ ডেমোর অবস্থা — দুই সুইচই বন্ধ; আগে এতেই দেয়াল উঠে যেত
        app(SettingsService::class)->set('customer.credit_limit_enabled', false);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->limit('0');
    }

    public function test_no_limit_and_nothing_paid_is_refused_with_no_approval_row(): void
    {
        $approvals = Approval::query()->count();
        $invoices = SalesInvoice::query()->count();

        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell('0')), '⛔ সীমা নেই, টাকা নেই — অথচ বিল আটকায়নি।');
        $this->assertSame($approvals, Approval::query()->count(), '⛔ সীমা পার হওয়া বিল অনুমোদনের সারিতে গেছে — দেয়ালের কথা ছিল সোজা "না"।');
        $this->assertSame($invoices, SalesInvoice::query()->count(), '⛔ আটকানো বিলের খসড়া রয়ে গেছে।');
    }

    public function test_within_the_limit_the_sale_goes_through(): void
    {
        $this->limit(bcadd($this->customer->outstanding(), '5000', 4));

        $this->assertNull($this->refusedField(fn () => $this->sell('0')), 'সীমার ভিতরের বাকি বিল আটকে গেছে — দেয়াল বেশি চেপেছে।');
    }

    public function test_paid_in_full_needs_no_limit(): void
    {
        $this->assertNull($this->refusedField(fn () => $this->sell('1000')), 'পুরো টাকা গুনে দেওয়া বিক্রি আটকে গেছে — সীমা কেবল বাকির।');
    }

    /** ⭐ একমাত্র পথ — একই মানুষ, একই বিল: আগে "না", সুপার অ্যাডমিন সীমা বাড়ানোর পরে চলে। */
    public function test_the_same_sale_passes_once_a_super_admin_raises_the_limit(): void
    {
        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell('0')), 'প্রস্তুতিটাই ভুল — প্রথমবার আটকায়নি।');

        $this->limit(bcadd($this->customer->outstanding(), '5000', 4));

        $this->assertNull($this->refusedField(fn () => $this->sell('0')), '⛔ সীমা বাড়ানোর পরেও একই বিল আটকে আছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function limit(string $amount): void
    {
        $this->customer->forceFill(['credit_limit' => $amount])->save();
        $this->customer->refresh();
    }

    /** সরাসরি বিক্রয় — ১০ × ১০০ = ১,০০০ টাকার বিল ([[NoLimitMeansNoCreditNotNoSaleTest]]-এর একই আকার)। */
    private function sell(string $paying): void
    {
        app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'deposit' => $paying,
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '10', 'rate' => '100']],
        );
    }

    private function refusedField(callable $act): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }
}
