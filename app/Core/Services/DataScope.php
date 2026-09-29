<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Models\UserDataScope;

/**
 * এই ব্যবহারকারী কোন সারিগুলো দেখতে পাবেন।
 *
 * ── কেন উত্তরটা `null` হতে পারে ──────────────────────────────────────
 * `null` মানে "কোনো সীমা নেই" — সব দেখা যায়। খালি অ্যারে মানে হত
 * "কিছুই দেখা যায় না", আর দুইটা খুব আলাদা জিনিস। একটা সংখ্যায় গুলিয়ে
 * ফেললে এই ফিচারটা চালুর দিনেই সবাই অন্ধ হয়ে যেতেন।
 *
 * ── ক্যাশ কেন অনুরোধ-জীবনকালের ───────────────────────────────────────
 * একটা পাতায় বিশটা মডেল কোয়েরি হতে পারে, আর প্রতিটাতে স্কোপ জিজ্ঞেস
 * করলে বিশটা একই কোয়েরি যেত। আবার দীর্ঘ ক্যাশ রাখা যায় না: কারো
 * অনুমতি কেড়ে নেওয়ার পরেও সে পুরনো ক্যাশ ধরে দেখতে থাকত।
 *
 * অনুরোধের মধ্যে ক্যাশ, অনুরোধের শেষে বিদায় — দুইটা সমস্যারই উত্তর।
 */
final class DataScope
{
    /** @var array<string, ?list<int>> */
    private array $cache = [];

    /**
     * এই ব্যবহারকারীর দেখার অনুমতিতে থাকা আইডিগুলো।
     *
     * @return list<int>|null null মানে সীমা নেই
     */
    public function idsFor(User|int|null $user, string $type): ?array
    {
        $userId = $user instanceof User ? $user->id : $user;
        $companyId = CompanyContext::id();

        if ($userId === null || $companyId === null) {
            return null;
        }

        $key = $companyId.':'.$userId.':'.$type;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $ids = UserDataScope::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('scope_type', $type)
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->cache[$key] = $ids === [] ? null : $ids;
    }

    /** এই ব্যবহারকারীর জন্য কোনো সীমা বসানো আছে কি না। */
    public function isLimited(User|int|null $user, string $type): bool
    {
        return $this->idsFor($user, $type) !== null;
    }

    /**
     * একটা নির্দিষ্ট শাখা বা গুদাম এই ব্যবহারকারীর নাগালে কি না।
     *
     * `null` শাখা সবসময় নাগালে: অনেক কাগজে শাখা লেখা থাকে না (প্রধান
     * অফিসের জাবেদা, কোম্পানি-স্তরের সেটিংস)। ওগুলো আটকালে সীমাবদ্ধ
     * ব্যবহারকারী নিজের কাজের অর্ধেকই দেখতে পেতেন না।
     */
    public function allows(User|int|null $user, string $type, ?int $id): bool
    {
        if ($id === null) {
            return true;
        }

        $ids = $this->idsFor($user, $type);

        return $ids === null || in_array($id, $ids, true);
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * `true` কেবল তখন, যখন তিনি "সব শাখা" নয়, একটা শাখা বেছেছেন আর সেটা তাঁর
     * নাগালের ভেতরে। ⓘ তখন কেবল সেই শাখার সারি — **শাখাহীন সারিও নয়**:
     * শাখাহীন কাগজ কোম্পানি-স্তরের (প্রধান অফিসের জাবেদা), সেটা "সব শাখা"-তে।
     *
     * ⛔ কেবল **দেখার** পথে — যাচাইয়ের পথে (বাকির সীমা, টিলের জের, খাতার সিল)
     * কখনো নয়; সেখানে গোটা কোম্পানিই সত্যি।
     */
    public function viewsOneBranch(mixed $user): bool
    {
        return $this->viewedBranch($user) !== null;
    }

    /**
     * ⭐ দেখার শাখার আইডিগুলো — একটা বাছা থাকলে [সেটা], নইলে নাগাল ([[idsFor()]]);
     * `null` মানে সীমা নেই।
     *
     * @return list<int>|null
     */
    public function viewBranchIds(mixed $user): ?array
    {
        $one = $this->viewedBranch($user);

        if ($one !== null) {
            return [$one];
        }

        return $user instanceof User ? $this->idsFor($user, UserDataScope::BRANCH) : null;
    }

    /** বাছা একটা শাখা — "সব শাখা" হলে, বা বাছাটা নাগালের বাইরে হলে null। */
    private function viewedBranch(mixed $user): ?int
    {
        if (! $user instanceof User || ($user->view_all_branches ?? true)) {
            return null;
        }

        $current = CompanyContext::branchId() ?? $user->current_branch_id;

        if ($current === null) {
            return null;
        }

        $reach = $this->idsFor($user, UserDataScope::BRANCH);

        return $reach === null || in_array((int) $current, $reach, true) ? (int) $current : null;
    }

    /** অনুমতি বদলালে ক্যাশটাও ভুল হয়ে যায়। */
    public function forget(): void
    {
        $this->cache = [];
    }
}
