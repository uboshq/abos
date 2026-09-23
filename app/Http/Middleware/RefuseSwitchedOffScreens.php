<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\BranchModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control Panel-এ বন্ধ করা পর্দা সত্যিই বন্ধ — মেনু থেকে সরানো নয়।
 *
 * ── কী ভেঙেছিল ──────────────────────────────────────────────────────
 * সুইচটা দেখা হত কেবল `MenuBuilder`-এ। ফলে বন্ধ করলে সারিটা মেনু থেকে
 * সরত ঠিকই, কিন্তু রুটটা খোলাই থাকত — লিংক জানা থাকলে (বুকমার্ক, আগের
 * ট্যাব, কারও পাঠানো ঠিকানা) যে কেউ ঢুকে কাজ করে ফেলতে পারতেন।
 *
 * অর্থাৎ সুইচটা ছিল আড়াল, বাধা নয়। HP-র পরীক্ষক ১৩ আগস্ট ধরেন: Direct
 * Purchase Invoice বন্ধ করার পরেও সরাসরি ঠিকানা দিয়ে পর্দাটা খুলছিল।
 *
 * ── কেন মানচিত্রটা মডিউলের ঘোষণা থেকেই তৈরি ─────────────────────────
 * প্রতিটা রুট ফাইলে আলাদা করে মিডলওয়্যার বসানো যেত। কিন্তু তাহলে
 * উনিশতম পর্দাটা লেখার দিনে কেউ ভুলত, আর ভুলটা কোনো ভুল দেখাত না —
 * পর্দাটা শুধু চুপচাপ খোলা থেকে যেত।
 *
 * `module.php`-এর মেনু সারিতে `route` আর `setting` দুইটাই আগে থেকেই
 * লেখা। তাই কোর ওই ঘোষণা পড়েই মানচিত্রটা বানায়, আর কোনো মডিউলকে
 * দ্বিতীয়বার কিছু বলতে হয় না। কোর তখনো কোনো মডিউলের নাম জানে না
 * (সেকশন ১৯.৭) — সে কেবল যা ঘোষিত তা মানে।
 *
 * ── কেন উপসর্গ ধরে, শুধু ঐ রুটটা নয় ─────────────────────────────────
 * মেনুতে থাকে `purchase.direct.create`, কিন্তু ওই পর্দার সাথে আসে
 * `purchase.direct.store`, `...preview` — এগুলো মেনুতে নেই। শুধু
 * মেনুর রুটটা আটকালে পর্দাটা খুলত না, অথচ ফর্মটা POST করলে কাজ হয়ে
 * যেত। তাই `purchase.direct.` উপসর্গের সবগুলোই আটকায়।
 *
 * প্যারামিটারসহ সারি (`inventory.report.show` + `slug=expiring`) এর
 * ব্যতিক্রম: ওখানে উপসর্গ ধরলে একই রুটের বাকি রিপোর্টগুলোও বন্ধ হয়ে
 * যেত। ওগুলো হুবহু রুট ও প্যারামিটার মিলিয়ে দেখা হয়।
 */
final class RefuseSwitchedOffScreens
{
    /**
     * কোরের নিজের রুট, কোনো মডিউলের নয়।
     *
     * বারোটা মডিউলের ড্যাশবোর্ড একই কন্ট্রোলার আঁকে, আর কোন মডিউলের
     * তা বলে ঠিকানার `{module}` অংশ। নামটা এখানে লেখা §১৯.৭ ভাঙে না —
     * এটা কোরের নিজের রুটের নাম, কোনো মডিউলের নাম নয়।
     */
    private const CORE_MODULE_DASHBOARD = 'module.dashboard';

    /** @var array<string, string>|null উপসর্গ => সুইচের কী */
    private ?array $prefixes = null;

    /** @var list<array{route: string, params: array<string, mixed>, setting: string}>|null */
    private ?array $exact = null;

    /** @var array<string, string> উপসর্গ => গ্রুপের সুইচের কী */
    private array $groupOf = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
        private readonly MenuSwitches $switches,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($name === null) {
            return $next($request);
        }

