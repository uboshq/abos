<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\CreditExposure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SignsMoneyOff;
use Tests\TestCase;

/**
 * সইয়ের অপেক্ষার জমা পুরো বিক্রি আটকে রাখে — সই হলে শেষ হয়, আর তখন খাতা মেলে।
 *
 * ── ⭐ চেকলিস্ট "ব্যবসা চালু", §৩ ───────────────────────────────────
 * *"সইয়ের অপেক্ষায় থাকা জমা: পুরো বিক্রি আটকে থাকে, সই হলে শেষ হয়।"*
 * ⓘ [[TheCounterDepositWaitedForItsSignatureTest]] কাগজের অবস্থা মাপে; এই
 * ফাইল মাপে টাকা — প্রতিটা ধাপে পাঁচ মিল।
 *
 * ── ⓘ হাতে গোনা অঙ্ক ─────────────────────────────────────────────────
 *   বিক্রি   ১০ বস্তা চাল × ৩,৫৫০ = ৩৫,৫০০
 *   খরচ     ১০ × ৩,৪০০ (FIFO, ডেমোর খোলা স্তর) = ৩৪,০০০
 *   ব্যাংক জমা                    = ২০,০০০  (সই চায় — নগদ নিজের বাক্সে চাইত না)
 *   পাওনা   ৩৫,৫০০ − ২০,০০০       = ১৫,৫০০
 *   লাভ     ৩৫,৫০০ − ৩৪,০০০       =  ১,৫০০
 *
 * ⚠️ সই দেন **আলাদা একজন** (Accountant) — বানানেওয়ালা নিজের কাগজে সই
 * দিতে পারেন না (নিরীক্ষা §১.৩, [[SignsMoneyOff]])।
 */
final class TheSignedCounterDepositFinishesTheSaleAndTheBooksMatchTest extends TestCase
{
    use ReadsTheCounterBooks;
    use RefreshDatabase;
    use SignsMoneyOff;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $rice;

    private Account $bank;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        app(SettingsService::class)->set('customer.credit_limit_enabled', true);
        app(SettingsService::class)->set('customer.zero_limit_blocks', false);

        $this->customer = app(CustomerService::class)->create([
            'name_en' => 'Signed Deposit Traders',
            'name_bn' => 'সই জমা ট্রেডার্স',
            'credit_limit' => '0',
            'credit_days' => 7,
        ]);
        $this->customer->forceFill(['credit_limit' => '100000'])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->rice = Product::query()->where('name_en', 'Miniket Rice 50kg')->firstOrFail();
        $this->bank = $this->bankAccount();

