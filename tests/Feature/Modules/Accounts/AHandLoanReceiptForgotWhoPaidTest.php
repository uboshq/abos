<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ হাতধার আর কর্মীর অগ্রিমের সারিতে পক্ষ বসত না — মালিকের অভিযোগ, ৫ অক্টোবর ২০২৬ (আভা ট্রেড RCV-0001)।
 *
 * ⓘ রসিদের মাথায় ব্যক্তি (Aminul) ছিলেন, অথচ খাতার ১১৭০-সারিতে পক্ষ নেই, কারণ পক্ষ নামত কেবল পাওনা আর দেনার খাতে
 * ([[VoucherService::accountsThatHoldAParty()]])। "Aminul কত দেবেন" প্রশ্নে টাকাটা আসতই না।
 */
final class AHandLoanReceiptForgotWhoPaidTest extends TestCase
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
    }

    public function test_a_hand_loan_repaid_by_a_person_lands_on_that_person(): void
    {
        $person = $this->person('Aminul');
        $voucher = $this->posted(StandardChart::HAND_LOAN, $person, '9000');

        $row = LedgerEntry::query()->where('document_no', $voucher->document_no)
            ->where('account_id', StandardChart::find(StandardChart::HAND_LOAN)->id)->sole();

        $this->assertSame('person', $row->party_type, '⛔ হাতধারের সারিতে পক্ষের ধরন নেই।');
        $this->assertSame((int) $person->id, (int) $row->party_id, '⛔ হাতধারের সারিতে ব্যক্তি নেই।');
        $this->assertSame(0, bccomp(app(AccountsFacts::class)->dueFrom('person', (int) $person->id), '-9000', 4),
            '⛔ ব্যক্তির হিসাবে ৯,০০০ (Cr) দেখায় না।');

        // ⓘ টাকার খাত কারো নামে বসে না — আগের নিয়ম অটুট
        $cash = LedgerEntry::query()->where('document_no', $voucher->document_no)->where('debit', '>', 0)->sole();
        $this->assertNull($cash->party_type);
    }

    public function test_an_employee_advance_lands_on_the_person_too(): void
    {
        $person = $this->person('Karim');
        $voucher = $this->posted(StandardChart::EMPLOYEE_ADVANCE, $person, '1200');

        $row = LedgerEntry::query()->where('document_no', $voucher->document_no)
            ->where('account_id', StandardChart::find(StandardChart::EMPLOYEE_ADVANCE)->id)->sole();

        $this->assertSame((int) $person->id, (int) $row->party_id, '⛔ কর্মীর অগ্রিমের সারিতে ব্যক্তি নেই।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => $this->company->id, 'code' => 'P-'.strtoupper($name), 'name_en' => $name, 'name_bn' => $name, 'is_active' => true,
        ]);
    }

    /** টাকা নগদে এল, অন্য পাশে ব্যক্তির নামের খাত (Cr) — মাথায় পক্ষ */
    private function posted(string $code, Person $person, string $amount): Voucher
    {
        $cash = \App\Modules\Accounts\Models\Account::query()->where('money_kind', \App\Modules\Accounts\Models\Account::CASH)
            ->where('is_group', false)->orderBy('code')->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'ফেরত',
                'party_type' => 'person', 'party_id' => $person->id],
            [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => StandardChart::find($code)->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        return app(VoucherService::class)->post($voucher);
    }
}
