<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\Recipe;

/**
 * রেসিপির চাবি — পণ্যের চাবির সাথে নয়।
 *
 * ── কেন আলাদা ───────────────────────────────────────────────────────
 * পণ্য দেখার ও সম্পাদনার অনুমতি অনেকের থাকে — গুদামের লোক, কাউন্টারের
 * লোক, হিসাবের লোক। রেসিপি **বদলানোর** অনুমতি অল্প কয়েকজনের থাকা উচিত।
 *
 * একটা লাইনে "৫ কেজি চাল" বদলে "৩ কেজি" করে দিলে ওই খাবারের প্রতিটা
 * বিক্রিতে দুই কেজি চাল খাতায় থেকে যাবে যা বাস্তবে নেই। ভুলটা নীরব:
 * পর্দায় কিছু ভাঙে না, বিল ছাপে, আর গুদামের হিসাব ধীরে ধীরে সরে যায়।
 *
 * পণ্যের চাবির সাথে জুড়ে দিলে যে কেউ পণ্য সম্পাদনা করতে পারেন তিনি
 * রেসিপিও বদলাতে পারতেন — আর ওই দুইটা এক দায়িত্ব নয়।
 */
class RecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.recipe.view');
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $user->can('inventory.recipe.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.recipe.create');
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $user->can('inventory.recipe.update');
    }

    /**
     * "মোছা" মানে নিষ্ক্রিয় করা — সারিটা থেকে যায়।
     *
     * আর সক্রিয় করাও এই একই চাবিতে: একটা রেসিপি আবার চালু করা মানে
     * আজ থেকে ওটাই স্টক কমাবে, অর্থাৎ ক্ষমতাটা নিষ্ক্রিয় করার সমানই।
     * গুদাম ও পণ্যে একই নিয়ম।
     */
    public function delete(User $user, Recipe $recipe): bool
    {
        return $user->can('inventory.recipe.delete');
    }
}
