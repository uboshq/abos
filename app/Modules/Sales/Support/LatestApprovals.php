<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Model;

/**
 * ⓘ পাতার অপেক্ষমাণ সারিগুলোর শেষ অনুমোদন — এক ডাকে, [[ApprovalEngine::latestFor()]]-এর একই প্রশ্ন (ধরন, কাগজ, কাজ,
 * সবচেয়ে নতুন)। ফোনের আদেশ আর DO-র তালিকা দুটোই এখান দিয়ে; আগে প্রতি সারিতে একটা করে ডাক যেত (পুরো ERP অডিট,
 * ৬ অক্টোবর ২০২৬, ফোন ⚠️১৭)।
 */
final class LatestApprovals
{
    /**
     * @param  iterable<Model>  $rows
     * @return array<int, Approval> কাগজের id ধরে; না থাকা মানে অনুমোদন নেই
     */
    public static function of(iterable $rows, string $class, string $status, string $action): array
    {
        $ids = collect($rows)->filter(fn (Model $r) => $r->status === $status)->map(fn (Model $r) => (int) $r->getKey())->values()->all();
        if ($ids === []) {
            return [];
        }

        $latest = [];
        foreach (Approval::query()->where('approvable_type', $class)->whereIn('approvable_id', $ids)
            ->where('action', $action)->orderByDesc('id')->get() as $a) {
            $latest[(int) $a->approvable_id] ??= $a;
        }

        return $latest;
    }
}
