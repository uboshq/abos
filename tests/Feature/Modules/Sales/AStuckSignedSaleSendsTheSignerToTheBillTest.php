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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⛔ "সই হলো, বিক্রি শেষ হলো না" খবর সইকারীকে কাউন্টারের খসড়ার তালিকায় পাঠাত — যেটা তিনি খুলতেই পারেন না (২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[HeldCounterSaleFinisher]] থেমে গেলে বানানেওয়ালা আর সইকারী দুজনেই খবর পান, আর দুজনের লিংকই ছিল
 * `sales.direct.drafts` ("সই হয়েছে" ভাগ)। ⚠️ ঐ তালিকা চায় `sales.challan.create` — কাউন্টারের লোকের চাবি; সইকারী (ধরুন
 * ব্যবস্থাপক) সাধারণত কাউন্টারে বিক্রি করেন না, তাই খবর চাপলেই ৪০৩, আর কোন বিল থামল তা দেখার পথ ছিল না।
 *
 * ── ⭐ দাবি ──────────────────────────────────────────────────────────
 * যিনি খসড়ার তালিকা খোলেন না, তাঁর খবর বিলের নিজের পাতায় (`sales.invoice.show`) — খবর খোলার দরজা দিয়ে সেখানে যায়, আর
 * পাতা খোলে (২০০, বিলের নম্বরসহ)। বানানেওয়ালা আগের মতো তালিকার "সই হয়েছে" ভাগে, যেখানে আবার-চেষ্টার বোতাম।
 * ⓘ বিলের পাতা খোলে `sales.invoice.view` থাকলে — সেই চাবি ছাড়া সইকারীর জন্য পাতাটা খোলা হয়নি (দরজা চওড়া করা হয়নি)।
 */
final class AStuckSignedSaleSendsTheSignerToTheBillTest extends TestCase
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

        // ⓘ সইকারী বিল দেখেন, কাউন্টারে বিক্রি করেন না
        $this->signer = User::factory()->create(['name' => 'Signing Manager', 'is_active' => true, 'current_company_id' => $this->company->id]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);
        CompanyContext::forCompany($this->company->id, fn () => $this->signer->givePermissionTo(Permission::findOrCreate('sales.invoice.view', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => VoucherApproval::MODULE, 'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_the_signer_goes_to_the_bill_and_the_maker_to_the_signed_tab(): void
    {
        $invoice = $this->stuckSale();

        $signers = Notification::query()->where('user_id', $this->signer->id)->where('type', HeldCounterSaleFinisher::NOTICE)->first();
        $this->assertNotNull($signers, 'প্রস্তুতিটাই ভুল — সইকারী থেমে থাকার খবর পাননি।');
        $this->assertSame(route('sales.invoice.show', $invoice), $signers->url,
            '⛔ সইকারীর খবর কাউন্টারের খসড়ার তালিকায় পাঠায় — যেটা তিনি খুলতে পারেন না।');

        $makers = Notification::query()->where('user_id', $this->maker->id)->where('type', HeldCounterSaleFinisher::NOTICE)->first();
        $this->assertNotNull($makers, 'প্রস্তুতিটাই ভুল — বানানেওয়ালা থেমে থাকার খবর পাননি।');
        $this->assertSame(route('sales.direct.drafts', ['tab' => 'signed']), $makers->url,
            '⛔ বানানেওয়ালার খবর আর "সই হয়েছে" ভাগে যায় না, যেখানে আবার-চেষ্টার বোতাম।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->signer->fresh());

        $this->assertSame(403, $this->call('GET', route('sales.direct.drafts', ['tab' => 'signed']))->status(),
            'প্রস্তুতিটাই ভুল — সইকারী খসড়ার তালিকা খুলতে পারেন, তাহলে পুরনো লিংকও কাজ করত।');

        $opened = $this->call('GET', route('notifications.open', $signers));
        $this->assertSame(302, $opened->status(), '⛔ খবর খোলার দরজা সইকারীকে বিলে পাঠায়নি।');
        $this->assertSame(route('sales.invoice.show', $invoice), $opened->headers->get('Location'), '⛔ খবর খুলে বিলের পাতায় যায় না।');
        $this->assertNotNull($signers->fresh()->read_at, '⛔ খবর খুলে পড়া হিসেবে বসেনি।');

        $page = $this->call('GET', route('sales.invoice.show', $invoice));
        $this->assertSame(200, $page->status(), '⛔ সইকারী থেমে থাকা বিলের পাতা খুলতে পারেন না।');
        $this->assertStringContainsString((string) $invoice->document_no, (string) $page->getContent(), '⛔ বিলের পাতায় বিলের নম্বর নেই।');
    }

    // ── সহায়ক ([[TheSignedSaleThatCouldNotFinishVanishedTest]]-এর ছাঁচ) ─────────

    /** কাউন্টারের বিক্রি, ব্যাংকের জমা সইয়ে; দেয়াল চালু (বাকির সীমা ১০০); সই → থামে */
    private function stuckSale(): SalesInvoice
    {
        $this->actingAs($this->maker);
        $stored = $this->call('POST', route('sales.direct.store'), [
            'own_transport' => '1', 'vehicle_no' => 'DHA-GA-11-2233',
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
        $this->actingAs($this->signer);
        foreach (Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', $ids)->where('status', Approval::PENDING)->get() as $approval) {
            app(ApprovalEngine::class)->approve($approval, $this->signer);
        }

        $this->assertSame('draft', $invoice->fresh()->status, 'প্রস্তুতিটাই ভুল — দেয়াল পেরিয়ে বিক্রি শেষ হয়ে গেছে।');

        return $invoice->fresh();
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
