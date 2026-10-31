<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderStatus;
use Illuminate\Validation\ValidationException;

/**
 * ⓘ সীমায় আটকে থাকা আদেশ, যেমন ফাঁক ২৮-এর আগের জমা লিখত (fe-র বিক্রয়ের ফাঁক ২৮, ২০ অক্টোবর ২০২৬)।
 *
 * ⛔ এখন সীমা পার করা আদেশের জমাই ফেরে ([[AnOrderPastTheCreditLimitIsRefusedAtSubmitTest]]), তাই জমা দিয়ে `credit_held`
 * বানানো যায় না। ⓘ অথচ আগের আটকে থাকা আদেশ খাতায় আছে, আর তাদের পাতা, তালিকা, রিপোর্ট, ট্র্যাকিং আর আবার-যাচাই
 * ([[SalesOrderService::recheckCredit()]]) অটুট — সেই পরীক্ষাগুলো সারিটা এখানে হুবহু আগের জমার মতো বসায়।
 */
trait HoldsAnOrderAsBeforeGap28
{
    /** খসড়া আদেশকে আগের জমার মতো সীমায় আটকে বসানো — চাওয়া পরিমাণ, জমার মুহূর্ত, হোল্ডের ধারা, কত কম */
    private function heldAsBeforeGap28(SalesOrder $draft, ?string $short = null): SalesOrder
    {
        foreach ($draft->lines()->get() as $line) {
            if ($line->requested_qty === null) {
                $line->forceFill(['requested_qty' => $line->ordered_qty])->save();
            }
        }

        $draft->forceFill([
            'status' => SalesOrderStatus::CREDIT_HELD,
            'submitted_at' => now(),
            'hold_mode' => SalesOrderStatus::HOLD_HOLDS,
            'credit_short' => $short ?? $draft->total,
            'credit_held_at' => now(),
            'credit_checked_at' => now(),
        ])->save();

        return $draft->fresh(['lines']);
    }

    /** জমা ফিরল কি না — গ্রাহকের ঘরে কারণসহ; আদেশ খসড়াই থাকে */
    private function assertRefusedAtSubmit(callable $submit, SalesOrder $draft): void
    {
        try {
            $submit();
            $this->fail('⛔ সীমা পার করা আদেশ জমা হলো (ফাঁক ২৮)।');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['customer_id'][0] ?? null, '⛔ জমা ফিরল, কিন্তু সীমার কারণে নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(SalesOrderStatus::DRAFT, $draft->fresh()->status, '⛔ ফেরানো আদেশ খসড়া নয়।');
    }
}
