<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লটের তালিকা গুদাম ধরে ছাঁকা যায়, যদিও লটের গুদাম নেই।
 *
 * ── ⛔ লাইভে যা ভেঙেছিল, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────
 *     Unknown column 'inv_batches.warehouse_id' in 'WHERE'
 *
 * `/sales/lots/trace` দুই কোম্পানিতেই ৫০০। ⓘ [[Batch]]-এ
 * [[ScopedToUserWarehouse]] বসানো আছে, আর সে ডিফল্টে `warehouse_id`
 * ঘরটা খোঁজে — কিন্তু `inv_batches`-এ ঘরটা নেই।
 *
 * ── ⚠️ আর এটাই এই ফাইলের আসল কথা ───────────────────────────────────
 * ছাঁকনিটা **কেবল তখনই** কাজে নামে যখন কারও গুদামের সীমা বসানো আছে।
 * ⛔ লাইভে কারও ছিল না, তাই ভুলটা মাসখানেক চুপচাপ বসে ছিল আর পাতাটা
 * সবুজ দেখিয়েছে — প্রথম সীমা বসানোর দিনেই সে ভেঙেছে।
 *
 * ⓘ অর্থাৎ নিরাপত্তার ঘরটা কোনোদিন খাটেইনি। ⭐ নিচের প্রথম দাবিটা তাই
 * ছাঁকনিটা **চালু অবস্থায়** মাপে — সীমা না বসিয়ে মাপলে এই পাহারাটাও
 * ঠিক একইভাবে চিরকাল সবুজ থাকত।
 */
final class TheLotHadNoWarehouseColumnToFilterOnTest extends TestCase
{
    use RefreshDatabase;

    private User $keeper;

    private Warehouse $mine;

    private Batch $placed;

    private Batch $fresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->mine = Warehouse::query()->orderBy('id')->firstOrFail();

        $this->keeper = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ⭐ সীমাটা সত্যিই বসানো হয় — এটাই দাবিগুলোকে দাবি বানায়।
         *
         * ⚠️ এটা ছাড়া `idsFor()` null ফেরায়, গ্লোবাল স্কোপটা কিছুই করে
         * না, আর নিচের সব দাবি সবুজ থাকত — ঠিক যেভাবে লাইভে ভুলটা
         * মাসখানেক লুকিয়ে ছিল।
         */
        UserDataScope::query()->create([
            'user_id' => $this->keeper->id,
            'scope_type' => UserDataScope::WAREHOUSE,
            'scope_id' => $this->mine->id,
        ]);

        app(DataScope::class)->forget();

        $this->actingAs($this->keeper);

        /*
         * ⛔ লট দুইটা পরীক্ষা নিজেই বানায়, আর এই লাইনগুলোই ফাইলটাকে
         * অর্থবহ করে।
         *
         * ⚠️ প্রথম চালে ডেমোর উপর ভরসা করা হয়েছিল, আর "সত্যিই তাকায়"
         * দাবিটা লাল হয়ে ধরিয়ে দিল: **ডেমোতে একটাও লট নেই**। ⓘ ঐ
         * দাবিটা না থাকলে গোটা ফাইলটা সবুজ দেখাত আর কিছুই মাপত না।
         */
        $this->placed = $this->lot('PLACED');
        $this->placed->movements()->create([
            'company_id' => $this->placed->company_id,
            'product_id' => $this->placed->product_id,
            'warehouse_id' => $this->mine->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => '5',
            'source_type' => 'test',
            'source_id' => 1,
        ]);

