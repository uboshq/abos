<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\DataScope;
use App\Models\UserDataScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * গুদাম ধরে সারি ছাঁকা — ভাগ চ (RLS)-এর দ্বিতীয় দেয়াল।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * `UserDataScope::WAREHOUSE` ধ্রুবকটা প্রথম দিন থেকেই ছিল, মন্তব্যসহ:
 * *"গুদাম — মজুদের কাগজে"*। কিন্তু গোটা প্রকল্পে **একটাও কোয়েরি ওটা
 * পড়ত না**। অর্থাৎ কেউ চাইলে সারি বসাতে পারতেন, আর সারিটা কিছুই করত
 * না — নীরবে।
 *
 * এটা এই প্রকল্পের সবচেয়ে বারবার ফেরা ভুল: **ধ্রুবকটা আছে বলে
 * জিনিসটা আছে বলে মনে হয়**।
 *
 * ── শাখার দেয়াল থেকে এটা কীভাবে আলাদা ───────────────────────────────
 * শাখা বলে *কোন অফিসের কাগজ*; গুদাম বলে *কোন তাকের মাল*। ময়মনসিংহ
 * শাখার একজন স্টোরকিপার শাখার সব বিল দেখতে পারেন, কিন্তু তাঁর কাজ
 * একটাই গুদামে — বাকি গুদামের মজুদ তাঁর দেখার কথা নয়, কারণ ওই
 * সংখ্যাগুলো তিনি মেলাতেও পারবেন না।
 *
 * ── কেন কেবল মজুদের কাগজে, বিল বা চালানে নয় ─────────────────────────
 * বিল, আদেশ ও চালানে `warehouse_id` আছে — কিন্তু ওটা "মাল কোথা থেকে
 * যাবে" বলে, "কাগজটা কার" নয়। ওগুলোয় ছাঁকনি বসালে একজন বিক্রয়কর্মী
 * নিজের কাটা বিলটাই দেখতে পেতেন না, কেবল ডেলিভারির গুদাম আলাদা বলে।
 * ওই কাগজগুলোর দেয়াল শাখা, আর সেটা আগে থেকেই আছে।
 *
 * এখানে বসে তিনটায়: মজুদের চলাচল, লট, আর গুদামের নিজের তালিকা।
 *
 * ── ছাঁকনি বসে না যেখানে, শাখার নিয়মেই ──────────────────────────────
 * লগইন না থাকলে (কনসোল, সিডার), সীমা বসানো না থাকলে, আর গুদামহীন
 * সারিতে। তিনটাই `ScopedToUserBranch`-এর একই কারণে।
 */
trait ScopedToUserWarehouse
{
    public static function bootScopedToUserWarehouse(): void
    {
        static::addGlobalScope('user-warehouse', function (Builder $builder): void {
            $user = auth()->user();

            if ($user === null) {
                return;
            }

            $ids = app(DataScope::class)->idsFor($user, UserDataScope::WAREHOUSE);

            if ($ids === null) {
                return;
            }

            $model = $builder->getModel();
            $column = $model->getTable().'.'.$model->warehouseScopeColumn();

            $builder->where(function (Builder $q) use ($column, $ids): void {
                $q->whereIn($column, $ids)->orWhereNull($column);
            });
        });
    }

    /**
     * কোন কলামে গুদামটা লেখা।
     *
     * ── কেন এটা বদলানোর দরকার হয় ────────────────────────────────────
     * মজুদের কাগজে ঘরটার নাম `warehouse_id`। কিন্তু গুদামের **নিজের**
     * তালিকায় ওই নামে কোনো ঘর নেই — সেখানে গুদামটা সারিটাই, তাই
     * ছাঁকনি বসে `id`-তে।
     *
     * এক নামে দুইটা জিনিস ধরতে গেলে হয় গুদামের তালিকা ছাঁকা যেত না,
     * নয়তো প্রতিটা মডেলে ছাঁকনিটা হাতে লিখতে হত — আর হাতে লেখা মানে
     * একদিন কেউ ভুলবে।
     */
    public function warehouseScopeColumn(): string
    {
        return 'warehouse_id';
    }

    /**
     * সীমা উপেক্ষা করে কোয়েরি — শুধু সচেতনভাবে।
     *
     * যেমন গোটা কোম্পানির মজুদ মেলানোর রিপোর্ট, বা নিরীক্ষা। ব্যবহারের
     * আগে প্রশ্নটা করা দরকার: এই সংখ্যাটা কি সত্যিই সবার দেখার কথা।
     *
     * @return Builder<static>
     */
    public static function acrossWarehouses(): Builder
    {
        return static::query()->withoutGlobalScope('user-warehouse');
    }
}
