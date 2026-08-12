<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * মেনু যে চাবি চায়, রুটও সেই চাবিই চায়।
 *
 * ── কেন এটা আলাদা একটা পাহারা ────────────────────────────────────────
 * অনুমতি দুই জায়গায় লেখা হয়: `module.php`-র মেনু সারিতে (কে সারিটা
 * দেখবে) আর কন্ট্রোলারের middleware()-এ (কে পর্দাটা খুলতে পারবে)।
 * `EveryRouteIsGuardedTest` দেখে রুটে **কোনো** চাবি আছে কি না। এটা দেখে
 * সেটা **একই** চাবি কি না।
 *
 * দুইটা আলাদা হলে দুই রকম ভুল হয়, আর দুইটাই নীরব:
 *
 *   মেনুর চাবি আছে, রুটেরটা নেই → সারিটা দেখা যায়, ক্লিক করলে ৪০৩।
 *                                   ব্যবহারকারী ভাবেন ব্যবস্থা নষ্ট।
 *   রুটের চাবি আছে, মেনুরটা নেই → সারিটা লুকানো, অথচ ঠিকানা টাইপ করলে
 *                                   পর্দা খোলে। পাহারাটা অর্ধেক।
 *
 * ── আসল ঘটনা ────────────────────────────────────────────────────────
 * ভাউচারের পাঁচটা সারি চাইত `accounts.voucher.create`, আর রুট প্রয়োগ
 * করত `accounts.report`। যে ক্যাশিয়ার ভাউচার লেখেন কিন্তু রিপোর্ট
 * দেখেন না, তিনি রোজ পাঁচটা সারি দেখতেন আর প্রতিবার ৪০৩ পেতেন।
 */
class TheMenuAsksWhatTheRouteAsksTest extends TestCase
{
    /**
     * পলিসি দিয়ে পাহারা দেওয়া রুট — এখানে তুলনা করা যায় না।
     *
     * `can:viewAny,App\Modules\Customer\Models\Customer` লেখা রুটের
     * আসল চাবিটা পলিসির ভেতরে, আর সেটা মার্কআপ দেখে জানা যায় না। ওই
     * পলিসিগুলোর নিজের পরীক্ষা আছে (প্রতিটা মডিউলের "অনুমতিহীন
     * ব্যবহারকারী কোনো পর্দায় ঢুকতে পারে না")।
     *
     * এখানে কেবল সরল `can:permission` ধরনের রুট মেলানো হয় — যেগুলোয়
     * ভুলটা হাতে লেখা হয়, আর সেখানেই হয়।
     */
    public function test_no_menu_row_asks_for_a_key_its_route_does_not(): void
    {
        $enforced = $this->permissionsByRoute();
        $mismatched = [];
        $checked = 0;

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->menu as $items) {
                foreach ($items as $item) {
                    // ঘোষিত-অথচ-অস্তিত্বহীন রুট ModuleMenuTest ধরে,
                    // planned সারিসহ। এখানে সেটা আবার দেখা হয় না।
                    if (($item['planned'] ?? false) || ! isset($enforced[$item['route']])) {
                        continue;
                    }

                    $keys = $enforced[$item['route']];

                    // পলিসি-ভিত্তিক (`can:ability,Model`) — উপরে ব্যাখ্যা।
                    if ($keys === [] || array_filter($keys, fn ($k) => str_contains($k, ','))) {
                        continue;
                    }

                    $checked++;

                    if (! in_array($item['permission'], $keys, true)
                        && ! $this->methodMentions($item['route'], $item['permission'])) {
                        $mismatched[] = sprintf(
                            '%s: %s — মেনু চায় "%s", রুট চায় "%s"',
                            $module->code,
                            $item['route'],
                            $item['permission'],
                            implode(' ', $keys),
                        );
                    }
                }
            }
        }

        $mismatched = array_values(array_unique($mismatched));
        sort($mismatched);

        $this->assertGreaterThan(20, $checked,
            'মেনুর সারিগুলোই মেলানো হয়নি — এই পরীক্ষাটা তখন কিছুই দেখছে না।');

        $this->assertSame([], $mismatched, implode("\n", [
            'এই সারিগুলো এক চাবি চায়, আর তাদের রুট আরেকটা প্রয়োগ করে।',
            'যাঁর মেনুর চাবি আছে অথচ রুটেরটা নেই, তিনি সারিটা দেখবেন আর',
            'ক্লিক করলে ৪০৩ পাবেন — নিজের কাজের পর্দায়, রোজ।',
            'দুইটার যেকোনো একটা বদলান, কিন্তু জেনে বদলান: রুট বদলালে',
            'কারও প্রবেশাধিকার যায়, মেনু বদলালে কেবল একটা অকেজো সারি যায়।',
            ...$mismatched,
        ]));
    }

    /**
     * চাবিটা কি পদ্ধতির ভেতরেই যাচাই হয়?
     *
     * ── কেন এটা লাগল ────────────────────────────────────────────────
     * সব অনুমতি middleware-এ বসে না, আর বসতেও পারে না। হিসাবের
     * রিপোর্টের রুট একটাই (`accounts.report.show`), কিন্তু ডে বুক আর
     * ব্যালেন্স শিট এক জিনিস নয় — প্রতিষ্ঠানের মুনাফা সবার জানার কথা
     * নয়। তাই `ReportController::show()` স্লাগ দেখে ভেতরেই
     * `accounts.report.final` চায়।
     *
     * এটা না দেখলে পরীক্ষাটা সঠিক কোডকে ভুল বলত — আর ঠিক সেটাই প্রথমে
     * করেছিল। middleware-এ যা নেই তা পাহারাহীন, এই ধরে নেওয়াটাই ভুল।
     */
    private function methodMentions(string $route, string $permission): bool
    {
        $action = Router::getRoutes()->getByName($route)?->getActionName();

        if ($action === null || ! str_contains($action, '@')) {
            return false;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return false;
        }

        $lines = file($file);

        if ($lines === false) {
            return false;
        }

        $body = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        // চাবিটা হুবহু, উদ্ধৃতিসহ — নাহলে `accounts.report` খুঁজতে গিয়ে
        // `accounts.report.final`-ও মিলে যেত।
        return str_contains($body, "'".$permission."'")
            || str_contains($body, '"'.$permission.'"');
    }

    /**
     * প্রতিটা নামওয়ালা রুটের `can:` চাবিগুলো।
     *
     * @return array<string, list<string>>
     */
    private function permissionsByRoute(): array
    {
        $found = [];

        foreach (Router::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $keys = [];

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $keys[] = substr($middleware, 4);
                }
            }

            /*
             * একই নামে একাধিক রুট থাকতে পারে না, কিন্তু একই রুটে
             * একাধিক `can:` থাকতে পারে (index আর show আলাদা চাবিতে
             * বাঁধা থাকলে)। তাই তালিকা, একটা মান নয়।
             */
            $found[$name] = $keys;
        }

        return $found;
    }
}
