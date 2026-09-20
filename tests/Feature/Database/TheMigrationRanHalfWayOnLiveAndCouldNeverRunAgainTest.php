<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * মাইগ্রেশনটা লাইভে অর্ধেক বসল, আর তারপর আর কোনোদিন চলত না।
 *
 * ── ⛔ কী ঘটেছিল, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * `…uniqueness_that_three_screens_believed_in…` লাইভে ছুঁড়ল
 * `number_series`-এর NULL `branch_id`-তে sentinel ০ বসাতে গিয়ে — ওই
 * কলামে `branches(id)`-এর foreign key আছে, আর শাখা ০ বলে কিছু নেই।
 *
 * ⚠️ কিন্তু তার **আগের ধাপে বারকোডের সূচকটা বসে গিয়েছিল**। ⓘ মাইগ্রেশন
 * ব্যর্থ হলে Laravel `migrations` টেবিলে সারিটা লেখে না, তাই লাইভে
 * সূচক ছিল আর `migrate:status` বলত Pending — পরের রানে "Duplicate key
 * name", অর্থাৎ **চিরকালের জন্য আটকে**।
 *
 * ── ⚠️ এই পরীক্ষাটা কী মাপে ──────────────────────────────────────────
 * ⓘ শুধু "মাইগ্রেশন চলে" নয় — সেটা তো প্রতিটা RefreshDatabase-ই দেখে।
 * এটা **লাইভের অবস্থাটা বানায়**: সূচক আছে, সারি `migrations`-এ নেই।
 * তারপর `up()` দ্বিতীয়বার ডাকে।
 */
final class TheMigrationRanHalfWayOnLiveAndCouldNeverRunAgainTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/migrations/'
        .'2026_11_27_100000_uniqueness_that_three_screens_believed_in_and_nobody_enforced.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * ⭐ দ্বিতীয়বার ডাকলেও থামে না — প্রতিটা ধাপ আগে দেখে নেয়।
     */
    public function test_running_it_a_second_time_does_not_throw(): void
    {
        $this->assertTrue(
            $this->indexIsThere('inv_products', 'inv_products_company_barcode_unique'),
            'প্রথম রানেই বারকোডের সূচকটা বসেনি — পরীক্ষাটা ভুল জিনিস মাপছে।',
        );

        // ⓘ লাইভে ঠিক এই অবস্থাটাই ছিল: সূচক বসা, অথচ মাইগ্রেশন Pending
        $this->migration()->up();

        $this->assertTrue(
            $this->indexIsThere('inv_products', 'inv_products_company_barcode_unique'),
            'দ্বিতীয় রানে সূচকটা হারিয়ে গেছে।',
        );
    }

    /**
     * ⛔ শাখার আইডিতে ০ বসানো হয় না — foreign key ওটা মানবে না।
     *
     * ⚠️ এই দাবিটাই লাইভের ব্যর্থতাটা ধরত। ⓘ সারিগুলোর `branch_id`
     * NULL-ই থাকে, একটাও বদলায় না।
     */
    public function test_no_row_is_pushed_onto_a_branch_that_does_not_exist(): void
    {
        $this->assertSame(
            0,
            DB::table('number_series')->where('branch_id', 0)->count(),
            'কোনো সারিতে শাখা ০ বসানো হয়েছে — অথচ ওই আইডির কোনো শাখা নেই।',
        );

        $this->assertSame(
            0,
            DB::table('number_series')->where('financial_year_id', 0)->count(),
            'কোনো সারিতে বছর ০ বসানো হয়েছে।',
        );
    }

    /**
     * ⭐ আর সূচকটা এখন সত্যিই কামড়ায় — NULL থাকা সত্ত্বেও।
     *
     * ⚠️ পাহারাটা না থাকলে এই পরীক্ষাটা সবুজ থাকত: MySQL-এ unique
     * index-এ NULL একাধিকবার বসে, তাই একই `doc_type`-এ দুইটা কাউন্টার
     * পাশাপাশি বসত আর দুইজনে দুই রকম নম্বর কাটত।
     */
    public function test_two_counters_can_no_longer_share_one_scope(): void
    {
        $row = [
            'company_id' => CompanyContext::id(),
            'branch_id' => null,
            'financial_year_id' => null,
            'module' => 'test',
            'doc_type' => 'ZZ',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('number_series')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('number_series')->insert($row);
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function indexIsThere(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
}
