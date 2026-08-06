<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * অনুমোদন — ইঞ্জিন, পর্দা, আর প্রথম আসল ব্যবহার।
 *
 * ── এখানে যা পরীক্ষা করা হচ্ছে না ───────────────────────────────────
 * ইঞ্জিনের স্তর গোনা আলাদা করে পরীক্ষিত। এখানকার প্রশ্নগুলো বাস্তবের:
 * ছাড় দিলে বিলটা সত্যিই আটকায় কি না, অনুমোদনকারী সেটা দেখতে পান কি
 * না, আর নিজের অনুরোধ নিজে অনুমোদন করা যায় কি না।
 *
 * ── কেন এটা এত জরুরি ────────────────────────────────────────────────
 * ইঞ্জিনটা Phase 1-এ লেখা হয়েছিল আর তারপর কেউ কোনোদিন ডাকেনি। সব
 * টেস্ট সবুজ ছিল, কারণ ইঞ্জিনের নিজের টেস্ট পাস করত — কিন্তু একটা
 * বিলেও কোনো অনুমোদন লাগত না, আর কেউ টের পায়নি।
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $seller;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->seller = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        $this->manager = User::factory()->create([
            'name' => 'ম্যানেজার',
            'current_company_id' => $this->company->id,
        ]);
        $this->manager->companies()->attach($this->company->id);
        $this->manager->givePermissionTo(['approval.view', 'approval.decide']);
    }

    /**
     * সীমার নিচে ছাড় — কেউ কিছু জিজ্ঞেস করে না।
     *
     * এটা আগে পরীক্ষা করা হয়, কারণ ভুল দিকের ভুলটাই বেশি ক্ষতিকর:
     * প্রতিটা ছোট ছাড়ে অনুমোদন চাইলে মানুষ নিয়মটা এড়ানোর পথ খোঁজে।
     */
    public function test_a_small_discount_needs_nobody(): void
    {
        $this->flow(threshold: '500');

        $invoice = $this->actingAs($this->seller)->invoice(discount: '100');

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertSame(0, Approval::query()->count());
    }

    /**
     * সীমার উপরে ছাড় — বিলটা খসড়াই থাকে, আর অনুরোধ তৈরি হয়।
     */
    public function test_a_big_discount_holds_the_invoice_as_a_draft(): void
    {
        $this->flow(threshold: '500');

        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
            $this->fail('বড় ছাড়ের বিলটা অনুমোদন ছাড়াই খাতায় বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }

        $this->assertSame(DocumentStatus::DRAFT, $invoice->fresh()->status);

        $approval = Approval::query()->firstOrFail();

        $this->assertSame('sales', $approval->module);
        $this->assertSame('discount', $approval->action);
        $this->assertSame('900.0000', $approval->amount);
        $this->assertSame(Approval::PENDING, $approval->status);
    }

    /**
     * বারবার নিশ্চিত চাপলেও একটাই অনুরোধ।
     *
     * ── কেন এটা ধরা দরকার ───────────────────────────────────────────
     * অনুরোধটা যদি লেনদেনের ভেতরে তৈরি হত, তাহলে ব্যতিক্রমের সাথে
     * সারিটাও রোল-ব্যাক হত — অনুমোদনকারীর তালিকায় কোনোদিন কিছু আসত
     * না, অথচ ব্যবহারকারী প্রতিবার "পাঠানো হয়েছে" বার্তা দেখতেন।
     */
    public function test_pressing_confirm_twice_does_not_pile_up_requests(): void
    {
        $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        foreach (range(1, 3) as $ignored) {
            try {
                app(SalesInvoiceService::class)->confirm($invoice);
            } catch (ValidationException) {
                // অপেক্ষমাণ — এটাই প্রত্যাশিত
            }
        }

        $this->assertSame(1, Approval::query()->count());
    }

    /**
     * অনুমোদনের পর বিলটা খাতায় বসে।
     */
    public function test_once_approved_the_invoice_posts(): void
    {
        $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        $approval = Approval::query()->firstOrFail();

        app(ApprovalEngine::class)->approve($approval, $this->manager, 'ঠিক আছে');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh());

        $this->assertSame(DocumentStatus::CONFIRMED, $confirmed->status);
    }

    /**
     * ফেরত পাঠানো হলে বিলটা আর বসে না — আর কারণটা বলা হয়।
     */
    public function test_a_rejected_discount_says_so(): void
    {
        $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        app(ApprovalEngine::class)->reject(
            Approval::query()->firstOrFail(),
            $this->manager,
            'এত ছাড় দেওয়া যাবে না',
        );

        $this->expectException(ValidationException::class);

        app(SalesInvoiceService::class)->confirm($invoice->fresh());
    }

    /**
     * নিজের অনুরোধ নিজে অনুমোদন করা যায় না।
     *
     * এটা না থাকলে পুরো ব্যবস্থাটাই সাজানো — যে ছাড় চায় সে নিজেই
     * দিয়ে দেয়, আর ছকটা কেবল একটা বাড়তি ক্লিক হয়ে দাঁড়ায়।
     */
    public function test_nobody_approves_their_own_request(): void
    {
        $this->flow(threshold: '500', approver: $this->seller);
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        $approval = Approval::query()->firstOrFail();

        $this->assertFalse(app(ApprovalEngine::class)->canDecide($approval, $this->seller));
    }

    /**
     * অনুমোদনের পর্দাগুলো খোলে, আর অনুরোধটা তালিকায় দেখা যায়।
     */
    public function test_the_screens_show_the_request(): void
    {
        $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        $approval = Approval::query()->firstOrFail();

        // অনুরোধকারী নিজেরটা দেখেন
        $this->actingAs($this->seller)
            ->get(route('approval.inbox.mine'))
            ->assertOk()
            ->assertSee(__('sales::approval.discount'));

        // অনুমোদনকারী নিজের তালিকায় সেটা পান
        $this->actingAs($this->manager)
            ->get(route('approval.inbox.index'))
            ->assertOk()
            ->assertSee(__('sales::approval.discount'));

        $this->actingAs($this->manager)
            ->get(route('approval.inbox.show', $approval->id))
            ->assertOk()
            ->assertSee($invoice->document_no);
    }

    /**
     * বাইরের কেউ অন্যের অনুরোধ খুলতে পারে না।
     *
     * ছাড়ের অঙ্ক আর গ্রাহকের নাম দুইটাই ওই পর্দায়, তাই "লিংক জানলেই
     * দেখা যায়" চলে না।
     */
    public function test_an_outsider_cannot_open_someone_elses_request(): void
    {
        $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        $invoice = $this->draft(discount: '900');

        try {
            app(SalesInvoiceService::class)->confirm($invoice);
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        $outsider = User::factory()->create(['current_company_id' => $this->company->id]);
        $outsider->companies()->attach($this->company->id);
        $outsider->givePermissionTo('approval.view');

        $this->actingAs($outsider)
            ->get(route('approval.inbox.show', Approval::query()->firstOrFail()->id))
            ->assertForbidden();
    }

    /**
     * ছক ছাড়া কোথাও অনুমোদন লাগে না।
     *
     * ইচ্ছাকৃত: নতুন কোম্পানিতে কিছুই আটকাবে না যতক্ষণ না মালিক নিজে
     * ছক বসান। ডিফল্টে সব আটকে দিলে প্রথম দিনেই কেউ কিছু করতে পারত না।
     */
    public function test_without_a_rule_nothing_needs_approval(): void
    {
        ApprovalFlow::query()->delete();

        $invoice = $this->actingAs($this->seller)->invoice(discount: '900');

        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status);
        $this->assertSame(0, Approval::query()->count());
    }

    /**
     * একই কাজে দুইটা ছক বসানো যায় না।
     *
     * ── কেন এটা একটা আসল ভুল ছিল ────────────────────────────────────
     * টেবিলে unique index ছিল, কিন্তু "সব ধরনের ডকুমেন্টে" বোঝাতে
     * document_type-এ NULL বসত — আর MySQL-এ NULL কখনো আরেকটা NULL-এর
     * সমান নয়। ফলে index-টা কিছুই আটকাত না।
     *
     * ক্ষতিটা নীরব: ইঞ্জিন প্রথম ছকটা নিত। কেউ সীমা ২০০০ করতে নতুন
     * ছক বসালে পুরনো ১০০০-এর ছকটাই চলত, আর তিনি ভাবতেন সীমা বেড়েছে।
     */
    public function test_the_same_action_cannot_have_two_rules(): void
    {
        $this->expectException(ValidationException::class);

        app(ApprovalFlowService::class)->create(
            ['module' => 'sales', 'action' => 'discount', 'threshold_amount' => '2000'],
            [['level' => 1, 'approver_type' => 'user', 'approver_id' => $this->manager->id]],
        );
    }

    /**
     * ঝুলে থাকা অনুরোধ রেখে ছক মোছা যায় না।
     *
     * মুছে ফেললে ওই অনুরোধগুলোর আর কোনো অনুমোদনকারী থাকত না —
     * canDecide() সবসময় "না" বলত, আর বিলগুলো চিরকাল খসড়া থেকে যেত।
     */
    public function test_a_rule_with_pending_requests_cannot_be_deleted(): void
    {
        $flow = $this->flow(threshold: '500');
        $this->actingAs($this->seller);

        try {
            app(SalesInvoiceService::class)->confirm($this->draft(discount: '900'));
        } catch (ValidationException) {
            // অনুরোধ তৈরি হলো
        }

        $this->expectException(ValidationException::class);

        app(ApprovalFlowService::class)->delete($flow);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    /**
     * ছাড়ের ছকটা এই পরীক্ষার মতো করে সাজানো।
     *
     * ── কেন নতুন ছক তৈরি নয় ─────────────────────────────────────────
     * ডেমো ডাটায় ছাড়ের একটা ছক আগে থেকেই আছে (১,০০০-এর উপরে মালিক)।
     * পাশে আরেকটা বসালে দুইটা ছক দাঁড়াত, আর ইঞ্জিন প্রথমটাই নিত —
     * পরীক্ষা তখন নিজের বসানো নিয়মটা পরীক্ষা করত না, ডেমোরটা করত।
     * প্রথমবার ঠিক এটাই ঘটেছিল, আর ফল দেখাচ্ছিল উল্টো।
     */
    private function flow(string $threshold, ?User $approver = null): ApprovalFlow
    {
        $flow = ApprovalFlow::query()
            ->where('module', 'sales')
            ->where('action', 'discount')
            ->firstOrFail();

        $flow->update(['threshold_amount' => $threshold, 'is_active' => true]);

        $flow->steps()->delete();

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => ($approver ?? $this->manager)->id,
            'requires_all' => false,
        ]);

        return $flow->fresh('steps');
    }

    private function draft(string $discount): SalesInvoice
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
        $product = Product::query()->orderBy('id')->firstOrFail();

        return app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $product->id, 'qty' => '10', 'rate' => '500', 'discount' => $discount]],
        );
    }

    private function invoice(string $discount): SalesInvoice
    {
        return app(SalesInvoiceService::class)->confirm($this->draft($discount));
    }
}
