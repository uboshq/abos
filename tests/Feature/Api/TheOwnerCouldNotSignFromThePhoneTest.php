<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * মালিক ডেস্কে নেই, আর কাগজগুলো দাঁড়িয়ে ছিল — ফোন থেকে সই দেওয়ার
 * কোনো দরজাই ছিল না। চুক্তি §৫।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি অংশ ──────────────────────────────────────
 * ⛔ বেতন। মালিকের সিদ্ধান্ত (১৩ সেপ্টেম্বর ২০২৬): বেতনের অনুমোদন ফোনে
 * **সারিটাই নয়**। ⚠️ যাচাইটা দুই দিক থেকে — একটা আসল বেতনের অনুরোধ
 * বসিয়ে দেখা সেটা আসেনি, **আর পাশের একটা ক্রয়াদেশ এসেছে**। কেবল
 * "তালিকা খালি" দেখে পাস করলে সবকিছু-ছাঁকা একটা ক্যোয়ারিও সবুজ হত।
 *
 * ── দরজার দাবিগুলো একই মানুষে দুইবার ────────────────────────────────
 * চাবি ছাড়া ৪০৩, তারপর **ঐ একই মানুষকে** চাবি দিয়ে ২০০। ⓘ দুইজন
 * আলাদা মানুষ হলে ৪০৩-টা অন্য কোনো পার্থক্য (ছক, কোম্পানি) থেকেও আসতে
 * পারত, আর দাবিটা কিছুই প্রমাণ করত না।
 */
class TheOwnerCouldNotSignFromThePhoneTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'approval.decide';

    private Company $depot;

    private Company $mart;

    /** ছকে যাঁর নাম — কিন্তু শুরুতে চাবি ছাড়া। */
    private User $signer;

    /** যিনি অনুরোধ করেছেন। */
    private User $clerk;

    private ApprovalFlow $orderFlow;

    private Approval $order;

    private Approval $payroll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->mart = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);

        // লাইভে PermissionSyncer বসায়; এখানে থাকলেও ক্ষতি নেই
        Permission::findOrCreate(self::KEY, 'web');

        $this->signer = $this->member('Signer');
        $this->clerk = $this->member('কেরানি');

        $this->orderFlow = ApprovalFlow::create(['module' => 'purchase', 'action' => 'order']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $this->orderFlow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        /*
         * ⚠️ বেতনের ছকেও ঐ একই মানুষ — ইচ্ছা করে।
         *
         * ⓘ ছকে তিনি না থাকলে বেতনের সারি এমনিতেই আসত না, আর ছাঁকনিটা
         * কাজ করছে কি না তা এই ফাইল কোনোদিন জানত না।
         */
        $payrollFlow = ApprovalFlow::create(['module' => 'hr', 'action' => 'payroll']);
        ApprovalFlowStep::create([
            'approval_flow_id' => $payrollFlow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $this->signer->id,
        ]);

        $this->order = $this->orderAwaiting('PO-PHONE-0001', now()->subHour());

        $run = PayrollRun::create([
            'company_id' => $this->depot->id,
            'document_no' => 'PRL-PHONE-0001',
            'month' => now()->format('Y-m'),
            'trx_date' => now()->toDateString(),
            'gross_total' => '900000',
            'deduction_total' => '25000',
            'net_total' => '875000',
            'employee_count' => 12,
            'created_by' => $this->clerk->id,
        ]);

        $this->payroll = Approval::create([
            'company_id' => $this->depot->id,
            'approvable_type' => PayrollRun::class,
            'approvable_id' => $run->id,
            'module' => 'hr',
            'action' => 'payroll',
            'amount' => '875000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            // ⚠️ সবচেয়ে পুরনো — ক্রম ধরে প্রথমেই আসত, যদি আসত
            'requested_at' => now()->subHours(2),
        ]);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function member(string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'current_company_id' => $this->depot->id,
        ]);
        $user->companies()->attach($this->depot->id, ['is_active' => true]);

        return $user;
    }

    private function orderAwaiting(string $no, $at): Approval
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->depot->id,
            'branch_id' => $this->depot->defaultBranch()?->id,
            'document_no' => $no,
            'supplier_id' => Supplier::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'total' => '125000',
            'narration' => 'Cement for the Netrakona depot',
            'created_by' => $this->clerk->id,
        ]);

        return Approval::create([
            'company_id' => $this->depot->id,
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'module' => 'purchase',
            'action' => 'order',
            'amount' => '125000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => $at,
        ]);
    }

    /**
     * ⚠️ কোম্পানির ভেতরে দেওয়া — teams চালু, তাই চাবি কোম্পানি ধরে বাঁধা।
     * তারপর ক্যাশ ভোলানো আর টাটকা মানুষ, নাহলে পুরনো উত্তরই ফিরত।
     */
    private function giveKey(User $user): User
    {
        CompanyContext::forCompany($this->depot->id, fn () => $user->givePermissionTo(self::KEY));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, [AuthController::APP]);
    }

    private function inbox(User $user, string $query = ''): TestResponse
    {
        $this->as($user);

        return $this->getJson('/api/v1/approvals/pending'.$query);
    }

    /** @return list<string> */
    private function idsIn(TestResponse $response): array
    {
        return array_column($response->json('rows'), 'id');
    }

    private function stillWaiting(Approval $approval): void
    {
        $this->assertSame(
            Approval::PENDING,
            Approval::query()->withoutGlobalScopes()->whereKey($approval->id)->value('status'),
            'কাগজটা আর অপেক্ষায় নেই — দরজাটা বন্ধ বলেছিল, অথচ কিছু একটা ঘটে গেছে।',
        );
    }

    /** ⭐ তালিকা — চাবি ছাড়া ৪০৩, একই মানুষকে চাবি দিলে ২০০ আর সারিটা আছে। */
    public function test_the_list_opens_only_once_the_same_person_is_given_the_key(): void
    {
        $this->inbox($this->signer)->assertForbidden();

        $signer = $this->giveKey($this->signer);

        $response = $this->inbox($signer)->assertOk();

        $this->assertContains($this->order->public_id, $this->idsIn($response));
    }

    /**
     * ⛔ বেতন আসে না — আর পাশের ক্রয়াদেশ আসে। দুই দিকই।
     *
     * ⓘ ইঞ্জিন নিজে বেতনের সারিটা এই মানুষের তালিকায় রাখে (নিচে মাপা),
     * তাই অনুপস্থিতিটা ছাঁকনির কাজ — ছক না-মেলার নয়।
     */
    public function test_payroll_never_reaches_the_phone_while_the_order_beside_it_does(): void
    {
        $signer = $this->giveKey($this->signer);

        $engineSees = CompanyContext::forCompany(
            $this->depot->id,
            fn () => app(ApprovalEngine::class)->pendingQueryFor($signer)->pluck('id')->all(),
        );
        $this->assertContains($this->payroll->id, $engineSees, 'ইঞ্জিনই বেতন দেখছে না — তাহলে ছাঁকনির দাবিটা কিছুই মাপছে না।');

        $response = $this->inbox($signer)->assertOk();
        $ids = $this->idsIn($response);

        $this->assertNotContains($this->payroll->public_id, $ids);
        $this->assertNotContains('PayrollRun', array_column($response->json('rows'), 'documentType'));
        $this->assertContains($this->order->public_id, $ids);

        // ⛔ আইডি জানা থাকলেও ফোন থেকে বেতনে সই নয়
        $this->as($signer);
        $this->postJson("/api/v1/approvals/{$this->payroll->public_id}/approve")->assertNotFound();
        $this->postJson("/api/v1/approvals/{$this->payroll->public_id}/reject", ['remarks' => 'no'])->assertNotFound();
        $this->stillWaiting($this->payroll);
    }

    /** ⓘ সারির আকার — public_id, চার ঘরের স্ট্রিং টাকা, কাগজের নম্বর। */
    public function test_a_row_speaks_in_public_ids_and_money_as_a_four_decimal_string(): void
    {
        $signer = $this->giveKey($this->signer);

        $rows = collect($this->inbox($signer)->assertOk()->json('rows'));
        $row = $rows->firstWhere('id', $this->order->public_id);

        $this->assertNotNull($row);
        $this->assertNotSame((string) $this->order->id, $row['id']);
        $this->assertFalse($rows->contains('id', $this->order->id));

        $this->assertIsString($row['amount']);
        $this->assertSame('125000.0000', $row['amount']);

        $this->assertSame('PurchaseOrder', $row['documentType']);
        $this->assertSame('PO-PHONE-0001', $row['documentNo']);

        // ⭐ নথির নিজের public_id — ইনবক্স থেকে §১০-এ কাগজটা খোলার চাবি; ক্রমিক id নয়
        $po = PurchaseOrder::query()->findOrFail($this->order->approvable_id);
        $this->assertSame($po->public_id, $row['documentId']);
        $this->assertNotSame((string) $po->id, (string) $row['documentId']);
        $this->assertSame('order', $row['action']);
        $this->assertSame(1, $row['currentLevel']);
        $this->assertSame('কেরানি', $row['requesterName']);
        $this->assertStringContainsString('Cement for the Netrakona depot', (string) $row['summary']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['requestedAt']));
    }

    /** ⓘ পাতা-ভাগ কার্সর ধরে — দ্বিতীয় পাতায় অন্য সারি, শেষে `null`। */
    public function test_the_list_pages_by_cursor_without_losing_a_row(): void
    {
        $second = $this->orderAwaiting('PO-PHONE-0002', now()->subMinutes(30));
        $signer = $this->giveKey($this->signer);

        $first = $this->inbox($signer, '?limit=1')->assertOk();
        $this->assertCount(1, $first->json('rows'));
        $this->assertNotNull($first->json('nextCursor'));

        $next = $this->inbox($signer, '?limit=1&cursor='.urlencode((string) $first->json('nextCursor')))->assertOk();
        $this->assertCount(1, $next->json('rows'));
        $this->assertNull($next->json('nextCursor'));

        $this->assertEqualsCanonicalizing(
            [$this->order->public_id, $second->public_id],
            [...$this->idsIn($first), ...$this->idsIn($next)],
        );
    }

    /** ⭐ সই — চাবি ছাড়া ৪০৩ আর কিছুই নড়ে না; একই মানুষকে চাবি দিলে ২০০। */
    public function test_the_approve_door_opens_only_once_the_same_person_is_given_the_key(): void
    {
        $this->as($this->signer);
        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertForbidden();
        $this->stillWaiting($this->order);

        $signer = $this->giveKey($this->signer);
        $this->as($signer);

        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve", ['remarks' => 'ঠিক আছে'])
            ->assertOk()
            ->assertJson(['id' => $this->order->public_id, 'status' => Approval::APPROVED]);

        $this->assertSame(Approval::APPROVED, $this->order->fresh()->status);
    }

    /** ⚠️ কারণ ছাড়া "না" হয় না — ৪২২, আর কাগজটা অপেক্ষাতেই। */
    public function test_a_rejection_without_a_reason_is_refused_and_nothing_moves(): void
    {
        $signer = $this->giveKey($this->signer);
        $this->as($signer);

        $this->postJson("/api/v1/approvals/{$this->order->public_id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->stillWaiting($this->order);
        $this->assertSame(0, $this->order->decisions()->count());

        // ⓘ কারণ দিলে একই মানুষ, একই কাগজ — এবার হয়
        $this->postJson("/api/v1/approvals/{$this->order->public_id}/reject", ['remarks' => 'দাম বেশি'])
            ->assertOk()
            ->assertJson(['status' => Approval::REJECTED]);
    }

    /** ⓘ চুক্তি §৫ খ — ৪০৯ মানে "আর নেই", ৪০৩ বা ৫০০ নয়। */
    public function test_an_approval_already_decided_answers_409(): void
    {
        $signer = $this->giveKey($this->signer);
        $this->as($signer);

        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertOk();

        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertStatus(409);
        $this->postJson("/api/v1/approvals/{$this->order->public_id}/reject", ['remarks' => 'late'])->assertStatus(409);

        $this->assertSame(Approval::APPROVED, $this->order->fresh()->status);
        $this->assertSame(1, $this->order->decisions()->count());
    }

    /**
     * ⭐ চাবি আছে, কিন্তু ছকে নাম নেই — ৪০৩; একই মানুষকে ছকে বসালে ২০০।
     *
     * ⓘ ফোনের `permissions` তালিকা পাহারা নয় (চুক্তি §৫ ক) — `canDecide()`
     * সার্ভারেই দেখে।
     */
    public function test_a_key_without_a_place_in_the_flow_is_403_until_that_same_person_is_named(): void
    {
        $outsider = $this->giveKey($this->member('Outsider'));
        $this->as($outsider);

        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertForbidden();
        $this->stillWaiting($this->order);

        ApprovalFlowStep::create([
            'approval_flow_id' => $this->orderFlow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $outsider->id,
        ]);
        app()->forgetInstance(ApprovalEngine::class);

        $this->as($outsider);
        $this->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertOk();
    }

    /**
     * ⛔ অন্য কোম্পানির কাগজ — তালিকায় নেই, সই-দরজায় ৪০৪।
     *
     * ⓘ ঐ কোম্পানির ছকেও তিনি আছেন, আর ইঞ্জিন সেখানে তাঁকে "হ্যাঁ" বলে
     * (নিচে মাপা) — তাই অনুপস্থিতিটা কোম্পানির দেয়ালের কাজ।
     */
    public function test_another_companys_approval_never_appears_and_cannot_be_signed(): void
    {
        $signer = $this->giveKey($this->signer);
        $signer->companies()->attach($this->mart->id, ['is_active' => true]);

        $foreign = CompanyContext::forCompany($this->mart->id, function () use ($signer): Approval {
            $flow = ApprovalFlow::create(['module' => 'purchase', 'action' => 'order']);
            ApprovalFlowStep::create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'approver_type' => ApprovalFlowStep::BY_USER,
                'approver_id' => $signer->id,
            ]);

            $branch = Branch::create(['company_id' => $this->mart->id, 'code' => 'PHX', 'name_en' => 'Phone X']);

            return Approval::create([
                'company_id' => $this->mart->id,
                'approvable_type' => Branch::class,
                'approvable_id' => $branch->id,
                'module' => 'purchase',
                'action' => 'order',
                'amount' => '5000',
                'status' => Approval::PENDING,
                'current_level' => 1,
                'requested_by' => $this->clerk->id,
                'requested_at' => now()->subDay(),
            ]);
        });

        app()->forgetInstance(ApprovalEngine::class);
        $this->assertTrue(
            CompanyContext::forCompany($this->mart->id, fn () => app(ApprovalEngine::class)->canDecide($foreign, $signer)),
            'ঐ কোম্পানিতে তিনি সই দিতে পারেন না — তাহলে দেয়ালের দাবিটা কিছুই মাপছে না।',
        );
        app()->forgetInstance(ApprovalEngine::class);

        $ids = $this->idsIn($this->inbox($signer)->assertOk());
        $this->assertNotContains($foreign->public_id, $ids);
        $this->assertContains($this->order->public_id, $ids);

        $this->as($signer);
        $this->postJson("/api/v1/approvals/{$foreign->public_id}/approve")->assertNotFound();
        $this->stillWaiting($foreign);
    }

    /**
     * ⭐ চুরি যাওয়া refresh টোকেনে এই দরজাগুলো খোলে না।
     *
     * ⓘ একই মানুষ, চাবিসহ — কেবল টোকেনটা আলাদা; app টোকেনে ঐ মানুষটার
     * জন্যই দরজা খোলে।
     */
    public function test_a_refresh_token_cannot_open_these_doors(): void
    {
        $signer = $this->giveKey($this->signer);
        $refresh = $signer->createToken('stolen', [AuthController::REFRESH])->plainTextToken;
        $app = $signer->createToken('phone', [AuthController::APP])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)->getJson('/api/v1/approvals/pending')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)->postJson("/api/v1/approvals/{$this->order->public_id}/approve")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($refresh)
            ->postJson("/api/v1/approvals/{$this->order->public_id}/reject", ['remarks' => 'x'])
            ->assertForbidden();

        $this->stillWaiting($this->order);

        $this->app['auth']->forgetGuards();
        $this->withToken($app)->getJson('/api/v1/approvals/pending')->assertOk();
    }
}
