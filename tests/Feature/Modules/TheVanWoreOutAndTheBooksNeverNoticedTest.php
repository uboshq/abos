<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\FixedAsset;
use App\Modules\Accounts\Services\FixedAssetService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ভ্যানটা ক্ষয়ে গেল, খাতা টেরই পেল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * হিসাবের ছকে খাতগুলো আগে থেকেই ছিল — ১২০০ স্থায়ী সম্পদ, ১২৯০ সঞ্চিত
 * অবচয়, ৫২১২ অবচয় খরচ। কিন্তু কোনো খাতা ছিল না, তাই অবচয় বসতও না।
 *
 * অবচয় না বসলে দুইটা মিথ্যা একসাথে চলে: ভ্যানটা কেনার দিনের দামেই
 * খাতায় বসে থাকে, আর বছরের মুনাফা ঠিক ওই ক্ষয়ের পরিমাণ বেশি দেখায়।
 * দ্বিতীয়টা বেশি ক্ষতিকর — বেশি মুনাফা দেখে বেশি টাকা তোলা হয়, আর
 * ভ্যানটা বদলানোর দিন টাকাটা থাকে না।
 */
class TheVanWoreOutAndTheBooksNeverNoticedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FixedAssetService $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->assets = app(FixedAssetService::class);
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function money(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => '1101-VAN'],
            [
                'company_id' => $this->company->id,
                'parent_id' => StandardChart::find(StandardChart::CASH_IN_HAND)->id,
                'name_en' => 'Main till',
                'name_bn' => 'প্রধান ক্যাশ',
                'type' => Account::ASSET,
                'nature' => Account::DEBIT,
                'is_cash' => true,
            ],
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function van(array $extra = []): FixedAsset
    {
        return $this->assets->register([
            'name' => 'Delivery van',
            'asset_account_id' => $this->account('1202')->id,
            'accumulated_account_id' => $this->account(StandardChart::ACCUMULATED_DEPRECIATION)->id,
            'expense_account_id' => $this->account(StandardChart::DEPRECIATION_EXPENSE)->id,
            'cost' => '1200000',
            'salvage' => '0',
            'acquired_on' => '2026-08-01',
            'method' => FixedAsset::STRAIGHT_LINE,
            'life_months' => 120,
            ...$extra,
        ]);
    }

    /* ── অঙ্কটা ─────────────────────────────────────────────────── */

    public function test_an_even_month_is_cost_divided_by_life(): void
    {
        $van = $this->van();

        // ১২,০০,০০০ ÷ ১২০ মাস = ১০,০০০
        $this->assertSame('10000.0000', $this->assets->monthlyAmount($van));
    }

    /**
     * বাতিল মূল্যের উপর ক্ষয় বসে না।
     *
     * বসালে খাতায় জিনিসটার দাম শূন্য হয়ে যেত, অথচ ভাঙারির দোকানে
     * ওটার এখনো দাম আছে।
     */
    public function test_the_scrap_value_is_never_written_off(): void
    {
        $van = $this->van(['salvage' => '200000']);

        // (১২,০০,০০০ − ২,০০,০০০) ÷ ১২০ = ৮,৩৩৩.৩৩
        $this->assertSame('8333.3333', $this->assets->monthlyAmount($van));
    }

    public function test_a_reducing_asset_writes_off_less_each_month(): void
    {
        $van = $this->van([
            'method' => FixedAsset::REDUCING,
            'life_months' => null,
            'rate' => '20',
        ]);

        $first = $this->assets->monthlyAmount($van);
        $this->assets->depreciate($van, '2026-08-31');

        $second = $this->assets->monthlyAmount($van->refresh());

        $this->assertTrue(bccomp($second, $first, 4) < 0,
            'ক্রমহ্রাসমান পদ্ধতিতে দ্বিতীয় মাসের ক্ষয় প্রথম মাসের চেয়ে কম হওয়ার কথা।');
    }

    /* ── খতিয়ানে কী বসে ─────────────────────────────────────────── */

    public function test_depreciation_is_an_expense_and_never_touches_the_asset_account(): void
    {
        $van = $this->van();
        $this->assets->depreciate($van, '2026-08-31');

        $expense = LedgerEntry::query()
            ->where('account_id', $this->account(StandardChart::DEPRECIATION_EXPENSE)->id)->sum('debit');
        $this->assertSame('10000.0000', (string) $expense);

        $accumulated = LedgerEntry::query()
            ->where('account_id', $this->account(StandardChart::ACCUMULATED_DEPRECIATION)->id)->sum('credit');
        $this->assertSame('10000.0000', (string) $accumulated);

        /*
         * সম্পদের নিজের খাত ছোঁয়া হয় না — আর এটাই মূল কথা।
         *
         * সরাসরি কাটলে "গাড়িটা কত দিয়ে কেনা হয়েছিল" প্রশ্নের উত্তর
         * হারিয়ে যেত, অথচ বিমা, বিক্রি ও কর — তিন জায়গাতেই ওই
         * সংখ্যাটা লাগে।
         */
        $this->assertSame(0, LedgerEntry::query()
            ->where('account_id', $this->account('1202')->id)->count());
    }

    public function test_book_value_falls_as_the_months_pass(): void
    {
        $van = $this->van();

        $this->assets->depreciate($van, '2026-08-31');
        $this->assets->depreciate($van, '2026-09-30');

        $this->assertSame('20000.0000', $van->refresh()->accumulated());
        $this->assertSame('1180000.0000', $van->bookValue());
    }

    /* ── গুণ্ডা-পরীক্ষা ─────────────────────────────────────────── */

    /**
     * একই মাস দুইবার বসে না।
     *
     * এটাই সবচেয়ে দরকারি পাহারা। অবচয় চালানো হয় মাস শেষে, হাতে — আর
     * হাতে চালানো জিনিস একদিন দুইবার চলে। দুইবার বসলে খরচ দ্বিগুণ,
     * মুনাফা কম, আর সম্পদের দাম দ্রুত শূন্যের দিকে নামে। কেউ ধরতে
     * পারে না, কারণ প্রতিটা সারিই দেখতে বৈধ।
     */
    public function test_the_same_month_cannot_be_posted_twice(): void
    {
        $van = $this->van();
        $this->assets->depreciate($van, '2026-08-31');

        $this->expectException(QueryException::class);
        $this->assets->depreciate($van->refresh(), '2026-08-31');
    }

    /** মাসের যেকোনো দিন দিলেই একই মাস — তাই ওভাবেও দুইবার বসে না। */
    public function test_any_day_of_the_month_means_the_same_month(): void
    {
        $van = $this->van();
        $this->assets->depreciate($van, '2026-08-31');

        $this->expectException(QueryException::class);
        $this->assets->depreciate($van->refresh(), '2026-08-05');
    }

    /**
     * কেনার আগের মাসে ক্ষয় হয় না।
     *
     * না আটকালে কেউ পুরনো মাস ধরে চালালে ভ্যানটা কেনার আগেই ক্ষয়ে
     * যাওয়া শুরু করত — আর সংখ্যাটা দেখতে বৈধই লাগত।
     */
    public function test_nothing_wears_out_before_it_is_bought(): void
    {
        $van = $this->van();

        $this->expectException(ValidationException::class);
        $this->assets->depreciate($van, '2026-07-31');
    }

    /**
     * পুরো ক্ষয় হয়ে গেলে আর কিছু বসে না।
     *
     * শেষ মাসে পড়ে থাকা টুকরোটাই বসে, তার বেশি নয় — নাহলে সরলরৈখিকে
     * শেষে এক-দুই পয়সা বেশি বসে যেত, আর সম্পদের দাম বাতিল মূল্যের
     * নিচে নেমে যেত।
     */
    public function test_a_fully_worn_asset_stops(): void
    {
        $van = $this->van(['cost' => '30000', 'life_months' => 3]);

        $this->assets->depreciate($van, '2026-08-31');
        $this->assets->depreciate($van->refresh(), '2026-09-30');
        $this->assets->depreciate($van->refresh(), '2026-10-31');

        $this->assertSame('30000.0000', $van->refresh()->accumulated());
        $this->assertTrue($van->isFullyDepreciated());

        // চতুর্থ মাসে আর কিছু বসে না — নীরবে, ব্যতিক্রম ছাড়া
        $this->assertNull($this->assets->depreciate($van, '2026-11-30'));
        $this->assertSame('30000.0000', $van->refresh()->accumulated());
    }

    public function test_the_salvage_value_is_the_floor(): void
    {
        $van = $this->van(['cost' => '30000', 'salvage' => '6000', 'life_months' => 3]);

        foreach (['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30'] as $month) {
            $this->assets->depreciate($van->refresh(), $month);
        }

        $this->assertSame('6000.0000', $van->refresh()->bookValue(),
            'সম্পদের দাম বাতিল মূল্যের নিচে নেমে গেছে।');
    }

    /* ── মাস শেষের দৌড় ──────────────────────────────────────────── */

    /**
     * দৌড় দুইবার চালালে দ্বিতীয়বার কিছুই বসে না।
     *
     * মাস শেষে সবাই একসাথে কাজ করেন, আর কেউ না কেউ বোতামটা দুইবার
     * চাপেন। দ্বিতীয়বারে ব্যর্থ না হয়ে নীরবে বাদ দেওয়াই সঠিক —
     * নাহলে একটা সম্পদের সমস্যায় বাকি চল্লিশটা আটকে যেত।
     */
    public function test_running_the_month_twice_posts_nothing_the_second_time(): void
    {
        $this->van();
        $this->van(['name' => 'Freezer', 'cost' => '240000', 'life_months' => 60]);

        $first = $this->assets->runFor('2026-08-01');
        $this->assertSame(2, $first['posted']);

        $second = $this->assets->runFor('2026-08-01');
        $this->assertSame(0, $second['posted']);
        $this->assertSame(2, $second['skipped']);
    }

    /* ── বিদায় ──────────────────────────────────────────────────── */

    /**
     * বিক্রি হলে কেনার দাম আর ক্ষয় দুইটাই খাতা থেকে বেরিয়ে যায়।
     *
     * না বেরোলে ব্যালেন্স শিটে এমন একটা ভ্যান দেখাত যা ছয় মাস আগে
     * বিক্রি হয়ে গেছে।
     */
    public function test_selling_it_clears_both_the_cost_and_the_wear(): void
    {
        $van = $this->van();
        $this->assets->depreciate($van, '2026-08-31');

        $this->assets->dispose($van->refresh(), '1150000', $this->money()->id, '2026-09-15');

        $assetOut = LedgerEntry::query()
            ->where('account_id', $this->account('1202')->id)->sum('credit');
        $this->assertSame('1200000.0000', (string) $assetOut);

        $wearOut = LedgerEntry::query()
            ->where('account_id', $this->account(StandardChart::ACCUMULATED_DEPRECIATION)->id)->sum('debit');
        $this->assertSame('10000.0000', (string) $wearOut);

        $this->assertTrue($van->refresh()->isDisposed());
    }

    /** খাতার দামের চেয়ে কমে গেলে সেটা লোকসান, আর সেটাও খাতায় বসে। */
    public function test_selling_below_book_value_books_a_loss(): void
    {
        $van = $this->van();
        $this->assets->depreciate($van, '2026-08-31');

        // খাতায় দাম ১১,৯০,০০০; গেল ১১,০০,০০০-এ → ৯০,০০০ লোকসান
        $this->assets->dispose($van->refresh(), '1100000', $this->money()->id, '2026-09-15');

        $expense = $this->account(StandardChart::DEPRECIATION_EXPENSE)->id;

        $debits = LedgerEntry::query()->where('account_id', $expense)->sum('debit');

        // ১০,০০০ অবচয় + ৯০,০০০ লোকসান
        $this->assertSame('100000.0000', (string) $debits);
    }

    public function test_a_disposed_asset_cannot_be_depreciated_again(): void
    {
        $van = $this->van();
        $this->assets->dispose($van, '1200000', $this->money()->id, '2026-09-15');

        $this->expectException(ValidationException::class);
        $this->assets->depreciate($van->refresh(), '2026-09-30');
    }

    /* ── নিবন্ধনের পাহারা ───────────────────────────────────────── */

    public function test_an_even_wearing_asset_needs_a_life(): void
    {
        $this->expectException(ValidationException::class);
        $this->van(['life_months' => null]);
    }

    public function test_scrap_value_cannot_beat_the_price_paid(): void
    {
        $this->expectException(ValidationException::class);
        $this->van(['cost' => '100000', 'salvage' => '200000']);
    }
}
