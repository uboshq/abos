<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * নীতিতে লেখা প্রতিটা নিয়ম সত্যিই কোথাও না কোথাও ডাকা হয়।
 *
 * ── কেন এই পাহারাটা দরকার ────────────────────────────────────────────
 * ২ সেপ্টেম্বর ২০২৬-এ একটা নিরীক্ষা বলেছিল *"২১টা নীতি, অথচ
 * `authorize()` ডাকা হয় মাত্র ৭ জায়গায় — নীতিগুলো মৃত।"* মেপে দেখা
 * গেল কথাটা **ভুল**: ২০টা রিসোর্স কন্ট্রোলার [[AuthorizesResource]]
 * দিয়ে সাতটা পদ্ধতিতেই `can:{ability},{model}` বসায়, অর্থাৎ নীতিগুলো
 * প্রতিটা অনুরোধে চলে। `$this->authorize(` গুনে প্রশ্নটাই ভুল করা
 * হয়েছিল।
 *
 * **কিন্তু একটা নিয়ম সত্যিই মৃত ছিল** — `CustomerPolicy::overrideCreditLimit()`।
 * একই সিদ্ধান্ত তিন জায়গায় লেখা ছিল, আর যে দুইটা চলত তারা নীতিটা
 * ছুঁতই না। তার মধ্যে একটা **ভুল চাবি** ধরেছিল: ক্রয়াদেশে
 * `sales.discount.override`, অথচ ধারের সীমার নিজের চাবি আলাদা।
 *
 * ── অর্থাৎ আসল প্রশ্নটা "কতবার ডাকা হয়" নয় ───────────────────────────
 * প্রশ্নটা: **প্রতিটা লেখা নিয়ম কি কোনো পথে পৌঁছায়?** একটা মৃত নিয়ম
 * নীরবে বিপজ্জনক — পরেরজন সেটা পড়ে বিশ্বাস করেন যে ওটাই পাহারা,
 * অথচ আসল সিদ্ধান্ত অন্য কোথাও, অন্যভাবে নেওয়া হচ্ছে।
 *
 * ── কেন রুট টেবিল পড়া হয়, উৎস grep করা নয় ────────────────────────────
 * `viewAny`/`view`/`update` — এই নামগুলো উৎসের কোথাও লেখা নেই।
 * [[AuthorizesResource]] সেগুলো **চলার সময়** বানায়। তাই grep করলে
 * প্রতিটা CRUD নিয়ম "পৌঁছায়" দেখাত কেবল ওই এক ফাইলের কারণে — সবুজ,
 * অথচ কিছুই মাপা হয়নি।
 *
 * নিবন্ধিত রুটগুলোর মিডলওয়্যার পড়লে উত্তরটা **যা সত্যি তাই** হয়:
 * কোন ক্ষমতা কোন লক্ষ্যের উপর সত্যিই চাওয়া হচ্ছে।
 */
class EveryPolicyRuleIsActuallyReachedTest extends TestCase
{
    /**
     * যেসব নিয়ম ইচ্ছাকৃতভাবে কোনো পথে নেই — আর কেন।
     *
     * ── কেন তালিকাটা এখানে, নীতির ফাইলে একটা মন্তব্যে নয় ─────────────
     * নীতির ফাইলে লিখলে সেটা লেখা মাত্রই ভুলে যাওয়া যেত। এখানে লিখতে
     * হলে সিদ্ধান্তটা একবার অন্তত ভাবতে হয়, আর পরেরজন সব ব্যতিক্রম
     * এক জায়গায় পড়তে পারেন।
     *
     * @var array<string, string> 'ShortPolicyName::ability' => কারণ
     */
    private const UNREACHED_ON_PURPOSE = [];

    /**
     * Laravel-এর নিজের নিয়ম, নীতিলেখকের নয়।
     *
     * `before()` প্রতিটা প্রশ্নের আগে চলে আর কোনো ক্ষমতার নাম নয়;
     * `deny`/`allow` সহায়ক। এগুলোকে "পৌঁছায়নি" বলা অর্থহীন হত।
     *
     * @var list<string>
     */
    private const NOT_ABILITIES = ['before', 'allow', 'deny', '__construct'];

