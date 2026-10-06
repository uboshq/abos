<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Events\ApprovalDecided;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;

/**
 * ⭐ ফেরত — শেষ সইয়ে পাকা, প্রত্যাখ্যানে বাতিল (মালিকের বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬: "ফেরতের আদেশ → মাল গ্রহণ
 * → ক্রেডিট নোট")।
 *
 * ⓘ একই কাগজের তিন অবস্থা: খসড়া → সইয়ের অপেক্ষা → নিশ্চিত (তখনই মাল সেই লটে ফেরে আর গ্রাহকের পাওনা কমে)।
 * ⛔ আগে শেষ সই পড়ার পরেও ফেরত খসড়াই থাকত — কাউকে আবার "নিশ্চিত" চাপতে হত, আর না চাপলে মাল তাকে ফেরা অবস্থায়
 * খাতার বাইরে পড়ে থাকত। ⓘ সইয়ের ছক কোম্পানির নিজের (ছক বন্ধ থাকলে — যেমন UB — এই শ্রোতা কখনো ডাক পায় না)।
 * ⓘ [[FinishTheCancellationOnTheLastSignature]]-এর একই ছাঁচ।
 */
final class FinishTheReturnOnTheLastSignature
{
    public const ACTION = 'return';

    public function __construct(private readonly SalesReturnService $returns) {}

    public function handle(ApprovalDecided $event): void
    {
        $approval = Approval::query()->find((int) ($event->payload['approval_id'] ?? 0));

        if ($approval === null || $approval->approvable_type !== SalesReturn::class || $approval->action !== self::ACTION) {
            return;
        }

        $return = SalesReturn::query()->find((int) $approval->approvable_id);

        // ⓘ ইতিমধ্যে নিশ্চিত বা বাতিল — দ্বিতীয় ডাক কিছুই করে না
        if ($return === null || $return->status !== DocumentStatus::DRAFT) {
            return;
        }

        $status = $event->payload['status'] ?? null;

        if ($status === Approval::REJECTED) {
            $this->returns->cancel($return, __('sales::message.return_rejected'));

            return;
        }

        if ($status === Approval::APPROVED) {
            $this->returns->confirm($return);
        }
    }
}
