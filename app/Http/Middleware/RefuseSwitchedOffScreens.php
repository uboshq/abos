<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
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
     * এই রুটটা কোন সুইচের পেছনে — না থাকলে null।
     *
     * @param  array<string, mixed>  $params
     */
    private function switchFor(string $name, array $params): ?string
    {
        $this->build();

        // মডিউলের নিজের সুইচ আগে: পুরো মডিউল বন্ধ থাকলে ভেতরের সারির
        // সুইচ কী বলছে তা অবান্তর (সেকশন ১৯.৫)।
        foreach ($this->registry->all() as $module) {
            if (str_starts_with($name, $module->code.'.')
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
            if (str_starts_with($name, $prefix) && ! $this->settings->get($groupKey, true)) {
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
            if (str_starts_with($name, $prefix)
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

                    if ($dot !== false) {
                        $this->groupOf[substr($item['route'], 0, $dot + 1)]
                            = $this->switches->forGroup($module->code, (string) $group);
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