        /*
         * ⭐ শাখার সুইচ — ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ শাখা-প্রতি মডিউল বসানোর দিন ([[BranchModule]]) কেবল মেনুটাই
         * শেখানো হয়েছিল। ⚠️ অর্থাৎ ঠিক **এই ফাইলটা যে ভুলের জন্য লেখা
         * হয়েছিল** সেটাই আবার ফিরে এসেছিল, নতুন স্তরে: সারিটা মেনু থেকে
         * সরত, কিন্তু ঠিকানা জানা থাকলে পর্দাটা দিব্যি খুলত।
         *
         * ⓘ উপরের মন্তব্যেই কথাটা লেখা — *"সুইচটা ছিল আড়াল, বাধা নয়"*।
         * ⭐ তাই একই দরজায় দুইটা স্তরই দেখা হয়, একই উত্তর নিয়ে (৪০৪)।
         */
        $module = $this->moduleFor($name, $route->parameters());

        if ($module !== null && $this->switchedOffInThisBranch($module)) {
            abort(404, __('core.message.screen_switched_off'));
        }

        $setting = $this->switchFor($name, $route->parameters());

        /*
         * ৪০৪, ৪০৩ নয় — বন্ধ পর্দা এই কোম্পানির জন্য সত্যিই নেই।
         *
         * `MasterListController::spec()` আগে থেকেই ঠিক এই কাজটা এই
         * কোডেই করত, কেবল নিজের পর্দাগুলোর জন্য। দুই জায়গায় দুই কোড
         * দিলে একই ঘটনার দুই রকম উত্তর যেত, আর কোনটা সত্যি তা বলার
         * উপায় থাকত না।
         */
        if ($setting !== null && ! $this->settings->get($setting, true)) {
            abort(404, __('core.message.screen_switched_off'));
        }

