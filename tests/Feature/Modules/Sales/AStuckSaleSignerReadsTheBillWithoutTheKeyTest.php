<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\HeldCounterSaleFinisher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ থেমে থাকা বিক্রির সইকারী বিলটা পড়েন — বিল দেখার চাবি ছাড়াও, কেবল পড়েন (fe, ২৪ অক্টোবর ২০২৬; মালিকের অভিযোগ
 * "নোটিফিকেশনে ক্লিক করলে দেখা যায় না")।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * কাউন্টারের বিক্রি সইয়ে আটকালে সইকারী সই দেন বিলের টাকার কাগজে (ব্যাংক জমার ভাউচার, বিলের বিপরীতে), বিলে নয়। সই হয়ে বিক্রি
 * থেমে গেলে খবর তাঁকে বিলের পাতায় পাঠায় ([[HeldCounterSaleFinisher]]) — কিন্তু [[SalesInvoicePolicy::view()]] কেবল
 * `sales.invoice.view` দেখত: চাবি না থাকলে ৪০৩, যে বিক্রিতে সই দিলেন সেটাই পড়তে পারতেন না।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * চাবি ছাড়া সইকারী খবর খুলে বিলের পাতায় যান, পাতা ২০০ আর বিলের নম্বরসহ; যে বিলের টাকায় তিনি সই দেননি সেটা ৪০৩ (ছাড় কেবল
 * **এই** বিলে)।
 */
final class AStuckSaleSignerReadsTheBillWithoutTheKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private User $signer;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->maker = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->maker);

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        $this->customer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();

        // ⓘ সইকারী — কোনো চাবি নেই: বিল দেখার চাবিও না, কাউন্টারের চাবিও না
        $this->signer = User::factory()->create(['name' => 'Keyless Signer', 'is_active' => true, 'current_company_id' => $this->company->id]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => VoucherApproval::MODULE, 'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_the_signer_without_the_bill_key_reads_the_stuck_bill_and_no_other(): void
    {
        $invoice = $this->stuckSale();
        $other = $this->anotherBill($invoice);

        $note = Notification::query()->where('user_id', $this->signer->id)->where('type', HeldCounterSaleFinisher::NOTICE)->first();
        $this->assertNotNull($note, 'প্রস্তুতিটাই ভুল — সইকারী থেমে থাকার খবর পাননি।');
        $this->assertSame(route('sales.invoice.show', $invoice), $note->url, 'প্রস্তুতিটাই ভুল — সইকারীর খবর বিলের পাতায় পাঠায় না।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->signer->fresh());
        $this->assertFalse($this->signer->fresh()->can('sales.invoice.view'), 'প্রস্তুতিটাই ভুল — সইকারীর বিল দেখার চাবি আছে।');

        $opened = $this->call('GET', route('notifications.open', $note));
        $this->assertSame(302, $opened->status(), '⛔ খবর খোলার দরজা সইকারীকে বিলে পাঠায়নি।');
        $this->assertSame(route('sales.invoice.show', $invoice), $opened->headers->get('Location'), '⛔ খবর খুলে বিলের পাতায় যায় না।');

        $page = $this->call('GET', route('sales.invoice.show', $invoice));
        $this->assertSame(200, $page->status(), '⛔ চাবি ছাড়া সইকারী যে বিক্রিতে সই দিলেন, তার বিল পড়তে পারেন না (৪০৩)।');
        $this->assertStringContainsString((string) $invoice->document_no, (string) $page->getContent(), '⛔ বিলের পাতায় বিলের নম্বর নেই।');

        $this->assertSame(403, $this->call('GET', route('sales.invoice.show', $other))->status(),
            '⛔ চাবি ছাড়া মানুষ যে বিলের টাকায় সই দেননি, সেটাও খুলে ফেললেন — ছাড় কেবল নিজের সই করা বিলে।');
    }

    // ── সহায়ক ([[AStuckSignedSaleSendsTheSignerToTheBillTest]]-এর ছাঁচ) ─────────

    /** কাউন্টারের বিক্রি, ব্যাংকের জমা সইয়ে; দেয়াল চালু (বাকির সীমা ১০০); সই → থামে */
    private function stuckSale(): SalesInvoice
    {
        $this->actingAs($this->maker);
        $stored = $this->call('POST', route('sales.direct.store'), [
            'own_transport' => '1', 'vehicle_no' => 'DHA-GA-11-2244',
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'deposits' => [$this->bank('500')],
            'lines' => [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']],
        ]);
        $this->assertSame(302, $stored->status(), 'প্রস্তুতিটাই ভুল — কাউন্টারের বিক্রি জমা হয়নি।');

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'প্রস্তুতিটাই ভুল — বিক্রিটা সইয়ের অপেক্ষায় থাকার কথা।');

        $this->customer->forceFill(['credit_limit' => '100'])->save();

        $ids = Voucher::acrossBranches()->where('against_type', SalesInvoice::drillSourceType())->where('against_id', $invoice->id)->pluck('id');
        $waiting = Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', $ids)->where('status', Approval::PENDING)->get();
        $this->assertNotEmpty($waiting, 'প্রস্তুতিটাই ভুল — বিলের টাকার কাগজ সইয়ের অপেক্ষায় নেই।');

        $this->actingAs($this->signer);
        foreach ($waiting as $approval) {
            app(ApprovalEngine::class)->approve($approval, $this->signer);
        }

        $this->assertSame('draft', $invoice->fresh()->status, 'প্রস্তুতিটাই ভুল — দেয়াল পেরিয়ে বিক্রি শেষ হয়ে গেছে।');

        return $invoice->fresh();
    }

    /** একই কোম্পানির আরেকটা বিল — তার বিপরীতে কোনো টাকার কাগজ নেই, সইকারী তাতে কিছুতে সই দেননি */
    private function anotherBill(SalesInvoice $invoice): SalesInvoice
    {
        $other = $invoice->replicate(['public_id', 'idempotency_key']);
        $other->forceFill(['document_no' => $invoice->document_no.'-B'])->save();

        $this->assertSame(0, Voucher::acrossBranches()->where('against_type', SalesInvoice::drillSourceType())->where('against_id', $other->id)->count(),
            'প্রস্তুতিটাই ভুল — আরেক বিলের বিপরীতেও টাকার কাগজ বসে গেছে।');

        return $other->fresh();
    }

    /** @return array<string, mixed> */
    private function bank(string $amount): array
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();
            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill(['code' => 'BANK-AUTO', 'name_en' => 'BANK-AUTO', 'name_bn' => 'BANK-AUTO', 'money_kind' => Account::BANK])->save();
        }

        return ['amount' => $amount, 'account_id' => $bank->id, 'reference' => 'TRX-'.uniqid()];
    }
}
