<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ⭐ কোন মডিউল ফোনে চলবে — কোম্পানি ধরে, মডিউল ধরে একটা করে সুইচ (১ অক্টোবর ২০২৬)।
 *
 * ── ⓘ মালিকের কথা ──────────────────────────────────────────────────────
 * অ্যাপে আপাতত কেবল হিসাব, মজুদ, বিক্রয়, ক্রয়, অনুমোদন আর প্রশাসন; বাকিগুলো
 * পরে চালু হবে — আর **প্রতিটা কোম্পানি নিজে বাছবে** (ABOS অনেক ব্যবসায় বিক্রি হয়)।
 * তাই সুইচটা সার্ভারে, সেটিংয়ের সারিতে (`mobile.modules.<code>`), আর কন্ট্রোল
 * প্যানেলের "মোবাইল অ্যাপ" ট্যাবে বদলায় — প্রতিটা বদল [[Setting]]-এর নিরীক্ষায়
 * (কে, কবে, আগে কী, পরে কী)।
 *
 * ── ⛔ মেনু লুকানো দেয়াল নয় ───────────────────────────────────────────────
 * `/me`-র মেনু থেকে সরানো কেবল সুবিধা; ফোনের হাতে টোকেন আছে, আর সে সরাসরি
 * দরজায় টোকা দিতে পারে। তাই বন্ধ মডিউলের দরজাগুলো (সিঙ্ক, রিপোর্ট, কাগজ,
 * অনুমোদন) **সার্ভারেই** ৪০৩ দেয় — [[refuseUnlessReachable()]]।
 *
 * ── ⓘ "দেখা" আর "পৌঁছানো" দুই আলাদা প্রশ্ন ───────────────────────────────
 * মেনুতে কেবল চালু মডিউল ([[isOn()]])। কিন্তু **তথ্য** আসে চালু মডিউলের
 * নির্ভরতা ধরে ([[isReachable()]]): বিক্রয় চালু থাকলে অর্ডার লিখতে গ্রাহকের
 * তালিকা লাগে, যদিও গ্রাহকের নিজের মেনু বন্ধ। ⛔ নির্ভরতা না মানলে বিক্রয়
 * চালু রেখেও ফোনে অর্ডার লেখা যেত না — সুইচটা যা চালু বলছে তা-ই ভাঙত।
 * নির্ভরতার তালিকা মডিউলের নিজের `depends_on`, এখানে হাতে লেখা নয়।
 *
 * ── কেন ৪০৩, ৪০৪ নয় ─────────────────────────────────────────────────────
 * ৪০৪ বলত "এমন দরজা নেই" — ফোন সেটাকে অ্যাপের ভুল ভাবত, আর মানুষ ভাবতেন
 * কিছু ভেঙেছে। ৪০৩ + `reason: module_off` বলে "আছে, কিন্তু আপনার কোম্পানি
 * এখন বন্ধ রেখেছে" — অ্যাপ তখন "এই অংশটা এখন বন্ধ" লেখে, আর সিঙ্ক ওটাকে
 * ব্যর্থতা ধরে বারবার চেষ্টা করে না।
 */
final class PhoneModules
{
    /** সুইচের চাবির গোড়া — `mobile.modules.sales`। */
    public const PREFIX = 'mobile.modules.';

    /** ফোন যে কারণ পড়ে "বন্ধ" লেখে — অ্যাপের `api_client.dart`-এ একই নাম। */
    public const REASON = 'module_off';

