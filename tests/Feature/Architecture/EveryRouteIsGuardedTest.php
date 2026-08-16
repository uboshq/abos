<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;
use Tests\TestCase;

/**
 * প্রতিটা রুটে চাবি — প্ল্যান WP-0.2, §৯.৪-এর তিন নম্বর।
 *
 * ── কেন এই পাহারাটা লাগল ─────────────────────────────────────────────
 * অনুমতি দুই জায়গায় লেখা হয়: মেনুর সারিতে (কে সারিটা দেখবে) আর রুটে
 * (কে পর্দাটা খুলতে পারবে)। প্রথমটা ভুলে গেলে সারিটা সবার কাছে দেখা
 * যায় — চোখে পড়ে। **দ্বিতীয়টা ভুলে গেলে কিচ্ছু দেখা যায় না**, কারণ
 * মেনু ঠিকই লুকানো থাকে; শুধু ঠিকানাটা টাইপ করলেই পর্দা খুলে যায়।
 *
 * এটা কাল্পনিক নয়। এই টেস্ট লেখার সময়ই ধরা পড়ল মজুদ ইস্যুর পর্দা
 * (`inventory.stock.issue`) কোনো `only:` তালিকায় ছিল না — অর্থাৎ **যে
 * কেউ লগইন করলেই গুদাম থেকে মাল বের করে দিতে পারতেন**, আর সেটা সোজা
 * খতিয়ানে বসত উপহার বা মালিকের উত্তোলন হিসেবে।
 *
 * ── পাহারা দুই রকম, আর দুইটাই বৈধ ────────────────────────────────────
 * রুটে `can:` মিডলওয়্যার, অথবা পদ্ধতির ভেতরে `$this->authorize(...)`।
 * দ্বিতীয়টা লাগে যখন অনুমতিটা রেকর্ড দেখে ঠিক হয় — যেমন সংযুক্তি:
 * কোন ডকুমেন্টের কাগজ, সেটা না জেনে বলা যায় না কার অনুমতি লাগবে।
 * তাই টেস্টটা দুইটাই মানে, আর ভেতরের সহায়ক পদ্ধতিতে ডাকা হলেও ধরে।
 */
class EveryRouteIsGuardedTest extends TestCase
{
    /**
     * যেগুলোয় লগইনই লাগে না — আর কেন।
     *
     * তালিকাটা ছোট রাখা ইচ্ছাকৃত। প্রতিটা সারি একটা সিদ্ধান্ত, আর
     * সিদ্ধান্তের কারণ পাশেই লেখা থাকে।
     */
    private const OPEN_TO_THE_WORLD = [
        'up' => 'স্বাস্থ্য পরীক্ষা — লগইন থাকার আগেই উত্তর দিতে হয়, আর ডেটার কিছুই বলে না',
        'login' => 'দরজাটাই',
        'login.store' => 'দরজাটাই; এখানে চাবির বদলে throttle',
    ];

