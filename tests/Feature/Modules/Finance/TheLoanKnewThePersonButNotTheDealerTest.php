<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * খাতা মানুষটাকে চিনত, কিন্তু জানত না ইনিই সেই ডিলার।
 *
 * ── ⓘ অর্থের মানচিত্র §১৪খ, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────
 * দুইটা লাইন, দুইটাই "আছে কিন্তু পর্দা নেই" ধরনের:
 *   · **পক্ষের সাথে জোড়া** — `partner_id`/`partner_type` কলাম অনেক দিন
 *     ধরেই আছে আর সেবা লেখেও, কিন্তু ফর্মে ঘরটা কেউ আঁকেনি, তাই মান
 *     কখনো আসতই না।
 *   · **মনে করিয়ে দেওয়া** — তারিখ ছিল, কিন্তু "কাকে আজ ফোন করতে হবে"
 *     প্রশ্নের উত্তর কোথাও ছিল না।
 */
final class TheLoanKnewThePersonButNotTheDealerTest extends TestCase
{
    use RefreshDatabase;

    private int $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->till = (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }

    /**
     * ⭐ ফর্ম থেকে জোড়াটা সত্যিই খাতায় পৌঁছায়, আর তালিকায় দেখা যায়।
     */
    public function test_the_form_links_the_loan_to_a_dealer(): void
    {
        $customer = Customer::query()->firstOrFail();
        $person = $this->person('Karim Lender');

        $this->get(route('finance.hand_loan.create'))
            ->assertOk()
            ->assertSee('name="party"', escape: false);

        $this->post(route('finance.hand_loan.store'), [
            'person_id' => $person->id,
            'party' => 'customer:'.$customer->id,
        ])->assertSessionHasNoErrors();

        $account = HandLoanAccount::query()->where('person_id', $person->id)->firstOrFail();

        $this->assertSame('customer', $account->partner_type);
        $this->assertSame((int) $customer->id, (int) $account->partner_id);

        // ⓘ তালিকায় নামটাও — সারি ধরে কোয়েরি নয়, একবারেই তোলা
        $rows = app(HandLoanService::class)->standing()['rows'];

        $this->assertSame($customer->name(), collect($rows)
            ->firstWhere('account.id', $account->id)['partner_name'] ?? null);

        $this->get(route('finance.hand_loan.index'))->assertOk()->assertSee($customer->name());
    }

    /**
     * ⛔ ভুল ছাঁদের জোড়া নেওয়া হয় না — ঠিকানায় যা খুশি পাঠানো যায়।
     */
    public function test_a_party_of_the_wrong_shape_is_refused(): void
    {
        $this->post(route('finance.hand_loan.store'), [
            'person_id' => $this->person('Rahim')->id,
            'party' => 'partner:7',
        ])->assertSessionHasErrors('party');
    }

    /**
     * ⭐ "মনে করিয়ে দেওয়া" — তারিখ পেরোনো আর সামনের ত্রিশ দিন, আর কেউ নয়।
     */
    public function test_the_chase_tab_holds_the_overdue_and_the_near(): void
    {
        $late = $this->loan('Late Borrower', now()->subDays(3)->toDateString());
        $soon = $this->loan('Soon Borrower', now()->addDays(10)->toDateString());
        $far = $this->loan('Far Borrower', now()->addDays(90)->toDateString());
        $noDate = $this->loan('Open Ended', null);

        $page = $this->get(route('finance.hand_loan.index', ['tab' => 'due']))->assertOk();

        $names = collect($page->viewData('rows'))
            ->map(fn (array $row) => (string) $row['account']->person?->name_en)
            ->all();

        $this->assertContains('Late Borrower', $names, 'তারিখ পেরোনো ধারটা তাগাদার তালিকায় নেই।');
        $this->assertContains('Soon Borrower', $names);

        $this->assertNotContains('Far Borrower', $names, 'তিন মাস পরের তারিখও তাগাদায় এসেছে।');
        $this->assertNotContains('Open Ended', $names,
            'তারিখহীন ধার তাগাদায় এসেছে — "যখন পারো দিও" ধারে মনে করানোর কিছু নেই।');

        $this->assertSame(2, $page->viewData('counts')['due']);

        // ⓘ চুকে যাওয়া হিসাব তাগাদায় থাকে না
        app(HandLoanService::class)->move($late, [
            'direction' => HandLoanMovement::IN,
            'amount' => '1000',
            'money_account_id' => $this->till,
            'trx_date' => now()->toDateString(),
        ]);

        $this->assertSame(1, $this->get(route('finance.hand_loan.index', ['tab' => 'due']))
            ->viewData('counts')['due'], 'শোধ হয়ে যাওয়া ধারও তাগাদায় রয়ে গেছে।');
    }

    private function loan(string $name, ?string $dueOn): HandLoanAccount
    {
        $service = app(HandLoanService::class);

        $account = $service->open([
            'person_id' => $this->person($name)->id,
            'due_on' => $dueOn,
        ]);

        // ⓘ টাকা বেরোল — নাহলে হিসাব শূন্য, আর শূন্যে তাগাদা হয় না
        $service->move($account, [
            'direction' => HandLoanMovement::OUT,
            'amount' => '1000',
            'money_account_id' => $this->till,
            'trx_date' => now()->toDateString(),
        ]);

        return $account->fresh();
    }

    private function person(string $name): Person
    {
        return Person::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => 'P-'.substr(md5($name), 0, 6),
            'name_en' => $name,
            'is_active' => true,
        ]);
    }
}
