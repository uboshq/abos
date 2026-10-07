<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাউচারের তালিকা — কার, কী বাবদ, কোথায়, কোন মাধ্যমে।
 *
 * ── কেন, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * তালিকায় ছিল কেবল তারিখ, নম্বর, বিবরণ, অঙ্ক, অবস্থা — টাকাটা কার,
 * কীসের, কোথায় গেল জানতে প্রতিটা খুলতে হত। মালিক প্রস্তাবটা দেখে
 * বললেন "koro"।
 *
 * ⓘ মাপ রেন্ডার হওয়া সারিতে: একজন ব্যক্তির মূলধনের রসিদ — পক্ষের নাম,
 * "মালিকের মূলধন", ব্যাংকের নাম, আর মাধ্যম — চারটাই ঐ সারিতে।
 */
final class TheVoucherListSaidAmountButNotWhoOrWhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_row_says_who_what_for_where_and_how(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $person = Person::query()->create([
            'company_id' => $company->id, 'code' => 'LND9', 'name_en' => 'Kawser Lender', 'is_active' => true,
        ]);

        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => '1234',
            'to_account_id' => $bank->id,
            'instrument_no' => 'R-LIST',
            'party_type' => 'person',
            'party_id' => $person->id,
            'from_account_id' => $capital->id,
            'narration' => 'LIST-ROW',
        ])->assertSessionHasNoErrors();

        $html = $this->get(route('accounts.voucher.index', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        $row = collect($rows[1])->first(fn (string $r) => str_contains($r, 'LIST-ROW'));

        $this->assertNotNull($row, 'রসিদটা তালিকায় নেই।');

        $text = strip_tags($row);

        $this->assertStringContainsString('Kawser Lender', $text, 'পক্ষের নাম সারিতে নেই।');
        $this->assertStringContainsString($capital->name(), $text, '"কী বাবদ" — মূলধন সারিতে নেই।');
        $this->assertStringContainsString($bank->name(), $text, '"কোথায় জমা" — ব্যাংকের নাম সারিতে নেই।');

        $kinds = [__('accounts::instrument.transfer'), __('accounts::instrument.cash'), __('accounts::instrument.cheque'), __('accounts::instrument.mfs')];
        $this->assertTrue(collect($kinds)->contains(fn ($k) => str_contains($text, $k)), 'মাধ্যম সারিতে নেই।');
    }
}
