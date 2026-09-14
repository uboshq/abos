<?php

declare(strict_types=1);

namespace App\Core\Engines\Search;

use App\Core\Contracts\Drillable;
use App\Core\Module\ModuleRegistry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * ⛔ সর্বজনীন খোঁজা — কোরের চতুর্দশ ইঞ্জিন, ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * টপবারে "যেকোনো কিছু খুঁজুন" বোতামটা ছিল, তার `title`-এ লেখা ছিল
 * **"(Ctrl K)"** — আর চাপলে **কিছুই হত না**। মেপে দেখা:
 *
 *     topbar.blade.php       $dispatch('open-command-center')
 *     bottom-nav.blade.php   একই ঘটনা
 *     পুরো রিপোতে শ্রোতা      ০টা
 *     Ctrl+K শ্রোতা           ০টা
 *
 * ⓘ ঘটনা পাঠানো হত, কেউ শুনত না। ⚠️ মালিক নিজে প্রশ্ন করেছেন *"search
 * box dutor dorkar ki zehetu univarsal search?"* — আর উত্তরটা ছিল:
 * **সর্বজনীন সার্চ বলে কিছু ছিলই না।**
 *
 * ── ⭐ কেন কোনো সার্চ ইঞ্জিন বসানো হয়নি (Scout · Meilisearch · Elastic)
 * সাইটটা চলে **শেয়ার্ড cPanel হোস্টিংয়ে**। ⓘ ওখানে দ্বিতীয় একটা সার্ভিস
 * চালানো যায় না; চালানো গেলেও সেটা মানে **দ্বিতীয় একটা ব্যাকআপ, দ্বিতীয়
 * একটা ব্যর্থতার জায়গা, আর দ্বিতীয় একটা সত্যের উৎস** যেটা মূল খাতার
 * সাথে বেসুরো হতে পারে।
 *
 * ⭐ এই ব্যবসার মাপে MySQL-এর `LIKE` যথেষ্ট: কয়েক হাজার সারি, আর খোঁজাটা
 * অন-ডিমান্ড — প্রতিটা কী-স্ট্রোকে নয়।
 *
 * ── ⚠️ সীমাটা লিখে রাখা হলো, যাতে পরের জন জানেন ─────────────────────
 * `LIKE '%…%'` **ইনডেক্স ব্যবহার করে না** — প্রতিটা সারি পড়া হয়। আজ
 * সেটা সস্তা। ⛔ যেদিন কোনো একটা টেবিল কয়েক লাখ সারিতে পৌঁছাবে, সেদিন
 * এটা ধীর হবে, আর তখন সারাই দুইটা:
 *   ১. MySQL-এর FULLTEXT ইনডেক্স (প্যাকেজ লাগে না, একই ডাটাবেজ)
 *   ২. তাতেও না হলে তবেই বাইরের ইঞ্জিন
 * ⓘ আগে থেকে বসানো হয়নি, কারণ **যে সমস্যা নেই তার সমাধান রাখলে সেটাই
 * পরের জনের সমস্যা হয়**।
 *
 * ── ⛔ এই ফিচারের আসল বিপদ: অনুমতি ─────────────────────────────────
 * একজন বিক্রয়কর্মী "রহিম" লিখে যদি বেতনের কাগজ পান, তাহলে গোটা
 * অনুমতি-ব্যবস্থা **একটা বাক্সে ফাঁকি দেওয়া গেল**। ⭐ তাই ছাঁকনিটা
 * কোয়েরির **আগে**: যে উৎসের পর্দা ব্যবহারকারী খুলতেই পারেন না, তার
 * টেবিল ছোঁয়াই হয় না।
 */
final class SearchEngine
{
    /**
     * ⚠️ অন্তত এতটা না লিখলে খোঁজা শুরু হয় না।
     *
     * ⓘ এক অক্ষরে প্রায় প্রতিটা সারি মেলে, অর্থাৎ ফলাফল অর্থহীন আর
     * খরচ সর্বোচ্চ — সবচেয়ে খারাপ জোড়া।
     */
    public const MIN_TERM = 2;

    /** এক উৎস থেকে সর্বোচ্চ কয়টা — নাহলে একটা বড় টেবিল বাকিদের ঢেকে দিত। */
    private const PER_SOURCE = 5;

