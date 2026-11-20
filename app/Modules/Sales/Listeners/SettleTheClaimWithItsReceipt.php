<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Support\Actor;
use App\Modules\Accounts\Events\VoucherCancelled;
use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Services\DepositClaimService;

/**
 * ⭐ জমার খবরের রসিদ ভাউচার (RCV-…) — মালিক, ২০ নভেম্বর ২০২৬: "টাকা ঢোকার কাগজ একটাই"।
 *
 *   · রসিদ পাকা হলো — শেষ সইয়ে নিজে বা হিসাবে অন্য হাতে — খবর "খাতায়" ([[DepositClaimService::settleWithVoucher()]])।
 *   · অপেক্ষার রসিদ হিসাব থেকে বাতিল হলো — খোলা খবর আবার রসিদহীন ([[DepositClaimService::releaseCancelledVoucher()]]),
 *     যাতে "অনুমোদন দিন" মরা খসড়ায় আটকে না থাকে।
 *
 * ⓘ হাতের "অনুমোদন দিন"-এ রসিদ সাথে সাথে পাকা হলে খবর আগেই গৃহীত — এখানে তখন কিছুই নয়। ⓘ কেবল রসিদ; অন্য ধরনের ভাউচারে খবর থাকে না।
 */
final class SettleTheClaimWithItsReceipt
{
    public function __construct(private readonly DepositClaimService $claims) {}

    public function handle(VoucherPosted|VoucherCancelled $event): void
    {
        if (($event->payload['type'] ?? null) !== Voucher::RECEIPT) {
            return;
        }

        if ($event instanceof VoucherCancelled) {
            $id = (int) ($event->payload['voucher_id'] ?? 0);

            if ($id > 0) {
                $this->claims->releaseCancelledVoucher($id);
            }

            return;
        }

        // ⓘ ঘোষণা কেবল public_id বয়ে আনে — পাকা হওয়ার লেনদেনের পরে, তাই সারিটা এখন পড়া যায়
        $id = Voucher::query()->withoutGlobalScopes()->where('company_id', $event->companyId)
            ->where('public_id', (string) $event->publicId)->value('id');

        if ($id !== null) {
            $this->claims->settleWithVoucher((int) $id, Actor::userId());
        }
    }
}
