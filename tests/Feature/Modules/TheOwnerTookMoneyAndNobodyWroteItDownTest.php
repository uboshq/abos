<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\Withdrawal;
use App\Modules\Finance\Services\WithdrawalService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * মালিক টাকা নিলেন, আর কেউ লিখে রাখল না।
 *
 * ── কী ভাঙা ছিল, ৩০ আগস্ট ২০২৬ ───────────────────────────────────────
 * টাকাটা তোলা যেত আগেও — একটা পরিশোধ ভাউচার, ডেবিট ৩২০০। কিন্তু
 * ভাউচারে **কে তুলল** বলার কোনো ঘর নেই; নামটা থাকত কেবল বিবরণে, আর
 * [[CapitalService::withdrawnBy()]] ওই লেখা মিলিয়ে খুঁজত।
 *
 * বানান একটু আলাদা হলেই টাকাটা কারও নামে বসত না — আর অংশীদারি ব্যবসায়
 * ঠিক ওই সংখ্যাটা নিয়েই ঝগড়া হয়।
 *
 * ── এই ফাইলটা তিনটা জিনিস পাহারা দেয় ────────────────────────────────
 * ১. দাখিলাটা ঠিক — উত্তোলন **খরচ নয়**, মূলধন কমা।
 * ২. মাসিক সীমাটা সত্যিই আটকায়, আর কতটা বাকি তা বলে।
 * ৩. অনুমোদন ঝুলে থাকলে টাকা যায় না।
 */