    public function test_every_rule_written_in_a_policy_is_reached_by_some_path(): void
    {
        $policies = $this->policyClasses();

        $this->assertNotSame([], $policies, implode(PHP_EOL, [
            'একটাও নীতি খুঁজে পাওয়া গেল না।',
            '',
            'তাহলে এই পাহারাটা কিছুই দেখছে না — আর সবুজ থাকছে ঠিক সেই',
            'কারণে যেটা সবচেয়ে খারাপ: দেখার মতো কিছু নেই বলে।',
        ]));

        $reachedByRoute = $this->abilitiesTheRouteTableAsksFor();
        $reachedInSource = $this->abilitiesNamedInSource();

        $unreached = [];

        foreach ($policies as $policy) {
            $model = $this->modelFor($policy);
            $short = class_basename($policy);

            foreach ($this->abilitiesOf($policy) as $ability) {
                if (array_key_exists($short.'::'.$ability, self::UNREACHED_ON_PURPOSE)) {
                    continue;
                }

                $viaRoute = in_array($ability, $reachedByRoute[$model] ?? [], true);

                if ($viaRoute || in_array($ability, $reachedInSource, true)) {
                    continue;
                }

                $unreached[] = $short.'::'.$ability.'()';
            }
        }

        $this->assertSame([], $unreached, implode(PHP_EOL, [
            'এই নিয়মগুলো লেখা আছে, কিন্তু কোনো পথে পৌঁছায় না:',
            '',
            implode(PHP_EOL, $unreached),
            '',
            'অর্থাৎ কেউ ওগুলো পড়ে বিশ্বাস করবেন যে এটাই পাহারা, অথচ',
            'সিদ্ধান্তটা অন্য কোথাও অন্যভাবে নেওয়া হচ্ছে — বা কোথাও',
            'নেওয়াই হচ্ছে না।',
            '',
            'হয় নিয়মটা যেখানে দরকার সেখানে ডাকুন (Gate::allows / @can /',
            'authorize), নয় এই ফাইলের UNREACHED_ON_PURPOSE তালিকায়',
            'কারণসহ লিখুন।',
        ]));
    }

    /**
     * প্রতিটা নীতির অন্তত একটা নিয়ম রুট টেবিল থেকেই পৌঁছায়।
     *
     * ── কেন এটা আলাদা করে দেখা হয় ───────────────────────────────────
     * উপরের পরীক্ষাটা উৎসে নামটা খুঁজে পেলেই সন্তুষ্ট। কিন্তু নামগুলো
     * সাধারণ — `view`, `update`, `create`। এক মডিউলের একটা `@can('view'`
     * অন্য মডিউলের নীতিকেও "পৌঁছেছে" দেখিয়ে দিত।
     *
     * রুট টেবিলে লক্ষ্যটা **মডেলের শ্রেণিনাম ধরে** মেলে, তাই এখানে
     * ভুল করার জায়গা নেই। একটা নীতির কোনো নিয়মই যদি কোনো রুট থেকে
     * না চাওয়া হয়, তবে ওই নীতিটা কার্যত অনাথ।
     */
    public function test_no_policy_is_an_orphan(): void
    {
        $reachedByRoute = $this->abilitiesTheRouteTableAsksFor();

        $orphans = [];

        foreach ($this->policyClasses() as $policy) {
            if (($reachedByRoute[$this->modelFor($policy)] ?? []) === []) {
                $orphans[] = class_basename($policy);
            }
        }

        $this->assertSame([], $orphans, implode(PHP_EOL, [
            'এই নীতিগুলোর কোনো নিয়মই কোনো রুট থেকে চাওয়া হয় না:',
            '',
            implode(PHP_EOL, $orphans),
            '',
            'কন্ট্রোলারটা সম্ভবত resourcePermissions() ব্যবহার করে না,',
            'অথবা মডেলের নাম আর নীতির নাম আর মেলে না।',
        ]));
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * সব নীতি ক্লাস।
     *
     * পথ ধরে বাছা হয়, নাম ধরে নয়: `ContentSecurityPolicy` নামেও
     * "Policy", কিন্তু ওটা মিডলওয়্যার — `app/Http/Middleware/`-এ থাকে
     * আর কোনো ক্ষমতার প্রশ্নের উত্তর দেয় না।
     *
     * @return list<class-string>
     */
    private function policyClasses(): array
    {
        $classes = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! $file->isFile() || ! str_contains($path, '/Policies/') || ! str_ends_with($path, 'Policy.php')) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr(
                $path,
                strpos($path, '/app/') + 5,
                -4,
            ));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * নীতি → মডেল।
     *
     * `App\Modules\Sales\Policies\SalesInvoicePolicy`
     *   → `App\Modules\Sales\Models\SalesInvoice`
     *
     * @param  class-string  $policy
     * @return class-string|string
     */
    private function modelFor(string $policy): string
    {
        return str_replace('\\Policies\\', '\\Models\\', substr($policy, 0, -6));
    }

