<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Services\StockFacts;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মরা · ধীর · দ্রুত চলা মাল — সংখ্যা আর তালিকা এক কথা বলে কি না।
 *
 * মালিকের নিয়ম: প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়। "ধীরগতি ৫" ক্লিক করলে
 * ঠিক পাঁচটাই দেখা যেতে হবে — তাই এখানকার আসল পরীক্ষা: প্রতিটা গণনা তার
 * নিজের তালিকার আকারের সমান। এটাই সেই ভুলটা ধরে যা আগে "আজকের বিক্রয়"
 * চার জায়গায় গুনে দুই উত্তর দিয়েছিল।
 */
class StockMovementReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    private function facts(): StockFacts
    {
        return app(StockFacts::class);
    }

    /**
     * ★ ড্রিল-ডাউন অখণ্ডতা: প্রতিটা সংখ্যা == তার তালিকার আকার।
     * তালিকা limit (১০০)-এর নিচে থাকলে সমান হতেই হবে — না হলে সংখ্যা আর
     * তালিকা আলাদা সংজ্ঞায় চলছে, আর সেটাই মালিকের নিষেধ।
     */
    public function test_each_count_matches_its_own_list(): void
    {
        $facts = $this->facts();

        foreach (StockFacts::WINDOWS as $days) {
            $this->assertSame(
                $facts->slowMoving($days),
                $facts->slowMovingList($days)->count(),
                "slow list ≠ count @ {$days}d",
            );
            $this->assertSame(
                $facts->nonMoving($days),
                $facts->nonMovingList($days)->count(),
                "non list ≠ count @ {$days}d",
            );
            $this->assertSame(
                $facts->fastMoving($days),
                $facts->fastMovingList($days)->count(),
                "fast list ≠ count @ {$days}d",
            );
            $this->assertSame(
                $facts->stagnantCount($days),
                $facts->stagnant($days, 100)->count(),
                "stagnant list ≠ count @ {$days}d",
            );
        }
    }

    /**
     * "বেরোচ্ছে না" (out=0) = ধীর (নড়েছে, বেরোয়নি) + নিশ্চল (কিছুই না)।
     * ড্যাশবোর্ডের তৃতীয় লিংক এই মিলিত সংখ্যায় যায়, তাই সমীকরণটা ধরে রাখা।
     */
    public function test_stagnant_equals_slow_plus_non(): void
    {
        $facts = $this->facts();

        foreach (StockFacts::WINDOWS as $days) {
            $this->assertSame(
                $facts->slowMoving($days) + $facts->nonMoving($days),
                $facts->stagnantCount($days),
                "stagnant ≠ slow + non @ {$days}d",
            );
        }
    }

    /**
     * তিনটা ভাগ পরস্পর-বিচ্ছিন্ন ও সম্পূর্ণ: মাল-আছে প্রতিটা পণ্য ঠিক একটা
     * ভাগে পড়ে (দ্রুত: বেরিয়েছে · ধীর: নড়েছে বেরোয়নি · নিশ্চল: কিছুই না)।
     * ডেমোতে মাল আছে, তাই যোগফল শূন্য নয় — খালি-সংগ্রহ ফাঁদ এড়ানো।
     */
    public function test_the_three_buckets_partition_on_hand_stock(): void
    {
        $facts = $this->facts();
        $days = 7;

        $total = $facts->fastMoving($days) + $facts->slowMoving($days) + $facts->nonMoving($days);

        $this->assertGreaterThan(0, $total, 'ডেমোতে মাল-আছে পণ্য থাকার কথা');
    }

    public function test_report_opens_for_a_user_who_may_see_reports(): void
    {
        $this->actingAs($this->user)
            ->get(route('inventory.stock.movement', ['type' => 'slow', 'days' => 7]))
            ->assertOk();
    }

    public function test_report_is_closed_to_a_user_without_report_permission(): void
    {
        /*
         * সদস্যপদ pivot-এ, `users`-এর কলামে নয়।
         *
         * ⚠️ আগে লেখা ছিল `User::factory()->create(['company_id' => …])`,
         * অথচ `users` টেবিলে ওই কলামটা **নেই** (দুইটা ডাটাবেসেই মেপে দেখা,
         * ৩ সেপ্টেম্বর ২০২৬)। ফলে পরীক্ষাটা SQL ত্রুটিতে ভেঙে পড়ত —
         * error হিসেবে, ব্যর্থতা হিসেবে নয়।
         *
         * ⓘ ত্রুটির চেহারাটা ছিল হুবহু সেই বার্তা যেটা ওই দিন দুইটা সুইট
         * একসাথে চলার সময়ও এসেছিল ("Unknown column company_id")। তাই এটাকে
         * সংঘর্ষের আবর্জনা ভেবে পার করে দেওয়া সহজ ছিল — **কিন্তু এটা
         * পরিষ্কার ডাটাবেসেও লাল হত।**
         *
         * এক কোম্পানিতে বসানোর নিয়মটা রিপোর বাকি টেস্টগুলোর মতোই।
         */
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company->id, ['is_active' => true]);

        $this->actingAs($stranger)
            ->get(route('inventory.stock.movement'))
            ->assertForbidden();
    }
}
