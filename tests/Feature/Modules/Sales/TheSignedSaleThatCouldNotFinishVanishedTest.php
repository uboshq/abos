<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * সই হলো, বিক্রি শেষ হলো না — আর বিলটা কোথাও দেখা গেল না (লাইভ DRF-0008, fe, ৭ অক্টোবর ২০২৬)।
 *
 * ⛔ কাউন্টারের বিক্রি সইয়ে গেল, মালিক সই দিলেন, তারপর [[HeldCounterSaleFinisher]] শেষ করতে গিয়ে থামল (তখন একটা দেয়াল চালু)।
 * বিলটা তখন সইয়ের অপেক্ষাতেও নেই, খোলা খসড়াতেও নেই, আর কাউন্টার বলত "খসড়াটা আর খোলা নেই"।
 * ⭐ এখন: খসড়ার তালিকায় নিজের ভাগ "সই হয়েছে, শেষ হয়নি", কারণসহ; "আবার চেষ্টা" (একই finisher) আর "খসড়ায় ফেরান";
 * বানানেওয়ালা আর সইকারী দুজনেই খবর পান। ⓘ দেয়াল এখানে বাকির সীমা — লাইভে ছিল ফ্রির দেয়াল; থামার পথ একই।
 */
final class TheSignedSaleThatCouldNotFinishVanishedTest extends TestCase
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

        $this->signer = User::query()->create([
            'name' => 'Signing Manager', 'email' => 'signer-'.uniqid().'@abos.test',
            'password' => Hash::make('secret-secret'), 'is_active' => true,
        ]);
        $this->signer->companies()->attach($this->company->id, ['is_active' => true]);

        $flow = ApprovalFlow::query()->create([
            'company_id' => $this->company->id, 'module' => VoucherApproval::MODULE, 'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->signer->id]);
    }

    public function test_a_refused_finish_is_listed_with_its_reason_both_hear_and_a_retry_finishes_it(): void
    {
        $invoice = $this->stuckSale();

        // ⓘ নিজের ভাগে, কারণসহ — আর খসড়ার ভাগে নয়
        $this->assertTrue(HeldCounterSaleFinisher::signedNotFinished()->whereKey($invoice->id)->exists(), '⛔ থেমে থাকা বিক্রি নিজের ভাগে নেই।');
        $this->assertFalse(HeldCounterSaleFinisher::exceptSigned(\App\Modules\Sales\Services\DirectSaleService::trueDrafts())->whereKey($invoice->id)->exists(),
            '⛔ থেমে থাকা বিক্রি "খসড়া"-তে — কাউন্টারে খুললে "খসড়াটা আর খোলা নেই"।');

        $reason = app(HeldCounterSaleFinisher::class)->lastRefusal($invoice->fresh());
        $this->assertNotSame('', $reason, '⛔ কেন থামল তা লেখা নেই।');
        $this->assertTrue(AuditTrail::query()->where('auditable_id', $invoice->id)->where('action', HeldCounterSaleFinisher::AUDIT_REFUSED)->exists());

        $this->actingAs($this->maker);
        $this->get(route('sales.direct.drafts', ['tab' => 'signed']))->assertOk()
            ->assertSee($invoice->document_no)->assertSee('data-signed-retry', false);
        // ⓘ খসড়ার ট্যাবের সারিতে নয় — পাতার মাথার ঘণ্টায় খবরের শিরোনামে নম্বরটা থাকে, তাই সারি ধরে মাপা
        $this->assertNotContains($invoice->id, $this->get(route('sales.direct.drafts'))->assertOk()->viewData('drafts')->getCollection()->modelKeys(),
            '⛔ থেমে থাকা বিক্রি খসড়ার ট্যাবে।');

        // ⓘ কাউন্টারের Pending ড্রপডাউন — নম্বরের পাশেই অবস্থা, আর চাপলে নিজের ট্যাবে (কাউন্টারে "খোলা নেই" নয়)
        $item = collect($this->get(route('sales.direct.create'))->assertOk()->viewData('pendingDrafts'))->flatten(1)
            ->firstWhere('id', $invoice->id);
        $this->assertNotNull($item, '⛔ কাউন্টারের ড্রপডাউনে থেমে থাকা বিক্রি নেই।');
        $this->assertSame(route('sales.direct.drafts', ['tab' => 'signed']), $item['url'], '⛔ ড্রপডাউন বিক্রিটা কাউন্টারে খুলতে পাঠায়।');
        $this->assertStringContainsString(__('sales::auto_finish.tab'), $item['no']);

        // ⭐ দুজনেই খবর পান — সইকারী নিজে সই দিলেও (থামাটা তিনি দেখেননি)
        foreach ([$this->maker, $this->signer] as $person) {
            $this->assertTrue(Notification::query()->where('user_id', $person->id)->where('type', HeldCounterSaleFinisher::NOTICE)->exists(),
                "⛔ {$person->name} থেমে থাকার খবর পাননি।");
        }

        // ⓘ দেয়াল থাকতেই আবার চেষ্টা — কারণ পর্দায়, বিক্রি থামা, নতুন খবর নয়
        $before = Notification::query()->where('type', HeldCounterSaleFinisher::NOTICE)->count();
        $this->post(route('sales.direct.signed_retry', $invoice))->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame($before, Notification::query()->where('type', HeldCounterSaleFinisher::NOTICE)->count(), 'হাতের চেষ্টায় আবার খবর গেল।');

        // ⭐ দেয়াল তোলা → আবার চেষ্টা → পাকা
        $this->customer->forceFill(['credit_limit' => '1000000000'])->save();
        $this->post(route('sales.direct.signed_retry', $invoice))->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.drafts', ['tab' => 'signed']));
        $this->assertSame('confirmed', $invoice->fresh()->status, '⛔ বাধা সরার পরেও আবার চেষ্টায় বিক্রি পাকা হলো না।');
        $this->assertFalse(HeldCounterSaleFinisher::signedNotFinished()->whereKey($invoice->id)->exists());
    }

    public function test_return_to_draft_reopens_it_at_the_counter_and_only_its_maker_or_the_owner_may(): void
    {
        $invoice = $this->stuckSale();

        // ⓘ অন্য কেউ — থামে
        $this->actingAs($this->signer);
        $this->signer->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('sales.challan.create', 'web'));
        $this->post(route('sales.direct.signed_return', $invoice))->assertSessionHasErrors('invoice');
        $this->assertNull($invoice->fresh()->counter_draft);

        $this->actingAs($this->maker);
        $this->post(route('sales.direct.signed_return', $invoice))->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.direct.create', ['draft' => $invoice->id]));

        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->counter_draft, '⛔ খসড়ায় ফিরল না।');
        $this->assertNull($fresh->counter_screen);
        $this->assertSame('draft', $fresh->status);
        $this->assertTrue(Voucher::acrossBranches()->where('against_type', SalesInvoice::drillSourceType())->where('against_id', $invoice->id)
            ->get()->every(fn (Voucher $v) => $v->isCancelled()), '⛔ কাউন্টারের জমা-ভাউচার রয়ে গেল।');
        $this->assertFalse(HeldCounterSaleFinisher::signedNotFinished()->whereKey($invoice->id)->exists());
    }

    /** কাউন্টারের বিক্রি, ব্যাংকের জমা সইয়ে; দেয়াল চালু (বাকির সীমা ১০০); সই → থামে */
    private function stuckSale(): SalesInvoice
    {
        $this->actingAs($this->maker);
        $this->post(route('sales.direct.store'), [
            'own_transport' => '1', 'vehicle_no' => 'DHA-GA-11-2233',
            'customer_id' => $this->customer->id,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
            'deposits' => [$this->bank('500')],
            'lines' => [['product_id' => Product::query()->orderBy('id')->firstOrFail()->id, 'qty' => '10', 'rate' => '100']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা সইয়ের অপেক্ষায় থাকার কথা।');

        $this->customer->forceFill(['credit_limit' => '100'])->save();

        $ids = Voucher::acrossBranches()->where('against_type', SalesInvoice::drillSourceType())->where('against_id', $invoice->id)->pluck('id');
        $this->actingAs($this->signer);
        foreach (Approval::query()->where('approvable_type', Voucher::class)->whereIn('approvable_id', $ids)->where('status', Approval::PENDING)->get() as $approval) {
            app(ApprovalEngine::class)->approve($approval, $this->signer);
        }

        $this->assertSame('draft', $invoice->fresh()->status, 'দৃশ্যটাই বানানো যায়নি — দেয়াল পেরিয়ে বিক্রি শেষ হয়ে গেছে।');

        return $invoice;
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
