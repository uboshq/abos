<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Reports\ChequeReports;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চেকের খাতা — কোনটা আগামী তারিখের, কোনটা আজ জমার, কোনটা ফেরত। রিপোর্ট সেন্টার ধাপ ৪, ২ অক্টোবর ২০২৬
 * ([[ChequeReports]])।
 *
 * চারটা চেক: পাওয়া ১০ দিন পরের (আগামী তারিখের), পাওয়া ২ দিন আগের (আজ জমার / পেরিয়েছে), পাওয়া ফেরত, আর দেওয়া একটা।
 */
final class TheChequeRegisterSaysWhatIsDueTest extends TestCase
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

        $bank = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1102-REG',
            'name_en' => 'Register Bank',
            'name_bn' => 'Register Bank',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);

        $cheques = app(ChequeService::class);
        $customer = Customer::query()->orderBy('id')->value('id');

        foreach ([['REG-PDC', 10], ['REG-DUE', -2], ['REG-BOUNCE', -5]] as [$no, $days]) {
            $cheques->create([
                'direction' => Cheque::RECEIVED, 'cheque_no' => $no, 'bank_name' => 'Sonali',
                'cheque_date' => now()->addDays($days)->toDateString(), 'amount' => '1000',
                'party_type' => 'customer', 'party_id' => $customer, 'bank_account_id' => $bank->id,
            ]);
        }

        $cheques->bounce(Cheque::query()->where('cheque_no', 'REG-BOUNCE')->firstOrFail(), 'অপর্যাপ্ত তহবিল');

        $cheques->create([
            'direction' => Cheque::ISSUED, 'cheque_no' => 'REG-ISSUED',
            'cheque_date' => now()->toDateString(), 'amount' => '500',
            'party_type' => 'supplier', 'party_id' => Supplier::query()->orderBy('id')->value('id'), 'bank_account_id' => $bank->id,
        ]);
    }

    public function test_the_filters_split_post_dated_due_bounced_and_issued(): void
    {
        $this->assertSame(['REG-PDC'], $this->numbers(['status' => 'pdc']), '⛔ আগামী তারিখের চেক ঠিক আসেনি।');
        $this->assertSame(['REG-DUE'], $this->numbers(['status' => 'due', 'direction' => 'received']), '⛔ আজ জমার / পেরোনো চেক ঠিক আসেনি।');
        $this->assertSame(['REG-BOUNCE'], $this->numbers(['status' => 'bounced']), '⛔ ফেরত চেক ঠিক আসেনি।');
        $this->assertSame(['REG-ISSUED'], $this->numbers(['direction' => 'issued']), '⛔ দেওয়া চেক আলাদা হয়নি।');

        $rows = collect(app(ReportEngine::class)->run(ChequeReports::KEY, $this->range(['status' => 'pdc']))->rows);
        $this->assertSame(10, (int) $rows->first()['days'], '⛔ আগামী তারিখের চেকের "দিন বাকি" ভুল।');
    }

    public function test_the_page_opens_with_its_own_filters(): void
    {
        $this->get(route('accounts.report.show', ['slug' => 'cheque-register']))
            ->assertOk()
            ->assertSee(__('accounts::cheque.state_pdc'));
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function range(array $filters): array
    {
        return [...$filters, 'from' => now()->subMonth()->toDateString(), 'to' => now()->addMonth()->toDateString()];
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function numbers(array $filters): array
    {
        return collect(app(ReportEngine::class)->run(ChequeReports::KEY, $this->range($filters), perPage: 500)->rows)
            ->pluck('cheque_no')
            ->filter(fn ($no) => str_starts_with((string) $no, 'REG-'))
            ->values()
            ->all();
    }
}
