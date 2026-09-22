<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * কে কোন নোটিশ দেখবেন — প্রশ্নটার একটাই উত্তর, একটাই জায়গায়।
 *
 * ── ⛔ কেন এটা একটা সেবা, দুই জায়গায় দুইটা কোয়েরি নয় ─────────────────
 * উত্তরটা **দুইবার** দরকার: বোর্ডের তালিকায়, আর নিচের চলন্ত বারে।
 * ⚠️ দুই জায়গায় দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা পুরনো নিয়মে
 * চলত — আর পার্থক্যটা **নীরব** হত: বারে একটা নোটিশ ঘুরত যেটা বোর্ডে
 * খুলে পড়া যেত না, বা উল্টোটা।
 *
 * ⓘ আজকের দিনেই এই ভুলটা রিপোতে দুইবার ধরা পড়েছে — সইকারীর তালিকা আর
 * ফরওয়ার্ডের বাছাই, আর ইনবক্সের কোয়েরি ও বোতামের নিয়ম।
 */
final class NoticeBoard
{
    /**
     * ⭐ এই মানুষটা আজ যে নোটিশগুলো দেখবেন।
     *
     * ⚠️ ভূমিকাগুলো **নাম ধরে** নেওয়া হয়, আর সেটা চলতি কোম্পানির
     * ভূমিকাই ([[Notice::scopeForRoles()]]-এ কারণ লেখা)।
     *
     * @return Collection<int, Notice>
     */
    public function forUser(User $user, ?Carbon $day = null): Collection
    {
        return Notice::query()
            ->liveOn($day ?? Carbon::today())
            ->forRoles($user->getRoleNames()->all())
            ->with('author')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * ⭐ নিচের চলন্ত বারে যেগুলো যাবে।
     *
     * ⓘ ঐ তালিকাটা উপরেরটারই একটা ছাঁকা রূপ — আলাদা কোয়েরি নয়। ⚠️
     * আলাদা করে লিখলে দুইটা নিয়ম হত, আর এই ফাইলটা যে কারণে আছে সেটাই
     * ভেঙে যেত।
     *
     * @return Collection<int, Notice>
     */
    public function forTicker(User $user, ?Carbon $day = null): Collection
    {
        return $this->forUser($user, $day)->where('in_ticker', true)->values();
    }

    /**
     * ⓘ পড়া হয়েছে বলে দাগ দেওয়া — একবারই।
     *
     * ⚠️ `firstOrCreate` ইচ্ছাকৃত: দ্বিতীয়বার খুললে **প্রথমবারের সময়টাই**
     * থাকে। ⛔ প্রতিবার বসালে *"কবে প্রথম দেখেছিলেন"* প্রশ্নের উত্তর
     * হারাত, আর ঐটাই একমাত্র প্রশ্ন যার জন্য হিসাবটা রাখা।
     */
    public function markRead(Notice $notice, User $user): void
    {
        NoticeRead::query()->firstOrCreate(
            ['notice_id' => $notice->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /**
     * ⭐ এই মানুষটার না-পড়া নোটিশ কয়টা।
     *
     * ⓘ ঘণ্টার লাল বিন্দুটা এই সংখ্যাটাই দেখায়।
     */
    public function unreadCount(User $user): int
    {
        return count($this->unreadIds($user));
    }

    /**
     * ⓘ কোনগুলো এখনো পড়া হয়নি — আইডি ধরে।
     *
     * ⚠️ পড়ার সারিগুলো **একটা কোয়েরিতে** তোলা হয়, নোটিশ ধরে ধরে নয়।
     * ⛔ নোটিশপ্রতি একটা `exists()` চালালে ঘণ্টার বিন্দুটা আঁকতে বিশটা
     * কোয়েরি লাগত, আর ওটা **প্রতিটা পাতায়** আঁকা হয়।
     *
     * @return list<int>
     */
    public function unreadIds(User $user): array
    {
        $mine = $this->forUser($user);

        if ($mine->isEmpty()) {
            return [];
        }

        $seen = NoticeRead::query()
            ->whereIn('notice_id', $mine->modelKeys())
            ->where('user_id', $user->id)
            ->pluck('notice_id')
            ->all();

        return array_values(array_diff($mine->modelKeys(), $seen));
    }
}