    /**
     * একটা নীতির ঘোষিত ক্ষমতাগুলো।
     *
     * @param  class-string  $policy
     * @return list<string>
     */
    private function abilitiesOf(string $policy): array
    {
        $abilities = [];

        foreach ((new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $policy) {
                continue;
            }

            if (in_array($method->getName(), self::NOT_ABILITIES, true)) {
                continue;
            }

            $abilities[] = $method->getName();
        }

        return $abilities;
    }

    /**
     * রুট টেবিল সত্যিই কোন মডেলের উপর কোন ক্ষমতা চায়।
     *
     * ── লক্ষ্য দুই রকম, আর সেটাই এখানকার একমাত্র জটিলতা ───────────────
     * `index`/`create`/`store`-এ তখনো কোনো রেকর্ড নেই, তাই লক্ষ্যটা
     * **মডেলের শ্রেণিনাম**। বাকি চারটায় লক্ষ্যটা **রুট প্যারামিটারের
     * নাম** (`invoice`, `till`, `challan`) — কারণ ওখানে একটা নির্দিষ্ট
     * রেকর্ড আছে।
     *
     * দ্বিতীয়টা মডেলে ফেরাতে রুটের বাঁধা মডেলটা দেখা হয়: Laravel-এর
     * নিজের বাইন্ডিং তথ্য, আমাদের অনুমান নয়।
     *
     * @return array<string, list<string>> মডেল => ক্ষমতার তালিকা
     */
    private function abilitiesTheRouteTableAsksFor(): array
    {
        $asked = [];

        foreach (Route::getRoutes() as $route) {
            $bindings = $this->modelsBoundTo($route);

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $parts = explode(',', substr($middleware, 4), 2);
                $ability = trim($parts[0]);
                $target = trim($parts[1] ?? '');

                if ($target === '') {
                    continue;
                }

                $model = class_exists($target) ? $target : ($bindings[$target] ?? null);

                if ($model === null) {
                    continue;
                }

                $asked[$model][] = $ability;
            }
        }

        return array_map(fn (array $a): array => array_values(array_unique($a)), $asked);
    }

    /**
     * একটা রুটের প্যারামিটারগুলো কোন মডেলে বাঁধা।
     *
     * কন্ট্রোলারের পদ্ধতির টাইপ-হিন্ট থেকে পড়া হয় — ওটাই Laravel
     * নিজেও ব্যবহার করে, তাই উত্তরটা অনুমান নয়।
     *
     * @return array<string, class-string>
     */
    private function modelsBoundTo(\Illuminate\Routing\Route $route): array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [];
        }

        [$controller, $method] = explode('@', $action, 2);

        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            return [];
        }

        $bound = [];

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $bound[$parameter->getName()] = $type->getName();
            }
        }

        return $bound;
    }

    /**
     * উৎসে যে ক্ষমতার নামগুলো স্পষ্ট করে ডাকা হয়েছে।
     *
     * CRUD-বহির্ভূত নিয়মগুলো (`overrideCreditLimit`, `managePortal`,
     * `confirm`) রুট টেবিলে থাকে না — সেগুলো সেবা স্তরে বা ব্লেডে
     * ডাকা হয়। তাই দুইটা উৎস মিলিয়ে দেখা হয়।
     *
     * @return list<string>
     */
    private function abilitiesNamedInSource(): array
    {
        $found = [];

        $patterns = [
            "/Gate::(?:allows|denies|authorize|any)\(\s*'([a-zA-Z]+)'/",
            "/->(?:can|cannot|cant)\(\s*'([a-zA-Z]+)'\s*,/",
            "/\\\$this->authorize\(\s*'([a-zA-Z]+)'/",
            "/@(?:can|cannot)\(\s*'([a-zA-Z]+)'\s*,/",
        ];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            /*
             * নীতির ফাইলগুলো নিজেরাই বাদ।
             *
             * না বাদ দিলে একটা নীতি নিজের নাম নিজেই "পৌঁছেছে" প্রমাণ
             * করত, আর পাহারাটা সবসময় সবুজ থাকত।
             */
            if (str_contains($path, '/Policies/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $source, $matches) > 0) {
                    foreach ($matches[1] as $ability) {
                        $found[] = $ability;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
