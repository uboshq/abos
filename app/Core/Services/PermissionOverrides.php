<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Models\UserPermissionOverride;

/**
 * একজন মানুষের নামে লেখা অনুমতির ব্যতিক্রম — একবারে, বারবার নয়।
 *
 * ── ⛔ কী ঘটত, ২০ সেপ্টেম্বর ২০২৬ অডিটে ধরা ───────────────────────────
 * `Gate::before` প্রতিটা `can()`-এর জন্য **একটা করে কোয়েরি** করত। ⓘ মেনু
 * আঁকতে গিয়ে প্রতিটা সারির অনুমতি দেখা হয়, তাই একটা পাতা খোলার আগেই
 * ১৯২টা কোয়েরি — আর তার প্রায় সবগুলোই কিছু না পেয়ে ফিরত, কারণ
 * ব্যতিক্রম বলে কিছু সাধারণত থাকেই না।
 *
 * ⭐ একজন মানুষের ব্যতিক্রম বড়জোর গোটা কয়েক সারি। সবগুলো একবারে তুলে
 * রাখলে ১৯২টা কোয়েরি একটায় নামে, আর উত্তরগুলো হুবহু আগের মতোই থাকে।
 *
 * ⚠️ স্মৃতিটা অনুরোধভিত্তিক (singleton), তাই কেউ মাঝপথে ব্যতিক্রম বদলালে
 * সেই অনুরোধে পুরনো উত্তর আসতে পারে। ⓘ তাই [[forget()]] আছে, আর যে
 * পর্দা ব্যতিক্রম লেখে তার সেটা ডাকা উচিত। ⛔ আজ কোনো পর্দা ওগুলো লেখে
 * না (কেবল পড়ে), তাই প্রশ্নটা তাত্ত্বিক — কিন্তু দরজাটা খোলা রাখা হলো।
 */
final class PermissionOverrides
{
    /**
     * কোম্পানি ও মানুষ ধরে: অনুমতির নাম → দেওয়া হয়েছে কি না।
     *
     * ⓘ চাবিতে কোম্পানিও আছে — একই মানুষ দুই কোম্পানিতে কাজ করতে পারেন,
     * আর এক কোম্পানির ব্যতিক্রম অন্যটায় খাটার কথা নয়।
     *
     * @var array<string, array<string, bool>>
     */
    private array $byUser = [];

    /**
     * এই অনুমতিটা এই মানুষের নামে আলাদা করে দেওয়া বা কাড়া হয়েছে কি না।
     *
     * ⓘ `null` মানে "কিছু লেখা নেই" — তখন সিদ্ধান্তটা রোল ও পলিসির,
     * আর সেটাই স্বাভাবিক পথ।
     */
    public function granted(User $user, string $ability): ?bool
    {
        $key = CompanyContext::id().':'.$user->id;

        if (! array_key_exists($key, $this->byUser)) {
            $this->byUser[$key] = UserPermissionOverride::query()
                ->where('user_id', $user->id)
                ->pluck('granted', 'permission')
                ->map(fn ($granted) => (bool) $granted)
                ->all();
        }

        return $this->byUser[$key][$ability] ?? null;
    }

    /** লেখার পর স্মৃতিটা ফেলে দেওয়া। */
    public function forget(): void
    {
        $this->byUser = [];
    }
}
