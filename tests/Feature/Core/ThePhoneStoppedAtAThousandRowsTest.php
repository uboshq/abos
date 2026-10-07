<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncState;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⛔ ফোনের সিঙ্ক প্রথম ১,০০০ সারির পরে আর এগোত না — Inventory অডিট গ১৮, ৪ অক্টোবর ২০২৬ ([[SyncService::pull()]],
 * [[SyncPosition]], [[SyncBatch]])।
 *
 * আগে প্রতি ডাকে জলচিহ্নের পরের একই প্রথম ১,০০০ সারি আর "আরও আছে" — বাকিগুলো কোনোদিন আসত না, আর `sync_states`-এ
 * কোনোদিন কিছু লেখা হত না। ⚠️ পরীক্ষার দোকানে ২,৩০০ বাড়তি গ্রাহক, **সবার `updated_at` একই সেকেন্ডে** (একসাথে আমদানি) —
 * কেবল সময় ধরে পাতা ভাগ করলে ঠিক এখানেই সারি হারাত বা একই পাতা চিরকাল ফিরত।
 *
 * দাবি:
 *   নতুন অ্যাপ (`cursor` ফেরত পাঠায়) — প্রতিটা গ্রাহক **ঠিক একবার**, দুই হ্যান্ডলারেই (গ্রাহক আর বকেয়া), তারপর "আর নেই";
 *   একই কার্সর দুবার — একই পাতা (হারানো উত্তরের পরে আবার চাওয়া নিরাপদ);
 *   পুরনো অ্যাপ (কার্সর চেনে না) — প্রতি টানায় এগোয়, কার্সর `sync_states`-এ লেখা হয়, আর "পুরোটা পেয়েছি"-তে মোছে।
 */
final class ThePhoneStoppedAtAThousandRowsTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRA = 2300;

    private const LIMIT = 1000;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['view_all_branches' => true])->save();
        $this->actingAs($this->owner);

        $at = now()->subDay()->startOfSecond()->format('Y-m-d H:i:s');
        foreach (array_chunk(range(1, self::EXTRA), 500) as $chunk) {
            DB::table('customers')->insert(array_map(fn (int $i) => [
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'code' => 'BULK-'.$i,
                'name_en' => 'Bulk shop '.$i,
                'public_id' => (string) Str::uuid(),
                'is_active' => true,
                'created_at' => $at,
                'updated_at' => $at,
            ], $chunk));
        }
    }

    public function test_the_new_app_gets_every_customer_exactly_once_across_pages(): void
    {
        $seen = [];
        $cursor = '';
        $pages = 0;

        do {
            $page = $this->sync()->pull($this->owner, 'phone-new', 'customer', self::LIMIT, $cursor, paged: true);
            foreach ($page['records'] as $record) {
                $seen[$record['entityType']][] = $record['entityId'];
            }
            $cursor = (string) $page['cursor'];
            $pages++;
        } while ($page['hasMore'] && $pages < 20);

        $this->assertFalse($page['hasMore'], '⛔ ২০ পাতাতেও শেষ হলো না — একই পাতা ফিরছে।');
        $this->assertNull($page['cursor'], '⛔ শেষ পাতার পরেও কার্সর।');

        $total = Customer::query()->inViewedBranch()->count();
        $this->assertGreaterThan(self::LIMIT * 2, $total, 'পরীক্ষার ভিত: ১,০০০-এর অনেক বেশি গ্রাহক চাই।');

        foreach ($seen as $type => $ids) {
            $this->assertCount($total, $ids, "⛔ {$type}: সব গ্রাহক আসেনি, বা কেউ দুবার এসেছে।");
            $this->assertCount($total, array_unique($ids), "⛔ {$type}: একই গ্রাহক দুবার এসেছে।");
        }
        $this->assertCount(2, $seen, 'গ্রাহক আর বকেয়া — দুই হ্যান্ডলারই পাতা ভাগ করে।');
    }

    public function test_the_same_cursor_twice_brings_the_same_page(): void
    {
        $first = $this->sync()->pull($this->owner, 'phone-new', 'customer', self::LIMIT, '', paged: true);
        $this->assertTrue($first['hasMore']);

        $a = $this->sync()->pull($this->owner, 'phone-new', 'customer', self::LIMIT, $first['cursor'], paged: true);
        $b = $this->sync()->pull($this->owner, 'phone-new', 'customer', self::LIMIT, $first['cursor'], paged: true);

        $this->assertSame(array_column($a['records'], 'entityId'), array_column($b['records'], 'entityId'));
        $this->assertNotSame(array_column($first['records'], 'entityId'), array_column($a['records'], 'entityId'),
            '⛔ দ্বিতীয় পাতাতেও প্রথম পাতার সারি — ঠিক আগের ভুলটা।');
    }

    public function test_the_old_app_moves_on_each_pull_and_the_cursor_is_kept_then_cleared(): void
    {
        $seen = [];
        $pulls = 0;

        do {
            $page = $this->sync()->pull($this->owner, 'phone-old', 'customer', self::LIMIT);
            foreach ($page['records'] as $record) {
                $seen[$record['entityType'].':'.$record['entityId']] = true;
            }
            $pulls++;

            if ($page['hasMore']) {
                $this->assertNotNull(SyncState::query()->where('device_id', 'phone-old')->value('page_cursor'),
                    '⛔ পুরনো অ্যাপের কার্সর sync_states-এ লেখা হয়নি।');
            }
        } while ($page['hasMore'] && $pulls < 20);

        $this->assertFalse($page['hasMore']);
        $this->assertCount(Customer::query()->inViewedBranch()->count() * 2, $seen, '⛔ পুরনো অ্যাপে সব গ্রাহক আসেনি।');

        $this->sync()->recordSuccessfulPull('phone-old', 'customer');
        $state = SyncState::query()->where('device_id', 'phone-old')->where('module', 'customer')->firstOrFail();
        $this->assertNotNull($state->last_synced_at, '⛔ জলচিহ্ন লেখা হয়নি।');
        $this->assertNull($state->page_cursor, '⛔ পালা শেষেও কার্সর রয়ে গেল।');
    }

    private function sync(): SyncService
    {
        return app(SyncService::class);
    }
}
