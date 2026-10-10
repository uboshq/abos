<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncBatch;
use App\Core\Engines\Sync\SyncPosition;
use App\Core\Engines\Sync\SyncRegistry;
use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * ⛔ ফোনের সিঙ্কে একটা খারাপ সারি পুরো সারি আটকাত (পুরো-ERP অডিট, ফোন; fe, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ একটা বদল প্রতিবার অপ্রত্যাশিতভাবে ভাঙে (একটা বাগ)। আগে গোটা পুশ ৫০০ — একই ব্যাচের ভালো অর্ডারটাও বসত না, আর ফোন প্রতিবার
 * আবার পাঠাত, চিরকাল। এখন ভালোটা বসে আর তার উত্তর যায়; ভাঙা সারির **কোনো উত্তর** যায় না, সার্ভারে দাগও পড়ে না —
 * ফোনের চুক্তিতে সে কিউয়ে থেকে পরে আবার যায়। ⛔ "ERROR"-এর মতো নতুন অবস্থা নয়: আজকের ফোন সেটাকে "হয়ে গেছে" ধরে মুছত।
 */
final class OneBadChangeDoesNotHoldTheQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_that_breaks_gets_no_answer_and_the_rest_of_the_batch_lands(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($salesman);
        $shop = Customer::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        // ⓘ একটা হ্যান্ডলার যে প্রতিবার ভাঙে — বিক্রয় মডিউলেরই
        $registry = app(SyncRegistry::class);
        $handlers = $registry->all();
        $handlers['TestBoom'] = new class implements SyncsToDevices
        {
            public static function module(): string { return 'sales'; }

            public static function entityType(): string { return 'TestBoom'; }

            public static function requiredPermission(): ?string { return null; }

            public static function requiredPushPermission(): ?string { return null; }

            public function pull(User $user, ?Carbon $since, int $limit, ?SyncPosition $after = null): SyncBatch
            {
                throw new RuntimeException('not pulled in this test');
            }

            public function acceptsPush(): bool { return true; }

            public function apply(User $user, PushedChange $change): string
            {
                throw new RuntimeException('a bug that breaks this change every time');
            }
        };
        (new \ReflectionProperty($registry, 'handlers'))->setValue($registry, $handlers);
        app()->instance(SyncRegistry::class, $registry);
        app()->forgetInstance(SyncService::class);

        $before = SalesOrder::query()->count();
        $outcomes = app(SyncService::class)->push($salesman, 'phone-bad', 'sales', [
            ['changeId' => 'local-bad-1', 'entityType' => 'TestBoom', 'entityId' => null, 'operation' => 'CREATE',
                'payloadJson' => '{}', 'clientVersion' => 1],
            ['changeId' => 'local-good-1', 'entityType' => 'SalesOrder', 'entityId' => null, 'operation' => 'CREATE',
                'payloadJson' => json_encode(['customerId' => (string) $shop->public_id, 'trxDate' => now()->toDateString(),
                    'lines' => [['productId' => (string) $product->public_id, 'qty' => '2', 'rate' => '100']]]),
                'clientVersion' => 1],
        ]);

        $byId = collect($outcomes)->keyBy('changeId');
        $this->assertSame(SyncChange::APPLIED, $byId['local-good-1']['status'] ?? null, '⛔ ভাঙা সারির জন্য ভালো অর্ডারটাও বসল না।');
        $this->assertSame($before + 1, SalesOrder::query()->count());
        $this->assertFalse($byId->has('local-bad-1'), '⛔ ভাঙা সারির উত্তর গেল — আজকের ফোন অচেনা অবস্থাকে "হয়ে গেছে" ধরে মুছে ফেলে।');
        $this->assertSame(0, SyncChange::query()->where('change_id', 'local-bad-1')->count(),
            '⛔ ভাঙা সারির দাগ পড়ল — তাহলে পরের বার আবার চেষ্টাই হবে না।');
    }
}
