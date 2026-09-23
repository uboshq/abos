<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\NoticeStatus;
use App\Models\Notice;
use App\Models\NoticeAck;
use App\Models\NoticeRead;
use Illuminate\Support\Facades\DB;

/**
 * নোটিশের সংখ্যাগুলো — ড্যাশবোর্ড ও রিপোর্ট, দুইটাই এখান থেকে।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৫, ২৮ ও ২৯ ────────────
 * KPI কার্ড, পড়ার হার, সইয়ের হার, আর শাখাভিত্তিক হিসাব।
 *
 * ── ⚠️ কেন গোনাগুলো এক জায়গায় ───────────────────────────────────────
 * ⓘ একই সংখ্যা ড্যাশবোর্ডে, রিপোর্টে আর API-তে লাগে। ⛔ তিন জায়গায়
 * তিনবার লিখলে একদিন একটায় `RECALLED` বাদ পড়ত, অন্যটায় নয় — আর তখন
 * দুইটা পর্দা দুইটা আলাদা সত্য দেখাত।
 *
 * ⚠️ আর কোনটা ঠিক তা বলার কোনো উপায় থাকত না।
 */
final class NoticeAnalytics
{
    /**
     * ⭐ অবস্থা ধরে গোনা — ড্যাশবোর্ডের কার্ডগুলো।
     *
     * ⓘ যে অবস্থায় একটাও নোটিশ নেই, সেটাও শূন্য হয়ে ফেরে। ⚠️ না
     * ফিরলে কার্ডটাই উধাও হত, আর *"খসড়া কয়টা"* প্রশ্নের উত্তর হত
     * একটা ফাঁকা জায়গা — যা শূন্যের চেয়ে খারাপ।
     *
     * @return array<string, int>
     */
    public function byStatus(): array
    {
        $rows = Notice::query()
            ->select('status', DB::raw('COUNT(*) as many'))
            ->groupBy('status')
            ->pluck('many', 'status')
            ->all();

        $out = [];

        foreach (NoticeStatus::cases() as $status) {
            $out[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * ⭐ একটা নোটিশ কতদূর পৌঁছাল।
     *
     * ── ⚠️ শতাংশের হরটা কেন সই-চাওয়া মানুষের সংখ্যা নয় ──────────────
     * ⓘ *"কতজনের কাছে পৌঁছানোর কথা"* বের করতে হলে প্রতিটা ব্যবহারকারীর
     * চাবি বানাতে হয় ([[NoticeAudience]]), আর সেটা হাজার জনের কোম্পানিতে
     * হাজারটা প্রশ্ন।
     *
     * ⭐ তাই হরটা এখানে **যতজন ছুঁয়েছেন** — পড়েছেন বা সই দিয়েছেন।
     * ⓘ সংখ্যাটা রক্ষণশীল, আর কখনো ১০০%-এর বেশি দেখায় না। ⛔ উল্টোটা,
     * অর্থাৎ ভুয়া *"সবাই মেনেছেন"*, বিপজ্জনক দিকে ভুল।
     *
     * @return array{read: int, signed: int, rate: int}
     */
    public function reachOf(Notice $notice): array
    {
        $read = NoticeRead::query()->where('notice_id', $notice->id)->count();
        $signed = NoticeAck::query()->where('notice_id', $notice->id)->count();

        $touched = max($read, $signed);

        return [
            'read' => $read,
            'signed' => $signed,
            'rate' => $touched === 0 ? 0 : (int) round($signed * 100 / $touched),
        ];
    }

    /**
     * ⭐ যে নোটিশগুলো সই চায় আর এখনো পুরো সই পায়নি।
     *
     * ⓘ ড্যাশবোর্ডের *"Pending Acknowledgement"* কার্ডটা এটাই।
     *
     * @return \Illuminate\Support\Collection<int, Notice>
     */
    public function waitingOnSignatures()
    {
        return Notice::query()
            ->where('status', NoticeStatus::PUBLISHED->value)
            ->where('ack_required', true)
            ->withCount(['signatures', 'reads'])
            ->orderByDesc('published_at')
            ->get()
            ->filter(fn (Notice $n) => $n->signatures_count < $n->reads_count)
            ->values();
    }
}
