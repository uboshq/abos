<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Security\MfaService;
use App\Http\Middleware\SuperAdminMustHaveTwoSteps;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * ⭐ অ্যাপের ভিতরে ওয়েবের পর্দা — নিয়মগুলো এক জায়গায় (মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei
 * korte pare"*)।
 *
 * ── কী হয় ────────────────────────────────────────────────────────────────
 * ফোনের যে পর্দা অ্যাপে নেই, তার জন্য অ্যাপ নিজের ভিতরে ওয়েবের আসল পর্দাটাই খোলে। ঢোকার পথ একবারের চাবি
 * ([[WebHandoffApiController]] → [[WebHandoffController]]); মানুষটা ওয়েবে ঠিক যা পান, অ্যাপের ভিতরেও তাই — তাঁর চাবি,
 * তাঁর কোম্পানি ও শাখা, তাঁর ডিলারের দেয়াল, কারণ প্রতিটা পর্দা নিজের পাহারা নিজে দেখে।
 *
 * ── ⛔ এখানে যা বাঁধা ─────────────────────────────────────────────────────
 *   ⭐ কোম্পানির সুইচ `mobile.web_inside_app` — চালু থাকে; বন্ধ করলে চাবি মেলে না।
 *   ⭐ কোথায় যাওয়া যায় — কেবল এই সাইটের ভিতরের, লগইন-চাওয়া ওয়েব পর্দা ([[target()]]); বাইরের ঠিকানা নয় (open redirect)।
 *   ⭐ দুই ধাপ — ওয়েবের লগইন যে কোড চায়, এখানেও সেটা পেরোনো চাই ([[secondStepFresh()]])।
 *   ⭐ SR-এর ফোনে মজুদ নয় (মালিক, ১ অক্টোবর ২০২৬) — মজুদ দেখার চাবি না থাকলে মজুদ-দেখানো পর্দা অ্যাপের ভিতরে খোলে না
 *     ([[hidesFrom()]], [[InAppHidesStock]])।
 */
final class WebInsideApp
{
    public const SETTING = 'mobile.web_inside_app';

    /** সেশনের দাগ — এই ওয়েব-সেশন অ্যাপের ভিতরে খোলা ([[WebHandoffController]] বসায়) */
    public const SESSION = 'abos.in_app';

    /** চাবি কত সেকেন্ড বাঁচে */
    public const TTL_SECONDS = 60;

    /**
     * দুই ধাপ একবার পেরোলে এই হ্যান্ডসেটে কত দিন মানা হয় — ফোনের নবায়ন-টোকেনের মেয়াদের সমান ([[AuthController]])।
     * ⓘ তার পরে আবার কোড চাওয়া হয়; দুই ধাপ নতুন করে চালু হলে আগের পেরোনোও আর চলে না।
     */
    public const SECOND_STEP_DAYS = 30;

    /**
     * মজুদ দেখায় এমন পর্দা — রুটের নাম ধরে ([[Str::is()]])। ⛔ মজুদ দেখার চাবি (`inventory.stock.view`) যাঁর নেই, অ্যাপের
     * ভিতরে তাঁর জন্য এগুলো মেনুতেও আসে না, খোলেও না।
     *
     * ⓘ ইচ্ছা করেই চওড়া: গোটা মজুদ মডিউল, নামে "stock" থাকা যেকোনো পর্দা, আর বিক্রি/ক্রয়ের যে ফর্মগুলো লট ও হাতে-থাকা
     * দেখায় (কাউন্টার, POS, আদেশ/চালানের ফর্ম, ডিপোর খোঁজ, লটের খোঁজ)। ⚠️ এদের কাজের ফোন-পর্দা আলাদা আছে, যা মজুদ লুকায়।
     *
     * @var list<string>
     */
    public const STOCK_SCREENS = [
        'inventory.*',
        '*stock*',
        'sales.pos.*',
        'sales.direct.*',
        'sales.depot_check*',
        'sales.lot.*',
        'sales.order.create',
        'sales.order.edit',
        'sales.challan.create',
        'sales.challan.edit',
        'sales.delivery_order.create',
        'purchase.direct.*',
    ];

    /**
     * যে পর্দায় চাবি দিয়ে পাঠানো যায় না — ঢোকা/বেরোনো আর চাবির দরজা নিজেই।
     *
     * @var list<string>
     */
    private const NEVER = [
        'login', 'login.*', 'logout', 'password.*', 'web_handoff',
        'system_admin.setup.*', 'sales.portal.*', 'paper.shared', 'profile.email.confirm',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PhoneModules $phone,
        private readonly MfaService $mfa,
    ) {}

    /**
     * কন্ট্রোল প্যানেলের "মোবাইল অ্যাপ" ট্যাবে সুইচটা ([[SettingsService::definitions()]])।
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::SETTING => [
                'type' => 'boolean',
                'default' => true,
                'module' => 'system_admin',
                'group' => 'mobile',
                'tab' => 'mobile',
                'label' => 'mobile.web_inside_app',
                'holds' => null,
                // ⓘ নিরাপত্তার সুইচ — ফোনের বাকি দুই নিরাপত্তা-সুইচের মতোই কেবল সুপার অ্যাডমিন বদলান
                'super_admin_only' => true,
            ],
        ];
    }

    /** চলতি কোম্পানিতে অ্যাপের ভিতরে ওয়েব খোলা চালু কি না */
    public function isOn(): bool
    {
        return (bool) $this->settings->get(self::SETTING, true);
    }

    /**
     * এই রুট এই মানুষের জন্য অ্যাপের ভিতরে মজুদ দেখানোর কারণে বন্ধ কি না।
     */
    public function hidesFrom(User $user, string $routeName): bool
    {
        if ($routeName === '' || $user->can('inventory.stock.view')) {
            return false;
        }

        foreach (self::STOCK_SCREENS as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `/me`-র একটা মেনু-সারির ওয়েব-ঠিকানা (সাইটের ভিতরের পথ), অথবা null — অ্যাপের ভিতরে খোলা যায় না।
     *
     * @param  array<string, mixed>  $row  [[MenuBuilder::forUser()]]-এর সারি
     */
    public function menuPath(User $user, array $row): ?string
    {
        if (($row['planned'] ?? false) === true || ! is_string($row['url'] ?? null) || ! $this->isOn()) {
            return null;
        }

        if ($this->hidesFrom($user, (string) ($row['route'] ?? ''))) {
            return null;
        }

        $path = (string) parse_url($row['url'], PHP_URL_PATH);
        $query = parse_url($row['url'], PHP_URL_QUERY);

        return $this->target(($path === '' ? '/' : $path).(is_string($query) && $query !== '' ? '?'.$query : ''), $user);
    }

    /**
     * চাওয়া পথটা নিরাপদ গন্তব্য কি না — হলে পথটাই, নাহলে null।
     *
     * ⛔ open redirect বন্ধ: কেবল `/`-দিয়ে শুরু, `//` বা `\` নয়, কোনো scheme/host নয়, কোনো ফাঁকা বা নিয়ন্ত্রণ-অক্ষর নয়;
     * আর পথটা এই সাইটের একটা **লগইন-চাওয়া ওয়েব-রুটে** মিলতে হবে (GET)। ঢোকা/বেরোনোর দরজা, মজুদ-লুকানো পর্দা আর ফোনে
     * বন্ধ মডিউলের পর্দাও নয়।
     */
    public function target(mixed $path, User $user): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 512) {
            return null;
        }

        if ($path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1 || str_contains($path, '@')) {
            return null;
        }

        $parts = parse_url($path);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])
            || isset($parts['user']) || isset($parts['fragment'])) {
            return null;
        }

        $route = $this->routeFor($path);

        if ($route === null) {
            return null;
        }

        $middleware = $route->gatherMiddleware();
        $name = (string) $route->getName();

        if (! in_array('web', $middleware, true) || array_intersect(['auth', 'auth:web'], $middleware) === [] || $name === '') {
            return null;
        }

        foreach (self::NEVER as $pattern) {
            if (Str::is($pattern, $name)) {
                return null;
            }
        }

        if ($this->hidesFrom($user, $name)) {
            return null;
        }

        /*
         * ⓘ রুটের নিজের চাবি (`can:…`) যাঁর নেই, তাঁকে চাবিই নয় — পর্দাটা এমনিতেও ৪০৩ দিত, কিন্তু একটা অচল জানালা খোলার
         * চেয়ে আগেই "খোলা যায় না" বলা ভালো, আর হিসাবরক্ষক প্রশাসনের পর্দার ঠিকানা আন্দাজ করে চাবিও পান না। পলিসির
         * (`can:x,Model`) চাবি পর্দা নিজে দেখে।
         */
        foreach ($middleware as $guard) {
            if (is_string($guard) && str_starts_with($guard, 'can:') && ! str_contains($guard, ',')
                && ! $user->can(substr($guard, 4))) {
                return null;
            }
        }

        // ⓘ কোম্পানি ফোনে যে মডিউল বন্ধ রেখেছে, তার পর্দা অ্যাপের ভিতরেও নয় — মেনুর নিয়মের সাথে এক ([[MeController]])
        $class = $route->getControllerClass();
        $module = is_string($class) && $class !== '' ? $this->phone->moduleOfClass($class) : null;

        if ($module !== null && ! $this->phone->isOn($module->code)) {
            return null;
        }

        return $path;
    }

    /**
     * দুই ধাপের নিয়ম পূরণ কি না, এই হ্যান্ডসেটে।
     *
     *   দুই ধাপ চালু নেই        → হ্যাঁ (ওয়েবের লগইনও কোড চায় না) — তবে বাধ্যতামূলক অথচ বসানো নেই হলে [[twoStepMissing()]]
     *   চালু                      → হ্যান্ডসেটটা কোড পেরিয়েছে দুই ধাপ চালুর **পরে**, আর [[SECOND_STEP_DAYS]]-এর মধ্যে
     */
    public function secondStepFresh(User $user, SyncDevice $device): bool
    {
        if (! $this->mfa->isOn($user)) {
            return true;
        }

        $passed = $device->second_step_at;

        if ($passed === null || $passed->lt(now()->subDays(self::SECOND_STEP_DAYS))) {
            return false;
        }

        return $user->mfa_confirmed_at === null || $passed->gte($user->mfa_confirmed_at);
    }

    /** দুই ধাপ বাধ্যতামূলক, অথচ বসানো নেই — ওয়েব তাঁকে বসানোর পর্দায় আটকায়, তাই চাবিও নয় */
    public function twoStepMissing(User $user): bool
    {
        return SuperAdminMustHaveTwoSteps::isRequiredFor($user) && ! $this->mfa->isOn($user);
    }

    /**
     * তালা আর খাতার নাম — মানুষটার ইমেইল, ছোট হাতে ([[LoginLock]] যেভাবে মেলায়)।
     * ⓘ ভুল কোড এই নামে গোনা হয়, তাই চাবির দরজায় কোড আন্দাজ করলে ওয়েবের তালাই পড়ে।
     */
    public static function identifierOf(User $user): string
    {
        return mb_strtolower(trim((string) ($user->email ?: $user->login_id ?: $user->public_id)));
    }

    /** খাতায় দরজার নাম — পোর্টালের `portal:`-এর মতো */
    public static function journalName(?User $user): string
    {
        return 'app-web:'.($user === null ? '-' : self::identifierOf($user));
    }

    private function routeFor(string $path): ?RoutingRoute
    {
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            // ⓘ ৪০৪/৪০৫ — এই সাইটে এমন পর্দা নেই
            return null;
        }

        return $route instanceof RoutingRoute && in_array('GET', $route->methods(), true) ? $route : null;
    }
}
