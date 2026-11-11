<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\NotificationService;
use App\Core\Services\SmsOutboxService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ অফিসের SMS ফোনের পাহারা — নিয়ে চুপ থাকা SMS আবার সারিতে, আর ২ ঘণ্টার বেশি অপেক্ষার খবর মালিককে
 * (নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২: *"ফোন বন্ধ থাকলে SMS সারিতে অপেক্ষা করে … ২ ঘণ্টার বেশি হলে মালিককে খবর"*)।
 *
 * ⓘ খবর যায় যাঁর হাতে SMS-এর ফোনের চাবি (`notification.sms_gateway`) — আজ কেবল মালিক। একই SMS-এর খবর একবারই
 *   ([[SmsOutboxService::markOverdue()]] দাগ দেয়); একই ঘণ্টায় কয়েকটা আটকালে একটাই চিঠি, সংখ্যাসহ।
 */
final class SmsWatch extends Command
{
    protected $signature = 'abos:sms-watch';

    protected $description = 'Return SMS a phone took but never answered to the queue, and tell the owner when SMS wait over two hours';

    public function handle(SmsOutboxService $outbox, NotificationService $notify): int
    {
        $told = 0;

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($outbox, $notify, $companyId, &$told): void {
                $outbox->release();
                $overdue = $outbox->markOverdue();

                if ($overdue === 0) {
                    return;
                }

                $keepers = User::query()->whereHas('companies', fn ($q) => $q->whereKey($companyId))->get()
                    ->filter(fn (User $u): bool => $u->can('notification.sms_gateway'));

                $notify->sendMany(
                    $keepers,
                    'sms.overdue',
                    __('notification::sms.overdue_title', ['n' => $overdue]),
                    null,
                    Route::has('notification.sms_phones.index') ? route('notification.sms_phones.index') : null,
                    key: 'sms:overdue:'.$companyId.':'.now()->format('YmdH'),
                );
                $told += $keepers->count();
            });
        }

        $this->info("told={$told}");

        return self::SUCCESS;
    }
}
