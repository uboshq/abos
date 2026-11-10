<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ রসিদের "টাকাটা কী বাবদ" পক্ষ থেকে নিজে বসে — মালিক, ৯ নভেম্বর ২০২৬ (৮ নভেম্বরের প্রস্তাব, আন্তর্জাতিক নিয়ম: পক্ষের নিজের খাত
 * → "eta 7nomber e koro")। সরবরাহকারী → তাঁর দেনা (২১১১); কর্মী → কর্মীর অগ্রিম (১১৩১); ব্যক্তি → মূলধন দিয়ে থাকলে মূলধন, চলতি
 * হাতধার থাকলে হাতধার (১১৭০); "অন্য" → পক্ষ ছাড়া শেষ রসিদের খাত। পাশে ছোট করে কেন।
 *
 * ⛔ আগে কেবল মূলধনের মানুষে বসত; সরবরাহকারী, কর্মী, হাতধারের মানুষ আর "অন্য"-তে ঘর ফাঁকা থাকত।
 */
final class TheMoneyPurposeComesFromThePartyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_each_party_brings_its_own_account_and_says_why(): void
    {
        $lender = $this->person('P-LOAN', 'Loan Person');
        HandLoanAccount::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'person_id' => $lender->id, 'status' => HandLoanAccount::ACTIVE,
        ]);

        $partner = $this->person('P-CAP', 'Capital Person');
        // ⓘ মূলধনও দিয়েছেন, হাতধারও আছে — মূলধন আগে (আগের নিয়ম অক্ষত)
        HandLoanAccount::query()->create([
            'company_id' => $this->company->id, 'branch_id' => $this->company->defaultBranch()?->id,
            'person_id' => $partner->id, 'status' => HandLoanAccount::ACTIVE,
        ]);
        CapitalEntry::query()->create([
            'branch_id' => $this->company->defaultBranch()?->id, 'document_no' => 'CAP-PURPOSE', 'person_id' => $partner->id,
            'contributor_type' => CapitalEntry::PARTNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->toDateString(), 'amount' => '1000', 'status' => CapitalEntry::DRAFT,
        ]);

        [$bind, $why] = $this->select();

        $id = fn (string $code) => (string) StandardChart::find($code)->id;

        $this->assertStringContainsString('"supplier":"'.$id(StandardChart::PAYABLE).'"', $bind, '⛔ সরবরাহকারী বাছলে তাঁর দেনার খাত বসে না।');
        $this->assertStringContainsString('"employee":"'.$id(StandardChart::EMPLOYEE_ADVANCE).'"', $bind, '⛔ কর্মী বাছলে কর্মীর অগ্রিম বসে না।');
        $this->assertStringContainsString('"p'.$lender->id.'":"'.$id(StandardChart::HAND_LOAN).'"', $bind, '⛔ হাতধারের মানুষে হাতধার বসে না।');
        $this->assertStringContainsString('"p'.$partner->id.'":"'.$id(StandardChart::OWNER_CAPITAL).'"', $bind, '⛔ মূলধনের মানুষে মূলধন আগে নয়।');
        $this->assertStringNotContainsString('"customer"', $bind, '⛔ গ্রাহকের ঘর নিজে বন্ধ থাকে — প্রস্তাবের কিছু নেই।');

        $this->assertStringContainsString(json_encode(__('accounts::message.on_account_why_supplier'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $why,
            '⛔ সরবরাহকারীর খাত কেন বসল, পাশে লেখা নেই।');
    }

    public function test_the_payable_account_is_on_the_list_to_be_chosen(): void
    {
        $html = (string) $this->get(route('accounts.voucher.create', 'receipt'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.StandardChart::find(StandardChart::PAYABLE)->id.'"/', $html,
            '⛔ সরবরাহকারীর দেনার খাত তালিকাতেই নেই — প্রস্তাব বসলেও দেখা যেত না।');
    }

    public function test_a_form_coming_back_after_an_error_keeps_the_persons_own_choice(): void
    {
        $this->withSession(['_old_input' => ['from_account_id' => '999999']]);

        $html = (string) $this->get(route('accounts.voucher.create', 'receipt'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<select name="from_account_id"[^>]*>/', $html, $m));
        $this->assertStringNotContainsString('x-bind:value', $m[0], '⛔ ভুলের পরে ফেরা ফর্মে প্রস্তাব মানুষের বাছাইয়ের উপর বসল।');
    }

    /** @return array{0: string, 1: string} বাঁধনটা আর কারণের লেখা, JSON খুলে */
    private function select(): array
    {
        $html = (string) $this->get(route('accounts.voucher.create', 'receipt'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<select name="from_account_id"[^>]*>/', $html, $m), '⛔ "কী বাবদ"-এর ঘর নেই।');
        $this->assertSame(1, preg_match('/x-bind:value="([^"]*)"/', $m[0], $bind), '⛔ ঘরে কোনো প্রস্তাবই বাঁধা নেই।');
        $this->assertSame(1, preg_match('/<p[^>]*data-on-account-why[^>]*x-text="([^"]*)"/', $html, $why), '⛔ কারণের লাইন নেই।');

        $open = fn (string $s): string => json_decode('"'.str_replace('"', '\"', html_entity_decode($s, ENT_QUOTES)).'"') ?? html_entity_decode($s, ENT_QUOTES);

        return [$open($bind[1]), $open($why[1])];
    }

    private function person(string $code, string $name): Person
    {
        return Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['name_en' => $name, 'name_bn' => $name, 'is_active' => true],
        );
    }
}
