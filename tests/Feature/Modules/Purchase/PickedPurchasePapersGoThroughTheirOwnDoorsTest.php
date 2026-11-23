<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Bulk\BulkOutcome;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ক্রয়ের তালিকায় বাছা সারি — বিল আর আদেশ একসাথে নিশ্চিত/অনুমোদনে, খসড়া বাতিল, আদেশের অনুমোদন
 * (মালিক, ১৯ নভেম্বর ২০২৬, নমুনা Q2W6mM3S v3; [[PurchaseBulkController]], [[BulkRun]])।
 *
 * ⛔ বিপজ্জনক দিকগুলো এখানে খাওয়ানো হয়: খসড়া নয় এমন কাগজ, চাবি নেই এমন মানুষ, অন্য শাখার কাগজ, কারণ ছাড়া বাতিল,
 *   নিজের কাগজ নিজে অনুমোদন। প্রতিটায় ডাটাবেজে কাগজের অবস্থা দেখা — কেবল উত্তরের কোড নয়।
 * ⓘ বার আর টিক-বাক্স কেবল তিন ABOS রূপে; SAP (`tiles`) আর Dynamic-এ কিছুই নয়।
 */
final class PickedPurchasePapersGoThroughTheirOwnDoorsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'rose'])->save();
        $this->actingAs($this->owner->fresh());
    }

    /** ⭐ খসড়া পাকা হয়; আগেই পাকা বিল বাদ, "খসড়া নয়" বলে */
    public function test_picked_draft_bills_are_confirmed_and_a_confirmed_one_is_skipped(): void
    {
        $draft = $this->bill('B-1');
        $done = app(PurchaseBillService::class)->confirm($this->bill('B-2'));
        $this->assertSame(DocumentStatus::CONFIRMED, $done->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — দ্বিতীয় বিল পাকা হয়নি।');

        $this->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'submit']), ['ids' => [$draft->id, $done->id]])
            ->assertRedirect(route('purchase.bill.index'));

        $this->assertSame(DocumentStatus::CONFIRMED, $draft->fresh()->status, '⛔ বাছা খসড়া বিল পাকা হয়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $done->fresh()->status);
        $result = $this->bulkResult();
        $this->assertStringContainsString($done->document_no.': '.BulkOutcome::reason('not_draft'), (string) $result['details'],
            '⛔ পাকা বিলটা "খসড়া নয়" বলে বাদের তালিকায় নেই।');
        $this->assertStringNotContainsString($draft->document_no, (string) $result['details'], 'পাকা হওয়া বিলটাও বাদের তালিকায়।');
    }

    /** ⛔ নিশ্চিত করার চাবি (purchase.bill.create) নেই — গোটা অনুরোধ ৪০৩, কিছুই বদলায় না */
    public function test_a_viewer_without_the_confirm_key_is_refused(): void
    {
        $draft = $this->bill('B-3');
        $viewer = $this->clerk(['purchase.bill.view']);

        $this->actingAs($viewer)
            ->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'submit']), ['ids' => [$draft->id]])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'cancel']), ['ids' => [$draft->id], 'reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status, '⛔ চাবি ছাড়া বিল বদলে গেল।');
    }

    /** ⛔ শাখা A-র কর্মী শাখা B-র বিলের id হাতে বসালে — "পাওয়া গেল না", বিল খসড়াই থাকে */
    public function test_a_bill_of_another_branch_is_not_found(): void
    {
        $a = $this->company->defaultBranch();
        $b = Branch::query()->where('company_id', $this->company->id)->whereKeyNot($a->id)->firstOrFail();

        $ours = $this->bill('A-1');
        $theirs = $this->bill('B-9');
        DB::table('pur_bills')->where('id', $ours->id)->update(['branch_id' => $a->id]);
        DB::table('pur_bills')->where('id', $theirs->id)->update(['branch_id' => $b->id]);

        $clerk = $this->clerk(['purchase.bill.view', 'purchase.bill.create'], $a);

        $this->actingAs($clerk)
            ->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'submit']), ['ids' => [$ours->id, $theirs->id]])
            ->assertRedirect();

        $this->assertSame(DocumentStatus::DRAFT, $theirs->fresh()->status, '⛔ অন্য শাখার বিল পাকা হয়ে গেল।');
        $this->assertSame(DocumentStatus::CONFIRMED, $ours->fresh()->status, 'নিজের শাখার বিল পাকা হয়নি।');
        $this->assertStringContainsString('#'.$theirs->id.': '.BulkOutcome::reason('not_found'), (string) $this->bulkResult()['details'],
            '⛔ অন্য শাখার বিল "পাওয়া গেল না" বলে বাদ নয়।');
    }

    /** ⛔ কারণ ছাড়া বাতিল নয়; কারণসহ খসড়া বাতিল, পাকা বিল বাদ */
    public function test_cancel_needs_a_reason_and_takes_only_drafts(): void
    {
        $one = $this->bill('C-1');
        $two = $this->bill('C-2');
        $posted = app(PurchaseBillService::class)->confirm($this->bill('C-3'));

        $this->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'cancel']), ['ids' => [$one->id, $two->id]])
            ->assertRedirect();
        $this->assertSame(DocumentStatus::DRAFT, $one->fresh()->status, '⛔ কারণ ছাড়াই বিল বাতিল হলো।');
        $this->assertSame(DocumentStatus::DRAFT, $two->fresh()->status, '⛔ কারণ ছাড়াই বিল বাতিল হলো।');
        $this->assertStringContainsString($one->document_no.': '.BulkOutcome::reason('no_reason'), (string) $this->bulkResult()['details']);

        $this->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'cancel']), [
            'ids' => [$one->id, $two->id, $posted->id], 'reason' => 'Wrong supplier',
        ])->assertRedirect();

        foreach ([$one, $two] as $bill) {
            $fresh = $bill->fresh();
            $this->assertSame(DocumentStatus::CANCELLED, $fresh->status, '⛔ কারণসহ বাছা খসড়া বাতিল হয়নি।');
            $this->assertSame('Wrong supplier', $fresh->cancel_reason, 'বাতিলের কারণ কাগজে বসেনি।');
        }
        $this->assertSame(DocumentStatus::CONFIRMED, $posted->fresh()->status, '⛔ একসাথে বাতিলে পাকা বিলও বাতিল হলো।');
        $this->assertStringContainsString($posted->document_no.': '.BulkOutcome::reason('not_draft'), (string) $this->bulkResult()['details']);
    }

    /**
     * ⭐ ছক বসানো আদেশ — কর্মীর "পাঠান" অনুমোদনে যায় (গোনা হয় পাঠানো), দ্বিতীয়বার "আগেই অপেক্ষায়"; ⛔ কর্মী নিজের আদেশ নিজে
     *   অনুমোদন দিতে পারেন না; মালিক পারেন — আর শেষ সইয়ে আদেশ নিজেই পাকা।
     */
    public function test_an_order_goes_to_approval_its_maker_cannot_approve_it_and_the_owner_can(): void
    {
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'order',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner->id]);

        $clerk = $this->clerk(['purchase.order.view', 'purchase.order.create', 'purchase.order.update', 'approval.decide']);
        $this->actingAs($clerk);
        $order = $this->order();

        $this->post(route('purchase.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])
            ->assertRedirect(route('purchase.order.index'));
        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status, '⛔ ছক থাকা আদেশ সই ছাড়াই পাকা হলো।');
        $asked = app(ApprovalEngine::class)->latestFor($order->fresh(), 'order');
        $this->assertSame(Approval::PENDING, $asked?->status, '⛔ "পাঠান" আদেশটাকে অনুমোদনে পাঠায়নি।');
        $this->assertNull($this->bulkResult()['details'], 'অনুমোদনে পাঠানো আদেশ বাদের তালিকায় গেছে।');

        $this->post(route('purchase.bulk', ['paper' => 'order', 'action' => 'submit']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertStringContainsString($order->document_no.': '.BulkOutcome::reason('already_waiting'), (string) $this->bulkResult()['details']);
        $this->assertSame(1, Approval::query()->where('approvable_type', PurchaseOrder::class)->where('approvable_id', $order->id)->count(),
            '⛔ দ্বিতীয় "পাঠান" নতুন অনুরোধ বসাল।');

        $this->post(route('purchase.bulk.approve', ['paper' => 'order']), ['ids' => [$order->id]])->assertRedirect();
        $this->assertSame(Approval::PENDING, $asked->fresh()->status, '⛔ কর্মী নিজের আদেশ নিজে অনুমোদন দিলেন।');
        $this->assertSame(DocumentStatus::DRAFT, $order->fresh()->status);
        $this->assertStringContainsString($order->document_no.': '.BulkOutcome::reason('own_paper'), (string) $this->bulkResult()['details']);

        $this->actingAs($this->owner->fresh())
            ->post(route('purchase.bulk.approve', ['paper' => 'order']), ['ids' => [$order->id]])
            ->assertRedirect();
        $this->assertSame(Approval::APPROVED, $asked->fresh()->status, '⛔ মালিকের একসাথে অনুমোদন খাটেনি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $order->fresh()->status, '⛔ শেষ সইয়ের পরে আদেশ পাকা হয়নি।');
        $this->assertNull($this->bulkResult()['details']);
    }

    /** ⓘ বিল টাকার কাজ — একসাথে অনুমোদন নেই (বোতামও না, ঠিকানাও না) */
    public function test_bills_offer_no_bulk_approval(): void
    {
        $draft = $this->bill('D-1');

        $this->post(route('purchase.bulk', ['paper' => 'bill', 'action' => 'approve']), ['ids' => [$draft->id]])->assertNotFound();
        $this->assertSame(DocumentStatus::DRAFT, $draft->fresh()->status);

        $html = (string) $this->get(route('purchase.bill.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('purchase.bulk', ['paper' => 'bill', 'action' => 'approve']), $html);
    }

    /** ⭐ ABOS রূপে টিক-বাক্স আর বার; ⛔ SAP আর Dynamic-এ একটাও নয় */
    public function test_the_bar_shows_in_the_abos_look_only(): void
    {
        $this->bill('E-1');
        $order = $this->order();

        $bills = (string) $this->get(route('purchase.bill.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-bulk-pick', $bills, 'ABOS রূপে বিলের তালিকায় টিক-বাক্স নেই।');
        $this->assertStringContainsString('id="purchase-bill-bulk"', $bills, 'ABOS রূপে বিলের তালিকায় বার নেই।');
        $this->assertStringContainsString(route('purchase.bulk', ['paper' => 'bill', 'action' => 'submit']), $bills);
        $this->assertStringContainsString(route('purchase.bulk', ['paper' => 'bill', 'action' => 'cancel']), $bills);

        $orders = (string) $this->get(route('purchase.order.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$order->id.'" form="purchase-order-bulk"', $orders, 'ABOS রূপে আদেশের সারিতে টিক-বাক্স নেই।');
        $this->assertStringContainsString(route('purchase.bulk.approve', ['paper' => 'order']), $orders);

        foreach (['tiles', 'dynamic'] as $look) {
            $this->owner->forceFill(['ui' => $look])->save();
            $this->actingAs($this->owner->fresh());

            foreach (['purchase.bill.index' => 'purchase-bill-bulk', 'purchase.order.index' => 'purchase-order-bulk'] as $list => $form) {
                $html = (string) $this->get(route($list))->assertOk()->getContent();
                $this->assertStringNotContainsString('data-bulk-pick', $html, "⛔ {$look} রূপে {$list}-এ টিক-বাক্স।");
                $this->assertStringNotContainsString('id="'.$form.'"', $html, "⛔ {$look} রূপে {$list}-এ একসাথে কাজের বার।");
            }
        }
    }

    /** @return array{summary?: string, details?: ?string} শেষ একসাথে কাজের ফল */
    private function bulkResult(): array
    {
        $result = session(BulkOutcome::FLASH);
        $this->assertIsArray($result, 'একসাথে কাজের ফলের বার্তা নেই।');

        return $result;
    }

    /** @param list<string> $keys */
    private function clerk(array $keys, ?Branch $only = null): User
    {
        $branch = $only ?? $this->company->defaultBranch();
        $clerk = User::factory()->create([
            'current_company_id' => $this->company->id, 'current_branch_id' => $branch?->id, 'is_active' => true, 'ui' => 'rose',
        ]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);

        if ($only !== null) {
            UserDataScope::query()->withoutGlobalScopes()->create([
                'company_id' => $this->company->id, 'user_id' => $clerk->id, 'scope_type' => UserDataScope::BRANCH, 'scope_id' => $only->id,
            ]);
        }

        CompanyContext::forCompany($this->company->id, function () use ($clerk, $keys): void {
            foreach ($keys as $key) {
                $clerk->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $clerk->fresh();
    }

    private function bill(string $supplierNo): PurchaseBill
    {
        return app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
                'supplier_bill_no' => $supplierNo],
            [['product_id' => $this->product(), 'qty' => '10', 'rate' => '100']],
        )->fresh();
    }

    private function order(): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product(), 'ordered_qty' => '10', 'rate' => '100', 'tax' => '0']],
        )->fresh();
    }

    private function product(): int
    {
        return (int) Product::query()
            ->whereNull('tax_id')
            ->where('track_batch', false)
            ->where('track_serial', false)
            ->where('qc_required', false)
            ->orderBy('id')
            ->value('id');
    }
}
