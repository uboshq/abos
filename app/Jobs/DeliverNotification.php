<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Notifications\DeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ⭐ একটা খবর একটা মাধ্যমে একবার চেষ্টা — database কিউয়ে, ক্রনের `queue:work` চালায় (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⓘ Laravel-এর নিজের আবার-চেষ্টা বন্ধ (`$tries = 1`): আবার চেষ্টা, backoff আর ব্যর্থ-তালিকা [[DeliveryService]] নিজে
 * রাখে নিজের টেবিলে — তাই পর্দায় দেখা যায় আর হাতে আবার চেষ্টা করা যায়। কেবল আইডি যায়, খবরের লেখা নয়।
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $jobId) {}

    public function handle(DeliveryService $delivery): void
    {
        $delivery->attempt($this->jobId);
    }
}
