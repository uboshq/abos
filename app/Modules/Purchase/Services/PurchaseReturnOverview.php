<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Overview\ConfirmOverview;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Purchase\Models\PurchaseReturn;

/**
 * ⭐ ক্রয় ফেরতের "নিশ্চিত করুন"-এর আগের সারাংশ (মালিক, ৪ অক্টোবর ২০২৬: *"sob kichutei"*; [[ConfirmOverview]])।
 *
 * ⓘ ফেরতটা আগেই খসড়া — সারাংশ তার নিজের সারি আর মোট পড়ে: কোন বিলের, কোন মাল কত, সরবরাহকারীর দেনা কত কমবে।
 * ⓘ "নিশ্চিত হবে না" দরজার নিজের পাহারা থেকে ([[PurchaseReturnService::whatWouldStopTheConfirm()]]): বিলের বেশি ফেরত নয়,
 * আর গুদামে মাল থাকতে হবে। সই — [[ApprovalEngine::requires()]], `purchase.return` (দরজা `assertClear()`-এ যা দেখে)।
 * ⛔ কেবল দেখায়: আসল পাহারা নিশ্চিতের দরজাতেই।
 */
final class PurchaseReturnOverview
{
    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function build(PurchaseReturn $return): ConfirmOverview
    {
        $return->loadMissing(['supplier', 'warehouse', 'bill', 'reasonCode', 'lines.product']);

        $o = ConfirmOverview::titled(__('purchase::overview_confirm.return_title', ['no' => $return->document_no]))
            ->head(__('purchase::field.supplier'), $return->supplier?->name())
            ->head(__('purchase::field.date'), DateFormat::format($return->trx_date))
            ->head(__('purchase::overview_confirm.return_of_bill'), $return->bill?->document_no)
            ->head(__('purchase::field.warehouse'), $return->warehouse?->name())
            ->head(__('purchase::overview_confirm.return_reason'), $return->reasonCode?->name());

        foreach ($return->lines as $line) {
            $tax = (string) ($line->tax ?? '0');
            $o->line((string) $line->product?->name(), [
                Money::quantity((string) $line->qty).' × '.Money::format((string) $line->rate),
                bccomp($tax, '0', 4) > 0 ? __('purchase::overview_confirm.vat', ['amount' => Money::format($tax)]) : null,
            ], Money::format((string) $line->amount));
        }

        $total = (string) $return->total;
        $o->total(__('purchase::overview_confirm.return_total'), Money::format($total), strong: true);
        $o->money(__('purchase::overview_confirm.return_lowers_debt'), Money::format($total), 'good');

        foreach ($this->returns->whatWouldStopTheConfirm($return) as $message) {
            $o->note($message, 'stop');
        }

        if ($this->approvals->requires('purchase', 'return', $total, class_basename(PurchaseReturn::class))) {
            $o->note(__('purchase::overview_confirm.return_signature'), 'warn');
        }

        return $o;
    }
}
