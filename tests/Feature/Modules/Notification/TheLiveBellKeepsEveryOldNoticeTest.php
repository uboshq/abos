<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⭐ লাইভের ৩০৬টা পুরনো খবর মেশানোর পরেও ঘণ্টায় যেমন ছিল তেমন (fe-র চাওয়া, cloud/notification-management মেশানোর আগে,
 * ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ লাইভের `notifications`-এ এখন ৩০৬ সারি, ১২৬ না-পড়া — ধরন: sales.delivery_stage ২৬৯, approval.approved ৩৫,
 * sales.signed_sale_stuck ২। মাইগ্রেশন কেবল শূন্য-যোগ্য বা আগে-থেকে-মান-বসানো ঘর যোগ করে, তাই ALTER-এর পরে পুরনো সারি
 * দেখতে ঠিক এমন: event_id/branch_id/module/archived_at শূন্য, category 'task', priority 'normal'। তারপর মাইগ্রেশনের
 * এককালীন শ্রেণিবিন্যাস চলে ([[classifyOldRows]])। এখানে সেই অবস্থাটা বসিয়ে শ্রেণিবিন্যাসটাই চালানো হয়।
 *
 * ⛔ দাবি: সারি গোনা আর প্রত্যেকের পড়া/না-পড়া ঠিক আগের মতো; ঘণ্টার না-পড়া সংখ্যা আর তালিকা একই; কিছুই আর্কাইভে যায়নি;
 * আর (event_id, user_id) অনন্য-চাবি একই মানুষের অনেক পুরনো সারিতে (event_id শূন্য) আটকায় না।
 */
final class TheLiveBellKeepsEveryOldNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_shape_of_old_notices_keeps_its_count_and_read_state_in_the_bell(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $people = User::query()->whereIn('email', ['owner@abos.test', 'sales@abos.test', 'accounts@abos.test'])->get()->values();
        $this->assertCount(3, $people);
        Notification::query()->withoutGlobalScopes()->delete();

        // ⓘ লাইভের মিশ্রণ — ধরন ধরে সংখ্যা, প্রতি তিনটার একটা করে মানুষ ঘুরে, প্রথম ১২৬টা না-পড়া
        $kinds = array_merge(array_fill(0, 269, 'sales.delivery_stage'), array_fill(0, 35, 'approval.approved'), array_fill(0, 2, 'sales.signed_sale_stuck'));
        $rows = [];
        foreach ($kinds as $i => $type) {
            $rows[] = [
                'public_id' => (string) Str::uuid(), 'company_id' => $company->id, 'user_id' => $people[$i % 3]->id,
                'type' => $type, 'title' => 'old '.$i, 'body' => null, 'url' => '/x/'.$i,
                // ⓘ ALTER-এর পরের চেহারা: নতুন ঘর শূন্য বা নিজের ডিফল্টে
                'event_id' => null, 'branch_id' => null, 'module' => null, 'category' => 'task', 'priority' => 'normal',
                'read_at' => $i < 126 ? null : now()->subDays(3), 'seen_at' => null, 'archived_at' => null,
                'created_at' => now()->subDays(20)->addMinutes($i), 'updated_at' => now()->subDays(20)->addMinutes($i),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('notifications')->insert($chunk); // ⓘ event_id শূন্য — একই মানুষের ১০২টা সারি, অনন্য-চাবি আটকায় না
        }

        $before = $this->state($people);

        $migration = require database_path('migrations/2027_02_20_100000_the_bell_kept_no_record_of_what_it_said.php');
        (new \ReflectionMethod($migration, 'classifyOldRows'))->invoke($migration);

        $this->assertSame(306, Notification::query()->withoutGlobalScopes()->count(), '⛔ মাইগ্রেশনের পরে সারি কমল বা বাড়ল।');
        $this->assertSame(126, Notification::query()->withoutGlobalScopes()->whereNull('read_at')->count(), '⛔ না-পড়া বদলে গেল।');
        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->whereNotNull('archived_at')->count(), '⛔ পুরনো খবর আর্কাইভে চলে গেল।');
        $this->assertSame($before, $this->state($people), '⛔ কারো ঘণ্টা বদলে গেল।');

        // ⓘ শ্রেণি ধরন ধরে বসল — ঘণ্টার গোনায় তার প্রভাব নেই, কেবল সাজানো আর রঙে
        $this->assertSame(0, Notification::query()->withoutGlobalScopes()->whereNull('module')->count());
        $this->assertSame(['update', 'low'], $this->classOf('sales.delivery_stage'));
        $this->assertSame(['approval', 'low'], $this->classOf('approval.approved'));
        $this->assertSame(['task', 'high'], $this->classOf('sales.signed_sale_stuck'));
    }

    /**
     * প্রত্যেকের ঘণ্টা — মোট দেখা, না-পড়া সংখ্যা, আর না-পড়া তালিকার আইডি।
     *
     * @param  \Illuminate\Support\Collection<int, User>  $people
     * @return array<int, array{int, int, list<int>}>
     */
    private function state($people): array
    {
        $bell = app(NotificationService::class);

        return $people->mapWithKeys(fn (User $u) => [$u->id => [
            $bell->mine($u)->inbox()->count(),
            $bell->unreadCount($u),
            $bell->unreadFor($u, 500)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]])->all();
    }

    /** @return array{string, string} */
    private function classOf(string $type): array
    {
        $row = Notification::query()->withoutGlobalScopes()->where('type', $type)->firstOrFail();

        return [(string) $row->category, (string) $row->priority];
    }
}
