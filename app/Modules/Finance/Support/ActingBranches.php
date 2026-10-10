<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * ⭐ যে শাখাগুলোতে মানুষটা **কাজ করতে পারেন** — নাগাল, হেডারে বাছা শাখা নয় (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন দরকার হলো ─────────────────────────────────────────────────
 * মাস শেষের সুদ আর অগ্রিম বীমা আগাম-দেখায় হেডারের শাখা নিত ([[ViewedBranch]]), অথচ আগের মাসের উল্টো দাখিলা
 * গোটা কোম্পানির জমা উল্টাত — এক শাখা বাছা থাকলে নতুন জমা বসত কেবল সেই শাখায়, উল্টো হত সবার। বাকি শাখার
 * সুদ সে মাসে খরচ থেকে উধাও।
 *
 * ── নিয়ম ([[DataScope::allows()]]-এর) ─────────────────────────────────
 *   সীমা নেই (মালিক, সীমাহীন কর্মী, কনসোল) → গোটা কোম্পানি
 *   শাখার সীমা বসানো                         → নাগালের শাখা + শাখাহীন সারি
 * ⓘ হেডার বদলালে ফল বদলায় না — চালানো আর আগাম দেখা একই সারি ধরে।
 *
 * ⛔ কেবল কাজের পথে (মাস বসানো, কাগজ খোলা); তালিকা আর রিপোর্ট আগের মতো [[ViewedBranch]] মানে।
 */
final class ActingBranches
{
    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function narrow(Builder $query, string $column): Builder
    {
        $ids = self::ids();

        if ($ids === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w->whereIn($column, $ids)->orWhereNull($column));
    }

    /** এই শাখার কাগজে মানুষটা হাত দিতে পারেন কি — শাখাহীন কাগজ সবসময় হ্যাঁ */
    public static function allows(int|string|null $branchId): bool
    {
        $user = auth()->user();

        return app(DataScope::class)->allows(
            $user instanceof User ? $user : null,
            UserDataScope::BRANCH,
            $branchId === null ? null : (int) $branchId,
        );
    }

    /** @return list<int>|null `null` মানে সীমা নেই */
    private static function ids(): ?array
    {
        $user = auth()->user();

        return $user instanceof User ? app(DataScope::class)->idsFor($user, UserDataScope::BRANCH) : null;
    }
}
