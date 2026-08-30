<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কত এল, আর তার কতটা বিক্রয় ছাড়া।
 *
 * ── কেন এই পর্দাটা লাগল, ৩০ আগস্ট ২০২৬ ───────────────────────────────
 * খরচের পর্দা ছিল, আয়ের ছিল না। পরিকল্পনার §১০-এ দুইটা লাইন বাকি ছিল:
 * "আয়ের শ্রেণি" আর "বিক্রয় ছাড়া অন্য আয় — ভাড়া, কমিশন, বাতিল মাল"।
 *
 * ── কেন ভাগটাই আসল খবর ───────────────────────────────────────────────
 * ভাড়া, কমিশন আর বাতিল মাল বিক্রির টাকার **কোনো ক্রয়মূল্য নেই** —
 * অর্থাৎ পুরোটাই মুনাফা। ৪% মার্জিনের ব্যবসায় দশ হাজার টাকার ভাড়া
 * আড়াই লাখ টাকার বিক্রয়ের সমান। বিক্রয়ের সাথে মিশিয়ে রাখলে কেউ
 * সেটা কোনোদিন দেখত না।
 */
class HowMuchCameInAndHowMuchOfItWasNotSellingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    private function chartHead(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function earn(string $code, string $amount): void
    {
        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'test'],
            [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->chartHead($code)->id, 'debit' => '0', 'credit' => $amount],
            ],
        );

        app(VoucherService::class)->post($voucher);
    }

    /** @return array<string, mixed> */
    private function screen(): array
    {
        $response = $this->get(route('finance.income.index'))->assertOk();

        return [
            'heads' => $response->viewData('heads'),
            'totals' => $response->viewData('totals'),
        ];
    }

    /**
     * বিক্রয় আর বিক্রয়-ছাড়া আলাদা করে গোনা হয়।
     *
     * এটাই পর্দাটার একমাত্র নতুন কথা — বাকিটা খরচের পর্দার আয়না।
     */
    public function test_it_separates_selling_from_everything_else(): void
    {
        $this->earn(StandardChart::SALES, '250000');
        $this->earn('4320', '10000');   // ভাড়া আয়
        $this->earn('4330', '4000');    // বাতিল মাল

        $totals = $this->screen()['totals'];

        $this->assertSame(0, bccomp($totals['sales'], '250000', 4),
            'বিক্রয়ের যোগফল ভুল।');

        $this->assertSame(0, bccomp($totals['other'], '14000', 4),
            'বিক্রয় ছাড়া আয়ের যোগফল ভুল — ভাড়া আর বাতিল মাল মিলে ১৪,০০০।');

        $this->assertSame(0, bccomp($totals['all'], '264000', 4));
    }

    /**
     * বিক্রয় ফেরত বিক্রয়ের ঘরেই থাকে।
     *
     * ── কেন ───────────────────────────────────────────────────────
     * ফেরত বিক্রয়ের উল্টো দিক, আলাদা কোনো আয় নয়। "বিক্রয় ছাড়া কত এল"
     * সংখ্যাটায় ঢুকলে ওটা ঋণাত্মক টেনে নামাত, আর প্রশ্নটার উত্তরই
     * মিথ্যা হত।
     */
    public function test_a_return_belongs_with_sales_not_with_the_rest(): void
    {
        $this->earn(StandardChart::SALES, '100000');
        $this->earn('4320', '5000');

        /* ফেরত — বিক্রয় ফেরতের খাতে ডেবিট */
        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'return'],
            [
                ['account_id' => $this->chartHead(StandardChart::SALES_RETURN)->id,
                    'debit' => '20000', 'credit' => '0'],
                ['account_id' => $cash->id, 'debit' => '0', 'credit' => '20000'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $totals = $this->screen()['totals'];

        $this->assertSame(0, bccomp($totals['sales'], '80000', 4),
            'ফেরতটা বিক্রয় থেকে বাদ যায়নি।');

        $this->assertSame(0, bccomp($totals['other'], '5000', 4),
            'ফেরতটা "বিক্রয় ছাড়া" ঘরে ঢুকে পড়েছে।');
    }

    /**
     * বিক্রয় ছাড়া আয়ের খাতগুলো ছকে সত্যিই আছে।
     *
     * পরিকল্পনায় নাম ধরে তিনটা চাওয়া হয়েছিল — ভাড়া, কমিশন, বাতিল মাল।
     */
    public function test_the_non_sales_heads_exist(): void
    {
        foreach (['4320', '4330', '4340'] as $code) {
            $this->assertNotNull(Account::query()->where('code', $code)->first(),
                "বিক্রয় ছাড়া আয়ের খাত {$code} ছকে নেই।");
        }
    }

    /**
     * কমিশন **আয়** আর কমিশনের **দাবি** এক খাত নয়।
     *
     * ── কেন এটার আলাদা পরীক্ষা ──────────────────────────────────────
     * ১১৫০ একটা সম্পদ: ডিপো ডিলারকে কমিশন দিয়ে দিয়েছে, কোম্পানির কাছ
     * থেকে ফেরত পাবে। ৪৩৪০ একটা আয়: ডিপো অন্য কারও মাল বেচে নিজে
     * কমিশন পেল।
     *
     * দুইটা মিশলে "কোম্পানি আমার কত দেবে" সংখ্যাটা ফুলে যেত এমন টাকায়
     * যা কেউ দেবে না — আর ওই সংখ্যাটাই মাস-শেষে দাবি করা হয়।
     */
    public function test_commission_earned_is_not_commission_claimable(): void
    {
        $earned = $this->chartHead('4340');
        $claimable = $this->chartHead(StandardChart::COMMISSION_CLAIM);

        $this->assertNotSame($earned->id, $claimable->id);
        $this->assertSame(Account::INCOME, $earned->type);
        $this->assertSame(Account::ASSET, $claimable->type);
    }

    /**
     * যে খাতে কিছুই আসেনি সেটা পর্দায় ওঠে না।
     *
     * ছকের বেশিরভাগ খাত একটা ডিপোতে সারা বছরেও ছোঁয়া হয় না; সবগুলো
     * দেখালে যেটা সত্যিই বেড়েছে সেটা শূন্যের ভিড়ে হারাত।
     */
    public function test_an_untouched_head_stays_off_the_screen(): void
    {
        $this->earn(StandardChart::SALES, '1000');

        $codes = collect($this->screen()['heads'])->map(fn ($r) => $r['account']->code);

        $this->assertTrue($codes->contains(StandardChart::SALES));
        $this->assertFalse($codes->contains('4340'),
            'যে খাতে কিছুই আসেনি সেটাও দেখানো হয়েছে।');
    }

    /**
     * প্রতিটা সংখ্যা তার খাতের এন্ট্রিগুলোতে নামায় (নিয়ম ১)।
     */
    public function test_each_figure_leads_to_its_entries(): void
    {
        $this->earn('4320', '7000');

        $html = (string) $this->get(route('finance.income.index'))->getContent();

        $this->assertStringContainsString(
            route('accounts.coa.show', $this->chartHead('4320')).'#transactions',
            $html,
            'আয়ের সংখ্যাটা খাতের এন্ট্রিগুলোর কাছে নামায় না।',
        );
    }
}
