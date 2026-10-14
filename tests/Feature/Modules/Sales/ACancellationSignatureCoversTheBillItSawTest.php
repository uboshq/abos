<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceCancellation;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SaleEditor;
use App\Modules\Sales\Services\SalesInvoiceCancellationService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ বাতিলের সই কেবল সেই বিলের, যেটা দেখে মালিক সই দিলেন — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ আগে: কর্মী ২ প্যাকেটের বিলের বাতিল চাইলেন (সইয়ের অপেক্ষা); তারপর বিলটা সম্পাদনায় ৫ প্যাকেট করা যেত ([[SaleEditor]]);
 * মালিক "২ প্যাকেটের বিল বাতিল"-এ সই দিলেন, আর উল্টে গেল ৫ প্যাকেটের বিল।
 *
 * দাবি:
 *  - বাতিলের অনুরোধ খোলা থাকলে বিল সম্পাদনা হয় না — কারণসহ;
 *  - তবু কোনো পথে বিলটা বদলে গেলে বাতিল পাকা হয় না — সই আগের বিলের;
 *  - মালিক "না" বললে (অনুরোধ আর খোলা নেই) সম্পাদনা আবার খোলে।
 */
final class ACancellationSignatureCoversTheBillItSawTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** @var array<string, mixed> */
    private array $data;

    private Product $biscuit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'own_transport' => '1',
        ];

        $role = Role::query()->where('company_id', $this->company->id)->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->firstOrFail();
        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => 'sales', 'action' => SalesInvoiceCancellationService::APPROVAL_ACTION,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'owner',
            'approver_type' => ApprovalFlowStep::BY_ROLE, 'approver_id' => (int) $role->id,
        ]);
    }

    public function test_the_bill_cannot_be_edited_while_its_cancellation_waits(): void
    {
        [$sale, $paper] = $this->askedToCancel();

        $this->actingAs($this->owner);

        try {
            app(SaleEditor::class)->edit($sale->fresh(), $this->data, $this->lines('5'));
            $this->fail('⛔ বাতিলের অনুরোধ খোলা, অথচ বিলটা সম্পাদনায় বদলে গেল — মালিক সই দিতেন এক বিলে, উল্টাত আরেকটা।');
        } catch (ValidationException $e) {
            $this->assertSame(__('sales::validation.edit_while_cancelling', ['no' => $sale->document_no, 'cxl' => $paper->document_no]),
                $e->errors()['edit'][0] ?? null, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(0, bccomp('20', (string) $sale->fresh()->total, 4), '⛔ থামার পরেও বিলের মোট বদলেছে।');

        // ⭐ সুখী পথ অটুট — বদলহীন বিলে সই পড়লেই বাতিল পাকা
        app(ApprovalEngine::class)->approve(app(ApprovalEngine::class)->latestFor($paper, SalesInvoiceCancellationService::APPROVAL_ACTION), $this->owner, 'ঠিক আছে');
        $this->assertSame(DocumentStatus::CONFIRMED, $paper->fresh()->status);
        $this->assertSame(DocumentStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_a_bill_that_changed_anyway_is_not_cancelled_on_the_old_signature(): void
    {
        [$sale, $paper] = $this->askedToCancel();

        // ⓘ কোনো পথে (আজ বন্ধ, কাল নতুন কোনো দরজা) বিলটা বদলে গেছে
        DB::table('sal_invoices')->where('id', $sale->id)->update(['total' => '50.0000']);

        $this->actingAs($this->owner);

        try {
            app(SalesInvoiceCancellationService::class)->confirm($paper->fresh(), $this->owner);
            $this->fail('⛔ ২০ টাকার বিল বাতিলের সই ৫০ টাকার বিল উল্টে দিল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('sales::cancellation.bill_changed', ['no' => $sale->document_no, 'cxl' => $paper->document_no]),
                $e->errors()['reason'][0] ?? null, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status, '⛔ বদলে যাওয়া বিল পুরনো সইয়ে বাতিল হলো।');
        $this->assertNotSame(DocumentStatus::CONFIRMED, $paper->fresh()->status);
    }

    public function test_a_refused_cancellation_opens_the_edit_again(): void
    {
        [$sale, $paper] = $this->askedToCancel();

        $this->actingAs($this->owner);
        app(ApprovalEngine::class)->reject(app(ApprovalEngine::class)->latestFor($paper, SalesInvoiceCancellationService::APPROVAL_ACTION), $this->owner, 'বাতিল নয়');

        $edited = app(SaleEditor::class)->edit($sale->fresh(), $this->data, $this->lines('5'));
        $this->assertSame(0, bccomp('50', (string) $edited->fresh()->total, 4), '⛔ "না"-র পরেও বিলটা সম্পাদনায় আটকে রইল।');
    }

    /** @return array{0: SalesInvoice, 1: SalesInvoiceCancellation} ২ প্যাকেট × ১০ — কর্মী বাতিল চেয়েছেন, মালিকের সইয়ের অপেক্ষা */
    private function askedToCancel(): array
    {
        $this->actingAs($this->owner);
        $sale = app(DirectSaleService::class)->complete($this->data, $this->lines('2'))['invoice']->fresh();

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $this->company->id, fn () => $clerk->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['sales.invoice.view', 'sales.invoice.cancellation'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $clerk = $clerk->fresh();

        $this->actingAs($clerk);
        $paper = app(SalesInvoiceCancellationService::class)->request($sale, $clerk, 'দর ভুল');
        $this->assertSame(SalesInvoiceCancellation::AWAITING, $paper->status, 'প্রস্তুতি — মালিকের সইয়ের অপেক্ষা');

        return [$sale->fresh(), $paper];
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $qty): array
    {
        return [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']];
    }
}
