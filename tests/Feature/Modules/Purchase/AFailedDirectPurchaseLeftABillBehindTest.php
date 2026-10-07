<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\PaymentMethod;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * ব্যর্থ সরাসরি ক্রয় একটা বিল ফেলে রেখে যেত — লাইভ QA (hp2, TCL), PBL-0002।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[DirectPurchaseService::complete()]] তিনটা আলাদা লেনদেনে চলত — কাগজ,
 * সই, মাল-ও-টাকা — যাতে সইয়ে আটকানো খসড়াটা হারিয়ে না যায়। ⚠️ কিন্তু
 * তার দাম ছিল: **অন্য যেকোনো** ভুলেও আগের ধাপগুলো থেকে যেত। কাগজ বানানোর
 * পরে ভুল হলে একটা অনাথ খসড়া বিল; পরিশোধের ধাপে ভুল হলে বিল **নিশ্চিত**,
 * মাল গুদামে, দেনা খাতায় — অথচ ব্যবহারকারী দেখলেন "হয়নি", আর আবার জমা
 * দিলেন। দ্বিতীয়বারে দ্বিগুণ মাল, দ্বিগুণ দেনা।
 *
 * ⭐ এখন একটাই লেনদেন। কেবল "সইয়ের অপেক্ষা" ভিতরে ধরা হয়, তাই খসড়া আর
 * অনুমোদনের অনুরোধ থাকে (আগের সংশোধনটা অক্ষত); বাকি সব ভুলে কিছুই থাকে না।
 */
final class AFailedDirectPurchaseLeftABillBehindTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * ⛔ পরিশোধের ধাপে ভুল হলে বিল, মাল, দেনা — কিছুই থাকে না।
     *
     * ⓘ ট্রিগার একটা দল-খাত (group account) থেকে পরিশোধ — খাতা সেটা
     * সবসময় ফেরায়। বিলটা ঠিক, কেবল টাকাটা ভুল; আগে বিল নিশ্চিত হয়ে যেত।
     */
    public function test_a_failure_while_paying_leaves_no_bill_no_stock_no_books(): void
    {
        $before = $this->counts();

        try {
            app(DirectPurchaseService::class)->complete(
                $this->documentData() + ['deposits' => [[
                    'amount' => '100',
                    'payment_method_id' => PaymentMethod::query()->orderBy('id')->firstOrFail()->id,
                    'account_id' => Account::query()->where('code', StandardChart::BANK)->firstOrFail()->id,
                ]]],
                $this->lines(),
            );
            $this->fail('ⓘ দাবির ভিত্তি: দল-খাত থেকে পরিশোধ ফেরত আসার কথা।');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(HeldForApproval::class, $e);
        }

        $this->assertSame($before, $this->counts(), implode(PHP_EOL, [
            '⛔ ব্যর্থ ক্রয় কিছু রেখে গেছে (বিল, মজুদ-চলাচল, খাতার সারি):',
            'আগে '.json_encode($before).' — পরে '.json_encode($this->counts()),
            'ব্যবহারকারী "হয়নি" দেখে আবার জমা দিলে এগুলো দ্বিগুণ হত।',
        ]));
    }

    /** ⭐ সইয়ে আটকালে খসড়া আর অনুরোধ দুটোই থাকে — আগের সংশোধন অক্ষত। */
    public function test_a_held_purchase_still_keeps_its_draft_and_its_request(): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(), 'module' => 'purchase', 'action' => 'bill',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id,
        ]);

        try {
            app(DirectPurchaseService::class)->complete($this->documentData(), $this->lines());
            $this->fail('ⓘ দাবির ভিত্তি: ছক বসানো, তাই সই লাগার কথা।');
        } catch (HeldForApproval) {
            // প্রত্যাশিত
        }

        $bill = PurchaseBill::query()->latest('id')->firstOrFail();
        $this->assertSame(DocumentStatus::DRAFT, $bill->status, '⛔ আটকানো বিল খসড়া হয়ে থাকেনি।');
        $this->assertTrue(
            DB::table('approvals')->where('approvable_id', $bill->id)->exists(),
            '⛔ অনুমোদনের অনুরোধটা মুছে গেছে — কেউ সই দিতে পারবে না।',
        );
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'bills' => PurchaseBill::query()->withTrashed()->count(),
            'moves' => DB::table('inv_stock_movements')->count(),
            'ledger' => LedgerEntry::query()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function documentData(): array
    {
        return [
            'supplier_id' => Supplier::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'supplier_bill_no' => 'FAIL-'.fake()->unique()->numberBetween(1000, 9999),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '10', 'rate' => '60', 'sales_price' => '60']];
    }
}
