<?php

declare(strict_types=1);

namespace App\Modules\Customer\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * গ্রাহকের তালিকা, ফোনে — অর্ডার লিখতে হলে এটা ছাড়া চলে না।
 *
 * নেট ছাড়া অর্ডার লেখার মানেই হলো দোকানটা বাছতে পারা, আর সেটা ক্যাশে
 * করা তালিকা ছাড়া হয় না।
 *
 * ── কেন ফোন থেকে গ্রাহক বসানো যায় না ────────────────────────────────
 * [[acceptsPush()]] false। একজন নতুন গ্রাহক মানে একটা প্রাপ্য হিসাবের
 * খাত, একটা ক্রেডিট সীমা, আর প্রায়ই একটা খোলার জের — তিনটাই অফিসের
 * সিদ্ধান্ত। মাঠ থেকে বসালে দুই সেলসম্যান একই দোকান দুইবার বসাতেন
 * (একই নাম, আলাদা বানানে), আর সেটা মিলিয়ে দেওয়ার কোনো সহজ উপায় নেই।
 *
 * নতুন দোকান নেটওয়ার্কে এসে বসাতে হবে — একটা সীমা, কিন্তু সৎ সীমা।
 */
final class CustomerSync implements SyncsToDevices
{
    public static function module(): string
    {
        return 'customer';
    }

    public static function entityType(): string
    {
        return 'Customer';
    }

    /**
     * ── ⚠️ কেন এই চাবি ─────────────────────────────────────────────
     * **এক অ্যাপ সবার জন্য** — মালিক, কর্মী, গ্রাহক। কোম্পানি ধরে ছাঁকা
     * ([[BelongsToCompany]]) এখানে **যথেষ্ট নয়**।
     *
     * একজন গ্রাহক সিঙ্ক করলে তাঁর ফোনে পুরো গ্রাহক-তালিকা নেমে যাওয়া
     * মানে **প্রতিযোগীর তালিকা প্রতিযোগীর হাতে**, আর একবার নেমে গেলে
     * ফেরত আনার কোনো উপায় নেই।
     *
     * ওয়েবের গ্রাহক-তালিকার পর্দাটা ঠিক এই একই চাবি চায়, আর সেটাই
     * উদ্দেশ্য — **দরজা দুইটা, তালা একটাই**।
     */
    public static function requiredPermission(): ?string
    {
        return 'customer.view';
    }

    /**
     * চাবিটা [[SyncService::pull()]] আগেই দেখে নিয়েছে, তাই এখানে আর
     * নয় — দুই জায়গায় থাকলে একদিন দুইটা অমিল হত।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        $query = Customer::query()
            /*
             * নিষ্ক্রিয় গ্রাহকও যায়, আর সেটা ইচ্ছাকৃত: ফোনে ইতিমধ্যে
             * নেমে যাওয়া একটা সারি বাদ দিলে সেটা **চিরকাল পুরনো অবস্থায়
             * থেকে যেত**, কারণ ডেল্টা-সিঙ্কে "এটা আর নেই" বলার একমাত্র
             * উপায় সারিটা পাঠানো।
             */
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query->get()->map(fn (Customer $customer) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $customer->public_id,
            /*
             * ── কেন হাতে বাছা ঘর, `toArray()` নয় ────────────────────
             * `toArray()` **সব** কলাম পাঠাত — `portal_password`-এর হ্যাশ,
             * ভেতরের `id`, হিসাবের খাতের আইডি। ফোনের কোনোটাই লাগে না,
             * আর প্রথমটা পাঠানো মানে গ্রাহকের পাসওয়ার্ডের হ্যাশ প্রতিটা
             * সেলসম্যানের ফোনে বসে থাকা।
             *
             * তালিকাটা হাতে লেখা বলেই একটা নতুন কলাম যোগ হলে সেটা
             * **নিজে থেকে ফোনে চলে যায় না** — কেউ সিদ্ধান্ত নিয়ে
             * এখানে লিখলে তবেই যায়।
             */
            payload: [
                'id' => (string) $customer->public_id,
                'code' => $customer->code,
                'nameEn' => $customer->name_en,
                'nameBn' => $customer->name_bn,
                'ownerName' => $customer->owner_name,
                'phone' => $customer->phone,
                'addressEn' => $customer->address_en,
                'addressBn' => $customer->address_bn,
                'customerType' => $customer->customer_type,
                'creditLimit' => (string) $customer->credit_limit,
                'creditDays' => (int) $customer->credit_days,
                'isActive' => (bool) $customer->is_active,
            ],
            updatedAt: $customer->updated_at ?? $customer->created_at ?? now(),
        ))->all();
    }

    public function acceptsPush(): bool
    {
        return false;
    }

    /**
     * কখনো ডাকা হয় না — [[acceptsPush()]] false, আর
     * [[SyncService::applyOne()]] তার আগেই থামে।
     *
     * তবু একটা সৎ প্রত্যাখ্যান, `return null` বা খালি বডি নয়: কেউ যদি
     * কোনোদিন `acceptsPush()` true করে দেন আর এই পদ্ধতিটা লিখতে ভুলে
     * যান, তখন যেন **নীরবে কিছু না ঘটে** — একটা কারণসহ "না" যেন ফোনে
     * পৌঁছায়।
     */
    public function apply(User $user, PushedChange $change): string
    {
        throw new SyncRejection(__('sync.not_allowed_offline', ['type' => self::entityType()]));
    }
}