    /**
     * ⭐ যে ঘরগুলোয় মানুষ খোঁজে।
     *
     * ⓘ গুনে দেখা (১৩ সেপ্টেম্বর ২০২৬): `document_no` ২৮টা টেবিলে,
     * `code` ১৯টায়, `name_en`/`name_bn` ১৮টায়। ⚠️ তালিকাটা ইচ্ছাকৃতভাবে
     * ছোট — টাকার অঙ্ক বা তারিখ এখানে নেই, কারণ "১২০০" লিখে খুঁজলে
     * অর্ধেক খাতা ফেরত আসত।
     *
     * @var list<string>
     */
    private const COLUMNS = ['document_no', 'code', 'name_en', 'name_bn', 'name', 'mobile', 'phone'];

    /** @var array<class-string, array{route: string, permission: ?string, columns: list<string>}>|null */
    private ?array $sources = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * খোঁজা — ব্যবহারকারী যা দেখার অধিকার রাখেন, কেবল তাই।
     *
     * @return list<SearchHit>
     */
    public function search(string $term, ?User $user, int $limit = 20): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_TERM) {
            return [];
        }

        $hits = [];

        foreach ($this->sources() as $class => $source) {
            if (count($hits) >= $limit) {
                break;
            }

            /*
             * ⛔ অনুমতি — কোয়েরির **আগে**, পরে নয়।
             *
             * ⚠️ পরে ছাঁকলে ফলাফল ফাঁস হত না ঠিকই, কিন্তু নিষিদ্ধ
             * টেবিলটা তবু পড়া হত — আর "কতগুলো মিলল" ধরনের কোনো
             * ভবিষ্যৎ সংখ্যা ঐ পথেই বেরিয়ে যেত।
             *
             * ⓘ `permission` খালি মানে রুটটার অনুমতি বের করা যায়নি।
             * ⭐ তখন উৎসটা **বাদ যায়**, দেখানো হয় না — অজানা অবস্থায়
             * দরজা বন্ধ রাখাই নিয়ম। আর যাতে এভাবে চুপচাপ সব উৎস হারিয়ে
             * না যায়, [[EveryStaffDoorIsShutInSearchTest]] গুনে দেখে
             * কয়টা উৎস সত্যিই খোঁজা হলো।
             */
            if ($user === null || ! $this->mayOpen($user, $source)) {
                continue;
            }

            foreach ($this->matchesIn($class, $source['columns'], $term) as $record) {
                /*
                 * ⛔ পলিসি-ধাঁচের উৎসে যাচাইটা **সারি ধরে**, আর সেটাই
                 * সবচেয়ে সৎ উত্তর: প্রশ্নটা তো "ইনি কি এই সারিটা দেখতে
                 * পারেন" — আর পলিসিই ঐ প্রশ্নের মালিক।
                 *
                 * ⓘ খরচ নেই: পলিসি মেমরিতে চলে, ডাটাবেজে যায় না।
                 */
                if ($source['ability'] !== null && ! $user->can($source['ability'], $record)) {
                    continue;
                }

                $hits[] = $this->hitFrom($record);

                if (count($hits) >= $limit) {
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * এই ব্যবহারকারীর জন্য কয়টা উৎস সত্যিই খোঁজা হয়।
     *
     * ⭐ পাহারার জন্য — একটা সবুজ টিককে প্রমাণ করতে হয় সে কী দেখেছে।
     * ⓘ শূন্য উৎসে "কেউ নিষিদ্ধ কিছু পায়নি" দাবিটা **শূন্যেই সত্য**।
     */
    public function sourcesVisibleTo(?User $user): int
    {
        return count(array_filter(
            $this->sources(),
            fn (array $s) => $user !== null && $this->mayOpen($user, $s),
        ));
    }

    /**
     * ⭐ এই উৎসটা কি আদৌ খোঁজা হবে — কোয়েরির আগের দরজা।
     *
     * ── ⓘ দুইটা রূপ, দুইটা উত্তর ─────────────────────────────────────
     * **সাধারণ অনুমতি** (`can:customer.view`) এখানেই যাচাই হয়, তাই
     * নিষিদ্ধ টেবিল ছোঁয়াই হয় না।
     *
     * **পলিসি** (`can:view,customer`) এখানে যাচাই করা **যায় না** — ওটার
     * উত্তর সারির উপর নির্ভর করে, আর সারি তখনো হাতে নেই। ⓘ তাই দরজাটা
     * খোলা থাকে, আর আসল ছাঁকনি বসে সারি পাওয়ার পরে ([[search()]])।
     *
     * ⚠️ এতে নিষিদ্ধ টেবিল পড়া হতে পারে, কিন্তু **ফলাফল ফাঁস হয় না** —
     * প্রতিটা সারি পলিসির মধ্য দিয়ে যায়। ⭐ আর সেটাই বেশি সৎ: পলিসি
     * সারি-স্তরের প্রশ্নের মালিক, আর মডিউলের দরজা আগেই আলাদা অনুমতিতে
     * বন্ধ।
     *
     * ⛔ দুইটার একটাও না জানা গেলে উৎসটা বাদ — অজানা অবস্থায় দরজা বন্ধ।
     *
     * @param  array{ability: ?string, permission: ?string}  $source
     */
    private function mayOpen(User $user, array $source): bool
    {
        if ($source['permission'] !== null) {
            return $user->can($source['permission']);
        }

        return $source['ability'] !== null;
    }

    /**
     * প্রতিটা খোঁজার যোগ্য মডেল — কোন রুট, কোন অনুমতি, কোন ঘর।
     *
     * ── ⓘ উৎসের তালিকা নতুন করে ঘোষণা করা হয়নি ─────────────────────
     * প্রতিটা মডিউল `module.php`-এ **`drill_sources`** আগে থেকেই ঘোষণা
     * করে — খতিয়ানের ড্রিল-ডাউনের জন্য। ⭐ সেই একই তালিকাই এখানে কাজে
     * লাগে, আর তাতে **কোনো `module.php` ছুঁতে হয়নি**।
     *
     * ⚠️ এটা কেবল সুবিধা নয়, সীমানাও: ঐ ফাইলগুলো অন্য হাতে, আর একই
     * ফাইলে দুইজন ঢুকলে কী হয় তা এই রিপো আজ দুইবার দেখেছে।
     *
     * @return array<class-string, array{route: string, permission: ?string, columns: list<string>}>
     */
    public function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $classes = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->drillSources as $class) {
                /*
                 * ⓘ একই মডেল একাধিক স্লাগে থাকতে পারে — ভাউচারের পাঁচটা
                 * ধরন একই `Voucher` ক্লাস। ⚠️ না ছাঁকলে একই টেবিলে
                 * পাঁচবার কোয়েরি যেত, আর ফলাফলে একই সারি পাঁচবার।
                 */
                $classes[$class] = true;
            }
        }

        $map = [];

        foreach (array_keys($classes) as $class) {
            $source = $this->describe($class);

            if ($source !== null) {
                $map[$class] = $source;
            }
        }

        return $this->sources = $map;
    }

    /**
     * একটা মডেল সম্পর্কে যা জানা দরকার — না জানা গেলে `null`।
     *
     * @param  class-string  $class
     * @return array{route: string, permission: ?string, columns: list<string>}|null
     */
    private function describe(string $class): ?array
    {
        if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Drillable::class)) {
            return null;
        }

        $model = new $class;

        /*
         * ⓘ খালি মডেলে `drillRoute()` — রুটের **নামটা** স্থির, কেবল
         * প্যারামিটারে `$this->id` বসে (যা এখানে null)। নামটাই দরকার।
         *
         * ⚠️ `try` — কোনো বাস্তবায়ন যদি সম্পর্ক ছুঁয়ে ফেলে, সেটা যেন
         * গোটা সার্চ ফেলে না দেয়। ⭐ ব্যর্থ হলে উৎসটা বাদ যায়, আর সেটা
         * পাহারার গোনায় ধরা পড়ে।
         */
        try {
            $route = $model->drillRoute()[0] ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($route) || $route === '') {
            return null;
        }

        $columns = array_values(array_intersect(self::COLUMNS, $this->columnsOf($model->getTable())));

        if ($columns === []) {
            return null;
        }

        return [
            'route' => $route,
            'columns' => $columns,
            ...$this->permissionFor($route),
        ];
    }

    /**
     * ঐ রুটটা খুলতে কোন অনুমতি লাগে — না বলা থাকলে `null`।
     *
     * ── ⭐ কেন রুট থেকে, মডিউলের নাম থেকে নয় ────────────────────────
     * প্রথম ভাবনা ছিল মডিউলের `<code>.view` ধরে ছাঁকা। ⛔ মেপে দেখা গেল
     * ছাঁচটা **সব জায়গায় এক নয়** — `accounts.view` আছে, কিন্তু
     * `finance.view`, `hr.view`, `purchase.view`, `sales.view` নেই।
     * ⚠️ অর্থাৎ নাম ধরে অনুমান করলে অর্ধেক মডিউলে ছাঁকনিটা **নীরবে
     * কিছুই ছাঁকত না**।
     *
     * ⭐ রুটের নিজের `can:` মিডলওয়্যারটাই একমাত্র সৎ উৎস, আর
     * [[EveryRouteIsGuardedTest]] আলাদাভাবে প্রমাণ করে প্রতিটা রুটে
     * ওটা আছে। অর্থাৎ এই ইঞ্জিন নতুন কোনো দাবি করছে না — যেটা ইতিমধ্যে
     * প্রমাণিত, সেটাই পড়ছে।
     */
    /**
     * @return array{ability: ?string, permission: ?string}
     */
    private function permissionFor(string $routeName): array
    {
        $nothing = ['ability' => null, 'permission' => null];

        $route = Route::getRoutes()->getByName($routeName);

        if ($route === null) {
            return $nothing;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                /*
                 * ⛔ `can:` দুই রকম, আর পার্থক্যটা গোনা হয়েছে
                 * (১৪ সেপ্টেম্বর ২০২৬): **৫২০টা সাধারণ, ১৩২টা পলিসি**।
                 *
                 *     can:customer.view          ← অনুমতির নাম
                 *     can:view,customer          ← পলিসির ক্ষমতা + সারি
                 *
                 * ⚠️ প্রথম খসড়ায় এখানে `explode(',', …)[0]` ছিল, অর্থাৎ
                 * দ্বিতীয় রূপ থেকে আসত `view` — যা কোনো অনুমতির নাম নয়।
                 * `$user->can('view')` সবসময় **মিথ্যা** ফেরাত, তাই ঐ
                 * উৎসগুলো চুপচাপ বাদ পড়ত।
                 *
                 * ⛔ আর ঠিক ওগুলোতেই সব ডেটা: গ্রাহক, পণ্য, সরবরাহকারী,
                 * হিসাব খাত, বিক্রয় বিল। ⓘ মেপে দেখা গেছে খোঁজায় যে
                 * ১৮টা উৎস টিকত তার প্রায় সবই **খালি টেবিল** — তাই
                 * সার্চ কাজ করছে মনে হত, শুধু কিছুই পাওয়া যেত না।
                 *
                 * ⭐ তাই কমা থাকলে সেটা পলিসি, আর পলিসি **সারি ছাড়া
                 * যাচাই করা যায় না** — ওটা ফেরত যায় `ability` হয়ে, আর
                 * যাচাই হয় সারি হাতে পাওয়ার পরে।
                 */
                $clause = substr($middleware, 4);

                return str_contains($clause, ',')
                    ? ['ability' => explode(',', $clause)[0], 'permission' => null]
                    : ['ability' => null, 'permission' => $clause];
            }
        }

        return $nothing;
    }

    /**
     * একটা টেবিলে কোন কলামগুলো আছে।
     *
     * ── ⚠️ কেন ক্যাশ, আর ক্যাশটা কখন ভুল বলে ────────────────────────
     * ⓘ `Schema::getColumnListing()` প্রতিবার ডাটাবেজকে জিজ্ঞেস করে।
     * ২৮টা উৎসে সেটা **প্রতিটা খোঁজায় ২৮টা বাড়তি কোয়েরি** — অর্থাৎ
     * সার্চটা কাজ করত আর সাইট ধীর হত, আর কেউ দুইটাকে জুড়ত না।
     *
     * ⭐ পুরো মানচিত্রটা **একটা** ক্যাশ-সারিতে, তাই খরচ এক পড়া।
     *
     * ⛔ দাম: কোনো টেবিলে নতুন করে `code` বা `name_bn` কলাম যোগ করলে
     * সার্চ সেটা **একদিন পর** দেখবে, বা `php artisan cache:clear`-এর
     * পরে। ⓘ কথাটা এখানে লেখা রইল, কারণ ওই দিনটায় লক্ষণটা হবে "সার্চে
     * নতুন ঘরটা আসছে না" — আর সেটা ইঞ্জিনের দোষ মনে হত।
     *
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        $map = Cache::remember(
            'search.columns',
            now()->addDay(),
            function (): array {
                $all = [];

                foreach ($this->tables() as $name) {
                    $all[$name] = Schema::getColumnListing($name);
                }

                return $all;
            },
        );

        return $map[$table] ?? [];
    }

    /**
     * যে টেবিলগুলোর কলাম জানা দরকার।
     *
     * ⓘ আলাদা পদ্ধতি, কারণ উপরের ক্যাশটা **সব টেবিল একসাথে** ভরে —
     * প্রতিটা টেবিলের জন্য আলাদা সারি হলে আবার ২৮টা পড়া হত।
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->drillSources as $class) {
                if (is_subclass_of($class, Model::class)) {
                    $tables[(new $class)->getTable()] = true;
                }
            }
        }

        return array_keys($tables);
    }

    /**
     * এক উৎসে মেলানো।
     *
     * ⓘ কোম্পানির ছাঁকনি এখানে হাতে বসানো নেই, আর বসানোও হবে না:
     * মডেলগুলো `BelongsToCompany`-র গ্লোবাল স্কোপ পায়, তাই `query()`
     * নিজেই চলতি কোম্পানিতে সীমিত। ⚠️ তবু ধরে নেওয়া হয়নি — দাবিটা
     * টেস্টে আলাদা করে লেখা।
     *
     * @param  class-string<Model>  $class
     * @param  list<string>  $columns
     * @return iterable<Model>
     */
    private function matchesIn(string $class, array $columns, string $term): iterable
    {
        /*
         * ⚠️ `%` আর `_` — LIKE-এর নিজের জাদুকরী অক্ষর।
         *
         * ⓘ না পালালে কেউ `%` লিখে **গোটা টেবিলটাই** ফেরত পেতেন, আর
         * `_` যেকোনো এক অক্ষরে মিলত। ⛔ ফাঁস নয়, কিন্তু অর্থহীন ফল আর
         * সর্বোচ্চ খরচ। [[AuditController]]-এ হুবহু এই পালানোটাই আছে।
         */
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $class::query()
            /*
             * ── সম্পর্কগুলো আগে থেকেই, ১৪ সেপ্টেম্বর ২০২৬ ──────────────
             * ⛔ `hitFrom()` প্রতিটা সারির `drillLabel()` ডাকে, আর কোনো
             * কোনো মডেলের লেবেল সম্পর্ক ছোঁয় — `Location::path()` উপরের
             * খাত ধরে ধরে উঠে যায়।
             *
             * ⚠️ আগে থেকে না তুললে সেটা lazy load, আর উন্নয়ন পরিবেশে
             * ওটা **ব্যতিক্রম**: খোঁজায় "ra" লিখলেই ৫০০ আসত। ⓘ চালু
             * সার্ভারে ব্যতিক্রম হত না, হত N+1 — অর্থাৎ ভুলটা ওখানে
             * ধরাই পড়ত না, কেবল ধীর হত।
             *
             * ⓘ মডেল নিজে বলে তার কী লাগে; না বললে কিছুই তোলা হয় না।
             */
            ->when(method_exists($class, 'drillRelations'), fn ($q) => $q->with($class::drillRelations()))
            ->where(function ($query) use ($columns, $like) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', $like);
                }
            })
            ->limit(self::PER_SOURCE)
            ->get();
    }

    /** একটা সারিকে ফলাফলে রূপ দেওয়া — চারটা ঘরই `Drillable` থেকে। */
    private function hitFrom(Model&Drillable $record): SearchHit
    {
        [$name, $params] = $record->drillRoute() + [1 => []];

        /*
         * ⛔ ধরনটা অনুবাদ করে, কাঁচা স্লাগ নয় — ১৪ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ `drillSourceType()` ফেরায় যন্ত্রের স্লাগ (`customer`,
         * `account`, `warehouse`) — মানুষের নাম নয়। ⓘ প্রথম খসড়ায়
         * ওটাই সরাসরি পর্দায় যেত, আর ফলাফলের তালিকা দেখাত
         * "customer · CUS-0001 · রহিম ট্রেডার্স"।
         *
         * ⭐ নামগুলো `core.source.*`-এ আছে, দুই ভাষায় — ৪৮টার ৪৮টাই
         * (৩৩টা এই কাজেই বসানো হয়েছে, কারণ আগে ছিল মাত্র ১৫টা)।
         *
         * ⓘ `Lang::has()` দিয়ে দেখা হয়, কারণ `__()` অনুবাদ না পেলে
         * **চাবিটাই** ফেরত দেয় — আর তখন পর্দায় `core.source.xyz` বসত,
         * যা কাঁচা স্লাগের চেয়েও খারাপ। ⚠️ না পেলে স্লাগটাই থাক:
         * অপরিচিত শব্দ, কিন্তু অন্তত শব্দ।
         */
        $slug = $record::drillSourceType();
        $key = 'core.source.'.$slug;

        return new SearchHit(
            slug: $slug,
            type: Lang::has($key) ? __($key) : $slug,
            documentNo: $record->drillDocumentNo(),
            label: $record->drillLabel(),
            url: route($name, $params),
        );
    }
}
