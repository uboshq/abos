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

    /**
     * ⛔ অনুমোদনের পর আর বদলানো যায় না।
     *
     * ⚠️ পার্থক্যগুলো ততক্ষণে সমন্বয় হয়ে খতিয়ানে বসে গেছে। ⓘ গোনার
     * সংখ্যা তখন বদলালে কাগজ আর খাতা দুই কথা বলত, আর কোনটা সত্যি তা
     * বলার কোনো উপায় থাকত না।
     */
    public function update(User $user, StockCount $document): bool
    {
        return $user->can('inventory.count.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    /**
     * ⓘ মোছা নয় — বাতিল। খসড়া গোনায় খাতা নড়েনি, তাই ওটা সরানো
     * নিরাপদ; অনুমোদিত গোনা কোনোদিন সরে না।
     */
    public function delete(User $user, StockCount $document): bool
    {
        return $user->can('inventory.count.create')
            && $document->status === DocumentStatus::DRAFT;
    }

    /**
     * ⭐ পার্থক্যটা মেনে নেওয়া — নিজের চাবি।
     */
    public function approve(User $user, StockCount $document): bool
    {
        return $user->can('inventory.count.approve')
            && $document->status === DocumentStatus::DRAFT;
    }
}
