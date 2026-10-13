<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Panels\CustomerCardFacts;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ অগ্রিম খালি বিয়োগে — গ্রাহকের সারাংশ (লাল রঙে, যেন বিপদ), গ্রাহক আর সরবরাহকারীর পাতার "সব শাখা" লাইন, আর সইয়ের পাতার
 * গ্রাহক-কার্ড (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিক, ৩ অক্টোবর ২০২৬: "+- dile bujte kosto hobe" — পক্ষের অঙ্ক (Dr)/(Cr)-এ; পাতার মূল অঙ্ক তাই বলে, পাশের লাইনগুলো বলত না।
 */
final class AnAdvanceReadAsABareMinusTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_advance_reads_as_dr_or_cr_on_every_party_screen(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $mms = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($company->id, $mms->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        // ⓘ উল্টো দিকটা যেকোনো চলতি খাত — এখানে প্রশ্ন কেবল পক্ষের দিক
        $bank = (int) Account::query()->postable()->active()->where('type', Account::INCOME)->value('id');
        $this->assertGreaterThan(0, $bank);

        // ⓘ গ্রাহক ৭০০ আগে দিয়েছেন, সরবরাহকারীকে ৯০০ আগে দেওয়া — দুটোই অগ্রিম
        $customer = Customer::query()->create(['company_id' => $company->id, 'branch_id' => $mms->id, 'code' => 'ADV-C', 'name_en' => 'Paid Ahead Shop', 'is_active' => true]);
        $supplier = Supplier::query()->create(['company_id' => $company->id, 'branch_id' => $mms->id, 'code' => 'ADV-S', 'name_en' => 'Paid Ahead Mills', 'is_active' => true]);
        $engine = app(PostingEngine::class);
        $engine->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => $bank, 'debit' => '700'],
            ['account_id' => (int) StandardChart::find(StandardChart::RECEIVABLE)->id, 'credit' => '700', 'party_type' => Customer::drillSourceType(), 'party_id' => $customer->id],
        ], branchId: $mms->id);
        $engine->post(sourceType: 'journal_voucher', sourceId: random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: [
            ['account_id' => (int) StandardChart::find(StandardChart::PAYABLE)->id, 'debit' => '900', 'party_type' => Supplier::drillSourceType(), 'party_id' => $supplier->id],
            ['account_id' => $bank, 'credit' => '900'],
        ], branchId: $mms->id);

        $cr700 = '(Cr) '.Money::format('700');
        $dr900 = '(Dr) '.Money::format('900');

        $this->get(route('customer.summary', $customer))->assertOk()->assertSee($cr700)->assertDontSee('-'.Money::format('700'));

        $card = collect(CustomerCardFacts::factsFor(CustomerCardFacts::ENTITY, $customer->id))->firstWhere('label', 'customer::field.outstanding');
        $this->assertSame($cr700, $card?->value, '⛔ সইয়ের পাতার কার্ডে অগ্রিম খালি বিয়োগে');

        // ⓘ হেডারে একটা শাখা — তখনই "সব শাখা" লাইনটা আসে
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $mms->id])->save();
        $this->actingAs($owner->fresh());

        $this->assertStringContainsString($cr700, $this->line(route('customer.show', $customer), 'data-due-all-branches'),
            '⛔ গ্রাহকের "সব শাখা" লাইনে অগ্রিম খালি বিয়োগে');
        $this->assertStringContainsString($dr900, $this->line(route('supplier.show', $supplier), 'data-payable-all-branches'),
            '⛔ সরবরাহকারীর "সব শাখা" লাইনে অগ্রিম খালি বিয়োগে');
    }

    /** পাতার একটা চিহ্নিত অনুচ্ছেদ — চিহ্ন থেকে তার `</p>` পর্যন্ত */
    private function line(string $url, string $marker): string
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        $at = strpos($html, $marker);
        $this->assertNotFalse($at, 'লাইনটাই আঁকা হয়নি: '.$marker);

        return substr($html, (int) $at, (int) strpos($html, '</p>', (int) $at) - (int) $at);
    }
}
