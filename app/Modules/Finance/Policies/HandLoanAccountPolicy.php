<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Models\User;
use App\Modules\Finance\Models\HandLoanAccount;

/**
 * হাতধার — কে কী পারে।
 *
 * ── ⛔ কেন ফাইলটা ১৫ সেপ্টেম্বর ২০২৬-এ লাগল ───────────────────────────
 * Finance-এর show-পর্দাগুলোয় কাগজপত্রের কার্ড বসানোর পর দেখা গেল কার্ডটা
 * আসে, কিন্তু **ফাইল তোলার ঘরটা আসে না** — মালিকের জন্যও নয়।
 *
 * ⓘ [[components/ui/attachments]] জিজ্ঞেস করে `can('create', $document)`,
 * আর Laravel নীতিটা খোঁজে `Models` → `Policies` ধরে। ⚠️ নীতি না থাকলে
 * উত্তর **নীরবে `false`** — কোনো ভুল নয়, শুধু বোতামটা থাকে না, আর কেউ
 * বুঝত না কেন কাগজ তোলা যাচ্ছে না।
 *
 * ⭐ অনুমতির নামগুলো `module.php`-তে ঘোষিত, আর ওটাই একমাত্র তালিকা।
 * এখানে দ্বিতীয় তালিকা রাখলে দুইটা একদিন আলাদা কথা বলত।
 */
class HandLoanAccountPolicy
{

    public function view(User $user, HandLoanAccount $document): bool
    {
        return $user->can('finance.hand_loan.view');
    }

    /*
     * ⚠️ কাগজ রাখার অধিকারও এটাই — `update` নয়।
     *
     * ⓘ কারণ প্রায় সব মডিউলে `update`-এ "খসড়া হলে তবেই" শর্ত থাকে,
     * অথচ ব্যাংকের মঞ্জুরিপত্র বা FDR-এর সার্টিফিকেট হাতে আসে খাতা
     * খোলার **পরে**।
     */
    public function create(User $user): bool
    {
        return $user->can('finance.hand_loan.create');
    }

    public function update(User $user, HandLoanAccount $document): bool
    {
        return $user->can('finance.hand_loan.create');
    }

    public function delete(User $user, HandLoanAccount $document): bool
    {
        return $user->can('finance.hand_loan.create');
    }
}
