<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Loan;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\LoanService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হাতধার সেভ করলে তালিকা বলত "সিসি"।
 *
 * ── কী ভাঙা ছিল, HP-র রিপোর্ট ২৯ আগস্ট ২০২৬ ─────────────────────────
 * ধারের তালিকা আর বিস্তারিত পাতা দুইটাতেই লেখা ছিল
 * `isTerm() ? "টার্ম" : "সিসি"` — অর্থাৎ **টার্ম নয় মানেই সিসি**।
 *
 * ধরন যখন দুইটা ছিল তখন কথাটা সত্যি ছিল। হাতধার যোগ হওয়ার পর সে
 * চুপচাপ "সিসি" হয়ে গেল। সেভ ঠিকই হত — ডাটাবেজে `hand` বসত — কিন্তু
 * পর্দা মিথ্যা বলত।
 *
 * ── কেন এটা কেবল লেবেলের সমস্যা নয় ──────────────────────────────────
 * সিসি একটা ব্যাংক-সীমা, হাতধার কাগজবিহীন ব্যক্তিগত ধার। কেউ তালিকা
 * দেখে ভাবতেন ব্যাংকের সীমা থেকে টাকা নেওয়া আছে, অথচ আসলে করিম ভাইয়ের
 * কাছ থেকে। "ব্যাংকে আমাদের দায় কত" প্রশ্নের উত্তরটাই ভুল হত।
 *
 * ── আর এই ফাইলের আসল কাজ ────────────────────────────────────────────
 * নামটা এখন মডেলে ([[Loan::kindLabel()]]), তাই দুইবার লেখা নেই। কিন্তু
 * পাহারাটা তার চেয়ে বড়: **ফর্ম যে ধরনগুলো নেয়, প্রতিটার নিজের নাম আছে
 * কি না** — তালিকাটা হাতে লেখা নয়, ফর্ম থেকেই আসে। চতুর্থ ধরনটা যোগ
 * করার দিন কেউ নাম দিতে ভুললে এখানেই লাল হবে।
 */
class AHandLoanWasCalledACashCreditTest extends TestCase
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
        app(CashTillService::class)->ensurePrimaryTill();
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->firstOrFail();
    }

    private function payable(): Account
    {
        return Account::query()->where('code', '2210')->firstOrFail();
    }

    private function interestExpense(): Account
    {
        return Account::query()->where('type', Account::EXPENSE)->postable()->orderBy('code')->firstOrFail();
    }

    /**
     * ফর্ম যে ধরনগুলো নেয় — হাতে লেখা তালিকা নয়।
     *
     * ফর্মের রেডিও বোতামগুলোই সত্য: ব্যবহারকারী ওগুলোই বাছতে পারেন।
     *
     * @return list<string>
     */
    private function kindsTheFormOffers(): array
    {
        $html = (string) $this->get(route('accounts.loan.create'))->assertOk()->getContent();

        preg_match_all('/name="kind"\s+value="([^"]+)"/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    private function make(string $kind): Loan
    {
        return app(LoanService::class)->create(
            data: [
                'lender' => 'করিম ভাই',
                'kind' => $kind,
                'sanctioned' => '50000',
                'interest_rate' => '0',
                'tenure_months' => 12,
                'interest_method' => 'flat',
                'start_date' => '2026-08-01',
                'principal_account_id' => $this->payable()->id,
                'interest_account_id' => $this->interestExpense()->id,
            ],
            intoAccountId: $this->cash()->id,
        )->refresh();
    }

    /**
     * হাতধার হাতধারই বলে, সিসি নয়।
     *
     * এটাই HP-র ধরা ভুলটা, হুবহু।
     */
    public function test_a_hand_loan_is_not_called_a_cash_credit(): void
    {
        $loan = $this->make(Loan::HAND);

        $this->assertSame(Loan::HAND, $loan->kind, 'সেভই ভুল ধরনে হয়েছে।');

        $this->assertSame(__('accounts::field.loan_hand'), $loan->kindLabel(),
            'হাতধারকে অন্য নামে ডাকা হচ্ছে।');

        $this->assertNotSame(__('accounts::field.loan_cc'), $loan->kindLabel(),
            'হাতধারকে "সিসি" বলা হচ্ছে — HP-র ২৯ আগস্টের বাগটা ফিরে এসেছে।');
    }

    /**
     * আর পর্দাতেও সেটাই লেখা।
     *
     * উপরেরটা মডেল দেখে; ভুলটা ছিল **পর্দায়**, তাই আঁকা পাতাটাও দেখা
     * দরকার। মডেল ঠিক করে পর্দায় পুরনো লাইনটা রেখে দিলে উপরের
     * পরীক্ষাটা সবুজ থাকত আর ব্যবহারকারী তবু "সিসি" দেখতেন।
     */
    public function test_the_list_and_the_record_page_both_say_hand_loan(): void
    {
        $loan = $this->make(Loan::HAND);

        $this->get(route('accounts.loan.index'))
            ->assertOk()
            ->assertSee(__('accounts::field.loan_hand'));

        $this->get(route('accounts.loan.show', $loan))
            ->assertOk()
            ->assertSee(__('accounts::field.loan_hand'));
    }

    /**
     * ফর্মের প্রতিটা ধরনের নিজের নাম আছে, আর নামগুলো আলাদা।
     *
     * ── কেন এটাই আসল পাহারা ─────────────────────────────────────────
     * উপরের দুইটা আজকের ভুলটা ধরে। এটা ধরে **আগামীকালেরটা**: চতুর্থ
     * ধরনটা যোগ করার দিন কেউ নাম দিতে ভুললে সে চুপচাপ কাঁচা `kind`
     * দেখাবে, আর এই পরীক্ষা লাল হবে।
     *
     * তালিকাটা ফর্ম থেকে পড়া হয়, হাতে লেখা নয় — হাতে লিখলে ওই দিনটাতেই
     * এখানেও ভোলা হত।
     */
    public function test_every_kind_the_form_offers_has_a_name_of_its_own(): void
    {
        $kinds = $this->kindsTheFormOffers();

        $this->assertNotEmpty($kinds, 'ফর্মে কোনো ধরনই নেই।');

        $names = [];

        foreach ($kinds as $kind) {
            $loan = new Loan(['kind' => $kind]);

            $label = $loan->kindLabel();

            $this->assertNotSame($kind, $label,
                "ধরন '{$kind}'-এর কোনো নাম নেই — পর্দায় কাঁচা চাবিটাই দেখাচ্ছে।");

            $this->assertArrayNotHasKey($label, $names,
                "'{$kind}' আর '".($names[$label] ?? '')."' দুইটাই একই নামে দেখায় — "
                .'ঠিক এভাবেই হাতধার সিসি হয়ে গিয়েছিল।');

            $names[$label] = $kind;
        }
    }
}
