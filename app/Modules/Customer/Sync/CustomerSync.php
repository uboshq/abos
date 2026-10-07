<?php

declare(strict_types=1);

namespace App\Modules\Customer\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncBatch;
use App\Core\Engines\Sync\SyncPosition;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
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

    /** দোকানের পয়েন্টের নাম — নিজের ধাপ পয়েন্ট হলে সেটা, নইলে তার মা; বাংলা আগে */
    private static function pointName(Customer $customer): ?string
    {
        foreach ([$customer->location, $customer->location?->parent] as $place) {
            if ($place !== null && $place->level === Location::POINT) {
                $name = trim((string) ($place->name_bn ?: $place->name_en));

                return $name === '' ? null : $name;
            }
        }

        return null;
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

    /** ফোন থেকে আসে না (`acceptsPush()` false) — লেখার চাবি নেই ([[SyncsToDevices::requiredPushPermission()]]) */
    public static function requiredPushPermission(): ?string
    {
        return null;
    }

    /**
     * চাবিটা [[SyncService::pull()]] আগেই দেখে নিয়েছে, তাই এখানে আর
     * নয় — দুই জায়গায় থাকলে একদিন দুইটা অমিল হত।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit, ?SyncPosition $after = null): SyncBatch
    {
        $query = Customer::query()
            /*
             * ⭐ বাছা শাখার গ্রাহকই — মালিক, ২ অক্টোবর ২০২৬: *"APp e sob branch er data ek branch e dekhay"*।
             * ওয়েবের তালিকার একই নিয়ম ([[Customer::scopeInViewedBranch()]]): এক শাখা বাছলে কেবল সেটা,
             * "সব শাখা"-য় সব। ⓘ শাখা বদলালে ফোন পুরনোটা মুছে নতুন করে টানে ([[WorkspaceApiController]])।
             */
            ->inViewedBranch()
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
            /*
             * ⭐ পয়েন্টের নাম বদলালে ঐ পয়েন্টের দোকানগুলোও যায় — নামটা গ্রাহকের সারিতে চড়ে ফোনে যায় (`pointName`), অথচ
             * গ্রাহকের নিজের `updated_at` নড়ে না। ⓘ দোকানের নিজের ধাপ বা তার মা (পয়েন্ট যেকোনো একটা)।
             */
            $query->where(fn ($q) => $q->where('updated_at', '>', $since)
                ->orWhereIn('location_id', fn ($l) => $l->select('l0.id')->from('mdm_locations as l0')
                    ->leftJoin('mdm_locations as l1', 'l1.id', '=', 'l0.parent_id')
                    ->where('l0.company_id', CompanyContext::id())
                    ->where(fn ($w) => $w->where('l0.updated_at', '>', $since)->orWhere('l1.updated_at', '>', $since))));
        }

        // ⭐ পরের পাতা — (সময়, id) জোড়ার পর থেকে ([[SyncPosition]], গ১৮)
        $after?->after($query, $query->qualifyColumn('updated_at'), $query->qualifyColumn('id'));

        // ⓘ পয়েন্ট খুঁজতে দুই ধাপ উপরে — এক কোয়েরিতে, সারি ধরে নয়
        $rows = $query->with('location.parent')->get();

        return SyncBatch::of($rows->map(fn (Customer $customer) => new SyncRecord(
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

                /*
                 * ⭐ দোকানের পয়েন্ট — মালিক, ৫ অক্টোবর ২০২৬ (ফোনের বকেয়া তালিকার ছবি দিয়ে): *"কাস্টমারের নাম মোবাইল নাম্বার
                 * দেয়া আছে এখন সাথে পয়েন্ট আউট করে দাও"*। বাংলা নাম আগে; দোকান পয়েন্টে না বসলে null — ফোন তখন কিছু যোগ করে না।
                 * ⓘ পুরনো ফোন বাড়তি ঘর উপেক্ষা করে।
                 */
                'pointName' => self::pointName($customer),
                'addressEn' => $customer->address_en,
                'addressBn' => $customer->address_bn,
                'customerType' => $customer->customer_type,
                'creditLimit' => (string) $customer->credit_limit,
                'creditDays' => (int) $customer->credit_days,
                'isActive' => (bool) $customer->is_active,
            ],
            updatedAt: $customer->updated_at ?? $customer->created_at ?? now(),
        ))->all(), $rows->count(), $limit, $rows->isEmpty() ? null : SyncPosition::of($rows->last()->updated_at, $rows->last()->id));
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
