<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\Notification;
use App\Models\NotificationChoice;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * খবর পাঠানো ও পড়া।
 *
 * ── একটাই নিয়ম, আর সেটা কড়া ─────────────────────────────────────────
 * বিজ্ঞপ্তি কেবল তখনই যায় যখন **পাওয়া মানুষটার কিছু করার বা জানার আছে**।
 * "সিস্টেম চালু হয়েছে", "রিপোর্ট তৈরি হয়েছে" — এসব পাঠালে মানুষ ঘণ্টাটা
 * দেখা বন্ধ করে দেন, আর তারপর যেদিন সত্যিকারের খবর আসে সেদিনও দেখেন না।
 *
 * ঘণ্টার একটা না-পড়া সংখ্যা তখনই কাজের, যখন সংখ্যাটা শূন্য হওয়া সম্ভব।
 */
final class NotificationService
{
    /** @var array<int, list<string>> এই অনুরোধে কার কোন ধরন বন্ধ, একবার দেখা */
    private array $silenced = [];

    /** নিজের কাজ নিজে করলে নিজেকে খবর দেওয়ার মানে নেই। */
    public function send(
        User|int $user,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
    ): ?Notification {
        $userId = $user instanceof User ? $user->id : $user;

        /*
         * নিজের করা কাজের খবর নিজের কাছে যায় না।
         *
         * নিজের ছোট খরচ নিজে অনুমোদন করলে (self_limit-এর নিচে) নিজেই
         * নিজেকে "আপনার দাবি অনুমোদিত" পাঠাত। ওরকম একটা খবর ঘণ্টায়
         * বসে থাকে, কিছু জানায় না, শুধু সংখ্যাটা বাড়ায়।
         */
        if ($userId === auth()->id()) {
            return null;
        }

        /*
         * ⭐ যিনি এই ধরনের খবর চান না, তাঁকে পাঠানো হয় না — ২০ সেপ্টেম্বর
         * ২০২৬, মালিকের *"বিজ্ঞপ্তির সেটিংস ta koro"*।
         *
         * ⓘ ছাঁকনিটা এখানে, পর্দায় নয়: না-দেখানো সারিও ঘণ্টার সংখ্যায়
         * গোনা হত, আর "৩টা নতুন" দেখে খুলে কিছুই না পাওয়ার চেয়ে খারাপ
         * কিছু নেই। ⛔ পছন্দ না লেখা থাকলে খবরটা যায় — চুপচাপ গিলে ফেলার
         * চেয়ে বাড়তি একটা খবর ভালো।
         */
        if (! $this->wants($userId, $type)) {
            return null;
        }

        return Notification::create([
            'company_id' => CompanyContext::id(),
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }

    /**
     * একই খবর একাধিক জনকে।
     *
     * @param  iterable<User|int>  $users
     * @return Collection<int, Notification>
     */
    /**
     * এই মানুষটা এই ধরনের খবর চান কি না।
     *
     * ⚠️ উত্তরটা অনুরোধের মধ্যে মনে রাখা হয়: একটা ছকে দশজন অনুমোদনকারী
     * থাকলে sendMany() দশবার একই প্রশ্ন করত।
     */
    private function wants(int $userId, string $type): bool
    {
        if (! array_key_exists($userId, $this->silenced)) {
            $this->silenced[$userId] = NotificationChoice::silencedFor($userId);
        }

        return ! in_array($type, $this->silenced[$userId], true);
    }

    public function sendMany(
        iterable $users,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
    ): Collection {
        $sent = collect();
        $seen = [];

        foreach ($users as $user) {
            $id = $user instanceof User ? $user->id : (int) $user;

            // একই ছকে একজন দুইবার থাকলে দুইটা খবর যেত
            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $one = $this->send($id, $type, $title, $body, $url);

            if ($one !== null) {
                $sent->push($one);
            }
        }

        return $sent;
    }

    /** @return Collection<int, Notification> */
    public function unreadFor(User|int $user, int $limit = 20): Collection
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Notification::query()
            ->for($userId)
            ->unread()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(User|int $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Notification::query()->for($userId)->unread()->count();
    }

    /**
     * একটা খবর পড়া হয়েছে।
     *
     * অন্যের খবর পড়া হিসেবে চিহ্নিত করা যায় না — নাহলে একটা বানানো
     * অনুরোধ দিয়ে অন্যের ঘণ্টা খালি করে দেওয়া যেত, আর তিনি কোনোদিন
     * জানতেন না তাঁর দাবিটা বাতিল হয়েছিল।
     */
    public function markRead(Notification $notification, User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ($notification->user_id !== $userId) {
            return false;
        }

        if ($notification->isUnread()) {
            $notification->update(['read_at' => now()]);
        }

        return true;
    }

    public function markAllRead(User|int $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Notification::query()
            ->for($userId)
            ->unread()
            ->update(['read_at' => now()]);
    }
}