    /**
     * লগইন লাগে, কিন্তু আলাদা অনুমতি নয় — আর কেন।
     *
     * সবগুলোরই একটা সাধারণ বৈশিষ্ট্য: এগুলো **ব্যবহারকারীর নিজের**
     * জিনিস নিয়ে কাজ করে, কোম্পানির ডেটা নিয়ে নয়। নিজের ছবি, নিজের
     * ভাষা, নিজের থিম। এগুলোয় অনুমতি বসালে প্রতিটা নতুন কর্মীকে
     * "নিজের প্রোফাইল দেখার" চাবি আলাদা করে দিতে হত।
     */
    private const ANY_SIGNED_IN_USER = [
        'dashboard' => 'হোম পর্দা — সংখ্যাগুলো নিজেরাই অনুমতি দেখে ছাঁকা হয় (DashboardRegistry)',
        'logout' => 'বেরোনোর দরজা — ঢুকতে পারলে বেরোতেও পারতে হবে',
        'profile' => 'নিজের প্রোফাইল',
        'profile.update' => 'নিজের প্রোফাইল',
        'profile.avatar' => 'নিজের ছবি',
        'profile.avatar.remove' => 'নিজের ছবি',
        'appearance' => 'নিজের চেহারা-পছন্দ',
        'appearance.save' => 'নিজের চেহারা-পছন্দ',
        'theme.switch' => 'নিজের থিম',
        'locale.switch' => 'নিজের ভাষা',
        'company.switch' => 'কোন কোম্পানিতে ঢুকবেন — তালিকাটা নিজেই তাঁর নিজের কোম্পানিগুলোয় সীমিত',
        'branch.switch' => 'কোন শাখা — একই কারণ',
        'components' => 'নকশার নমুনা পাতা, কোনো ডেটা নেই',

        /*
         * নিজের খোলা লগইনগুলো — প্রোফাইল বা চেহারার মতোই নিজের জিনিস।
         *
         * ── কেন চাবির পেছনে নয় ──────────────────────────────────────
         * "আমি কোথায় কোথায় লগইন আছি" প্রশাসনিক প্রশ্ন নয়। চাবি লাগালে
         * যাঁর সবচেয়ে বেশি দরকার — যে কর্মী কাউন্টারে লগইন রেখে চলে
         * এসেছেন — তিনিই পৌঁছাতে পারতেন না, আর ওই খোলা ব্রাউজারটা
         * সারারাত খোলাই থাকত।
         *
         * নিরাপত্তাটা অনুমতিতে নয়, কোয়েরিতে: তিনটা রুটই কেবল
         * `user_id = নিজে` সারিগুলো ছোঁয়। অন্য কারও সেশনের id বসিয়ে
         * দিলেও কিছু হয় না, আর সেটার নিজের পরীক্ষা আছে
         * (`test_you_cannot_end_somebody_elses_session`)।
         */
        'governance.session.index' => 'নিজের খোলা লগইনগুলো — কোয়েরিই নিজের সারিতে সীমিত',
        'governance.session.destroy' => 'নিজের একটা লগইন বন্ধ — user_id ধরে বাঁধা',
        'governance.session.others' => 'নিজের বাকি লগইনগুলো বন্ধ — user_id ধরে বাঁধা',
    ];

    /**
     * লগইন ছাড়া কোনো পর্দা খোলে না।
     *
     * ছাড় পাওয়া তিনটা ছাড়া প্রতিটা রুটে `auth` থাকতেই হবে। নতুন রুট
     * লেখার সময় ভুলে গেলে এখানেই ধরা পড়বে — প্রথম ব্যবহারকারীর হাতে নয়।
     */
    public function test_no_screen_opens_without_signing_in(): void
    {
        $open = [];

        foreach ($this->ourRoutes() as $route) {
            $name = $route->getName() ?? $route->uri();

            if (array_key_exists($name, self::OPEN_TO_THE_WORLD)) {
                continue;
            }

            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                $open[] = $name.'  ['.$route->methods()[0].' '.$route->uri().']';
            }
        }

        sort($open);