class TheOwnerTookMoneyAndNobodyWroteItDownTest extends TestCase
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

    private function service(): WithdrawalService
    {
        return app(WithdrawalService::class);
    }

    private function cash(): Account
    {
        return Account::query()->money()->postable()->active()->firstOrFail();
    }

    private function ledger(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $row = LedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) $row->d, (string) $row->c, 4);
    }

    /** @param  array<string, mixed>  $extra */
    private function take(string $amount, array $extra = []): Withdrawal
    {
        return $this->service()->request(array_merge([
            'contributor_name' => 'মালিক',
            'amount' => $amount,
            'trx_date' => now()->toDateString(),
        ], $extra));
    }

    /**
     * লেখা আর খাতায় বসা আলাদা — মূলধনের পর্দার মতোই।
     *
     * কথা হয় একদিন, টাকা যায় আরেকদিন। লেখার সাথেই বসিয়ে দিলে ব্যবসার
     * নগদ কম দেখাত এমন টাকায় যা এখনো কেউ নেয়নি।
     */
    public function test_writing_it_down_does_not_move_the_money(): void
    {
        $before = $this->ledger(StandardChart::DRAWINGS);

        $withdrawal = $this->take('5000');

        $this->assertSame(DocumentStatus::DRAFT, $withdrawal->status);

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DRAWINGS), $before, 4),
            'কেবল লেখাতেই খাতায় টাকা বসে গেছে।');
    }

    /**
     * খাতায় বসলে মূলধন কমে, খরচ বাড়ে না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি ───────────────────────────────────────
     * খরচে ফেললে ব্যবসার মুনাফা ঠিক ওই পরিমাণ কম দেখাত, আর বছরশেষে
     * কে কত নিল তা বলার উপায় থাকত না।
     */
    public function test_posting_it_lowers_the_capital_not_the_profit(): void
    {
        $withdrawal = $this->take('5000');
        $cashBefore = $this->ledger($this->cash()->code);

        $this->service()->post($withdrawal, $this->cash());

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DRAWINGS), '5000', 4),
            'উত্তোলনের খাতে টাকাটা বসেনি।');

        $this->assertSame(0, bccomp(
            bcsub($this->ledger($this->cash()->code), $cashBefore, 4), '-5000', 4),
            'টিল থেকে টাকাটা বেরোয়নি।');

        $this->assertSame(0, bccomp($this->ledger(StandardChart::OPERATING_EXPENSES), '0', 4),
            'উত্তোলন খরচের খাতে গেছে — ব্যবসার মুনাফা মিথ্যা কম দেখাবে।');
    }

    /**
     * মাসিক সীমা সত্যিই আটকায়, আর কতটা বাকি তা বলে।
     */
    public function test_the_monthly_cap_refuses_and_says_what_is_left(): void
    {
        $this->service()->setCap('মালিক', '10000');

        $this->take('7000');

        try {
            $this->take('5000');
            $this->fail('সীমা পেরিয়ে গেলেও আটকায়নি।');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->all());

            $this->assertStringContainsString('3000', $message,
                'বার্তাটা বলছে না আর কতটা তোলা যাবে।');
        }
    }

    /**
     * সীমার ঠিক ভেতরে থাকলে যায়।
     *
     * আটকানোর পরীক্ষা একা যথেষ্ট নয় — একটা নিয়ম যা সবকিছু আটকায়
     * সেটাও "কাজ করে"।
     */
    public function test_right_up_to_the_cap_is_allowed(): void
    {
        $this->service()->setCap('মালিক', '10000');

        $this->take('7000');
        $withdrawal = $this->take('3000');

        $this->assertSame(0, bccomp((string) $withdrawal->amount, '3000', 4));
    }

    /**
     * সীমা না থাকা মানে সীমা নেই, শূন্য নয়।
     *
     * শূন্য ধরলে প্রথম দিনেই সবার উত্তোলন আটকে যেত, আর কেউ বুঝত না কেন।
     */
    public function test_no_cap_means_no_limit(): void
    {
        $withdrawal = $this->take('900000');

        $this->assertSame(0, bccomp((string) $withdrawal->amount, '900000', 4));
    }

    /**
     * সীমা তুলে নেওয়া যায়।
     */
    public function test_a_cap_can_be_lifted(): void
    {
        $this->service()->setCap('মালিক', '1000');
        $this->service()->setCap('মালিক', null);

        $withdrawal = $this->take('50000');

        $this->assertSame(0, bccomp((string) $withdrawal->amount, '50000', 4),
            'সীমা তুলে নেওয়ার পরেও আটকাচ্ছে।');
    }

    /**
     * খসড়াও সীমায় গোনা হয়।
     *
     * ── কেন ───────────────────────────────────────────────────────
     * কেবল বসে যাওয়াগুলো গুনলে কেউ পাঁচটা খসড়া লিখে রেখে তারপর একসাথে
     * বসিয়ে দিতেন, আর সীমাটা কখনো আটকাত না।
     */
    public function test_drafts_count_towards_the_cap_too(): void
    {
        $this->service()->setCap('মালিক', '10000');

        $this->take('9000');

        $this->expectException(ValidationException::class);

        $this->take('2000');
    }

    /**
     * দুইবার খাতায় বসে না।
     */
    public function test_it_cannot_be_posted_twice(): void
    {
        $withdrawal = $this->take('1000');
        $this->service()->post($withdrawal, $this->cash());

        $this->expectException(ValidationException::class);

        $this->service()->post($withdrawal->fresh(), $this->cash());
    }

    /**
     * নাম আর অঙ্ক দুইটাই লাগে।
     */
    public function test_it_needs_a_name_and_a_positive_amount(): void
    {
        try {
            $this->take('5000', ['contributor_name' => '   ']);
            $this->fail('নাম ছাড়াই লেখা হয়ে গেছে।');
        } catch (ValidationException) {
            // ঠিক আছে
        }

        $this->expectException(ValidationException::class);
        $this->take('0');
    }

    /**
     * "কে কোথায় দাঁড়িয়ে" মূলধনের নামগুলোও ধরে।
     *
     * যিনি টাকা দিয়েছেন অথচ এখনো তোলেননি, তাঁর সারিটাও থাকা দরকার —
     * নাহলে তাঁর সীমা বসানোর কোনো জায়গা থাকত না।
     */
    public function test_somebody_who_only_put_money_in_still_gets_a_row(): void
    {
        CapitalEntry::query()->create([
            'company_id' => $this->company->id,
            'document_no' => 'CAP-TEST-1',
            'contributor_name' => 'অংশীদার',
            'contributor_type' => 'partner',
            'entry_type' => 'contribution',
            'trx_date' => now()->toDateString(),
            'amount' => '100000',
            'status' => CapitalEntry::DRAFT,
        ]);

        $names = collect($this->service()->standing())->pluck('name');

        $this->assertTrue($names->contains('অংশীদার'),
            'যিনি কেবল টাকা দিয়েছেন তাঁর সারিটা নেই — সীমা বসানোর জায়গা থাকত না।');
    }

    /**
     * পর্দাটা খোলে, আর ওখান থেকেই পুরো পথটা চলে।
     */
    public function test_the_screen_works_end_to_end(): void
    {
        $this->get(route('finance.withdrawal.index'))->assertOk();

        $this->post(route('finance.withdrawal.cap'), [
            'contributor_name' => 'মালিক',
            'monthly_cap' => '20000',
        ])->assertRedirect();

        $this->post(route('finance.withdrawal.store'), [
            'contributor_name' => 'মালিক',
            'amount' => '8000',
            'trx_date' => now()->toDateString(),
            'reason' => 'বাড়ির খরচ',
        ])->assertRedirect();

        $withdrawal = Withdrawal::query()->latest('id')->firstOrFail();

        $this->post(route('finance.withdrawal.post', $withdrawal), [
            'money_account_id' => $this->cash()->id,
        ])->assertRedirect();

        $this->assertTrue($withdrawal->fresh()->isPosted());

        $this->assertSame(0, bccomp($this->ledger(StandardChart::DRAWINGS), '8000', 4),
            'পর্দা থেকে করা উত্তোলনটা খাতায় বসেনি।');
    }
}
