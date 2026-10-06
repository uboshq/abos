<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Engines\Sync\SyncRegistry;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⛔ ফোন থেকে যা লেখা যায়, তার নিজের লেখার চাবি আছে — আর সেটা আসল, দেখার চাবি নয়।
 *
 * পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⛔১: অফলাইন আদেশের পুশ পাহারা পেত কেবল পড়ার চাবি `sales.order.view`-এ।
 * এখন চুক্তিতে [[SyncsToDevices::requiredPushPermission()]]; এই পাহারা দেখে নতুন কোনো লেখার হ্যান্ডলার
 * চাবি null রেখে, দেখার চাবি বসিয়ে, বা এমন নাম লিখে আসে না যা অনুমতির তালিকায় নেই।
 */
class EverySyncWriteHasItsOwnKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_handler_that_takes_a_push_names_a_real_write_key(): void
    {
        $this->seed(DemoSeeder::class);
        $known = Permission::query()->pluck('name')->all();
        $handlers = app(SyncRegistry::class)->all();
        $this->assertNotEmpty($handlers, 'প্রস্তুতিটাই ভুল — কোনো সিঙ্ক হ্যান্ডলার পাওয়া গেল না।');

        $bad = [];
        $writers = 0;

        foreach ($handlers as $handler) {
            $type = $handler::entityType();
            $key = $handler::requiredPushPermission();

            if (! $handler->acceptsPush()) {
                if ($key !== null) {
                    $bad[] = "{$type}: ফোন থেকে আসে না, অথচ লেখার চাবি {$key} — ভুল বোঝায়";
                }

                continue;
            }

            $writers++;

            if ($key === null) {
                $bad[] = "{$type}: ফোন থেকে লেখা যায়, লেখার চাবি নেই";
            } elseif (str_ends_with($key, '.view')) {
                $bad[] = "{$type}: লেখার চাবি {$key} — দেখার চাবিতে লেখা";
            } elseif (! in_array($key, $known, true)) {
                $bad[] = "{$type}: লেখার চাবি {$key} অনুমতির তালিকায় নেই — কেউ কোনোদিন পাবেন না";
            }
        }

        $this->assertGreaterThan(0, $writers, 'প্রস্তুতিটাই ভুল — ফোন থেকে লেখা যায় এমন কোনো ধরন নেই।');
        $this->assertSame([], $bad, implode("\n", $bad));
    }
}
