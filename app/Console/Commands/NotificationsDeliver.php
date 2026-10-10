<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Notifications\DeliveryService;
use Illuminate\Console\Command;

/**
 * ⭐ বিজ্ঞপ্তির আবার-চেষ্টা — সময় হওয়া সারি কিউয়ে, আটকে থাকা সারি ছাড়ানো, হারানো সারি আবার (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⓘ প্রতি মিনিটে, ক্রনের `schedule:run` থেকে ([[routes/console.php]])। পাঠানোটা নিজে কিউয়ের কাজ ([[DeliverNotification]]);
 * এটা কেবল ঠিক করে কোনটা এখন আবার পাঠানোর সময়। বারবার চালানো নিরাপদ — একটা সারি একবারই দখল হয়।
 */
final class NotificationsDeliver extends Command
{
    protected $signature = 'abos:notifications-deliver';

    protected $description = 'Re-queue notification deliveries whose retry time has come, and free stuck ones';

    public function handle(DeliveryService $delivery): int
    {
        $done = $delivery->retryDue();

        $this->info("retried={$done['retried']} unstuck={$done['unstuck']} requeued={$done['requeued']}");

        return self::SUCCESS;
    }
}
