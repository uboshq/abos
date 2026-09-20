<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Models\Institution;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুইটা ফর্ম তখনো ব্যাংকের নাম হাতে লিখতে বলত — ধাপ ৩।
 *
 * ⭐ ব্যাংক সুবিধা আর আমানত, দুইটাই এখন তালিকা থেকে প্রতিষ্ঠান নেয়
 * ([[App\Modules\Finance\Services\InstitutionService::resolve]]), আর তালিকায়
 * না থাকলে সেই জমাতেই যোগ করা যায়। ⓘ পুরনো লেখার ঘরদুটো (`bank`,
 * `institution`) থেকেই যায় আর প্রতিষ্ঠানের নামেই ভরে — পুরনো সারি আর
 * নতুন সারি যেন একইভাবে পড়া যায়।
 */
final class TheTwoFormsStillAskedForTheBankByHandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
    }

    /**
     * ⭐ ব্যাংক সুবিধার ফর্মে তালিকা, আর তালিকায় না থাকলে "+"।
     */
    public function test_a_bank_facility_takes_its_bank_from_the_list(): void
    {
        $ebl = Institution::query()->create([
            'company_id' => CompanyContext::id(), 'kind' => Institution::BANK,
            'name_en' => 'Eastern Bank PLC', 'short_code' => 'EBL',
        ]);

        $page = $this->get(route('finance.bank_facility.create'))->assertOk();
        $page->assertSee('name="institution_id"', escape: false);
        $page->assertSee('name="institution_new"', escape: false);
        $page->assertSee('Eastern Bank PLC (EBL)');
        $page->assertDontSee('name="bank"', escape: false);

        $this->post(route('finance.bank_facility.store'), [
            'kind' => BankFacility::GUARANTEE,
            'institution_id' => $ebl->id,
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '2000000',
            'margin_percent' => '15',
        ])->assertSessionHasNoErrors();

        $facility = BankFacility::query()->latest('id')->firstOrFail();

        $this->assertSame($ebl->id, (int) $facility->institution_id, 'সুবিধাটা প্রতিষ্ঠানে বসেনি।');
        $this->assertSame('Eastern Bank PLC', $facility->bank, 'পুরনো নামের ঘরটা ফাঁকা থেকে গেছে।');
    }

    /**
     * ⭐ আমানতের ফর্মেও একই — আর নতুন নাম একই জমায় তালিকায় ওঠে।
     */
    public function test_a_deposit_adds_a_new_institution_in_the_same_submit(): void
    {
        $from = Account::query()->money()->postable()->active()->firstOrFail();

        $page = $this->get(route('finance.deposit.create', ['issuer' => 'bank']))->assertOk();
        $page->assertSee('name="institution_id"', escape: false);
        $page->assertSee('name="institution_new"', escape: false);

        /*
         * ⓘ ধরনটা পাতার নিজের তালিকা থেকে, আর না থাকলে এখানেই একটা বসানো।
         * ⚠️ অনুরোধের কোম্পানি ঠিক করে মিডলওয়্যার, পরীক্ষার ভেতরে হাতে বসানো
         * প্রসঙ্গ নয় — তাই ধরনটাও ঐ কোম্পানিরই হতে হবে, নাহলে জমা দিতে গিয়ে
         * "ধরনটাই নেই" বলে থামত। ⓘ ডেমোতে প্রতিটা কোম্পানিতে ব্যাংক-আমানতের
         * ধরন বসানো থাকে না, তাই পরীক্ষাটা ডেমোর উপর নির্ভর করে না।
         */
        $kind = $page->viewData('kinds')->first() ?? DepositKind::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'FDR-T',
            'name_en' => 'Fixed Deposit (test)',
            'name_bn' => 'স্থায়ী আমানত (পরীক্ষা)',
            'shape' => DepositKind::AT_MATURITY,
            'issuer' => DepositKind::BANK,
            'is_active' => true,
        ]);

        $this->post(route('finance.deposit.store', ['issuer' => 'bank']), [
            'kind_id' => $kind->id,
            'institution_new' => 'IDLC Finance PLC',
            'held_by' => Deposit::BUSINESS,
            'return_word' => 'interest',
            'principal' => '100000',
            'opened_on' => now()->toDateString(),
            'funded_from_account_id' => $from->id,
        ])->assertSessionHasNoErrors();

        $deposit = Deposit::query()->latest('id')->firstOrFail();
        $idlc = Institution::query()->where('name_en', 'IDLC Finance PLC')->firstOrFail();

        $this->assertSame($idlc->id, (int) $deposit->institution_id, 'আমানতটা প্রতিষ্ঠানে বসেনি।');
        $this->assertSame('IDLC Finance PLC', $deposit->institution);

        // ⓘ প্রতিষ্ঠানের পাতায় আমানতটা উঠে আসে — জোড়াটা সত্যিই কাজে লাগে
        $this->get(route('finance.institution.show', $idlc))->assertOk()
            ->assertSee($deposit->document_no);
    }

    /**
     * ⛔ প্রতিষ্ঠান ছাড়া জমা নেওয়া যায় না — দুইটার একটা লাগবেই।
     */
    public function test_neither_form_accepts_a_row_without_an_institution(): void
    {
        $this->post(route('finance.bank_facility.store'), [
            'kind' => BankFacility::GUARANTEE,
            'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '2000000',
            'margin_percent' => '15',
        ])->assertSessionHasErrors('institution_new');

        $this->assertSame(0, BankFacility::query()->where('kind', BankFacility::GUARANTEE)->count());
    }
}
