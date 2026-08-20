<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\DataScope;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * শাখা ধরে সারি ছাঁকা — ভাগ চ (RLS)।
 *
 * `BelongsToCompany` কোম্পানি আলাদা রাখে; এটা কোম্পানির **ভেতরে** দেয়াল
 * তোলে। মডেলে trait বসালেই প্রতিটা কোয়েরি ছাঁকা হয়, ঠিক একই কারণে:
 * হাতে লিখলে একদিন কেউ একটা কোয়েরিতে লিখতে ভুলবে, আর সেই একটাই যথেষ্ট।
 *
 * ── তিনটা জায়গায় ছাঁকনি বসে না, আর তিনটাই ইচ্ছাকৃত ─────────────────
 *
 * ১ · কোনো ব্যবহারকারী লগইন না থাকলে। কনসোল কমান্ড, সিডার, নির্ধারিত
 *     কাজ — ওখানে কারো "দেখার অনুমতি" বলে কিছু নেই, আর ছাঁকনি বসালে
 *     ব্যাকআপ ও মাস শেষের দৌড় অর্ধেক সারি নিয়ে চলত।
 *
 * ২ · ব্যবহারকারীর কোনো সীমা বসানো না থাকলে। সেটাই আজকের অবস্থা, আর
 *     সেটাই ডিফল্ট — নাহলে ফিচারটা চালুর দিনে সবাই অন্ধ হয়ে যেতেন।
 *
 * ৩ · শাখাহীন সারি (`branch_id` null)। প্রধান অফিসের জাবেদা, কোম্পানি
 *     স্তরের কাগজ — ওগুলোর কোনো শাখা নেই, আর আটকালে সীমাবদ্ধ
 *     ব্যবহারকারী নিজের কাজের অর্ধেকই দেখতেন না।
 */
trait ScopedToUserBranch
{
    public static function bootScopedToUserBranch(): void
    {
        static::addGlobalScope('user-branch', function (Builder $builder): void {
            $user = auth()->user();

            if ($user === null) {
                return;
            }

            $ids = app(DataScope::class)->idsFor($user, UserDataScope::BRANCH);

            if ($ids === null) {
                return;
            }

            $table = $builder->getModel()->getTable();

            $builder->where(function (Builder $q) use ($table, $ids): void {
                $q->whereIn($table.'.branch_id', $ids)
                    ->orWhereNull($table.'.branch_id');
            });
        });
    }

    /**
     * সীমা উপেক্ষা করে কোয়েরি — শুধু সচেতনভাবে।
     *
     * যেমন মাস শেষের রিপোর্ট যেখানে পুরো কোম্পানির যোগফল লাগে, বা
     * নিরীক্ষার পর্দা। ব্যবহার করার আগে প্রশ্নটা করা দরকার: এই
     * সংখ্যাটা কি সত্যিই সবার দেখার কথা।
     *
     * @return Builder<static>
     */
    public static function acrossBranches(): Builder
    {
        return static::query()->withoutGlobalScope('user-branch');
    }
}
