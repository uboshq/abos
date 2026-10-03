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

    /**
     * ⛔ সীমা মাপে ছাড়ের **পরের** টাকা — abos-bb-এর ধরা, ২ অক্টোবর ২০২৬ ([[CreditExposure::billedAs()]])।
     *
     * ১,০০০ টাকার মালে ১০% ছাড় = বিল ৯০০। ৯০০ পুরো নগদে দিলে বাকি শূন্য — বিক্রি চলে; ⚠️ আগে চালানের
     * দেয়াল ছাড়ের আগের ১,০০০ মাপত আর "১০০ সীমা পার" বলত। ৮৯৯ দিলে ১ টাকা বাকি, আর সীমা ০ — আটকায়,
     * অর্থাৎ দেয়াল ঢিলে হয়নি, কেবল ঠিক অঙ্কটা মাপছে।
     */
    public function test_a_discounted_sale_paid_in_full_needs_no_limit_and_one_taka_short_is_refused(): void
    {
        $this->assertNull($this->refusedField(fn () => $this->sell('900', ['discount_percent' => '10'])),
            '⛔ ছাড়সহ পুরো নগদ, বাকি শূন্য — অথচ আটকেছে: দেয়াল ছাড়ের আগের দাম মাপছে।');

        $approvals = Approval::query()->count();

        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell('899', ['discount_percent' => '10'])),
            '⛔ ১ টাকা বাকি আর সীমা ০ — অথচ বিক্রি চলেছে।');

        // ⓘ সীমার "না" সইয়ের আগে — ছাড়ের সইয়ের অনুরোধও জন্মায় না (abos-bb-এর ক্রম: দেয়াল আগে, ছাড়ের থামা পরে)
        $this->assertSame($approvals, Approval::query()->count(), '⛔ সীমা পার হওয়া বিক্রি সইয়ের সারিতে গেছে।');
    }

    /**
     * ⭐ বিক্রির পরে মোট বকেয়া সীমার ভিতরে — মালিকের সিদ্ধান্ত, ৩ অক্টোবর ২০২৬: *"চলবে না — আগের বকেয়াও শোধ চাই"*
     * ([[CreditExposure::assertRoom()]])।
     *
     * নতুন গ্রাহক, সীমা ০, আগের বকেয়া ১২,০০০, বিল ১,০০০: ১৩,০০০ দিলে চলে (ডেমো S-0010-এর আকার — বিল আর বকেয়া দুটোই
     * শোধ); ১২,৯৯৯ দিলে আটকায়; কেবল বিলের ১,০০০ দিলেও আটকায় — ⛔ আগে এটাই চলত।
     */
    public function test_an_old_due_must_be_cleared_with_the_sale_when_there_is_no_limit(): void
    {
        $this->customer = Customer::query()->create(['code' => 'OLD-DUE', 'name_en' => 'Old Due', 'name_bn' => 'Old Due', 'is_active' => true]);
        $this->limit('0');

        app(\App\Core\Engines\Posting\PostingEngine::class)->post(
            sourceType: 'test:old-due', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(),
            lines: [
                ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '12000', 'party_type' => 'customer', 'party_id' => $this->customer->id],
                ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => '12000'],
            ],
            branchId: Company::query()->where('code', 'TDEPOT')->firstOrFail()->defaultBranch()?->id,
        );

        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell('1000')),
            '⛔ পুরনো বকেয়া ১২,০০০, সীমা ০ — কেবল বিলটুকু দিয়ে বিক্রি চলেছে।');
        $this->assertSame('customer_id', $this->refusedField(fn () => $this->sell('12999')),
            '⛔ বিল আর বকেয়া মিলে ১৩,০০০ — এক টাকা কমেও চলেছে।');
        $this->assertNull($this->refusedField(fn () => $this->sell('13000')),
            '⛔ বিল আর পুরনো বকেয়া দুটোই দেওয়া হলো — তবু আটকেছে।');
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
    /** @param  array<string, string>  $line  সারির বাড়তি ঘর, যেমন ছাড় */
    private function sell(string $paying, array $line = []): void
    {
        app(DirectSaleService::class)->complete(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'deposit' => $paying,
            ],
            [['product_id' => Product::query()->where('track_batch', false)->where('is_active', true)->orderBy('id')->value('id'), 'qty' => '10', 'rate' => '100', ...$line]],
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
