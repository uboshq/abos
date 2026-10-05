<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
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
 * ম১০ — চেকের পাশ আর ফেরত একসাথে এলে খাতা আর কাগজ এক কথা বলে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ দুজন একসাথে চাপলে দুজনেই "জমা" দেখতেন — খাতায় পাশের টাকা ব্যাংকে, কাগজ শেষে "ফেরত"। ⓘ একই প্রক্রিয়ায় দুই অনুরোধ:
 * একই চেকের দুই বাসি কপি, একটা আগে কাজ করে, তারপর অন্যটা।
 */
final class AChequeClearedAndBouncedAtOnceTest extends TestCase
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
            'company_id' => $company->id,
            'code' => '1102-M10',
            'name_en' => 'M10 Bank',
            'name_bn' => 'ম১০ ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    public function test_a_bounce_from_a_stale_copy_after_a_clear_takes_the_bank_money_back(): void
    {
        [$first, $second] = $this->twoCopies($this->receive());

        app(ChequeService::class)->clear($first);
        app(ChequeService::class)->bounce($second, 'তহবিল নেই');

        $this->assertSame(Cheque::BOUNCED, $first->fresh()->status);
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 4),
            '⛔ কাগজ "ফেরত", অথচ পাশের টাকা ব্যাংকে রয়ে গেল — বাসি কপি "জমা" দেখে ফেরায়নি।');
    }

    public function test_a_clear_from_a_stale_copy_after_a_bounce_is_refused(): void
    {
        [$first, $second] = $this->twoCopies($this->receive());

        app(ChequeService::class)->bounce($first, 'তহবিল নেই');

        try {
            app(ChequeService::class)->clear($second);
            $this->fail('⛔ ফেরত চেক বাসি কপি থেকে পাশ হলো — ব্যাংকে টাকা, কাগজ "ফেরত"।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(Cheque::BOUNCED, $first->fresh()->status);
        $this->assertSame(0, bccomp($this->bankBalance(), '0', 4));
    }

    public function test_two_clears_post_once(): void
    {
        [$first, $second] = $this->twoCopies($this->receive());

        app(ChequeService::class)->clear($first);

        try {
            app(ChequeService::class)->clear($second);
            $this->fail('⛔ একই চেক দুইবার পাশ।');
        } catch (ValidationException) {
        }

        $this->assertSame(0, bccomp($this->bankBalance(), '5000', 4), '⛔ ব্যাংকে টাকা দুইবার বসল।');
    }

    /** @return array{0: Cheque, 1: Cheque} */
    private function twoCopies(Cheque $cheque): array
    {
        return [Cheque::query()->findOrFail($cheque->id), Cheque::query()->findOrFail($cheque->id)];
    }

    private function receive(): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'M10'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '5000',
            'party_type' => 'customer',
            'party_id' => Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail()->id,
            'bank_account_id' => $this->bank->id,
        ]);
    }

    private function bankBalance(): string
    {
        return (string) (LedgerEntry::query()->where('account_id', $this->bank->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal') ?? '0');
    }
}
