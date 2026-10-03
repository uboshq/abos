<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\OwnerSignsDiscounts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * বিলে যেকোনো ছাড় — মালিকের সই ছাড়া নয় (মালিকের নিয়ম, ১ অক্টোবর ২০২৬: *"bill e kono char maliker onumoti chara
 * dite parbe na"*; *"রাউন্ডিং 0.5 mane 50 poisa porzonto accept"*)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * ছাড়ের ছকে সীমা ছিল (ডেমোতে ১,০০০ টাকা) — তার নিচের ছাড় কারও সই ছাড়াই খাতায় উঠত; বিলের মাথার ছাড় গোনাই
 * হত না; আর রাউন্ডিং যত বড়ই হোক, ছাড় নয়।
 *
 * ── ⭐ এখন ([[OwnerSignsDiscounts]], [[SalesInvoiceService::assertDiscountApproved()]]) ──
 * প্রতিটা কোম্পানির ছাড়ের ছক: সীমা নেই, একটাই ধাপ — মালিক (super_admin); আগের ধাপ উঠে যায়, লগে থাকে (মালিক, ২ অক্টোবর)। সারির হাতে-দেওয়া ছাড়, মাথার ছাড়, আর ৳০.৫০-এর
 * বেশি রাউন্ডিং — এক টাকাও সই চায়। ⓘ অফারের ছাড় নয় — ওটা নিজের ছকে সই পেয়ে চালু।
 */
final class TheOwnerSignsEveryDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->assertTrue($this->owner->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE), 'দৃশ্যটাই বানানো যায়নি — মালিক super_admin নন।');

        /*
         * ⓘ ডেমোর আগের ছক (১,০০০ টাকার সীমা) — চলতি কোম্পানিগুলোর মতো; মাইগ্রেশন ঠিক এটাই ডাকে।
         */
        app(OwnerSignsDiscounts::class)->ensure($this->company);

        $this->seller = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($this->seller);
    }

    /** ⭐ প্রতিটা কোম্পানিতে ছাড়ের ছক: চালু, সীমা নেই, একটাই ধাপ — মালিক; আবার চালালে দ্বিতীয় ছক বা ধাপ জন্মায় না। */
    public function test_every_company_sends_discounts_to_its_owner(): void
    {
        foreach (Company::query()->get() as $company) {
            app(OwnerSignsDiscounts::class)->ensure($company);
            $once = $this->flowOf($company)->steps->count();
            app(OwnerSignsDiscounts::class)->ensure($company);

            $flow = $this->flowOf($company);
            $this->assertTrue((bool) $flow->is_active);
            $this->assertNull($flow->threshold_amount, "⛔ {$company->code}: ছাড়ের ছকে সীমা আছে — তার নিচের ছাড় সই ছাড়াই যেত।");
            $this->assertSame($once, $flow->steps->count(), "⛔ {$company->code}: দ্বিতীয়বার চালাতে আরেকটা ধাপ জন্মেছে।");
            $this->assertTrue($this->ownerSigns($company, $flow), "⛔ {$company->code}: কোনো ধাপ মালিকের নয়।");
            $this->assertCount(1, $flow->steps, "⛔ {$company->code}: মালিক ছাড়া আরও কারও ধাপ আছে।");
        }
    }

    /**
     * ⛔ আগের ধাপ (যেমন লাইভ ADI-র Manager) উঠে যায়, শুধু মালিক থাকেন — মালিকের সিদ্ধান্ত, ২ অক্টোবর ২০২৬; আর বন্ধ
     * রাখা ছকও চালু হয়। ⓘ আগে কী ছিল তা ফেরত আসে — মাইগ্রেশন সেটা লগে লেখে।
     */
    public function test_an_earlier_step_gives_way_to_the_owner_alone_and_is_reported(): void
    {
        $flow = $this->flowOf($this->company);
        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->delete();
        $manager = Role::query()->where('company_id', $this->company->id)->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'manager',
            'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => $manager->id]);
        $flow->forceFill(['threshold_amount' => '5000', 'is_active' => false])->save();

        $report = app(OwnerSignsDiscounts::class)->ensure($this->company);

        $after = $this->flowOf($this->company);
        $this->assertCount(1, $after->steps, '⛔ ব্যবস্থাপকের ধাপ রয়ে গেছে — ছাড়ে মালিক ছাড়াও আরেকজনের সই লাগত।');
        $this->assertTrue($this->ownerSigns($this->company, $after), '⛔ একমাত্র ধাপটা মালিকের নয়।');
        $this->assertTrue((bool) $after->is_active, '⛔ বন্ধ ছক বন্ধই রইল — ছাড় সই ছাড়াই যেত।');
        $this->assertNull($after->threshold_amount, '⛔ আগের ৫,০০০ টাকার সীমা রয়ে গেছে — তার নিচের ছাড় সই ছাড়াই যেত।');
        $this->assertSame('5000.0000', $report['before'][0]['threshold'] ?? null, '⛔ আগের সীমা ফেরত আসেনি — লগে থাকত না।');
        $this->assertSame(['1:role#'.$manager->id], $report['before'][0]['steps'] ?? null, '⛔ আগের ধাপ ফেরত আসেনি — লগে থাকত না।');
    }

    /** ⛔ মালিক নিজে ছাড় দিলেও সই লাগে — super_admin-এর জন্য কোনো ছাড় নেই (মালিক, ২ অক্টোবর ২০২৬)। */
    public function test_the_owners_own_discount_also_waits_for_a_signature(): void
    {
        $this->actingAs($this->owner);
        $bill = $this->draft(['discount' => '1']);

        $this->assertSame('discount', $this->held(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh())),
            '⛔ মালিকের নিজের ছাড় সই ছাড়াই গেছে।');
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);
    }

    /** ⭐ পর্দা থেকে খোলা নতুন কোম্পানি প্রথম দিন থেকেই ছাড়ে মালিকের সই চায়। */
    public function test_a_new_company_starts_with_the_owner_signing_discounts(): void
    {
        $this->actingAs($this->owner);

        $this->post(route('system_admin.company.store'), [
            'code' => 'NEWCO',
            'name_en' => 'New Depot',
            'name_bn' => 'নতুন ডিপো',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertRedirect();

        $company = Company::query()->where('code', 'NEWCO')->firstOrFail();
        $flow = $this->flowOf($company);

        $this->assertNull($flow->threshold_amount);
        $this->assertTrue((bool) $flow->is_active);
        $this->assertTrue($this->ownerSigns($company, $flow), '⛔ নতুন কোম্পানির ছাড়ের ছকে মালিক নেই।');
    }

    /** ⛔ এক টাকার সারির ছাড় — মালিকের সইয়ের অপেক্ষায়, বিল খসড়া। */
    public function test_a_one_taka_line_discount_waits_for_the_owner(): void
    {
        $bill = $this->draft(['discount' => '1']);

        $this->assertSame('discount', $this->held(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh())));
        $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);
    }

    /**
     * ⛔ ছক নেই, বা বন্ধ — তবু এক টাকার ছাড় যায় না (fail-closed; সমন্বয়ক, ২ অক্টোবর ২০২৬)। ⓘ লাইভে দুই কোম্পানির
     * কোনো ছাড়ের ছকই ছিল না — আগে সেখানে ছাড় চুপচাপ খাতায় উঠত।
     */
    public function test_a_company_with_no_discount_flow_still_cannot_give_a_discount(): void
    {
        $flow = $this->flowOf($this->company);

        foreach (['off' => fn () => $flow->forceFill(['is_active' => false])->save(),
            'gone' => function () use ($flow) {
                ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->delete();
                $flow->delete();
            }] as $how => $remove) {
            $remove();
            $bill = $this->draft(['discount' => '1']);

            $this->assertSame('discount', $this->held(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh())), "⛔ ছক {$how}: ছাড় সই ছাড়াই গেছে।");
            $this->assertSame(DocumentStatus::DRAFT, $bill->fresh()->status);
        }
    }

    /** ⛔ এক টাকার মাথার ছাড়ও। */
    public function test_a_one_taka_head_discount_waits_for_the_owner(): void
    {
        $bill = $this->draft([], ['bill_discount' => '1']);

        $this->assertSame('discount', $this->held(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh())));
    }

    /** ⭐ ৳০.৪০ রাউন্ডিং সই চায় না; ⛔ ৳০.৬০ চায় — দুই দিকেই। */
    public function test_rounding_up_to_fifty_paisa_passes_and_beyond_it_waits(): void
    {
        $bill = $this->draft();

        foreach (['-0.40', '0.40', '0.50'] as $small) {
            $bill->forceFill(['rounding_amount' => $small]);
            app(SalesInvoiceService::class)->assertDiscountApproved($bill);
        }

        foreach (['-0.60', '0.60'] as $big) {
            $bill->forceFill(['rounding_amount' => $big]);
            $this->assertSame('discount', $this->held(fn () => app(SalesInvoiceService::class)->assertDiscountApproved($bill)),
                "⛔ {$big} রাউন্ডিং সই ছাড়া গেছে।");
        }
    }

    /** ⭐ মালিক সই দিলে বিল পাকা হয়। */
    public function test_the_owner_signs_and_the_bill_posts(): void
    {
        $bill = $this->draft(['discount' => '1']);
        $this->held(fn () => app(SalesInvoiceService::class)->confirm($bill->fresh()));

        $approval = Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $bill->id)
            ->where('action', 'discount')->firstOrFail();

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        app(SalesInvoiceService::class)->confirm($bill->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $bill->fresh()->status);
    }

    /**
     * ⛔ কাউন্টারে এক টাকার ছাড় — বিক্রি খসড়া, আর সইয়ের অনুরোধ **টিকে থাকে**; ⭐ মালিক সই দিলে বিক্রি নিজে শেষ হয়।
     * ⓘ আগে অনুরোধটা লেনদেনের ভিতরে লেখা হত আর ফেরত-গড়ানোয় মুছত — পর্দা বলত "পাঠানো হয়েছে", তালিকায় কিছুই নেই।
     */
    public function test_a_counter_discount_survives_as_a_request_and_the_owners_signature_finishes_the_sale(): void
    {
        $result = app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'deposit' => '0'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '1', 'rate' => '100', 'discount_percent' => '1']],
        );

        $this->assertTrue($result['discount_held'] ?? false, '⛔ কাউন্টারের ছাড় সইয়ে যায়নি।');
        $this->assertSame(DocumentStatus::DRAFT, $result['invoice']->fresh()->status);

        $approval = Approval::query()->where('approvable_type', SalesInvoice::class)->where('approvable_id', $result['invoice']->id)
            ->where('action', 'discount')->where('status', Approval::PENDING)->first();
        $this->assertNotNull($approval, '⛔ সইয়ের অনুরোধটা মুছে গেছে — মালিকের তালিকায় কিছুই নেই।');

        app(ApprovalEngine::class)->approve($approval, $this->owner);

        $this->assertSame(DocumentStatus::CONFIRMED, $result['invoice']->fresh()->status, '⛔ মালিকের সইয়ের পরেও বিক্রিটা শেষ হয়নি।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $line
     * @param  array<string, string>  $head
     */
    private function draft(array $line = [], array $head = []): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(),
            ...$head,
        ], [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '1', 'rate' => '100', ...$line]]);
    }

    private function flowOf(Company $company): ApprovalFlow
    {
        $flows = ApprovalFlow::query()->withoutGlobalScopes()->with('steps')
            ->where('company_id', $company->id)->where('module', 'sales')->where('action', 'discount')->get();

        $this->assertCount(1, $flows, "⛔ {$company->code}: ছাড়ের ছক একটা নয়।");

        return $flows->first();
    }

    /** ⓘ super_admin রোলের ধাপ, কিংবা নাম ধরে এমন কেউ যিনি ঐ কোম্পানির super_admin */
    private function ownerSigns(Company $company, ApprovalFlow $flow): bool
    {
        $role = (int) Role::query()->where('company_id', $company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->value('id');

        return $flow->steps->contains(fn (ApprovalFlowStep $s) => $s->approver_type === ApprovalFlowStep::BY_ROLE
            ? (int) $s->approver_id === $role
            : CompanyContext::forCompany($company->id, fn () => (bool) User::query()->find($s->approver_id)?->hasRole(PermissionSyncer::SUPER_ADMIN_ROLE)));
    }

    private function held(callable $work): string
    {
        try {
            $work();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        $this->fail('⛔ কিছুই আটকায়নি — ছাড়টা মালিকের সই ছাড়াই গেছে।');
    }
}
