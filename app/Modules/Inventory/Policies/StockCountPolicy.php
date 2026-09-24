<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Inventory\Models\StockCount;

/**
 * মাল গোনা — কে কী পারে।
 *
 * ── ⚠️ গোনা আর মেনে নেওয়া এক অধিকার নয় ──────────────────────────────
 * ⓘ গোনা একটা **পর্যবেক্ষণ**: খাতা এক চুলও নড়ে না, তাই ওটা গুদামের
 * লোকের রোজকার কাজ। ⛔ মেনে নেওয়া একটা **সিদ্ধান্ত** — ওই মুহূর্তে মাল
 * খাতা থেকে উবে যায়, বা বিনা টাকায় জন্ম নেয়।
 *
 * ⚠️ দুইটা এক চাবিতে রাখলে যিনি গোনেন তিনিই নিজের গোনাটা মেনে নিতে
 * পারতেন, আর তখন গোনার কোনো মানেই থাকত না। ⓘ ঠিক এই কারণেই
 * স্থানান্তরে পাঠানো আর বুঝে নেওয়া আলাদা ([[StockTransferPolicy]])।
 */
class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.count.view');
    }

    public function view(User $user, StockCount $document): bool
    {
        return $user->can('inventory.count.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.count.create');
    }

    /*
     * ⛔ এখানে `update()` ও `delete()` ছিল — ২৪ সেপ্টেম্বর ২০২৬-এ সরানো।
     *
     * ── ⚠️ কেন সরল ──────────────────────────────────────────────────
     * গোনার কোনো সম্পাদনা বা মোছার রুট নেই, তাই নিয়ম দুইটায় **পৌঁছানোর
     * কোনো পথ ছিল না**। ⓘ [[EveryPolicyRuleIsActuallyReachedTest]] ঠিক
     * এই জিনিসটাই ধরে, আর তার কারণটা তার গায়েই লেখা: একটা মৃত নিয়ম
     * নীরবে বিপজ্জনক — পরেরজন সেটা পড়ে বিশ্বাস করেন ওটাই পাহারা,
     * অথচ আসল সিদ্ধান্ত অন্য কোথাও নেওয়া হচ্ছে।
     *
     * ⭐ গোনা বদলানোর দরকার হলে নতুন একটা গোনা লেখা হয়: খসড়ায় খাতা
     * নড়ে না, তাই পুরনোটা পড়ে থাকলেও কারও ক্ষতি নেই। ⓘ সম্পাদনার
     * পর্দা যেদিন সত্যিই লাগবে, নিয়ম দুইটা সেদিন রুটসহ ফিরবে।
     */

    /**
     * ⭐ পার্থক্যটা মেনে নেওয়া — নিজের চাবি।
     */
    public function approve(User $user, StockCount $document): bool
    {
        return $user->can('inventory.count.approve')
            && $document->status === DocumentStatus::DRAFT;
    }
}
