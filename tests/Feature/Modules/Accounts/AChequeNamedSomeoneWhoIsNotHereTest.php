<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম১২ — চেকের পক্ষ সত্যিই আছে, এই কোম্পানিতে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে অন্য কোম্পানির গ্রাহক বা না-থাকা কারো নামে চেক লেখা যেত, আর পাশের দিন টাকা সেই নামে বসত।
 */
final class AChequeNamedSomeoneWhoIsNotHereTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => $this->company->id, 'code' => '1102-M12', 'name_en' => 'M12 Bank', 'name_bn' => 'ম১২ ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET, 'nature' => Account::DEBIT,
            'money_kind' => Account::BANK, 'is_active' => true, 'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_a_party_from_another_company_a_missing_one_or_an_unknown_kind_is_refused(): void
    {
        $before = Cheque::query()->count();
        // ⓘ অন্য কোম্পানির একজন গ্রাহক — সেই কোম্পানির নিজের প্রসঙ্গে বানানো
        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        $theirs = CompanyContext::forCompany((int) $other->id,
            fn () => app(\App\Modules\Customer\Services\CustomerService::class)->create(['name_en' => 'Not Our Customer', 'credit_limit' => 0, 'credit_days' => 0]));

        foreach ([['customer', (int) $theirs->id, 'অন্য কোম্পানির গ্রাহক'], ['customer', 987654321, 'না-থাকা গ্রাহক'], ['martian', 1, 'অচেনা ধরন']] as [$type, $id, $who]) {
            try {
                $this->receive($type, $id);
                $this->fail("⛔ {$who}-এর নামে চেক লেখা গেল।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('party', $e->errors(), $who);
            }
        }

        $this->assertSame($before, Cheque::query()->count(), '⛔ থামার পরেও চেক রয়ে গেছে।');
    }

    /** ⓘ নিজের গ্রাহক — আজকের মতোই */
    public function test_our_own_customer_is_as_before(): void
    {
        $cheque = $this->receive('customer', (int) Customer::query()->orderBy('id')->firstOrFail()->id);

        $this->assertSame(Cheque::PENDING, $cheque->status);
    }

    private function receive(string $type, int $id): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'M12'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '5000',
            'party_type' => $type,
            'party_id' => $id,
            'bank_account_id' => $this->bank->id,
        ]);
    }
}