        return $next($request);
    }

    /**
     * ⓘ রুটের নামটা যে মডিউলের, সেই উপসর্গসহ নাম।
     *
     * ── কোরের শেয়ার করা রুট কোন মডিউলের, তা ঠিকানা বলে ────────────
     * `module.dashboard` বারোটা মডিউলের ড্যাশবোর্ড একই কন্ট্রোলারে
     * আঁকে, তাই নামটা কোনো মডিউলের উপসর্গ বহন করে না। ⚠️ উপসর্গ ধরে
     * খোঁজা তখন কোনোদিন মিলত না, আর **বন্ধ মডিউলের ড্যাশবোর্ডও ঠিকানা
     * দিলে খুলে যেত** (৩ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ মডিউলের নামটা ঠিকানার `{module}` অংশ থেকেই আসে, তাই কোরে কোনো
     * মডিউলের নাম লেখা থাকে না (§১৯.৭)।
     *
     * @param  array<string, mixed>  $params
     */
    private function scopedName(string $name, array $params): string
    {
        return $name === self::CORE_MODULE_DASHBOARD && is_string($params['module'] ?? null)
            ? $params['module'].'.dashboard'
            : $name;
    }

    /**
     * এই রুটটা কোন মডিউলের — না বলা গেলে null।
     *
     * ⓘ [[switchFor()]]-র প্রথম লুপটাই, কেবল সুইচের কী-র বদলে মডিউলের
     * কোড ফেরায়। ⚠️ ঐ লুপটা কোম্পানির সুইচ **বন্ধ** থাকলেই কেবল উত্তর
     * দেয়, আর শাখার প্রশ্নটা তার আগেই করতে হয়।
     *
     * @param  array<string, mixed>  $params
     */
    private function moduleFor(string $name, array $params): ?string
    {
        $scoped = $this->scopedName($name, $params);

        foreach ($this->registry->all() as $module) {
            if (str_starts_with($scoped, $module->code.'.')) {
                /*
                 * ⭐ অপরিহার্য মডিউল শাখার সুইচেও বন্ধ হয় না —
                 * ২৪ সেপ্টেম্বর ২০২৬।
                 *
                 * ⓘ শাখা-প্রতি মডিউল বসানোর টেবিলে
                 * ([[BranchModule]]) যেকোনো মডিউলের কোড বসতে পারে।
                 * ⚠️ প্রশাসনের মডিউলটা ওখানে বসলে ঐ শাখার কেউ আর
                 * কন্ট্রোল প্যানেলে ঢুকতেই পারতেন না — অর্থাৎ যে
                 * তালাটা কোম্পানির সুইচে বসানো হলো, সে এখান দিয়ে
                 * পাশ কাটিয়ে যেত।
                 */
                return $module->essential ? null : $module->code;
            }
        }

        return null;
    }

    /**
     * ⛔ এই শাখায় মডিউলটা ইচ্ছা করে বন্ধ করা আছে কি।
     *
     * ── ⚠️ কেন উত্তরটা মনে রাখা হয় না ───────────────────────────────
     * মিডলওয়্যার প্রতি অনুরোধে নতুন করে তৈরি হয় বলে মনে রাখা নিরাপদ
     * *মনে হয়*। ⓘ কিন্তু ২২ সেপ্টেম্বরেই [[MenuBuilder]]-এ ঠিক ঐ
     * অনুমানটা ভুল প্রমাণ হয়েছে (Laravel-এর `Route` কন্ট্রোলার মনে
     * রাখে), আর তার দাম ছিল একটা **নীরব ভুল মেনু**।
     *
     * ⭐ একটা ছোট, ইনডেক্স করা টেবিলে একটা কোয়েরির চেয়ে বাসি উত্তরের
     * ঝুঁকিটা বড়। ⓘ শাখা বাছা না থাকলে কোয়েরিই হয় না
     * ([[BranchModule::switchedOffIn()]])।
     */
    private function switchedOffInThisBranch(string $module): bool
    {
        return in_array(
            $module,
            BranchModule::switchedOffIn(CompanyContext::branchId()),
            true
        );
    }

    /**
     * এই রুটটা কোন সুইচের পেছনে — না থাকলে null।
     *
     * @param  array<string, mixed>  $params
     */
    private function switchFor(string $name, array $params): ?string
    {
        $this->build();

        /*
         * ⓘ কারণটা [[scopedName()]]-এ একবারই লেখা — দুই কপি রাখলে একদিন
         * একটা বদলাত আর অন্যটা পুরনো কথাটাই বলে যেত।
         *
         * ⚠️ নামটা কেবল **এই দুইটা লুপের জন্য** বদলানো হয়; নিচের `exact`
         * তালিকা ঘোষিত নামেই খোঁজে, কারণ সেখানে প্যারামিটারও মেলানো হয়।
         */
        $scoped = $this->scopedName($name, $params);

        // মডিউলের নিজের সুইচ আগে: পুরো মডিউল বন্ধ থাকলে ভেতরের সারির
        // সুইচ কী বলছে তা অবান্তর (সেকশন ১৯.৫)।
        foreach ($this->registry->all() as $module) {
            /*
             * ⭐ অপরিহার্য মডিউলের সুইচটা পড়াই হয় না — ২৪ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ কন্ট্রোল প্যানেল এখন ঐ চাবিটা আর লিখতেই দেয় না, কিন্তু
             * সেটা **আজকের পর্দার** পাহারা। ⛔ যে ডাটাবেসে চাবিটা আগে
             * থেকেই `false` বসে আছে (বা কেউ সরাসরি বসিয়ে দিয়েছেন),
             * সেখানে দরজাটা বন্ধই থাকত — আর ওটাই ঠিক সেই অবস্থা যেখানে
             * খোলার কোনো পথ নেই।
             *
             * ⓘ তাই তালাটা **দুই জায়গায়**: লেখার দরজায়, আর পড়ার দরজায়।
             */
            if ($module->essential) {
                continue;
            }

            if (str_starts_with($scoped, $module->code.'.')
                && ! $this->settings->get($module->code.'.enabled', true)) {
                return $module->code.'.enabled';
            }
        }

        /*
         * তারপর গ্রুপ (সাবমডিউল) — সারির নিজের সুইচের **আগে**।
         *
         * ── কেন আগে ────────────────────────────────────────────────
         * স্তরগুলোর ক্রম মেনু আঁকার সময় যা ([[MenuSwitches::itemIsOn()]]),
         * এখানেও ঠিক তা-ই হতে হবে। উল্টো হলে গ্রুপ বন্ধ অথচ সারির নিজের
         * সুইচ চালু — এমন হলে পর্দাটা মেনু থেকে উধাও, কিন্তু ঠিকানা
         * দিলে দিব্যি খুলত। ওটাই "আড়াল, বাধা নয়" ভুলটা।
         */
        foreach ($this->groupOf as $prefix => $groupKey) {
            if (str_starts_with($scoped, $prefix) && ! $this->settings->get($groupKey, true)) {
                return $groupKey;
            }
        }

        foreach ($this->exact as $entry) {
            if ($entry['route'] === $name && $this->matches($entry['params'], $params)) {
                return $entry['setting'];
            }
        }

        /*
         * সবচেয়ে লম্বা মিল জেতে।
         *
         * একটা মডিউলে `sales.order.` আর `sales.` দুইটাই থাকলে ছোটটা
         * আগে মিলে গিয়ে ভুল সুইচ ফেরত দিত।
         */
        $best = null;

        foreach ($this->prefixes as $prefix => $setting) {
            if (str_starts_with($scoped, $prefix)
                && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $setting];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * মেনুর প্যারামিটারগুলো অনুরোধেরটার সাথে মেলে কি না।
     *
     * অনুরোধে বাড়তি প্যারামিটার থাকতে পারে; ঘোষিতগুলো মিললেই যথেষ্ট।
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, mixed>  $actual
     */
    private function matches(array $declared, array $actual): bool
    {
        foreach ($declared as $key => $value) {
            if (! array_key_exists($key, $actual) || (string) $actual[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    private function build(): void
    {
        if ($this->prefixes !== null) {
            return;
        }

        $this->prefixes = [];
        $this->exact = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->menu as $group => $items) {
                foreach ($items as $item) {
                    if (! isset($item['route'])) {
                        continue;
                    }

                    /*
                     * ── কেন প্রতিটা সারির কী এখন নিয়ম ধরে, ৩০ আগস্ট ২০২৬ ──
                     * আগে কেবল যারা `setting` ঘোষণা করেছিল তারাই এই
                     * মানচিত্রে আসত — একশোর বেশি সারির মধ্যে কয়েকটা।
                     * বাকিগুলোর সুইচই ছিল না, তাই বন্ধ করার প্রশ্নও ছিল না।
                     *
                     * এখন কী-টা [[MenuSwitches]] বানায়, তাই কন্ট্রোল
                     * প্যানেলে বন্ধ করা যেকোনো সারির ঠিকানাও ৪০৪ দেয় —
                     * নাহলে সুইচটা আড়াল হত, বাধা নয়, ঠিক যে ভুলটা
                     * ১৩ আগস্ট ধরা পড়েছিল।
                     */
                    $setting = $this->switches->forItem($item);
                    $params = $item['route_params'] ?? [];

                    /*
                     * গ্রুপের মানচিত্রটা **প্যারামিটার দেখার আগে** —
                     * নাহলে যে গ্রুপে কেবল প্যারামিটারওয়ালা সারি আছে
                     * (পাঁচ ধরনের ভাউচার) সেটা এখানে কখনো নথিভুক্তই হত
                     * না, আর তার সুইচ বন্ধ করলে ঠিকানাগুলো খোলাই থাকত।
                     */
                    $dot = strrpos($item['route'], '.');

                    /*
                     * ── গ্রুপের উপসর্গ কেবল নিজের মডিউলের হলে ──────────
                     * উপসর্গটা রুটের নাম থেকে কাটা হয়, আর কোরের শেয়ার
                     * করা রুটে (`module.dashboard` — বারোটা মডিউলের
                     * একটাই কন্ট্রোলার) সেটা দাঁড়ায় `module.`, যা কোনো
                     * মডিউলেরই নয়।
                     *
                     * ফলে তেরোটা মডিউল একই চাবিতে লিখত আর **শেষজন
                     * জিতত**: কোনো এক কোম্পানি যদি ওই একটা মডিউলের
                     * ড্যাশবোর্ড-গ্রুপ বন্ধ করত, **সব মডিউলের**
                     * ড্যাশবোর্ড ৪০৪ দিত (৩ সেপ্টেম্বর ২০২৬)।
                     *
                     * শর্তটা সাধারণ, `module.dashboard`-এর জন্য বিশেষ
                     * নয়: যে উপসর্গ মডিউলের নিজের নামে শুরু হয় না,
                     * সেটা তার গ্রুপের চাবি হতে পারে না।
                     */
                    if ($dot !== false) {
                        $prefix = substr($item['route'], 0, $dot + 1);

                        if (str_starts_with($prefix, $module->code.'.')) {
                            $this->groupOf[$prefix]
                                = $this->switches->forGroup($module->code, (string) $group);
                        }
                    }

                    if ($params !== []) {
                        $this->exact[] = [
                            'route' => $item['route'],
                            'params' => $params,
                            'setting' => $setting,
                        ];

                        continue;
                    }

                    if ($dot === false) {
                        continue;
                    }

                    $this->prefixes[substr($item['route'], 0, $dot + 1)] = $setting;
                }
            }
        }
    }
}