    /**
     * ⭐ নতুন কোম্পানিতে কোনগুলো চালু — মালিকের তালিকা, ১ অক্টোবর ২০২৬।
     *
     * ⓘ এটা কেবল **ডিফল্ট**: প্রতিটা কোম্পানি কন্ট্রোল প্যানেল থেকে যেকোনোটা
     * চালু বা বন্ধ করে, আর সেই সারিটাই জেতে।
     *
     * ⭐ গ্রাহক যোগ — মালিক, ২ অক্টোবর ২০২৬: *"ha calu thakbe"*। ⚠️ আগে বাদ ছিল: তথ্য আসত
     * (বিক্রয়ের নির্ভরতা ধরে), কিন্তু ফোনে গ্রাহক আর বকেয়ার টাইল লুকানো থাকত — মালিকের নিজের
     * ফোনে "গ্রাহক আসছে না"।
     */
    public const ON_BY_DEFAULT = ['accounts', 'customer', 'inventory', 'sales', 'purchase', 'approval', 'system_admin'];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly MenuSwitches $switches,
    ) {}

    public static function key(string $code): string
    {
        return self::PREFIX.$code;
    }

    /**
     * সেটিংয়ের ঘোষণা — [[SettingsService::definitions()]] এখান থেকে নেয়।
     *
     * ⚠️ static, কারণ SettingsService নিজেই এই ক্লাসের নির্ভরতা — উল্টো দিকে
     * ইনজেক্ট করলে চক্র হত।
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitionsFor(ModuleRegistry $registry): array
    {
        $out = [];

        foreach ($registry->all() as $module) {
            $out[self::key($module->code)] = [
                'type' => 'boolean',
                'default' => in_array($module->code, self::ON_BY_DEFAULT, true),
                'module' => $module->code,
                'group' => 'mobile',
                'tab' => 'mobile',
                'label' => 'mobile.show_in_app',
                'holds' => null,
            ];
        }

        return $out;
    }

    /** এই কোম্পানির ফোনের মেনুতে মডিউলটা আছে কি না — ওয়েবে বন্ধ হলে ফোনেও বন্ধ। */
    public function isOn(string $code): bool
    {
        $module = $this->registry->get($code);

        if ($module === null) {
            return false;
        }

        if (! $module->essential && ! $this->settings->get($this->switches->forModule($code), true)) {
            return false;
        }

        return (bool) $this->settings->get(self::key($code), false);
    }

    /** @return list<string> চালু মডিউলের কোড, রেজিস্ট্রির ক্রমে */
    public function onCodes(): array
    {
        return array_values(array_filter(
            array_keys($this->registry->all()),
            fn (string $code) => $this->isOn($code),
        ));
    }

    /**
     * তথ্য পৌঁছাতে পারে কি না — নিজে চালু, নয়তো কোনো চালু মডিউল এর উপর দাঁড়ায়।
     *
     * @return list<string>
     */
    public function reachableCodes(): array
    {
        $reach = [];
        $queue = $this->onCodes();

        while ($queue !== []) {
            $code = array_shift($queue);

            if (isset($reach[$code])) {
                continue;
            }

            $reach[$code] = true;

            foreach ($this->registry->get($code)?->dependsOn ?? [] as $dependency) {
                $queue[] = (string) $dependency;
            }
        }

        return array_values(array_filter(
            array_keys($this->registry->all()),
            fn (string $code) => isset($reach[$code]),
        ));
    }

    public function isReachable(?string $code): bool
    {
        return $code !== null && in_array($code, $this->reachableCodes(), true);
    }

    /** একটা ক্লাস কোন মডিউলের — নামস্থান ধরে (`App\Modules\Sales\…` → sales)। */
    public function moduleOfClass(string $class): ?ModuleDefinition
    {
        foreach ($this->registry->all() as $module) {
            if (str_starts_with(ltrim($class, '\\'), $module->namespace.'\\')) {
                return $module;
            }
        }

        return null;
    }

    /**
     * ⛔ বন্ধ মডিউলের দরজায় ৪০৩ — JSON, কারণসহ।
     *
     * ⓘ `null` মডিউল (কোনো মডিউলের নয়, যেমন কোরের জিনিস) আটকায় না — সুইচটা
     * মডিউলের, কোরের নয়।
     */
    public function refuseUnlessReachable(?string $code): void
    {
        /* ⓘ অচেনা কোড এখানে থামে না — দরজার নিজের ৪০৪ ("এমন মডিউল নেই") বলুক */
        if ($code === null || ! $this->registry->has($code) || $this->isReachable($code)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => __('mobile.module_off'),
            'reason' => self::REASON,
            'module' => $code,
        ], 403));
    }
}
