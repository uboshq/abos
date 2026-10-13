<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Models\LoanInstalment;
use App\Modules\Accounts\Services\LoanSchedule;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ কিস্তি একবারই দেওয়া হয়, পুরনো পর্দা থেকেও — অডিট ছ৯, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ [[LoanService::payInstalment()]] "দেওয়া হয়েছে?" দেখত হাতের কপিতে, লেনদেনের বাইরে, আর কিস্তির সারিতে তালা ছিল না। পুরনো
 * কপি নিয়ে দ্বিতীয়জন "বাকি" দেখে ঢুকতেন, আর থামতেন কেবল ইঞ্জিনের ভিতরে — নামহীন ত্রুটিতে। এখন সারিটা তালায় টাটকা পড়া হয়।
 */
final class ALoanInstalmentIsPaidOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_copy_of_a_paid_instalment_is_refused_by_name(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $bank = Account::query()->create([
            'company_id' => $company->id, 'code' => '1102-CH9', 'name_en' => 'Loan Bank', 'name_bn' => 'ঋণের ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_group' => false,
        ]);

        $loan = app(LoanService::class)->create(
            data: [
                'lender' => 'Sonali Bank', 'kind' => Loan::TERM, 'interest_method' => LoanSchedule::REDUCING,
                'sanctioned' => '120000', 'interest_rate' => '12', 'tenure_months' => 12,
                'start_date' => '2026-07-01', 'first_instalment_on' => '2026-08-01',
                'principal_account_id' => Account::query()->where('code', '2210')->value('id'),
                'interest_account_id' => Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->value('id'),
            ],
            intoAccountId: $bank->id,
        );

        // ⓘ দুই পর্দায় একই কিস্তি খোলা — দুটো কপিই "বাকি"
        $first = $loan->instalments()->orderBy('no')->firstOrFail();
        $stale = LoanInstalment::query()->findOrFail($first->id);

        app(LoanService::class)->payInstalment($first, $bank->id, '2026-08-01');
        $this->assertFalse($stale->isPaid(), 'দৃশ্যটাই বানানো যায়নি — পুরনো কপি বাসি নয়');

        try {
            app(LoanService::class)->payInstalment($stale, $bank->id, '2026-08-02');
            $this->fail('⛔ দেওয়া কিস্তি পুরনো পর্দা থেকে আবার দেওয়া গেল');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::validation.instalment_already_paid'), $e->errors()['paid_on'][0] ?? null);
        }

        $this->assertSame(1, LedgerEntry::query()->where('source_type', LoanInstalment::drillSourceType())->where('source_id', $first->id)
            ->where('account_id', $bank->id)->count(), '⛔ একই কিস্তি খাতায় দুইবার');
        $this->assertSame('2026-08-01', \Illuminate\Support\Carbon::parse($first->fresh()->paid_on)->toDateString(), '⛔ দেওয়ার তারিখ বদলে গেছে');
    }
}
