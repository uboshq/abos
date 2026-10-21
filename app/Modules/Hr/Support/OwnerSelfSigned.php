<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Services\Ownership;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ⛔ মালিক নিজের চাওয়া কাজে নিজেই সই দিলেন — অডিটে আলাদা চিহ্ন `owner_self_signed` (fe-র উত্তর ১; মালিকের ব্যতিক্রম, a6, ২১ অক্টোবর
 * ২০২৬)।
 *
 * ⓘ বেতনের কাঠামো বদলের চিহ্নের অবিকল একই নিয়ম ([[SalaryStructureService]]), এখন তিন কাজে এক জায়গায় — বেতনের রান, কাঠামো আর
 * বেতনের ঠিকানা। চিহ্নটা বসে যখন "সই দিন" চাপা মানুষটাই অনুরোধকারী আর তিনি এই কোম্পানির মালিক ([[Ownership::isOwnerIn()]])।
 * ⓘ ইঞ্জিন কেবল সুপার অ্যাডমিনকে নিজের অনুরোধে সই দিতে দেয় ([[ApprovalEngine::canDecide()]]); SOX: শীর্ষ কর্তার নিজে-অনুমোদিত কাজ
 * আলাদা করে খোঁজা যায়।
 */
final class OwnerSelfSigned
{
    /** ⚠️ অডিটের `action` কলাম ২৪ অক্ষরের */
    public const ACTION = 'owner_self_signed';

    /**
     * @param  Model  $document  যে সারির অডিটে চিহ্নটা বসবে (`IsAudited` থাকা)
     * @return bool চিহ্ন বসল কি না
     */
    public static function mark(Approval $approval, Model $document, string $reason): bool
    {
        $requester = $approval->requested_by === null ? null : User::query()->find((int) $approval->requested_by);

        if ($requester === null || ! method_exists($document, 'auditAction')) {
            return false;
        }

        $selfSigned = $approval->decisions()
            ->where('decision', ApprovalDecision::APPROVED)
            ->where('user_id', $requester->id)
            ->exists();

        if (! $selfSigned || ! app(Ownership::class)->isOwnerIn($requester, (int) $approval->company_id)) {
            return false;
        }

        $document->auditAction(self::ACTION, $reason);

        return true;
    }
}
