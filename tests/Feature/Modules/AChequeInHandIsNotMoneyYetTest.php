<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

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
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হাতে চেক মানে এখনো টাকা নয়।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ভাউচারে "চেক" বলে একটা উপায় বাছা যেত, নম্বর ও তারিখও লেখা যেত।
 * কিন্তু **ওই বাছাইটা কোনো দাখিলা বদলাত না** — টাকাটা সাথে সাথেই
 * ব্যাংকে বসত।
 *
 * ফলে দুইটা মিথ্যা: ব্যাংক ব্যালেন্স এমন টাকা দেখাত যা এখনো আসেনি,
 * আর চেক ফেরত এলে সেটা ব্যাংকে **কোনোদিন ছিলই না** — অথচ খাতা বলত
 * ছিল, আর ফেরতটা লেখার কোনো উপায়ও ছিল না।
 *
 * বাংলাদেশের পরিবেশনে আদায়ের বড় অংশ চেকে, আর তার একটা অংশ ফেরত আসে।
 *
 * ── ⭐ নিয়ম বদলাল — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ──────────────
 * *"ক্লিয়ারিং এর পরে একাউন্টে জমা হলে তার পর"*। আগে গৃহীত চেক হাতে
 * আসতেই Dr ১১০৪ / Cr ডিলার বসত — ডিলারের দেনা সেদিনই কমত, আর বাকির
 * সীমাও সেদিনই খুলত। ⓘ এখন হাতে আসা কেবল রেজিস্টারে; দেনা কমে পাশের
 * দিন (Dr ব্যাংক / Cr ডিলার)। নিচের তিনটা দাবি সেই কারণেই উল্টেছে।
 * পুরো নতুন পথ আর পুরনো চেকের পথ: [[AChequeReachesTheBooksOnlyWhenItClearsTest]]।
 */
class AChequeInHandIsNotMoneyYetTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Supplier $principal;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->firstOrFail();
        $this->principal = Supplier::query()->firstOrFail();

        $this->bank = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '1102-CITY',
            'name_en' => 'City Bank',
            'name_bn' => 'সিটি ব্যাংক',
            'parent_id' => StandardChart::find(StandardChart::BANK)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => Account::BANK,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function service(): ChequeService
    {
        return app(ChequeService::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function received(string $amount = '50000', array $extra = []): Cheque
    {
        return $this->service()->create([
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'A'.random_int(100000, 999999),
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->addDays(7)->toDateString(),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
            'bank_account_id' => $this->bank->id,
            ...$extra,
        ]);
    }

    private function balanceOf(string $code): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', StandardChart::find($code)->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }

    private function bankBalance(): string
    {
        return (string) (LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')
            ->value('bal') ?? '0');
    }

    private function dealerDue(): string
    {
        return (string) (LedgerEntry::query()
            ->where('party_type', 'customer')->where('party_id', $this->dealer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');
    }

    // ── কেন্দ্রীয় দাবি ───────────────────────────────────────────────

    /**
     * চেক হাতে এল — ব্যাংকে কিছুই ঢোকেনি, আর ডিলারের দেনাও কমেনি।
     *
     * এটাই পুরো কাজটা। আগে টাকাটা সাথে সাথেই ব্যাংকে বসত।
     *
     * ⚠️ উল্টানো দাবি, ২৬ সেপ্টেম্বর ২০২৬: আগে এখানে দাবি ছিল ১১০৪-এ
     * ৫০,০০০ বসে আর দেনা ৫০,০০০ কমে। ⭐ মালিকের নিয়মে চেক খাতায় ওঠে
     * কেবল পাশের পরে — তাই এখন ১১০৪ শূন্য, দেনা অপরিবর্তিত।
     */
    public function test_the_bank_does_not_move_when_the_cheque_arrives(): void
    {
        $dueBefore = $this->dealerDue();

        $this->received('50000');

        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2),
            'চেক হাতে আসতেই ব্যাংকে টাকা বসে গেছে — ওটা এখনো টাকা নয়।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'চেক হাতে আসতেই ১১০৪-এ বসেছে — মালিকের নিয়মে খাতায় ওঠার কথা কেবল পাশের দিন।');

        $this->assertSame(0, bccomp($this->dealerDue(), $dueBefore, 2),
            'চেক হাতে আসতেই ডিলারের দেনা কমেছে — পাশ না হওয়া পর্যন্ত টাকা আসেনি।');
    }

    /** জমা দেওয়া কেবল অবস্থার বদল — খাতায় কিছুই নড়ে না। */
    public function test_depositing_moves_no_money(): void
    {
        $cheque = $this->received('50000');
        $before = $this->bankBalance();

        $deposited = $this->service()->deposit($cheque);

        $this->assertSame(Cheque::DEPOSITED, $deposited->status);
        $this->assertSame(0, bccomp($this->bankBalance(), $before, 2),
            'জমা দেওয়াতেই ব্যাংক ব্যালেন্স বদলে গেছে।');
    }

    /** পাশ হলে তবেই টাকাটা ব্যাংকে ঢোকে। */
    public function test_clearing_is_when_the_money_arrives(): void
    {
        $cheque = $this->service()->clear($this->received('50000'));

        $this->assertSame(Cheque::CLEARED, $cheque->status);

        $this->assertSame(0, bccomp($this->bankBalance(), '50000', 2),
            'পাশ হওয়ার পরেও ব্যাংকে টাকা ঢোকেনি।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'পাশ হওয়ার পরেও হাতে চেকের খাতে টাকা পড়ে আছে।');
    }

    /**
     * ফেরত এলে ডিলারের দেনা ফিরে আসে, আর ব্যাংক অক্ষত থাকে।
     *
     * ── কেন উল্টো দাখিলা নয় ─────────────────────────────────────────
     * ফেরত আসা একটা **নতুন ঘটনা**, পুরনোটার বাতিল নয়। উল্টে দিলে খাতায়
     * দেখাত চেকটা কোনোদিন আসেইনি — অথচ ওটা এসেছিল, জমা পড়েছিল, আর
     * ফেরত এসেছিল। তিনটাই সত্যি।
     */
    public function test_a_bounce_puts_the_debt_back(): void
    {
        $dueBefore = $this->dealerDue();

        $cheque = $this->received('50000');
        $this->service()->deposit($cheque);
        $bounced = $this->service()->bounce($cheque->fresh(), 'তহবিল অপর্যাপ্ত');

        $this->assertSame(Cheque::BOUNCED, $bounced->status);

        $this->assertSame(0, bccomp($this->dealerDue(), $dueBefore, 2),
            'ফেরতের পরেও ডিলারের দেনা আগের জায়গায় ফেরেনি।');

        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2),
            'ফেরত আসা চেকের টাকা ব্যাংকে রয়ে গেছে।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2));
    }

    /**
     * যা খাতায় উঠেছিল তা খাতায় থেকে যায় — মুছে যায় না।
     *
     * নিরীক্ষায় "এই চেকটার কী হয়েছিল" প্রশ্নের উত্তর কেবল তখনই দেওয়া
     * যায় যখন প্রতিটা ঘটনা আলাদা করে দেখা যায়।
     *
     * ⚠️ বদলানো দাবি, ২৬ সেপ্টেম্বর ২০২৬: আগে হাতে আসার দিনের `cheque`
     * দাখিলাও থাকত। ⭐ মালিকের নিয়মে হাতে আসাটা এখন কেবল রেজিস্টারে,
     * তাই খাতায় দুইটা ঘটনা — পাশ, আর পরে ফেরত।
     */
    public function test_all_three_events_stay_on_the_books(): void
    {
        $cheque = $this->received('50000');
        $this->service()->deposit($cheque);
        $this->service()->clear($cheque->fresh());
        $this->service()->bounce($cheque->fresh(), 'সই মেলেনি');

        $sources = LedgerEntry::query()
            ->where('source_id', $cheque->id)
            ->where('source_type', 'like', 'cheque%')
            ->pluck('source_type')->unique()->values()->all();

        $this->assertNotContains('cheque', $sources,
            'হাতে আসার দিনের দাখিলা বসেছে — নতুন নিয়মে ওটা কেবল রেজিস্টারে।');
        $this->assertContains('cheque:cleared', $sources);
        $this->assertContains('cheque:bounced', $sources);
    }

    // ── ইস্যু করা চেক ────────────────────────────────────────────────

    /**
     * নিজের দেওয়া চেকেও ব্যাংক কমে ভাঙানোর দিন, দেওয়ার দিন নয়।
     *
     * দেওয়ার দিনেই ব্যাংক কমালে ব্যাংক-মিলকরণে প্রতিটা অভাঙা চেক একটা
     * অমিল হয়ে দাঁড়াত।
     */
    public function test_an_issued_cheque_leaves_the_bank_when_it_is_presented(): void
    {
        $cheque = $this->service()->create([
            'direction' => Cheque::ISSUED,
            'cheque_no' => 'B900001',
            'bank_name' => 'City Bank',
            'cheque_date' => now()->toDateString(),
            'amount' => '30000',
            'party_type' => 'supplier',
            'party_id' => $this->principal->id,
            'bank_account_id' => $this->bank->id,
        ]);

        $this->assertSame(0, bccomp($this->bankBalance(), '0', 2),
            'চেক দেওয়ার দিনেই ব্যাংক থেকে টাকা কেটে গেছে।');

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_ISSUED), '-30000', 2),
            'দেওয়া চেকের দায়টা বসেনি।');

        $this->service()->clear($cheque);

        $this->assertSame(0, bccomp($this->bankBalance(), '-30000', 2),
            'ভাঙানোর পরেও ব্যাংক থেকে টাকা যায়নি।');
    }

    // ── পাহারা ──────────────────────────────────────────────────────

    /** কারণ ছাড়া ফেরত বসানো যায় না। */
    public function test_a_bounce_needs_its_reason(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->bounce($this->received(), '  ');
    }

    /** একবার সিদ্ধান্ত হয়ে গেলে দ্বিতীয়বার নয়। */
    public function test_a_cleared_cheque_is_not_cleared_again(): void
    {
        $cheque = $this->service()->clear($this->received());

        $this->expectException(ValidationException::class);

        $this->service()->clear($cheque->fresh());
    }

    /** ব্যাংক না বললে পাশ করা যায় না — অনুমান করে বসানো হয় না। */
    public function test_clearing_without_a_bank_is_refused(): void
    {
        $cheque = $this->received('1000', ['bank_account_id' => null]);

        $this->expectException(ValidationException::class);

        $this->service()->clear($cheque);
    }

    /**
     * তারিখ পেরোনো অথচ ঝুলে থাকা চেকগুলো আলাদা করে চেনা যায়।
     *
     * আগাম তারিখের চেক ফেলে রাখা স্বাভাবিক; তারিখ পেরোনোর পরেও ফেলে
     * রাখা মানে হয় কেউ জমা দিতে ভুলেছে, নয় ব্যাংক থেকে খবর আসেনি।
     */
    public function test_cheques_past_their_date_can_be_found(): void
    {
        $this->received('1000', ['cheque_date' => now()->subDays(3)->toDateString()]);
        $this->received('2000', ['cheque_date' => now()->addDays(10)->toDateString()]);

        $this->assertSame(1, Cheque::query()->ripe()->count(),
            'তারিখ পেরোনো চেকের গোনাটা ভুল।');
    }

    /** একই ব্যাংকের একই নম্বরের চেক দুইবার ওঠে না। */
    public function test_the_same_cheque_number_is_refused_twice(): void
    {
        $this->received('1000', ['cheque_no' => 'DUP-1']);

        $this->expectException(QueryException::class);

        $this->received('1000', ['cheque_no' => 'DUP-1']);
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** পর্দাটা খোলে, আর তারিখ পেরোনো চেকের সতর্কতাটা দেখায়। */
    public function test_the_screen_warns_about_overdue_cheques(): void
    {
        $this->received('7500', ['cheque_date' => now()->subDay()->toDateString()]);

        $this->get(route('accounts.cheque.index'))
            ->assertOk()
            ->assertSee(__('accounts::field.cheques_ripe'))
            ->assertSee('7,500.00');
    }

    /**
     * পর্দা থেকেই চেক বসানো যায় — রেজিস্টারে, খতিয়ানে নয়।
     *
     * ⚠️ উল্টানো দাবি, ২৬ সেপ্টেম্বর ২০২৬: আগে দাবি ছিল ১১০৪-এ ১২,০০০
     * বসে। ⭐ মালিকের নিয়মে খতিয়ানে পৌঁছায় কেবল পাশের দিন।
     */
    public function test_a_cheque_can_be_recorded_from_the_screen(): void
    {
        $this->post(route('accounts.cheque.store'), [
            'direction' => Cheque::RECEIVED,
            'cheque_no' => 'SCREEN-1',
            'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->addDays(5)->toDateString(),
            'amount' => '12000',
            'party' => 'customer:'.$this->dealer->id,
            'bank_account_id' => $this->bank->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, bccomp($this->balanceOf(StandardChart::CHEQUES_IN_HAND), '0', 2),
            'পর্দা থেকে তোলা চেক সেদিনই ১১০৪-এ বসেছে।');

        $this->assertDatabaseHas('acc_cheques', [
            'cheque_no' => 'SCREEN-1',
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
        ]);
    }
}
