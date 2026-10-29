<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের সই-বাক্স "সংশোধনে ফেরত" বোতামের জন্য প্রতিটা সারির কাগজ আলাদা করে পড়ত — a6, ২০ অক্টোবর ২০২৬ (fe-র অনুমতি)।
 *
 * ⓘ এখন গোটা পাতা এক ডাকে ([[ApprovalEngine::canSendBackMany()]]), নিয়ম একটাই ([[ApprovalEngine::sendBackAllowed()]])।
 *
 * দাবি:
 *  - মিশ্র পাতায় প্রতিটা অনুরোধের হ্যাঁ/না একা ডাকার ([[ApprovalEngine::canSendBack()]]) সমান, আর প্রত্যাশিত উত্তরের সমান —
 *    "না"-এর প্রতিটা কারণ: অপেক্ষায় নেই, কাগজ নেই, কাগজ খসড়া নয়, কাগজ নিজে "না" বলে; আর "হ্যাঁ" দুই রকম: কাগজ "হ্যাঁ" বলে,
 *    কাগজের কোনো মত নেই।
 *  - পাতার হিসাব কাগজের ধরন ধরে এক ডাকে — সারি যতই হোক।
 *  - ফোনের সই-বাক্সে প্রতিটা সারির `canSendBack` সেই একই উত্তর; আর ২ সারি ও ২০ সারির বাক্সে মোট কোয়েরি সমান।
 */
