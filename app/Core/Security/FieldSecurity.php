<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Module\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * কে কোন **ঘর** দেখবে।
 *
 * ── কেন রুটের অনুমতি যথেষ্ট নয় ───────────────────────────────────────
 * অনুমতি বলে কে পাতাটা খুলতে পারবে। বিক্রির সময় প্রশ্নটা অন্য, আর
 * প্রায় সবসময় একই: *"আমার সেলসম্যান কি ক্রয়মূল্য দেখতে পাবে?"*
 *
 * তিনি পণ্যের পাতা দেখতে পারেন — দেখতেই হবে, নাহলে বিক্রি করবেন কী
 * করে। কিন্তু ওই একটা ঘর তাঁর নয়। **পাতাটা বন্ধ করা উত্তর নয়, ঘরটা
 * বন্ধ করা উত্তর।**
 *
 * ── ২ সেপ্টেম্বর ২০২৬-এ যা ছিল ────────────────────────────────────────
 * শূন্য। একটামাত্র হাতে লেখা পাহারা ছিল বিক্রয় চালানের `cost_of_goods`
 * ঘরটায়, আর বাকি সব খোলা — **পণ্যের পাতায় ক্রয়মূল্য যেকোনো
 * লগইন-করা মানুষ দেখতে পেতেন।**
 *
 * ── কেন তালিকাটা মডিউলের, কোরের নয় ───────────────────────────────────
 * কোর যদি জানে "মজুদ মডিউলের Product-এ purchase_price আছে", তবে ওই
 * মডিউল ছাড়া কোর চলে না (§১৯.৭)। মডিউল নিজের ঘর নিজে ঘোষণা করে,
 * ঠিক যেভাবে সে অডিটের ব্যতিক্রম ঘোষণা করে।
 *
 * ── কেন এখানে মান বদলে দেওয়া হয় না ───────────────────────────────────
 * এই ক্লাসটা কেবল **প্রশ্নের উত্তর দেয়** — "ইনি কি এই ঘরটা দেখতে
 * পাবেন?" — মডেলের মান ছোঁয় না।
 *
 * কারণ মানটা মুছে দিলে হিসাবও ভাঙত: `unit_cost` কেবল পর্দার জিনিস নয়,
 * ওটা দিয়ে মুনাফা গোনা হয়। যে সেবা মুনাফা গোনে সে কোনো মানুষের হয়ে
 * চলে না, তবু তাকে সংখ্যাটা লাগে। **লুকানোর জায়গা দেখানোর মুহূর্ত,
 * গোনার মুহূর্ত নয়।**
 */
final class FieldSecurity
{
    /** এক অনুরোধে একবারই সব মডিউল পড়া হয়। */
    private static ?array $map = null;

    /**
     * সব মডিউলের ঘোষণা এক জায়গায়।
     *
     * @return array<class-string, array<string, string>>
     */
    public static function all(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $map = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->sensitiveFields as $class => $fields) {
                $map[$class] = array_merge($map[$class] ?? [], $fields);
            }
        }

        return self::$map = $map;
    }

    /**
     * এই ঘরটা কোন অনুমতির পেছনে — না থাকলে `null`।
     *
     * @param  class-string|Model  $model
     */
    public static function permissionFor(string|Model $model, string $field): ?string
    {
        $class = $model instanceof Model ? $model::class : $model;

        return self::all()[$class][$field] ?? null;
    }

    /**
     * চলতি ব্যবহারকারী এই ঘরটা দেখতে পাবেন কি না।
     *
     * ── কেন ঘোষণাহীন ঘর সবসময় দেখা যায় ─────────────────────────────
     * ডিফল্ট "দেখা যাবে", "লুকানো" নয়। উল্টোটা করলে একটা নতুন কলাম
     * যোগ করার দিনেই প্রতিটা পর্দা থেকে সেটা উধাও হয়ে যেত, আর কেউ
     * বুঝত না কেন — আর নিরাপত্তা এমনভাবে আসে না, এমনভাবে কেবল
     * বিরক্তি আসে।
     *
     * যা লুকানো দরকার, সেটা **লিখে** লুকানো হয়। আর সেটা যাতে কেউ
     * লিখতে ভুলে না যায়, তার জন্য আলাদা পাহারা আছে
     * ([[NoSensitiveFieldIsPrintedInTheOpenTest]])।
     *
     * ── কেউ লগইন না থাকলে ───────────────────────────────────────────
     * তখন `false`। কনসোল, সিডার বা কিউয়ের কোড কোনো মানুষের হয়ে চলে
     * না, আর ওরা এই ক্লাসটা ব্যবহারও করে না — ওরা মডেল থেকে সরাসরি
     * মান পড়ে, যা এখানে ছোঁয়া হয় না।
     *
     * @param  class-string|Model  $model
     */
    public static function visible(string|Model $model, string $field): bool
    {
        $permission = self::permissionFor($model, $field);

        if ($permission === null) {
            return true;
        }

        return (bool) auth()->user()?->can($permission);
    }

    /**
     * লুকানো ঘরের জায়গায় যা দেখা যায়।
     *
     * ── কেন ফাঁকা নয় ────────────────────────────────────────────────
     * ফাঁকা ঘর মানে "কিছু নেই" — একজন ভাবতেন ক্রয়মূল্য বসানোই হয়নি,
     * আর পণ্য-মাস্টার ঠিক করতে গিয়ে হয়তো নতুন একটা দর বসিয়ে দিতেন।
     * চিহ্নটা বলে **"আছে, কিন্তু আপনার জন্য নয়"** — দুইটা সম্পূর্ণ
     * আলাদা কথা।
     */
    public static function mask(): string
    {
        return '••••';
    }

    /**
     * দেখা গেলে মান, নাহলে চিহ্ন।
     *
     * @param  class-string|Model  $model
     */
    public static function show(string|Model $model, string $field, mixed $value): mixed
    {
        return self::visible($model, $field) ? $value : self::mask();
    }

    /** টেস্টের জন্য — মডিউলের তালিকা বদলালে ক্যাশটা বাসি হয়ে যায়। */
    public static function forget(): void
    {
        self::$map = null;
    }
}
