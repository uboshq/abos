<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * স্থিতিপত্র যা মিলত না।
 *
 * ── মালিকের কথা, ৩০ আগস্ট ২০২৬ ───────────────────────────────────────
 * *"balance sheet koroni acc e"* — পর্দাটা ছিল, তাই প্রথমে মনে হয়েছিল
 * ভুল বলছেন। খুলে দেখে বোঝা গেল তিনি ঠিক: ওটা স্থিতিপত্র ছিল না,
 * **নাম বদলানো রেওয়ামিল** — ডেবিট/ক্রেডিট কলাম, সমতল তালিকা, উপমোট
 * নেই, **দায়ের একটাও সারি নেই**, আর নিচে মোট লেখা −১০,০৫০।
 *
 * ── এই ফাইলটার একটাই কাজ ─────────────────────────────────────────────
 * স্থিতিপত্রের একমাত্র দাবি: **সম্পদ = দায় + মূলধন**। বাকি সব সাজসজ্জা।
 * তাই প্রতিটা পরীক্ষা ওই সমতাটাই ধরে — নতুন লেনদেনের পরে, নতুন খাতের
 * পরে, বছরের মাঝখানে।
 *
 * ── আর যেটা সবচেয়ে সহজে ভাঙে ─────────────────────────────────────────
 * **চলতি বছরের লাভ।** বছর বন্ধ না হওয়া পর্যন্ত আয়-ব্যয় খাতেই বসে
 * থাকে; মূলধনে যায় সমাপনী দাখিলায়। ওটা যোগ করতে ভুললে দুই পক্ষ ঠিক
 * লাভের পরিমাণ আলাদা হয় — আর ওটাই ছিল সেই −১০,০৫০।
 */
class ABalanceSheetThatDidNotBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        /*
         * একটা টিল লাগে, কারণ বসানো ছকে টাকার কোনো **খাত** নেই।
         *
         * ১১০১ "হাতে নগদ" একটা মাথা, আর মাথায় সরাসরি লেনদেন বসে না।
         * আসল খাতটা তৈরি হয় টিল বসানোর দিন। প্রথমে ফলব্যাক হিসেবে
         * মাথাটাই ধরা হয়েছিল আর সেবা ঠিকই আটকে দিল।
         */
        app(CashTillService::class)->ensurePrimaryTill();
    }

    /** @return array<string, mixed> */
    private function sheet(?string $asOf = null): array
    {
        return app(BalanceSheetService::class)->build($asOf);
    }

    private function chartHead(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    /**
     * সবচেয়ে জরুরি পরীক্ষা — দুই পক্ষ সমান।
     */
    public function test_the_two_sides_are_equal(): void
    {
        $sheet = $this->sheet();

        $this->assertTrue($sheet['agrees'], implode(' ', [
            'স্থিতিপত্র মিলছে না — পার্থক্য '.$sheet['difference'].'.',
            'সম্পদ '.$sheet['totals']['assets'].', দায়+মূলধন '.$sheet['totals']['funding'].'.',
        ]));
    }

    /**
     * বছরের মাঝখানে লাভ হলেও মেলে।
     *
     * ── কেন এটাই আসল পরীক্ষা ────────────────────────────────────────
     * শূন্য খাতায় দুই পক্ষ মেলানো সহজ — দুইটাই শূন্য। ভাঙে তখন, যখন
     * আয় বা ব্যয় বসে, কারণ ওটা মূলধনে যায় কেবল হিসাব করে। পুরনো
     * পর্দাটা ঠিক এখানেই ভেঙেছিল।
     */
    public function test_it_still_balances_after_a_profit(): void
    {
        $before = $this->sheet();

        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        /* একটা বিক্রয় — নগদ বাড়ল, আয় বাড়ল */
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'narration' => 'test sale'],
            [
                ['account_id' => $cash->id, 'debit' => '5000', 'credit' => '0'],
                ['account_id' => $this->chartHead(StandardChart::SALES)->id, 'debit' => '0', 'credit' => '5000'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $after = $this->sheet();

        $this->assertTrue($after['agrees'],
            'আয় বসানোর পর স্থিতিপত্র মিলছে না — পার্থক্য '.$after['difference']);

        $this->assertSame(0, bccomp(
            bcsub($after['profit'], $before['profit'], 4), '5000', 4),
            'চলতি বছরের লাভ ৫,০০০ বাড়েনি।');

        $this->assertSame(0, bccomp(
            bcsub($after['totals']['assets'], $before['totals']['assets'], 4), '5000', 4),
            'সম্পদ ৫,০০০ বাড়েনি।');
    }

    /**
     * খরচেও মেলে, আর লাভ কমে।
     */
    public function test_an_expense_lowers_the_profit_and_it_still_balances(): void
    {
        $before = $this->sheet();

        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'test rent'],
            [
                ['account_id' => $this->chartHead('5202')->id, 'debit' => '2000', 'credit' => '0'],
                ['account_id' => $cash->id, 'debit' => '0', 'credit' => '2000'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $after = $this->sheet();

        $this->assertTrue($after['agrees'],
            'খরচ বসানোর পর স্থিতিপত্র মিলছে না — পার্থক্য '.$after['difference']);

        $this->assertSame(0, bccomp(
            bcsub($after['profit'], $before['profit'], 4), '-2000', 4),
            'খরচে লাভ কমেনি।');
    }

    /**
     * দায়ের ঘরটা থাকে, শূন্য হলেও।
     *
     * ── কেন এটা আলাদা করে দেখা ──────────────────────────────────────
     * পুরনো পর্দার সবচেয়ে বড় দোষ: দায়ের একটাও সারি ছিল না, কারণ ওটা
     * কেবল যেসব খাতে এন্ট্রি আছে সেগুলো দেখাত। শূন্য দায় আর দায়ের
     * ঘরটাই না থাকা এক জিনিস নয় — প্রথমটা একটা উত্তর, দ্বিতীয়টা
     * একটা ফাঁক।
     */
    public function test_the_liability_side_is_always_shown(): void
    {
        $sheet = $this->sheet();

        $this->assertNotEmpty($sheet['liabilities'],
            'দায়ের কোনো ঘরই নেই — পুরনো পর্দার দোষটা ফিরে এসেছে।');

        $this->assertNotEmpty($sheet['equity'], 'মূলধনের কোনো ঘর নেই।');
        $this->assertNotEmpty($sheet['assets'], 'সম্পদের কোনো ঘর নেই।');
    }

    /**
     * পর্দাটা খোলে, আর সমতার কথাটা লেখা থাকে।
     */
    public function test_the_screen_says_whether_it_balances(): void
    {
        $html = (string) $this->get(route('accounts.balance_sheet'))
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('accounts::message.sheet_agrees'), $html,
            'পর্দা বলছে না খাতা মেলে কি না — অথচ ওটাই স্থিতিপত্রের একমাত্র দাবি।');
    }

    /**
     * পুরনো ঠিকানাটা ভাঙে না।
     *
     * `/accounts/reports/balance-sheet` বহুদিন ধরে আছে, আর কেউ বুকমার্ক
     * করে রাখতে পারেন। রুটটা এখন নতুন পাতাতেই যায়।
     */
    public function test_the_old_address_still_works(): void
    {
        $this->get('/accounts/reports/balance-sheet')->assertOk();
    }

    /**
     * প্রতিটা সংখ্যা তার খাতের এন্ট্রিগুলোতে নামায় (নিয়ম ১)।
     */
    public function test_each_figure_leads_to_its_entries(): void
    {
        $html = (string) $this->get(route('accounts.balance_sheet'))->assertOk()->getContent();

        /*
         * পর্দা যে সারিগুলো সত্যিই এঁকেছে, তার একটা ধরে দেখা।
         *
         * প্রথমে "প্রাপ্য হিসাব" ধরে খোঁজা হয়েছিল আর লাল হলো — ওই
         * খাতে সিডারের ডেটায় জের ছিল না, আর শূন্য সারি আঁকাই হয় না
         * (ইচ্ছাকৃত: ৬৪টা খাতের বেশিরভাগ কোনোদিন ছোঁয়া হয় না)।
         *
         * প্রশ্নটা কোনো নির্দিষ্ট খাত নিয়ে নয় — **যে সারিই আঁকা হোক,
         * সেটা তার এন্ট্রিতে নামে কি না**। তাই তালিকাটা পর্দার নিজের
         * ফল থেকেই নেওয়া, আর সিডার বদলালেও পরীক্ষাটা সত্যি থাকে।
         */
        $sheet = $this->sheet();
        $lines = collect($sheet['assets'])->flatMap(fn ($g) => $g['lines']);

        $this->assertNotEmpty($lines, 'সম্পদের কোনো সারিই আঁকা হয়নি।');

        foreach ($lines as $line) {
            $this->assertStringContainsString(
                route('accounts.coa.show', $line['account']).'#transactions',
                $html,
                $line['account']->code.' সারিটা তার এন্ট্রিতে নামায় না।',
            );
        }
    }

    /**
     * উত্তোলন মূলধন কমায়, বাড়ায় না।
     *
     * ── কেন এটার নিজের পরীক্ষা ──────────────────────────────────────
     * পর্দাটা প্রথম খুলেই ৩২ লাখের ফারাক দেখাল — ঠিক উত্তোলনের দ্বিগুণ।
     * কারণ চিহ্নটা খাতের **নিজের** প্রকৃতি ধরে বসানো হয়েছিল, আর
     * উত্তোলন মূলধনের ঘরে বসা একটা **ডেবিট** প্রকৃতির খাত: ওটা মূলধন
     * কমায়। ফলে যোগ হচ্ছিল, বিয়োগের বদলে।
     *
     * ভুলটা নীরব নয় — দুই পক্ষ ঠিক দ্বিগুণ পরিমাণে আলাদা হয়। কিন্তু
     * পুরনো পর্দা সমতার কথা বলত না বলে কেউ কোনোদিন দেখত না।
     */
    public function test_a_drawing_lowers_the_equity(): void
    {
        $before = $this->sheet();

        $cash = Account::query()->money()->postable()->active()->firstOrFail();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'owner took cash'],
            [
                ['account_id' => $this->chartHead(StandardChart::DRAWINGS)->id,
                    'debit' => '3000', 'credit' => '0'],
                ['account_id' => $cash->id, 'debit' => '0', 'credit' => '3000'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $after = $this->sheet();

        $this->assertTrue($after['agrees'],
            'উত্তোলনের পর স্থিতিপত্র মিলছে না — পার্থক্য '.$after['difference']);

        $this->assertSame(0, bccomp(
            bcsub($after['totals']['equity'], $before['totals']['equity'], 4), '-3000', 4),
            'উত্তোলনে মূলধন কমেনি — সম্ভবত যোগ হয়েছে।');
    }

    /**
     * সঞ্চিত অবচয় সম্পদ কমায়।
     *
     * উত্তোলনের উল্টো ঘটনা: সম্পদের ঘরে বসা একটা **ক্রেডিট** প্রকৃতির
     * খাত। একই নিয়মে দুইটাই ঠিক হওয়ার কথা, তাই দুইটাই দেখা।
     */
    public function test_accumulated_depreciation_lowers_the_assets(): void
    {
        $before = $this->sheet();

        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'depreciation'],
            [
                ['account_id' => $this->chartHead(StandardChart::DEPRECIATION_EXPENSE)->id,
                    'debit' => '1000', 'credit' => '0'],
                ['account_id' => $this->chartHead(StandardChart::ACCUMULATED_DEPRECIATION)->id,
                    'debit' => '0', 'credit' => '1000'],
            ],
        );

        app(VoucherService::class)->post($voucher);

        $after = $this->sheet();

        $this->assertTrue($after['agrees'],
            'অবচয়ের পর স্থিতিপত্র মিলছে না — পার্থক্য '.$after['difference']);

        $this->assertSame(0, bccomp(
            bcsub($after['totals']['assets'], $before['totals']['assets'], 4), '-1000', 4),
            'সঞ্চিত অবচয় সম্পদ কমায়নি।');
    }
}