        $this->assertSame([], $open, implode("\n", [
            'এই রুটগুলো লগইন ছাড়াই খোলে। ইচ্ছাকৃত হলে OPEN_TO_THE_WORLD-এ',
            'কারণসহ লিখুন — নাহলে auth মিডলওয়্যার যোগ করুন।',
            ...$open,
        ]));
    }

    /**
     * লগইন করলেই সব খুলে যায় না — প্রতিটা পর্দার নিজের চাবি আছে।
     */
    public function test_every_screen_asks_for_a_permission(): void
    {
        $unguarded = [];

        foreach ($this->ourRoutes() as $route) {
            $name = $route->getName() ?? $route->uri();

            if (array_key_exists($name, self::OPEN_TO_THE_WORLD)
                || array_key_exists($name, self::ANY_SIGNED_IN_USER)) {
                continue;
            }

            if ($this->hasCanMiddleware($route) || $this->authorizesItself($route)) {
                continue;
            }

            $unguarded[] = $name.'  ['.$route->methods()[0].' '.$route->uri().']';
        }

        sort($unguarded);

        $this->assertSame([], $unguarded, implode("\n", [
            'এই রুটগুলোয় কোনো অনুমতি লাগে না — লগইন করা যে কেউ খুলতে পারবেন।',
            'মেনুতে সারিটা লুকানো থাকলেও ঠিকানা টাইপ করলেই পর্দা খোলে।',
            'হয় কন্ট্রোলারের middleware()-এ can: বসান, নয় পদ্ধতিতে $this->authorize(),',
            'নয় ANY_SIGNED_IN_USER-এ কারণসহ লিখুন।',
            ...$unguarded,
        ]));
    }

    /**
     * রুট যে চাবি চায়, কোনো মডিউল সেটা ঘোষণা করেছে।
     *
     * ── কেন এটা আলাদা করে দেখা দরকার ────────────────────────────────
     * অঘোষিত চাবি কাউকে কোনোদিন দেওয়া হয় না — `PermissionSyncer`
     * মডিউলের ঘোষণা থেকেই রোল ভরে। তাই একটা টাইপো (`purchase.bil.view`)
     * মানে পর্দাটা **সবার জন্য চিরকাল বন্ধ, মালিকসহ**, আর কোথাও কোনো
     * ভুলের বার্তা নেই — শুধু ৪০৩।
     *
     * WP-0.6 ঠিক এই কারণেই লাগত: নতুন মডিউলের অনুমতি তৈরি হয়েও কোনো
     * রোলে যেত না, আর মালিক প্রতিটা পর্দায় ৪০৩ পাচ্ছিলেন।
     *
     * আজ একটাও নেই। পাহারাটা বসানো যাতে কালও না থাকে।
     */
    public function test_every_permission_a_route_demands_is_declared_by_a_module(): void
    {
        $declared = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                $declared[$permission] = true;
            }
        }

        $unknown = [];

        foreach ($this->ourRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $ability = substr($middleware, 4);

                /*
                 * `can:viewAny,App\...\Customer` — পলিসি, অনুমতির নাম নয়।
                 * পলিসির ভেতরের চাবিটা এখান থেকে দেখা যায় না, আর
                 * প্রতিটা মডিউলের নিজের অনুমতি-পরীক্ষা ওটা ধরে।
                 */
                if (str_contains($ability, ',') || isset($declared[$ability])) {
                    continue;
                }

                $unknown[] = ($route->getName() ?? $route->uri()).' → '.$ability;
            }
        }

        $unknown = array_values(array_unique($unknown));
        sort($unknown);

        $this->assertNotEmpty($declared, 'একটাও ঘোষিত অনুমতি পাওয়া যায়নি।');

        $this->assertSame([], $unknown, implode("\n", [
            'এই রুটগুলো এমন চাবি চায় যা কোনো মডিউল ঘোষণা করেনি।',
            'অঘোষিত চাবি কোনো রোলে বসে না, তাই পর্দাটা সবার জন্য বন্ধ —',
            'মালিকসহ — আর কোথাও কিছু বলা হয় না, শুধু ৪০৩।',
            ...$unknown,
        ]));
    }

    /**
     * ছাড়ের তালিকা দুইটা যেন মরা নাম বয়ে না বেড়ায়।
     *
     * রুটটা মুছে ফেলার পর নামটা তালিকায় থেকে গেলে সেটা নীরবে একটা
     * ভবিষ্যতের ছাড় হয়ে বসে থাকত — কেউ ওই নামে নতুন রুট লিখলে সেটা
     * বিনা পাহারায় পাশ করে যেত।
     */
    public function test_the_exemption_lists_name_only_routes_that_exist(): void
    {
        $known = [];

        foreach ($this->ourRoutes() as $route) {
            $known[$route->getName() ?? $route->uri()] = true;
        }

        $stale = array_values(array_diff(
            [...array_keys(self::OPEN_TO_THE_WORLD), ...array_keys(self::ANY_SIGNED_IN_USER)],
            array_keys($known),
        ));

        $this->assertSame([], $stale,
            'ছাড়ের তালিকায় এমন রুটের নাম আছে যা আর নেই — মুছে দিন: '
            .implode(', ', $stale));
    }

    /**
     * আমাদের নিজের রুটগুলো — ফ্রেমওয়ার্কের ভেতরের কারিগরি রুট বাদে।
     *
     * Livewire নিজের সম্পদ ও আপলোডের রুট বসায়; ওগুলোর পাহারা তার
     * নিজের দায়, আর ওগুলোয় আমাদের কোনো কোড চলে না।
     *
     * @return list<Route>
     */
    private function ourRoutes(): array
    {
        $ours = [];

        foreach (Router::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'livewire')) {
                continue;
            }

            $ours[] = $route;
        }

        /*
         * রুট সত্যিই লোড হয়েছে কি না — নাহলে পুরো পাহারাটাই মিথ্যা।
         *
         * উপরের দুইটা পরীক্ষাই "খারাপ কিছু পাওয়া গেল না" দেখে পাশ করে।
         * মডিউলের রুট ফাইলগুলো কোনো কারণে লোড না হলে তালিকাটা খালি
         * আসত, আর তখন **দুইটাই পাশ করত — অথচ কিছুই দেখা হয়নি**। একটা
         * পরীক্ষা যেটা জিনিসটা অনুপস্থিত থাকলেও পাশ করে, সেটা পরীক্ষা নয়।
         *
         * সংখ্যাটা আলগা করে ধরা (১০০), কারণ প্রশ্নটা "কয়টা রুট আছে" নয়,
         * "রুটগুলো আদৌ লোড হয়েছে কি না"। শক্ত সংখ্যা ধরলে প্রতিটা নতুন
         * পর্দায় এই টেস্টটা ভাঙত — অগ্রগতিতে ভাঙা টেস্ট কেউ রাখে না,
         * আর তখন পাহারাটাই মুছে ফেলা হত।
         */
        $this->assertGreaterThan(100, count($ours),
            'রুট লোডই হয়নি — এই ফাইলের পাহারাগুলো তখন কিছুই দেখছে না।');

        return $ours;
    }

    private function hasCanMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * পদ্ধতিটা নিজেই অনুমতি দেখে কি না।
     *
     * ── কেন এক ধাপ ভেতরেও দেখা হয় ───────────────────────────────────
     * সংযুক্তির তিনটা পদ্ধতিই `authorizeAttaching()` নামের একটা নিজস্ব
     * সহায়ক ডাকে, আর `authorize()` ডাকা হয় সেটার ভেতরে। শুধু পদ্ধতির
     * শরীর দেখলে তিনটাই "বিনা পাহারায়" বলে ধরা পড়ত — অথচ পাহারা
     * আছে, আর এক জায়গায় থাকাটাই ঠিক।
     *
     * এক ধাপেই থামা: দুই ধাপ খুঁজলে টেস্টটা এমন কিছু "প্রমাণ" করতে
     * শুরু করত যা সে আসলে বোঝে না।
     */
    private function authorizesItself(Route $route): bool
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        $body = $this->bodyOf($class, $method);

        if ($body === null) {
            return false;
        }

        if (preg_match('/\bauthorize\w*\s*\(/', $body) === 1) {
            return true;
        }

        preg_match_all('/\$this->(\w+)\s*\(/', $body, $calls);

        foreach ($calls[1] as $helper) {
            $inner = $this->bodyOf($class, $helper);

            if ($inner !== null && preg_match('/\bauthorize\w*\s*\(/', $inner) === 1) {
                return true;
            }
        }

        return false;
    }

    private function bodyOf(string $class, string $method): ?string
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
