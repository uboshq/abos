<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Services\FcmSender;
use App\Models\SyncDevice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * একজন মানুষের সব ফোনে একটা পুশ — কিউ থেকে, তাই FCM ধীর বা বন্ধ হলে ওয়েবের কাজ আটকায় না
 * (সমন্বয়কের শর্ত ৩)। ⓘ চেষ্টা সীমিত: ৩ বার, ১ ও ৫ মিনিট পরে; তারপর ছেড়ে দেওয়া — বার্তাটা ঘণ্টা দেরিতে
 * আসার চেয়ে না আসাই ভালো, আর ইন-অ্যাপ বার্তা তো আছেই।
 *
 * ⛔ সমন্বয়কের শর্ত ২: লক-স্ক্রিনে সবাই দেখে — শিরোনামে কেবল কাগজের নম্বর আর ধাপ; টাকা, ফোন নম্বর নয়।
 * সেটা ডাকনেওয়ালার দায় ([[TrackingNotices]]), এখানে যা আসে তাই যায়।
 */
class SendPushToUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param  array<string, string>  $data */
    public function __construct(
        public readonly int $userId,
        public readonly string $title,
        public readonly array $data = [],
    ) {}

    public function handle(FcmSender $fcm): void
    {
        $devices = SyncDevice::query()->withoutGlobalScopes()
            ->where('user_id', $this->userId)
            ->whereNotNull('push_token')
            ->get();

        $retry = false;

        foreach ($devices as $device) {
            $result = $fcm->send((string) $device->push_token, $this->title, null, $this->data);

            if ($result === FcmSender::OFF) {
                return;
            }

            if ($result === FcmSender::GONE) {
                // ⓘ ফোন থেকে অ্যাপ মোছা বা টোকেন বদলানো — আর পাঠানো হবে না
                $device->forceFill(['push_token' => null, 'push_token_at' => null])->save();
            }

            $retry = $retry || $result === FcmSender::FAILED;
        }

        if ($retry && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
