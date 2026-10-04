<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteAccounts;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * গ্রাহকের ক্রেডিট নোট — মাল ফেরত হলে বিক্রয় ফেরত (৪১১০), কেবল দামের ছাড় হলে দেওয়া ছাড় (৫৩০০); মালিকের পরিকল্পনা সংস্করণ ২,
 * ৪ অক্টোবর ২০২৬ ([[NoteAccounts::others()]])। ডিফল্ট আগের মতোই ৪১১০; তালিকার বাইরের খাত আগের মতোই ফেরে।
 */
final class ACreditNoteCanBeAPriceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->customer = Customer::query()->firstOrFail();
    }

    public function test_the_default_stays_sales_return_and_discount_is_offered(): void
    {
        $accounts = app(NoteAccounts::class);
        $codes = $accounts->others(Note::KIND_CUSTOMER, Note::CREDIT)->pluck('code')->all();

        $this->assertSame([StandardChart::SALES_RETURN, StandardChart::DISCOUNT_GIVEN], $codes);
        $this->assertSame((int) StandardChart::find(StandardChart::SALES_RETURN)->id,
            $accounts->defaultOther(Note::KIND_CUSTOMER, Note::CREDIT, (int) $this->customer->id), '⛔ ডিফল্ট বদলে গেছে।');
        $this->assertSame([StandardChart::SALES], $accounts->others(Note::KIND_CUSTOMER, Note::DEBIT)->pluck('code')->all(), '⛔ ডেবিট নোট বদলেছে।');
    }

    public function test_a_price_discount_note_debits_discount_given(): void
    {
        $note = $this->note(StandardChart::DISCOUNT_GIVEN);

        $this->assertSame(0, bccomp($this->debitOn($note, StandardChart::DISCOUNT_GIVEN), '700', 4), '⛔ দেওয়া ছাড়ে Dr বসেনি।');
        $this->assertSame(0, bccomp($this->debitOn($note, StandardChart::SALES_RETURN), '0', 4));
    }

    public function test_an_account_off_the_list_is_still_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->note(StandardChart::MARKETING);
    }

    private function note(string $code): Note
    {
        $service = app(NoteService::class);

        return $service->confirm($service->create([
            'direction' => Note::CREDIT, 'party_type' => 'customer', 'party_id' => $this->customer->id,
            'trx_date' => now()->toDateString(), 'amount' => '700', 'tax_amount' => '0',
            'reason' => 'agreed_discount', 'narration' => 'দামের ছাড়',
            'other_account_id' => (int) StandardChart::find($code)->id,
        ]));
    }

    private function debitOn(Note $note, string $code): string
    {
        return (string) LedgerEntry::query()->where('source_type', $note->sourceType())->where('source_id', $note->id)
            ->where('account_id', StandardChart::find($code)->id)->sum('debit');
    }
}