        $this->signer = $this->secondSignerIn($this->company);
        $this->counterDepositFlow($this->signer);
    }

    /**
     * ব্যাংকের পাতা-খাত — ডেমোতে না থাকলে একটা বৈধ নগদ খাত নকল করে কেবল
     * ধরন বদলানো ([[TheCounterDepositWaitedForItsSignatureTest]]-এর পথ)।
     */
    private function bankAccount(): Account
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $sibling = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail();

            $bank = $sibling->replicate(['public_id']);
            $bank->forceFill([
                'code' => 'BANK-GS2',
                'name_en' => 'BANK-GS2',
                'name_bn' => 'BANK-GS2',
                'money_kind' => Account::BANK,
            ])->save();
        }

        $this->assertTrue($bank->fresh()->isBank(), 'দৃশ্যটাই বানানো যায়নি — খাতটা ব্যাংক নয়।');

        return $bank->fresh();
    }

    private function counterDepositFlow(User $approver): void
    {
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => VoucherApproval::MODULE,
            'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => 'user',
            'approver_id' => $approver->id,
        ]);

        $this->forgetTheEnginesFlows();
    }

    private function books0(): array
    {
        return $this->books($this->customer, [(int) $this->rice->id]);
    }

    /**
     * ⭐ সইয়ের আগে: পাঁচ মিল অক্ষত, মাল তাকে, সীমা আটকে। সইয়ের পরে শেষ — পাঁচ মিল হাতের গোনায়।
     */
    public function test_a_held_deposit_keeps_the_books_still_until_signed_and_then_they_match(): void
    {
        $before = $this->books0();

        $this->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'deposits' => [['amount' => '20000', 'account_id' => $this->bank->id, 'reference' => 'TRX-GS2']],
            'lines' => [['product_id' => $this->rice->id, 'qty' => '10', 'rate' => '3550']],
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $show = route('sales.invoice.show', $invoice->id);

        $this->assertSame(DocumentStatus::DRAFT, $invoice->status, 'দৃশ্যটাই বানানো যায়নি — বিক্রিটা সইয়ে আটকায়নি।');
        $this->assertMoney('0', (string) $invoice->tax, 'দৃশ্যটাই বানানো যায়নি — চালে ভ্যাট বসেছে');

        $voucher = $invoice->heldCounterDeposits()->firstOrFail();
        $challanId = $invoice->fresh()->load('lines.challanLine')->lines->first()?->challanLine?->delivery_challan_id;

        // ── আটকে: কোনো খাত, পক্ষ, তাক বা স্তর নড়েনি; সীমায় পুরো বিল আটকে
        $this->assertBooksUnchanged($before, $this->books0(), 'সইয়ের অপেক্ষায়');
        $this->assertSame(DocumentStatus::DRAFT, DeliveryChallan::query()->findOrFail($challanId)->status,
            '⛔ সইয়ের আগে চালান পাকা — মাল বেরিয়েছে।');
        $this->assertMoney('35500', app(CreditExposure::class)->pending($this->customer->fresh()),
            '⛔ সইয়ের অপেক্ষার বিল সীমা আটকায়নি');

        // ── সইয়ের আগে "নিশ্চিত" — থামে, কিছুই নড়ে না
        $this->from($show)->post(route('sales.invoice.confirm', $invoice))->assertSessionHasErrors('status');
        $this->assertBooksUnchanged($before, $this->books0(), 'সইয়ের আগে নিশ্চিত চাপার পরে');

        // ── সই — আলাদা মানুষ
        $this->signOffAsSecondPerson($voucher, VoucherApproval::COUNTER_DEPOSIT, $this->signer);

        /*
         * ⚠️ সই মানে "হ্যাঁ", বিক্রি শেষ নয় — শেষ হয় বিলের পাতার বোতামে।
         * ⓘ বিক্রি পাকা না হয়ে গিয়ে থাকলে টাকাও খাতায় নেই।
         */
        if ($invoice->fresh()->status === DocumentStatus::DRAFT) {
            $this->assertBooksUnchanged($before, $this->books0(), 'সই হলো কিন্তু বিক্রি শেষ হয়নি');

            $this->from($show)->post(route('sales.invoice.confirm', $invoice))
                ->assertSessionHasNoErrors()
                ->assertRedirect($show);
        }

        $invoice = $invoice->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $invoice->status, '⛔ সইয়ের পরেও বিক্রি শেষ হয়নি।');
        $this->assertSame('confirmed', $voucher->fresh()->status, '⛔ জমার ভাউচার খাতায় ওঠেনি।');

        $after = $this->books0();

        $this->assertBooksMatch($before, $after, [
            'sales' => '35500',
            'cogs' => '34000',
            'received' => [(int) $this->bank->id => '20000'],
            'stockOut' => [$this->rice->id.'/'.$this->warehouse->id.'/-' => '10'],
        ], 'সই হয়ে বিক্রি শেষ হওয়ার পরে');

        $this->assertMoney('15500', $this->customer->fresh()->outstanding(), '⛔ গ্রাহকের বকেয়া হাতের গোনা নয়');
        $this->assertMoney('0', app(CreditExposure::class)->pending($this->customer->fresh()),
            '⛔ শেষ হওয়া বিক্রি এখনো সীমার আটকে ভাগে — একই টাকা দুইবার');

        // ⛔ দ্বিতীয় চাপ — কিছুই দ্বিগুণ হয় না
        $this->from($show)->post(route('sales.invoice.confirm', $invoice))->assertSessionHasErrors('status');
        $this->assertBooksUnchanged($after, $this->books0(), 'শেষ হওয়া বিক্রিতে আবার নিশ্চিত চাপার পরে');
        $this->assertSame(1, DB::table('ledger_entries')->where('source_type', Voucher::SOURCE_TYPES[Voucher::RECEIPT])
            ->where('source_id', $voucher->id)->where('debit', '>', 0)->count(), '⛔ জমার ভাউচার একবারের বেশি খাতায় বসেছে।');
    }
}
