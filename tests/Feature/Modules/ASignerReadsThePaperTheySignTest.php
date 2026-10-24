<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Sales\Services\CollectionService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ সইকারী নিজের সই করা কাগজটা পড়েন — কেবল পড়েন (fe-র সিদ্ধান্ত, ২৪ অক্টোবর ২০২৬; মালিকের অভিযোগ "নোটিফিকেশনে ক্লিক
 * করলে দেখা যায় না")।
 *
 * <p>⛔ সইয়ের পাতা আর নোটিশ ক্রয় বিল, আদায়, বিক্রয় আদেশ বা টিলের লিংক দেয় তাঁকে, যাঁর সই চাওয়া হয়েছে বা যিনি সই দিয়েছেন।
 * কিন্তু ঐ কাগজের পাতার নীতি ([[PurchaseBillPolicy]], [[CollectionPolicy]], [[SalesOrderPolicy]], [[CashTillPolicy]]) কেবল
 * দেখার চাবি দেখত — সইকারীর চাবি না থাকলে ৪০৩, যে কাগজে সই দিলেন সেটাই পড়তে পারতেন না। ভাউচারের নিয়মই এখন সবখানে
 * ([[VoucherPolicy::view()]], [[PaperSigners]]): দেখার চাবি, নয়তো **এই** কাগজের সইকারী।
 *
 * দাবি, প্রতিটা কাগজে (সইকারীর দেখার চাবি নেই):
 *  - যে কাগজে তিনি সই দিয়েছেন — পাতা ২০০;
 *  - একই ধরনের অন্য কাগজ — ৪০৩ (ছাড় কেবল **এই** কাগজে);
 *  - সই করা কাগজটা বদলানো বা পাকা করা — ৪০৩ (ছাড় কেবল পড়ায়)।
 *  - ক্রয় বিলে আরও: নাগালের বাইরের শাখার সইকারী কাগজটা পড়েন (২০০), কিন্তু পাকা করার দরজা ৪০৪ ([[OpensInReach]])।
 */
final class ASignerReadsThePaperTheySignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        Customer::query()->orderBy('id')->firstOrFail()->forceFill(['credit_limit' => '100000'])->save();
    }

    public function test_a_signer_reads_the_purchase_bill_they_signed_and_nothing_more(): void
    {
        $signed = $this->bill();
        $stranger = $this->bill();

        $this->assertSignerReadsOnly(
            'purchase.bill.show', $signed, $stranger, 'ক্রয় বিল',
            edit: route('purchase.bill.edit', $signed->id), confirm: route('purchase.bill.confirm', $signed->id),
        );
    }

    public function test_a_signer_reads_the_collection_they_signed_and_nothing_more(): void
    {
        $signed = $this->collection();
        $stranger = $this->collection();

        $this->assertSignerReadsOnly(
            'sales.collection.show', $signed, $stranger, 'আদায়',
            edit: route('sales.collection.edit', $signed->id), confirm: route('sales.collection.confirm', $signed->id),
        );
    }

    public function test_a_signer_reads_the_sales_order_they_signed_and_nothing_more(): void
    {
        $signed = $this->salesOrder();
        $stranger = $this->salesOrder();

        $this->assertSignerReadsOnly(
            'sales.order.show', $signed, $stranger, 'বিক্রয় আদেশ',
            edit: route('sales.order.edit', $signed->id), confirm: route('sales.order.confirm', $signed->id),
        );
    }

    public function test_a_signer_reads_the_cash_till_they_signed_and_nothing_more(): void
    {
        $signed = app(CashTillService::class)->create(['code' => 'SIGN-1', 'name_en' => 'Signed Box', 'name_bn' => 'সই করা বাক্স']);
        $stranger = app(CashTillService::class)->create(['code' => 'SIGN-2', 'name_en' => 'Other Box', 'name_bn' => 'অন্য বাক্স']);

        // ⓘ টিলের "পাকা করা" নেই — বদলের দুই দরজা: সম্পাদনার পাতা আর দায়িত্ব হস্তান্তর
        $this->assertSignerReadsOnly(
            'accounts.till.show', $signed, $stranger, 'টিল',
            edit: route('accounts.till.edit', $signed->id), confirm: route('accounts.till.handover', $signed->id),
        );
    }

    /** ⓘ নাগালের বাইরের সইকারী — ছাড় কেবল পড়ায় ([[OpensInReach::resolveRouteBinding()]]) */
    public function test_a_signer_outside_the_branch_reads_the_bill_but_cannot_confirm_it(): void
    {
        $signed = $this->bill();
        $stranger = $this->bill();
        $mms = $this->branch('MMS');
        DB::table($signed->getTable())->whereIn('id', [$signed->id, $stranger->id])->update(['branch_id' => $mms->id]);

        $signer = $this->signer(reach: $this->branch('NTK'));
        $this->signedBy($signed, $signer);
        $this->asUser($signer, $this->branch('NTK'));

        $this->assertSame(200, $this->get(route('purchase.bill.show', $signed->id))->status(),
            '⛔ NTK-তে সীমিত সইকারী MMS-এর যে বিলে সই দিলেন, সেটা পড়তে পারলেন না');
        $this->assertSame(404, $this->get(route('purchase.bill.show', $stranger->id))->status(),
            '⛔ NTK-তে সীমিত মানুষ MMS-এর যে বিলে সই দেননি, সেটাও খুলে ফেললেন — নাগাল ভাঙল');
        $this->assertSame(404, $this->post(route('purchase.bill.confirm', $signed->id))->status(),
            '⛔ নাগালের বাইরের সইকারী বিলটা পাকা করার দরজা পেরোলেন — সইয়ের ছাড় কেবল পড়ায়');
        $this->assertSame('draft', (string) PurchaseBill::acrossBranches()->findOrFail($signed->id)->status,
            '⛔ নাগালের বাইরের সইকারীর হাতে বিল পাকা হয়ে গেল');
    }

    /**
     * ⭐ টিল নাগাল ধরে খোলে, মাথায় বাছা শাখা ধরে নয় ([[CashTill]]-এর নিজের `viewed-branch` ছাঁকনি তালিকায় থাকে, বাঁধনে নয়;
     * [[OpensInReach]])। ⓘ মালিক হেডারে MMS রেখে NTK-র টিল খোলেন (২০০); MMS-এ সীমিত কর্মী একই টিলে ৪০৪, নিজের শাখারটায় ২০০।
     */
    public function test_a_till_opens_in_reach_not_in_the_header(): void
    {
        $mms = $this->branch('MMS');
        $ntk = $this->branch('NTK');
        $north = app(CashTillService::class)->create(['code' => 'REACH-N', 'name_en' => 'North Box', 'name_bn' => 'উত্তরের বাক্স', 'branch_id' => $ntk->id]);
        $home = app(CashTillService::class)->create(['code' => 'REACH-M', 'name_en' => 'Home Box', 'name_bn' => 'ঘরের বাক্স', 'branch_id' => $mms->id]);
        $this->assertSame((int) $ntk->id, (int) $north->branch_id, 'প্রস্তুতিটাই ভুল — বাক্সটা NTK-তে বসেনি');

        $this->asUser($this->owner, $mms);
        $this->assertNull(CashTill::query()->find($north->id),
            'প্রস্তুতিটাই ভুল — হেডারে MMS বাছা, তবু টিলের নিজের ছাঁকনি NTK-র বাক্স দেখায়; তাহলে পুরনো বাঁধনও খুলত');
        $this->assertSame(200, $this->get(route('accounts.till.show', $north->id))->status(),
            '⛔ মালিক হেডারে MMS রেখে NTK-র টিল খুলতে পারলেন না — বাঁধন হেডারের শাখা দেখে, নাগাল নয়');

        $clerk = $this->tillReader(reach: $mms);
        $this->asUser($clerk, $mms);
        $this->assertSame(200, $this->get(route('accounts.till.show', $home->id))->status(),
            'প্রস্তুতিটাই ভুল — MMS-এর কর্মী নিজের শাখার টিলই খুলতে পারেন না');
        $this->assertSame(404, $this->get(route('accounts.till.show', $north->id))->status(),
            '⛔ MMS-এ সীমিত কর্মী NTK-র টিল খুলে ফেললেন — নাগাল ভাঙল');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** টিল দেখার চাবি — আর নাগাল একটা শাখায় সীমিত */
    private function tillReader(Branch $reach): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('accounts.till.view', 'web')));
        UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
            'scope_type' => UserDataScope::BRANCH, 'scope_id' => $reach->id]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user->fresh();
    }

    private function assertSignerReadsOnly(string $show, Model $signed, Model $stranger, string $what, string $edit, string $confirm): void
    {
        $signer = $this->signer();
        $this->signedBy($signed, $signer);
        $this->asUser($signer);

        $this->assertSame(200, $this->get(route($show, $signed->getKey()))->status(),
            '⛔ দেখার চাবি ছাড়া সইকারী নিজের সই করা '.$what.' পড়তে পারলেন না (সইয়ের পাতা/নোটিশের ৪০৩)');
        $this->assertSame(403, $this->get(route($show, $stranger->getKey()))->status(),
            '⛔ দেখার চাবি ছাড়া মানুষ যে '.$what.'-এ সই দেননি, সেটাও খুলে ফেললেন — ছাড় কেবল নিজের কাগজে');
        $this->assertSame(403, $this->get($edit)->status(),
            '⛔ সইকারী নিজের সই করা '.$what.'-এর সম্পাদনার পাতা খুলে ফেললেন — সইয়ের ছাড় কেবল পড়ায়');
        $this->assertSame(403, $this->post($confirm)->status(),
            '⛔ সইকারী নিজের সই করা '.$what.' বদলানোর/পাকা করার দরজা পেরোলেন — সইয়ের ছাড় কেবল পড়ায়');
    }

    /** দেখার কোনো চাবি নেই — কেবল অনুমোদনের চাবি; নাগাল দিলে সেই শাখায় সীমিত */
    private function signer(?Branch $reach = null): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('approval.decide', 'web')));

        if ($reach !== null) {
            UserDataScope::query()->create(['company_id' => $this->company->id, 'user_id' => $user->id,
                'scope_type' => UserDataScope::BRANCH, 'scope_id' => $reach->id]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(DataScope::class)->forget();

        return $user->fresh();
    }

    /** ⓘ আগের সইকারী — এই কাগজের অনুরোধে আগে "হ্যাঁ" দিয়েছেন ([[PaperSigners::isSigner()]]) */
    private function signedBy(Model $paper, User $signer): void
    {
        $approval = Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => $paper->getMorphClass(), 'approvable_id' => $paper->getKey(),
            'module' => 'test', 'action' => 'confirm', 'status' => Approval::APPROVED, 'current_level' => 1,
            'requested_by' => $this->owner->id, 'requested_at' => now(),
        ]);
        ApprovalDecision::query()->create(['approval_id' => $approval->id, 'level' => 1, 'user_id' => $signer->id,
            'decision' => ApprovalDecision::APPROVED, 'decided_at' => now()]);
    }

    /** হেডারে একটা শাখা বাছা (null = "সব শাখা") */
    private function asUser(User $user, ?Branch $header = null): void
    {
        if ($header !== null) {
            $user->forceFill(['view_all_branches' => false, 'current_branch_id' => $header->id])->save();
        }
        CompanyContext::set($this->company->id, $header?->id ?? $this->company->defaultBranch()?->id);
        app(DataScope::class)->forget();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    private function bill(): PurchaseBill
    {
        return app(PurchaseBillService::class)->create(
            ['supplier_id' => Supplier::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '10', 'rate' => '100']],
        )->fresh();
    }

    private function collection(): Model
    {
        return app(CollectionService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id, 'trx_date' => now()->toDateString(),
                'amount' => '50', 'instrument' => 'cash'], [],
        )->fresh();
    }

    private function salesOrder(): Model
    {
        return app(SalesOrderService::class)->create(
            ['customer_id' => Customer::query()->orderBy('id')->firstOrFail()->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail()->id, 'ordered_qty' => '1', 'rate' => '10']],
        )->fresh();
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}
