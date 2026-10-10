<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\CollectionLine;
use App\Modules\Sales\Models\DepositClaim;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DepositClaimService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ জমার দাবির বাছা বিল — দাবির শাখায় খোঁজা, গ্রহণকারীর হেডারের শাখায় নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, বিক্রয় ৩;
 * [[DepositClaimService::sharesAt()]])।
 *
 * ⓘ ডিলার প্রধান শাখার, বিলও সেখানে। গ্রহণকারীর হেডারে আরেকটা শাখা থাকলে আগে বিলটা "নেই" হত — চুপচাপ বাদ, আর টাকা কোনো বিলে না বসে
 * খালি জমা। বিলের বকেয়া রয়ে যেত, তাগাদার তালিকায় শোধ হওয়া বিল উঠত।
 */
final class AClaimedBillIsFoundWhateverTheAcceptersHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_picked_bill_is_paid_even_when_the_accepter_looks_at_another_branch(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($home->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($company->id, $home->id);
        app(StandardChart::class)->install();
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $dealer = Customer::query()->orderBy('id')->firstOrFail();
        $dealer->forceFill(['branch_id' => $home->id])->save();
        $bank = Account::query()->create(['company_id' => $company->id, 'code' => '1102-CLAIMBANK', 'name_en' => 'Claim bank', 'name_bn' => 'দাবির ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT, 'money_kind' => Account::BANK]);
        $bill = SalesInvoice::query()->create(['branch_id' => $home->id, 'document_no' => 'INV-BR-1', 'customer_id' => $dealer->id,
            'trx_date' => now()->subDays(3)->toDateString(), 'due_on' => now()->addDays(27)->toDateString(),
            'subtotal' => '600', 'discount' => '0', 'tax' => '0', 'total' => '600', 'status' => DocumentStatus::CONFIRMED]);

        $claim = app(DepositClaimService::class)->raise($dealer, ['claimed_on' => now()->toDateString(), 'amount' => '600', 'method' => DepositClaim::BANK,
            'bills' => [['sales_invoice_id' => $bill->id, 'amount' => '600']]]);

        // ⓘ গ্রহণকারীর হেডারে আরেকটা শাখা
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $elsewhere->id])->save();
        CompanyContext::set($company->id, $elsewhere->id);
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());

        app(DepositClaimService::class)->accept(DepositClaim::query()->withoutGlobalScopes()->findOrFail($claim->id), $bank->id);

        $claim = DepositClaim::query()->withoutGlobalScopes()->findOrFail($claim->id);
        $this->assertSame(DepositClaim::ACCEPTED, $claim->status);
        $this->assertSame([(int) $bill->id], CollectionLine::query()->where('collection_id', $claim->collection_id)->pluck('sales_invoice_id')->map(fn ($i) => (int) $i)->all(),
            '⛔ বাছা বিল চুপচাপ বাদ — টাকা খালি জমা হয়ে বসল');
        // ⓘ বকেয়া দেখা বিলের শাখা থেকে
        $owner->forceFill(['view_all_branches' => true])->save();
        CompanyContext::set($company->id, $home->id);
        app(DataScope::class)->forget();
        $this->actingAs($owner->fresh());
        $this->assertSame('0.0000', SalesInvoice::acrossBranches()->findOrFail($bill->id)->dueAmount(), '⛔ বিলের বকেয়া কমেনি');
    }

    /**
     * ⛔ অন্য শাখার বিল দাবিতে বাছা যায় না — আগেভাগেই কারণসহ ফেরে, গ্রহণের সময় চুপচাপ বাদ নয় (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১২)।
     *
     * ⓘ আদায় বসে দাবির শাখায় (গ্রাহকের শাখা), আর উপরের সংশোধনে গ্রহণ বিল খোঁজে সেই শাখাতেই। পোর্টালের খোলা-বিল তালিকায়
     * অন্য শাখার বিলও আসত — বাছা যেত, গ্রহণে বাদ পড়ত, টাকা অ-প্রযুক্ত জমা হয়ে বসত।
     */
    public function test_another_branchs_bill_is_refused_when_the_claim_is_raised_and_is_not_offered(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = $company->defaultBranch();
        $elsewhere = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereKeyNot($home->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($company->id, $home->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($owner->fresh());

        $dealer = Customer::query()->orderBy('id')->firstOrFail();
        $dealer->forceFill(['branch_id' => $home->id])->save();
        $there = SalesInvoice::query()->create(['branch_id' => $elsewhere->id, 'document_no' => 'INV-BR-2', 'customer_id' => $dealer->id,
            'trx_date' => now()->subDays(3)->toDateString(), 'due_on' => now()->addDays(27)->toDateString(),
            'subtotal' => '400', 'discount' => '0', 'tax' => '0', 'total' => '400', 'status' => DocumentStatus::CONFIRMED]);

        $this->assertNotContains((int) $there->id, app(DepositClaimService::class)->openBills($dealer->fresh())->pluck('id')->map(fn ($i) => (int) $i)->all(),
            '⛔ অন্য শাখার বিল বাছার তালিকায় — বাছলে গ্রহণে চুপচাপ বাদ পড়বে');

        try {
            app(DepositClaimService::class)->raise($dealer->fresh(), ['claimed_on' => now()->toDateString(), 'amount' => '400', 'method' => DepositClaim::BANK,
                'bills' => [['sales_invoice_id' => $there->id, 'amount' => '400']]]);
            $this->fail('⛔ অন্য শাখার বিলে দাবি উঠে গেল — গ্রহণে বিলটা চুপচাপ বাদ পড়বে।');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('INV-BR-2', (string) ($e->errors()['bills'][0] ?? ''), 'কারণে বিলের নম্বর নেই');
        }
    }
}
