<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\PartyBooked;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\AutoDues;

/** ⭐ গ্রাহকের খাতা বদলালে তাঁর বিলের অটো বাকি আবার গোনা ([[AutoDues]]) */
final class SpreadTheBalanceOverTheBills
{
    public function __construct(private readonly AutoDues $dues) {}

    public function handle(PartyBooked $event): void
    {
        if ($event->payload['party_type'] !== Customer::drillSourceType()) {
            return;
        }

        // ⛔ বিলের বাকি গোনা ব্যর্থ হলে কাগজ আটকায় না — খাতা ঠিক আছে, রাতের কাজ আবার গোনে; ভুলটা লেখা থাকে
        try {
            $this->dues->refresh($event->companyId, (int) $event->payload['party_id']);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
