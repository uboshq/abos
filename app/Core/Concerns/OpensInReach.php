<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Engines\Approval\PaperSigners;
use App\Core\Services\DataScope;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ কাগজের নিজের পাতা নাগাল ধরে খোলে, মাথায় বাছা শাখা ধরে নয় — মালিকের বাগ "ভাউচার ভিউ হয় না" (fe, ১৯ অক্টোবর ২০২৬)।
 *
 * <p>⛔ আগে ভাউচারের পাতা ([[ScopedToUserBranch]]-এর `user-branch` স্কোপে) কেবল মাথায় বাছা শাখার ভাউচার খুঁজত। সইয়ের পাতা,
 * নোটিশ সেন্টার আর ফোনের কাগজ ভাউচারটা ইচ্ছা করেই সব শাখা জুড়ে খুঁজে পেত, তারপর ঐ পাতার লিংক দিত — চাপলে ৪০৪, পপআপে
 * "খোলা গেল না"। মালিক মাথায় একটা শাখা বাছলেও তাই হত (super admin-এর ছাড় মাথার বাছাইয়ে খাটে না, [[DataScope]])।
 *
 * <p>⭐ এখন: কোম্পানির দেয়াল থাকে; শাখা দেখা হয় মানুষের **নাগাল** ধরে ([[DataScope::allows()]] — মালিক/super admin আর সীমা
 * ছাড়া কর্মী সব শাখা; শাখা-সীমিত কর্মী কেবল নিজের নাগাল; শাখা ছাড়া কাগজ নাগালের সবাই)। নাগালের বাইরে কেবল এই কাগজের
 * সইকারী ([[PaperSigners]]) — অন্য কারো জন্য ৪০৪, আগের মতোই। Finance ([[OpensOnlyInReach]]) আর Documents
 * ([[Document::resolveRouteBinding()]])-এর একই সারাই, মূল কাঠামোয়।
 *
 * @mixin Model
 */
trait OpensInReach
{
    public function resolveRouteBinding($value, $field = null)
    {
        $row = $this->resolveRouteBindingQuery(static::acrossBranches(), $value, $field)->first();

        // ⛔ সইকারীর ছাড় কেবল পড়ায় (GET/HEAD) — নাগালের বাইরের সইকারী কাগজটা বদলাতে বা পাকা করতে পারেন না
        $reading = in_array(request()->method(), ['GET', 'HEAD'], true);

        return $row !== null && static::opensFor(auth()->user(), $row, signers: $reading) ? $row : null;
    }

    /** এই মানুষের জন্য এই কাগজ খোলে কি না — নাগাল, নয়তো (পড়ায়) এই কাগজের সইকারী */
    public static function opensFor(mixed $user, Model $row, bool $signers = true): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $branch = $row->getAttribute('branch_id');

        if (app(DataScope::class)->allows($user, UserDataScope::BRANCH, $branch === null ? null : (int) $branch)) {
            return true;
        }

        return $signers && PaperSigners::isSigner($user, $row);
    }

    /** আইডি বা অন্য ঘর ধরে, নাগালের নিয়মে — লিংক বানানো আর ছাপার দরজার জন্য */
    public static function findInReach(int|string $value, string $column = 'id'): ?static
    {
        $row = static::acrossBranches()->where($column, $value)->first();

        /*
         * ⓘ কর্মী নেই (লগইন-ছাড়া শেয়ার-লিংক, কনসোল, সারির কাজ) — শাখার দেয়াল নিজেও তখন কিছু ছাঁকে না
         * ([[ScopedToUserBranch]]), তাই খোঁজাও আগের মতো সাদা। ⛔ এটা ছাড়া শেয়ার-লিংকে ছাপা ভাউচার তার বিলের
         * নম্বর হারাত (ec, ২৪ অক্টোবর ২০২৬)। দরজার পাহারা আলাদা: route-বাঁধনে কর্মী ছাড়া কিছুই খোলে না।
         */
        if (! auth()->user() instanceof User) {
            return $row;
        }

        return $row !== null && static::opensFor(auth()->user(), $row) ? $row : null;
    }
}
