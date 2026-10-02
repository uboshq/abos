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
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একই চেক দুইবার খাতায় উঠত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[ChequeService::create()]] কোনো যাচাই করত না। গৃহীত চেকে টেবিলের সূচক শেষে ধরত — কিন্তু ৫০০-এর ভাঙা
 * পাতায়; দেওয়া চেকে ব্যাংকের নাম খালি থাকে, তাই সূচক কিছুই ধরত না (NULL ≠ NULL) আর দায় দুইবার বসত।
 * ⭐ এখন দুইটাই পরিষ্কার কথায় ফেরে; ভিন্ন ব্যাংকের একই নম্বর চলে।
 */
final class OneChequeWasRegisteredTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1102-TWICE',
            'name_en' => 'Twice Bank',
            'name_bn' => 'Twice Bank',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_a_received_cheque_twice_is_refused_in_words_not_a_crash(): void
    {
        $this->received('RCV-1', 'Sonali Bank');

        $this->assertSame('cheque_no', $this->refused(fn () => $this->received('RCV-1', 'sonali bank ')),
            '⛔ একই গৃহীত চেক দুইবার — পরিষ্কার কথায় ফেরেনি।');
        $this->assertNull($this->refused(fn () => $this->received('RCV-1', 'Janata Bank')), 'ভিন্ন ব্যাংকের একই নম্বর আটকে গেছে।');
    }

    public function test_an_issued_cheque_twice_is_refused_even_with_no_bank_name(): void
    {
        $this->issued('ISS-1');

        $this->assertSame('cheque_no', $this->refused(fn () => $this->issued('ISS-1')),
            '⛔ একই দেওয়া চেক দুইবার উঠেছে — দায় দুইবার বসত।');
        $this->assertSame(1, Cheque::query()->where('cheque_no', 'ISS-1')->count());
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function refused(callable $act): ?string
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return (string) array_key_first($e->errors());
        }

        return null;
    }

    private function received(string $no, string $bankName): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => $no,
            'bank_name' => $bankName,
            'cheque_date' => now()->toDateString(),
            'amount' => '1000',
            'party_type' => 'customer',
            'party_id' => Customer::query()->orderBy('id')->value('id'),
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function issued(string $no): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED,
            'cheque_no' => $no,
            'cheque_date' => now()->toDateString(),
            'amount' => '1000',
            'party_type' => 'supplier',
            'party_id' => Supplier::query()->orderBy('id')->value('id'),
            'bank_account_id' => $this->bank->id,
        ]);
    }
}
