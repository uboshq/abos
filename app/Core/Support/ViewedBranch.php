<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Services\DataScope;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * ⭐ হেডারে বাছা শাখা — **দেখানোর** পর্দাগুলোর জন্য এক জায়গায় (৩০ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ কেন দরকার হলো ─────────────────────────────────────────────────
 * মালিক হেডারে একটা শাখা বেছে হিসাবের ছক, স্থিতিপত্র আর গ্রাহকের বকেয়া দেখেন।
 * ২৯ সেপ্টেম্বরে ([[DataScope::viewBranchIds()]]) তালিকা আর রিপোর্ট বাছা শাখা
 * মানতে শিখেছিল; কিন্তু খাতা-পড়া ২১টা পর্দা নিজের মতো কোয়েরি লেখে, আর ওরা
 * তখনও গোটা কোম্পানির জের দেখাত। লাইভে ADI-র তিন শাখা, তাই "ময়মনসিংহ" বেছে
 * তিনি নেত্রকোনার টাকাও দেখতেন।
 *
 * ── নিয়ম (২৯ সেপ্টেম্বরের নকশা, বদলায়নি) ──────────────────────────────
 *   এক শাখা বাছা → কেবল সেই শাখার সারি, **শাখাহীন সারিও নয়**
 *   "সব শাখা"    → নাগালের শাখা + শাখাহীন (সীমাহীন হলে কোনো ছাঁকনিই নয়)
 * ⇒ প্রতিটা শাখার অঙ্ক + শাখাহীন অঙ্ক = "সব শাখা"-র অঙ্ক।
 *
 * ── ⛔ কেবল **দেখানোয়** ───────────────────────────────────────────────
 * যাচাইয়ের পথ — বাকির সীমা, টিলের জের, খাতার সিল, পোস্টিং — কখনো এটা ডাকে না;
 * সেখানে গোটা কোম্পানিই সত্যি ([[EveryLedgerReaderSaysWhetherItShowsOrChecksTest]])।
 *
 * ⓘ [[DataScope]]-এর ওপর পাতলা মোড়ক — ছাঁকনি নিজেই [[DataScope::inView()]] ডাকে,
 * তাই নিয়মটা একটাই জায়গায় থাকে। এখানে যোগ হয় কেবল একটা-শাখার প্রশ্ন ([[one()]])
 * আর নাম ধরে দেওয়া মানুষ (ফোন)।
 */
final class ViewedBranch
{
    /**
     * বাছা একটা শাখা, নইলে `null` ("সব শাখা")।
     *
     * ⓘ [[Account::balanceOn()]] আর [[LedgerBalances]] একটাই শাখা নেয় — ওদের জন্য এটা।
     * ⚠️ "সব শাখা"-তে ওরা গোটা কোম্পানি পড়ে, নাগালে ছাঁকে না; নাগালে-আটকানো কর্মীর
     * খাতের জের তাই [[narrow()]]-এর চেয়ে চওড়া হতে পারে — জানা সীমা, খাতের পর্দা
     * এমনিতেই কেবল হিসাবরক্ষক আর মালিকের।
     */
    public static function one(?User $user = null): ?int
    {
        $user ??= auth()->user();
        $scope = app(DataScope::class);

        return $scope->viewsOneBranch($user) ? ($scope->viewBranchIds($user)[0] ?? null) : null;
    }

    /**
     * কোয়েরিটা দেখার শাখায় ছাঁকা — উপরের নিয়মে।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function narrow(Builder $query, string $column, ?User $user = null): Builder
    {
        $scope = app(DataScope::class);

        // ⭐ চলতি মানুষের বেলায় নিয়মটা একটাই জায়গায় — [[DataScope::inView()]]
        if ($user === null) {
            return $scope->inView($query, $column);
        }

        // ⓘ নাম ধরে ডাকা মানুষ (ফোনের ড্যাশবোর্ড) — একই নিয়ম, আলাদা মানুষের জন্য
        $ids = $scope->viewBranchIds($user);

        if ($ids === null) {
            return $query;
        }

        $one = $scope->viewsOneBranch($user);

        return $query->where(fn ($w) => $one
            ? $w->whereIn($column, $ids)
            : $w->whereIn($column, $ids)->orWhereNull($column));
    }
}
