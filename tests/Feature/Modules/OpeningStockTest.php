<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\CostLayerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * শুরুর দিন তাকে যা ছিল — গুদামে, স্তরে আর খাতায়, তিন জায়গাতেই।
 *
 * ── কেন এই ফাইলটা আছে ───────────────────────────────────────────────
 * খোলা মজুদ এতদিন কেবল দুই জায়গায় বসত: গুদামে (পরিমাণ) আর স্তরে (দাম)।
 * খতিয়ানে কিছুই যেত না। ফল: ডিপোর তাকে ৮,৪০,০০০ টাকার মাল, অথচ ব্যালেন্স
 * শিটে মজুদ শূন্য — সম্পদটা ছিল, খাতায় ছিল না।
 *
 * কেউ ধরেনি, কারণ দুইটা সংখ্যা কখনো পাশাপাশি রাখা হত না। FIFO বসানোর পর
 * স্তরের মূল্য আর খতিয়ানের মজুদ মিলিয়ে দেখতেই ফাঁকটা বেরিয়ে এল।
 *
 * তাই এখানকার পরীক্ষাগুলো একটা সংখ্যার শুদ্ধতা নিয়ে নয় — **তিন জায়গার
 * মিল** নিয়ে। ওটাই একমাত্র প্রশ্ন যেটা এই বাগটা ধরতে পারত।
 */
class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /**
     * খতিয়ানের মজুদ আর তাকের মালের মূল্য — এক পয়সাও আলাদা নয়।
     *
     * এটাই আসল পাহারা। যেদিন কোনো পথ স্তর বানিয়ে খতিয়ান ভুলে যাবে,
     * বা উল্টোটা, সেদিন এই সংখ্যাটাই সরে যাবে।
     */
    public function test_the_ledger_and_the_shelf_agree_after_seeding(): void
    {
        $costs = app(CostLayerService::class);

        $onShelf = Product::query()->get()->reduce(
            fn (string $sum, Product $p) => bcadd($sum, $costs->valueOnHand($p), 4),
            '0',
        );

        $this->assertSame(0, bccomp($onShelf, $this->balanceOf(StandardChart::INVENTORY), 4),
            'খতিয়ানের মজুদ আর স্তরে পড়ে থাকা মালের মূল্য আলাদা।');

        // আর অঙ্কটা শূন্য নয় — নইলে "দুইটাই শূন্য" বলেও পাশ করা যেত
        $this->assertGreaterThan(0, (float) $onShelf);
    }

    /**
     * খোলা মজুদ যায় অবশিষ্ট মুনাফায়, আয়ে নয়।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা করা হয় ────────────────────────────
     * শুরুর দিনের মাল এই বছরের কোনো ঘটনা নয় — আগের ব্যবসার ফল, নতুন
     * খাতায় তোলা হচ্ছে মাত্র। ঘাটতি-উদ্বৃত্ত বা বিক্রয় খাতে ফেললে প্রথম
     * মাসেই আট লাখ টাকা "আয়" দেখাত, অথচ কেউ কিছু বেচেনি — আর সেই ভুল
     * মুনাফার উপর কর বসত।
     */
    public function test_opening_stock_lands_in_equity_not_in_income(): void
    {
        $inventory = $this->balanceOf(StandardChart::INVENTORY);

        // মজুদ যত ডেবিট, অবশিষ্ট মুনাফা ঠিক তত ক্রেডিট (তাই ঋণাত্মক)
        $this->assertSame(0, bccomp(
            bcmul($inventory, '-1', 4),
            $this->balanceOf(StandardChart::RETAINED_EARNINGS),
            4,
        ));

        // আয় বা ঘাটতির খাত ছোঁয়া হয়নি
        $this->assertSame(0, bccomp('0', $this->balanceOf(StandardChart::SALES), 4));
        $this->assertSame(0, bccomp('0', $this->balanceOf(StandardChart::INVENTORY_SHORTAGE_SURPLUS), 4));
    }

    /**
     * একই পণ্য দুই গুদামে থাকলে দুইটা দাখিলাই বসে।
     *
     * ── কেন এই পাহারাটা ─────────────────────────────────────────────
     * প্রথমে দাখিলার উৎস হিসেবে পণ্যের id দেওয়া হয়েছিল, আর পোস্টিং
     * ইঞ্জিন একই উৎসে দুইবার বসতে দেয় না (ঠিকই করে — নইলে একই কাগজ
     * দুইবার পোস্ট করলে খাতা দ্বিগুণ হত)। ফল: নেত্রকোনার ৪০ বস্তা চাল,
     * ১,৩৬,০০০ টাকা, নীরবে বাদ পড়ে গিয়েছিল।
     *
     * এখন উৎস চলাচলের id — প্রতিটা গুদামের প্রতিটা ঢোকা আলাদা ঘটনা।
     */
    public function test_the_same_product_in_two_warehouses_posts_twice(): void
    {
        $rice = Product::query()->orderBy('id')->firstOrFail();

        $entries = LedgerEntry::query()
            ->where('source_type', 'opening_stock')
            ->whereIn('account_id', [Account::query()->where('code', StandardChart::INVENTORY)->value('id')])
            ->get();

        // ছয়টা পণ্য, তার একটা দুই গুদামে — মোট সাতটা ঢোকা
        $this->assertSame(7, $entries->count(),
            'প্রতিটা গুদামের প্রতিটা ঢোকার জন্য একটা করে দাখিলা থাকার কথা।');

        // আর চালের মালটা দুইটা গুদামেই আছে
        $riceQty = (string) (DB::table('inv_stock_movements')
            ->where('product_id', $rice->id)
            ->where('source_type', 'opening')
            ->count());

        $this->assertSame('2', $riceQty);
    }

    /** খোলা দাখিলাগুলোও ভারসাম্যে — ডেবিট ও ক্রেডিট সমান। */
    public function test_the_opening_entries_balance(): void
    {
        $rows = LedgerEntry::query()->where('source_type', 'opening_stock')->get();

        $debit = $rows->reduce(fn (string $s, LedgerEntry $e) => bcadd($s, (string) $e->debit, 4), '0');
        $credit = $rows->reduce(fn (string $s, LedgerEntry $e) => bcadd($s, (string) $e->credit, 4), '0');

        $this->assertSame(0, bccomp($debit, $credit, 4));
    }

    /** শূন্য মূল্যের মাল কোনো দাখিলা বসায় না — শূন্য সারি শুধু ভিড় বাড়ায়। */
    public function test_stock_worth_nothing_writes_no_entry(): void
    {
        $before = LedgerEntry::query()->count();

        app(OpeningBalanceService::class)->forInventory(
            sourceId: 999999,
            documentNo: 'OPENING-ZERO',
            amount: '0',
        );

        $this->assertSame($before, LedgerEntry::query()->count());
    }

    private function balanceOf(string $code): string
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        return LedgerEntry::query()
            ->where('account_id', $account->id)
            ->get()
            ->reduce(
                fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
                '0.0000',
            );
    }
}
