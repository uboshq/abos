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
 * গ্রাহক ছাড়া কারও কাছ থেকে রসিদ — যে ঘর চাওয়া হয়, সেটা পর্দায় থাকতে হবে।
 *
 * ── ⛔ কী ঘটছিল, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * মালিক একজন ব্যক্তির কাছ থেকে ৫ লাখের রসিদ কাটতে গেলেন ("2nd capital
 * dewa")। পর্দা বলল *"যে খাত থেকে দিতেই হবে"*, অথচ ঐ ঘরটা পর্দায়
 * ছিলই না — নমুনা মেনে লুকানো ও নিষ্ক্রিয়। আর 5e7d508c থেকে পক্ষ দেখে
 * খাত আন্দাজ কেবল গ্রাহকের বেলায়। ⛔ ফল: ব্যক্তি, কর্মী বা ঋণদাতার কাছ
 * থেকে কোনো রসিদই কাটা যেত না।
 *
 * ⚠️ সার্ভারের পাহারাগুলো ঠিক ছিল, আর তাদের পরীক্ষাও সবুজ ছিল
 * ([[TheOwnersCapitalWasBookedAsACustomerPayingTest]]) — কারণ তারা ঘরটা
 * সরাসরি পাঠাত। ⓘ **কেউ পর্দা থেকে পাঠায়নি।** তাই এই পরীক্ষা আগে পর্দা
 * পড়ে, তারপর পর্দায় যা আছে কেবল তা দিয়েই পাঠায়।
 */
final class AReceiptFromAPersonAskedForABoxThatWasNotThereTest extends TestCase
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

    public function test_the_receipt_screen_offers_an_account_box_that_is_actually_submitted(): void
    {
        $html = $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();

        /*
         * ⓘ জমা পড়ে এমন (নিষ্ক্রিয় নয়) `from_account_id` ঘর একটা থাকতে হবে।
         * নমুনার পুরনো ঘরটা `disabled` থাকে, আর নতুনটার `disabled` কেবল
         * Alpine-এ বাঁধা (গ্রাহক বাছলে) — তাই পাতা খোলার মুহূর্তে সেটা খোলা।
         */
        preg_match_all('/<select[^>]*name="from_account_id"[^>]*>/', $html, $m);

        $live = array_filter($m[0], fn (string $tag) => ! preg_match('/\sdisabled(\s|>|=")/', $tag));

        $this->assertCount(1, $live, 'রসিদের পর্দায় জমা পড়ে এমন "কোন খাতের টাকা" ঘর নেই — '
            .'গ্রাহক ছাড়া কারও রসিদ কাটা যাবে না।');

        $this->assertStringContainsString('3100', $html, 'খাতের তালিকায় মালিকের মূলধন (3100) নেই।');
    }

    public function test_money_from_a_person_reaches_the_account_chosen_on_screen(): void
    {
        $person = Person::query()->create([
            'company_id' => $this->company->id,
            'code' => 'LND1',
            'name_en' => 'A Lender',
            'is_active' => true,
        ]);

        $loan = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->firstOrFail();
        $bank = Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? Account::query()->where('money_kind', Account::CASH)->postable()->active()->orderBy('code')->firstOrFail();

        $this->post(route('accounts.voucher.store', ['type' => Voucher::RECEIPT]), [
            'type' => Voucher::RECEIPT,
            'trx_date' => now()->toDateString(),
            'amount' => '500000',
            'to_account_id' => $bank->id,
            'instrument_no' => 'R-1',
            'party_type' => 'person',
            'party_id' => $person->id,
            'from_account_id' => $loan->id,
            'narration' => '2nd capital dewa',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $credit = $voucher->lines()->where('credit', '>', 0)->firstOrFail();

        $this->assertSame($loan->id, (int) $credit->account_id, 'টাকাটা বাছা খাতে যায়নি।');
    }
}
