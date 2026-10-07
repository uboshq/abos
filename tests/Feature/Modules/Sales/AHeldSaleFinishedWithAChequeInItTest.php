<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ধরে রাখা বিক্রির পুরনো চেক শেষ করার সময় খাতায় উঠত — চেকলিস্ট (অডিট ২৭ সেপ্টেম্বর) §২, ১ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * কাউন্টার নতুন চেক নেয় না ([[DirectSaleService::assertNoChequeAtTheCounter()]]) — মালিকের নিয়ম, চেক খাতায়
 * বসে কেবল ক্লিয়ারের পরে, চেকের খাতা দিয়ে। কিন্তু [[DirectSaleService::finishHeld()]] ধরে রাখা বিক্রির
 * জমাগুলো দেখত না: নিয়মের আগে রাখা একটা খসড়ায় চেক (১১০৪, হাতে থাকা চেক) থাকলে "শেষ করুন" চাপলে সেটা
 * ক্লিয়ারের আগেই গ্রাহকের খাতায় বসত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * শেষ করার আগে রাখা জমার প্রতিটা ভাউচার দেখা হয় — ১১০৪-এ ডেবিট বা চেকের মাধ্যম থাকলে কাউন্টারের একই
 * কথায় থামে, আর কিছুই খাতায় বসে না। নগদের খসড়া আগের মতোই শেষ হয়।
 */
final class AHeldSaleFinishedWithAChequeInItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ মালিক — সুপার অ্যাডমিন; নগদের খসড়া তাঁর হাতে শেষ হয়
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_a_held_sale_with_a_cash_deposit_still_finishes(): void
    {
        $held = $this->hold();

        // ⓘ শেষ সই পড়লে আটকে থাকা বিক্রি নিজে শেষ হয় ([[FinishTheHeldSaleOnTheLastSignature]])
        app(ApprovalEngine::class)->approve($this->pendingFor($held), $this->owner());

        $this->assertSame(DocumentStatus::CONFIRMED, $held->fresh()->status, 'নগদের জমা সই পেলে বিক্রি শেষ হওয়ার কথা।');
    }

    public function test_a_held_sale_with_an_old_cheque_deposit_is_refused_and_posts_nothing(): void
    {
        $held = $this->hold();
        $voucher = app(DirectSaleService::class)->counterVouchers($held)[0] ?? null;

        $this->assertNotNull($voucher, 'প্রস্তুতিটাই ভুল — খসড়ায় জমার ভাউচার নেই।');

        // ⓘ নিয়মের আগে রাখা খসড়া: জমাটা হাতে থাকা চেকে (১১০৪), মাধ্যম চেক
        $inHand = StandardChart::find(StandardChart::CHEQUES_IN_HAND);
        DB::table('voucher_lines')->where('voucher_id', $voucher->id)->where('debit', '>', 0)->update(['account_id' => $inHand->id]);
        Voucher::query()->whereKey($voucher->id)->update(['instrument' => 'cheque', 'instrument_no' => 'OLD-HELD-1']);

        $field = null;

        // ⓘ দুই দরজাই: শেষ সই (নিজে শেষ করে), আর হাতে "শেষ করুন"
        try {
            app(ApprovalEngine::class)->approve($this->pendingFor($held), $this->owner());
            app(DirectSaleService::class)->finishHeld($held->fresh());
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());
        }

        $this->assertSame('deposits', $field, '⛔ পুরনো চেকসহ খসড়া শেষ হয়ে গেল — চেক ক্লিয়ারের আগেই খাতায়।');
        $this->assertSame(DocumentStatus::DRAFT, $held->fresh()->status, '⛔ খসড়াটা আর খসড়া নেই।');
        $this->assertSame(0, LedgerEntry::query()->where('account_id', $inHand->id)->count(), '⛔ চেক ক্লিয়ারের আগেই ১১০৪-এ বসেছে।');
    }

    /** ব্যাংকের খাত — ডেমোতে না থাকলে নগদ খাত নকল করে ধরন বদলানো ([[TheCounterDepositWaitedForItsSignatureTest]])। */
    private function bank(): Account
    {
        $bank = Account::query()->ofMoneyKind(Account::BANK)->postable()->active()->orderBy('id')->first();

        if ($bank === null) {
            $bank = Account::query()->ofMoneyKind(Account::CASH)->postable()->orderBy('id')->firstOrFail()->replicate(['public_id']);
            $bank->forceFill(['code' => 'BANK-HELD', 'name_en' => 'BANK-HELD', 'name_bn' => 'BANK-HELD', 'money_kind' => Account::BANK])->save();
        }

        return $bank;
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    private function pendingFor(\App\Modules\Sales\Models\SalesInvoice $held): Approval
    {
        $voucher = app(DirectSaleService::class)->counterVouchers($held)[0];

        return Approval::query()->where('approvable_id', $voucher->id)->where('status', Approval::PENDING)->firstOrFail();
    }

    private function hold(): \App\Modules\Sales\Models\SalesInvoice
    {
        // ⓘ কাউন্টারের জমায় সই লাগে — বিক্রি তখন জমার সই-এর অপেক্ষায় খসড়া থাকে
        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => VoucherApproval::MODULE,
            'action' => VoucherApproval::COUNTER_DEPOSIT,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $this->owner()->id]);
        $this->app->forgetInstance(ApprovalEngine::class);

        $sale = app(DirectSaleService::class)->complete([
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            // ⓘ ব্যাংকে — নিজের বাক্সে নগদ রসিদ সই চায় না (নগদ-বাক্সের ছাড়, [[VoucherApproval::stopping()]])
            'deposits' => [['amount' => '500', 'account_id' => $this->bank()->id, 'reference' => 'TRX-HELD']],
        ], [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '1000']]);

        $this->assertSame(DocumentStatus::DRAFT, $sale['invoice']->status, 'প্রস্তুতিটাই ভুল — বিক্রয় জমার সই-এর অপেক্ষায় নেই।');

        return $sale['invoice']->fresh();
    }
}