final class TheSigningBoxReadEveryPaperAgainToOfferSendBackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app(SettingsService::class)->set(PhoneModules::PREFIX.'approval', true);

        $this->signer = $this->person(['approval.decide', 'approval.view']);
        $this->writer = $this->person(['accounts.voucher.create']);
    }

    public function test_the_page_answer_equals_the_single_answer_for_every_reason(): void
    {
        // ⓘ পাকা ভাউচার আগে — ছক বসার আগে, যাতে সই ছাড়াই পাকা হয়
        $posted = $this->voucher(post: true);
        $draft = $this->voucher();
        $po = PurchaseOrder::create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'PO-SB-1',
            'supplier_id' => Supplier::query()->value('id'), 'trx_date' => now()->toDateString(), 'total' => '1000',
            'status' => DocumentStatus::DRAFT, 'created_by' => $this->writer->id,
        ]);
        $this->assertSame(DocumentStatus::DRAFT, (string) $po->fresh()->status, 'পরীক্ষার ভিত্তি — আদেশ খসড়া');
        $this->assertFalse(method_exists($po, 'canBeSentBack'), 'পরীক্ষার ভিত্তি — আদেশের নিজের কোনো মত নেই');

        $expected = [
            'হ্যাঁ — খসড়া ভাউচার, হিসাবের নিজের সই' => [$this->ask(Voucher::class, $draft->id, VoucherApproval::MODULE), true],
            'হ্যাঁ — খসড়া আদেশ, কাগজের মত নেই' => [$this->ask(PurchaseOrder::class, $po->id, 'purchase', 'order'), true],
            'না — অনুরোধ অপেক্ষায় নেই' => [$this->ask(Voucher::class, $draft->id, VoucherApproval::MODULE, status: Approval::APPROVED), false],
            'না — কাগজটাই নেই' => [$this->ask(Voucher::class, 99999999, VoucherApproval::MODULE), false],
            'না — কাগজ খসড়া নয় (পাকা)' => [$this->ask(Voucher::class, $posted->id, VoucherApproval::MODULE), false],
            'না — কাগজ নিজে বলে না (অন্য কাগজের সই)' => [$this->ask(Voucher::class, $draft->id, 'finance'), false],
        ];

        $engine = app(ApprovalEngine::class);
        $approvals = collect($expected)->map(fn (array $e) => $e[0]->fresh())->values();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $many = $engine->canSendBackMany($approvals);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // ⓘ অপেক্ষার অনুরোধগুলোয় দুই ধরনের কাগজ — ভাউচার আর আদেশ — তাই দুই ডাক, সারি ছয়টা হলেও
        $this->assertSame(2, $queries, "⛔ পাতার হিসাব সারি ধরে কাগজ পড়ে: {$queries} কোয়েরি।");

        foreach ($expected as $why => [$approval, $answer]) {
            $single = $engine->canSendBack($approval->fresh());
            $this->assertSame($answer, $single, "⛔ একার উত্তর ভুল: {$why}");
            $this->assertSame($single, $many[(int) $approval->id] ?? null, "⛔ পাতার উত্তর একার উত্তরের সাথে মেলে না: {$why}");
        }
    }

    public function test_the_phone_inbox_says_the_same_and_asks_as_much_for_twenty_rows_as_for_two(): void
    {
        $posted = $this->voucher(post: true);
        $this->flow();

        $yes = $this->ask(Voucher::class, $this->voucher()->id, VoucherApproval::MODULE);
        $no = $this->ask(Voucher::class, $posted->id, VoucherApproval::MODULE);

        $rows = $this->inbox(2);
        $this->assertTrue($rows->firstWhere('id', $yes->public_id)['canSendBack'], '⛔ খসড়া ভাউচারে "সংশোধনে ফেরত" নেই।');
        $this->assertFalse($rows->firstWhere('id', $no->public_id)['canSendBack'], '⛔ পাকা ভাউচারে "সংশোধনে ফেরত" দেখায়।');
        $two = $this->inboxQueries(2);

        foreach (range(1, 18) as $i) {
            $this->ask(Voucher::class, ($i % 3 === 0 ? $posted : $this->voucher())->id, VoucherApproval::MODULE);
        }

        $twenty = $this->inboxQueries(20);
        $this->assertSame($two, $twenty, "⛔ সই-বাক্স সারি ধরে কোয়েরি বাড়ায়: ২ সারিতে {$two}, ২০ সারিতে {$twenty}।");

        // ⓘ বাক্সের প্রতিটা উত্তর একার উত্তরের সমান
        foreach ($this->inbox(20) as $row) {
            $approval = Approval::query()->where('public_id', $row['id'])->firstOrFail();
            $this->assertSame(app(ApprovalEngine::class)->canSendBack($approval), $row['canSendBack'], '⛔ বাক্সের উত্তর একার উত্তরের সাথে মেলে না।');
        }
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    private function inboxQueries(int $rows): int
    {
        $this->inbox($rows); // ⓘ গরম করা — সেটিং আর চাবির প্রথম পড়া মাপের বাইরে
        $this->phone($this->signer);
        $this->app->forgetInstance(ApprovalEngine::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/approvals/pending?limit=25')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function inbox(int $rows)
    {
        $this->phone($this->signer);
        $this->app->forgetInstance(ApprovalEngine::class);
        $answer = collect($this->getJson('/api/v1/approvals/pending?limit=25')->assertOk()->json('rows'));
        $this->assertCount($rows, $answer, 'পরীক্ষার ভিত্তি — বাক্সের সারি');

        return $answer;
    }

    private function flow(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => VoucherApproval::MODULE, 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    private function ask(string $class, int $id, string $module, string $action = 'journal', string $status = Approval::PENDING): Approval
    {
        return Approval::create([
            'company_id' => $this->company->id, 'approvable_type' => $class, 'approvable_id' => $id,
            'module' => $module, 'action' => $action, 'amount' => '500', 'status' => $status, 'current_level' => 1,
            'requested_by' => $this->writer->id, 'requested_at' => now(),
            'decided_at' => $status === Approval::PENDING ? null : now(),
        ]);
    }

    private function voucher(bool $post = false): Voucher
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->as($this->writer);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ফেরতের প্রশ্ন',
                'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );

        if ($post) {
            app(VoucherService::class)->post($voucher);
            $this->assertTrue($voucher->fresh()->isPosted(), 'পরীক্ষার ভিত্তি — ভাউচার পাকা');
        }

        return $voucher->fresh();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
