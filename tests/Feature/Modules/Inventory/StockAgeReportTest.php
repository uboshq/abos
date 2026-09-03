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
 * স্টকের বয়স — কোন বয়স-ভাগে কত টাকা আটকে (FIFO স্তর ধরে)।
 *
 * সবচেয়ে জরুরি পরীক্ষা: একটা বাকেটের অঙ্ক (আটকে থাকা টাকা) তার নিজের
 * তালিকার যোগফলের সমান — সংখ্যা আর তালিকা এক agingScope থেকে, তাই
 * "৯০+ দিনে ৳X" ক্লিক করলে ঠিক ওই স্তরগুলোই দেখা যায়, যোগফলও মেলে।
 */
class StockAgeReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0:int,1:?int}> */
    private array $buckets = [[0, 30], [30, 60], [60, 90], [90, null]];

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
     * ★ ড্রিল-ডাউন অখণ্ডতা: প্রতিটা বাকেটের টাকা == তার তালিকার স্তরগুলোর যোগফল।
     */
    public function test_each_bucket_value_matches_its_own_layer_list(): void
    {
        $facts = $this->facts();

        foreach ($this->buckets as [$min, $max]) {
            $bucketValue = $facts->agingValue($min, $max);

            $listSum = '0';
            foreach ($facts->agingLayers($min, $max, 1000) as $layer) {
                $listSum = bcadd($listSum, (string) $layer->value_stuck, 4);
            }

            $this->assertSame(
                0,
                bccomp($bucketValue, $listSum, 2),
                "aging bucket [{$min},".($max ?? '∞').') টাকা ≠ তালিকার যোগফল',
            );
        }
    }

    public function test_report_opens_for_a_user_who_may_see_reports(): void
    {
        $this->actingAs($this->user)
            ->get(route('inventory.stock.age', ['bucket' => '90+']))
            ->assertOk();
    }

    public function test_report_is_closed_without_report_permission(): void
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
            ->get(route('inventory.stock.age'))
            ->assertForbidden();
    }
}