        $this->fresh = $this->lot('FRESH');
    }

    /** এখনো কোনো চলাচল হয়নি এমন একটা লট। */
    private function lot(string $prefix): Batch
    {
        return Batch::query()->create([
            'company_id' => CompanyContext::id(),
            'product_id' => Product::query()->orderBy('id')->firstOrFail()->id,
            'batch_no' => $prefix.'-'.uniqid(),
        ]);
    }

    /** ⛔ আগে এই কোয়েরিটাই SQL ত্রুটি ছুঁড়ত। */
    public function test_a_scoped_user_can_list_lots_at_all(): void
    {
        $this->assertIsInt(Batch::query()->count(),
            '⛔ গুদামের সীমা বসানো থাকলে লটের তালিকা কোয়েরিই ভেঙে যায়।');
    }

    /** ⭐ আর পাতাটা সত্যিই খোলে — লাইভে ঠিক এটাই ৫০০ দিত। */
    public function test_the_lot_trace_page_opens(): void
    {
        $this->get(route('sales.lot.trace'))->assertOk();
    }

    /**
     * ⭐ চলাচলহীন লট হারায় না।
     *
     * ⛔ কেবল `whereHas('movements')` লিখলে সদ্য খোলা লট — মাল এসেছে,
     * লট খোলা হয়েছে, এখনো কোনো চলাচল হয়নি — তালিকা থেকে উধাও হত।
     * ⚠️ যিনি লটটা খুললেন তিনিই সেটা দেখতে পেতেন না, আর কোথাও কিছু
     * লাল হত না।
     */
    public function test_a_lot_with_no_movement_yet_is_still_visible(): void
    {
        $this->assertTrue(
            Batch::query()->whereKey($this->fresh->id)->exists(),
            implode("\n", [
                '⛔ এখনো চলাচল হয়নি এমন লট তালিকা থেকে উধাও।',
                '',
                '⚠️ মাল এসেছে, লট খোলা হয়েছে — আর যিনি খুললেন তিনিই সেটা',
                'দেখতে পাচ্ছেন না। ⓘ গুদামহীন সারি ছাঁকনিতে পড়ে না, এটাই',
                'ট্রেইটের নিজের নিয়ম।',
            ]),
        );
    }

    /**
     * ⭐ আর অন্য গুদামের লট দেখা যায় না — দেয়ালটা সত্যিই দেয়াল।
     *
     * ⛔ উপরের দাবিগুলো কেবল বলে ছাঁকনিটা **ভাঙে না**। ⚠️ সেটা সারানোর
     * সবচেয়ে সহজ উপায় হত ছাঁকনিটা তুলে দেওয়া, আর তিনটা দাবিই সবুজ
     * থাকত। ⓘ এই দাবিটা সেই পথটা বন্ধ করে।
     */
    public function test_a_lot_in_someone_elses_warehouse_stays_hidden(): void
    {
        /*
         * ⚠️ দ্বিতীয় গুদামটা পরীক্ষা নিজেই বানায়, `markTestSkipped` নয়।
         *
         * ⛔ প্রথম চালে এড়িয়ে যাওয়া হয়েছিল, আর ডেমোতে গুদাম একটাই —
         * অর্থাৎ এই ফাইলের একমাত্র দাবি যেটা ছাঁকনিটাকে **কাজ করতে**
         * বাধ্য করে, সেটাই কোনোদিন চলত না। ⓘ এড়িয়ে যাওয়া দাবি আর
         * সবুজ দাবি রিপোর্টে প্রায় একরকম দেখায়, আর সেখানেই ফাঁদ।
         */
        $theirs = Warehouse::withoutGlobalScope('user-warehouse')
            ->whereKeyNot($this->mine->id)
            ->first()
            ?? Warehouse::query()->create([
                'company_id' => CompanyContext::id(),
                'code' => 'WH-FAR',
                'name_en' => 'Far Warehouse',
                'name_bn' => 'দূরের গুদাম',
            ]);

        $far = $this->lot('FAR');
        $far->movements()->create([
            'company_id' => $far->company_id,
            'product_id' => $far->product_id,
            'warehouse_id' => $theirs->id,
            'trx_date' => now()->toDateString(),
            'floor_change' => '5',
            'source_type' => 'test',
            'source_id' => 1,
        ]);

        $this->assertFalse(Batch::query()->whereKey($far->id)->exists(), implode("\n", [
            '⛔ অন্য গুদামের লটও দেখা যাচ্ছে।',
            '',
            '⚠️ তাহলে গুদামের দেয়ালটা কেবল দেখতে আছে — ঠিক যেমন এতদিন',
            '`warehouse_id` খুঁজতে গিয়ে সে কারোই কিছু ছাঁকেনি।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি সীমাটা বসেই না থাকত — তখন গ্লোবাল
     * স্কোপটা কিছুই করত না। ⚠️ তাই মাপা হয় সে সত্যিই কাজে নেমেছে:
     * সীমাহীন গোনার চেয়ে ছাঁকা গোনা বেশি হতে পারে না, আর অন্তত একটা
     * লট সত্যিই আছে।
     */
    public function test_the_scope_is_really_switched_on(): void
    {
        $all = Batch::acrossWarehouses()->count();

        $this->assertGreaterThan(0, $all, 'ডেমোতে কোনো লটই নেই — দাবিগুলো তখন কিছুই প্রমাণ করে না।');

        $this->assertNotNull(app(DataScope::class)->idsFor($this->keeper, UserDataScope::WAREHOUSE),
            'গুদামের সীমাটাই বসেনি — ছাঁকনিটা তখন ঘুমিয়ে থাকে, আর ভুল থাকলেও ধরা পড়ে না।');

        $this->assertLessThanOrEqual($all, Batch::query()->count(),
            'ছাঁকা তালিকা সীমাহীন তালিকার চেয়ে বড় — ছাঁকনিটা উল্টো দিকে কাজ করছে।');
    }
}
