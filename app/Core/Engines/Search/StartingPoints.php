<?php

declare(strict_types=1);

namespace App\Core\Engines\Search;

use App\Core\Contracts\Drillable;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use App\Models\RecentPaper;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐ খোঁজার বাক্স খালি থাকলে কী দেখাবে — সাম্প্রতিক কাগজ আর প্রস্তাবিত কাজ।
 *
 * ── ⓘ ডিজাইন-চেকলিস্ট, ধাপ ৭ · ৫ (২ অক্টোবর ২০২৬) ─────────────────────
 * *"Ctrl+K খালি অবস্থায় সাম্প্রতিক কাগজ আর প্রস্তাবিত কাজ"*। ⚠️ এতদিন
 * Ctrl+K খুললে লেখা থাকত কেবল "খুঁজতে লিখুন" — অর্থাৎ যে কাগজটা পাঁচ মিনিট
 * আগে খোলা ছিল, সেটাতে ফিরতেও তার নম্বর মনে রাখতে হত।
 *
 * ── ⛔ নতুন কোনো তালিকা হাতে লেখা হয়নি ─────────────────────────────────
 * কোন কাগজ "খোলা" গোনা হবে, সেটা মডিউলগুলো আগেই বলে রেখেছে: `drill_sources`
 * আর প্রতিটা মডেলের `drillRoute()`। ⭐ যে মডেলের `drillRoute()` তার নিজের
 * আইডি দিয়ে একটা `.show` পাতা দেখায়, সেই পাতা খোলাই "কাগজ খোলা"
 * ([[papersByRoute()]])। ⓘ নতুন মডিউল নিজের কাগজ ঘোষণা করলেই এখানে আসে।
 *
 * কাজের তালিকাও তাই: মেনুর `.create` সারি, আর প্রতিটা কাগজের পাশের
 * `.create` রুট — অনুমতি রুটের নিজের `can:` থেকে, সুইচ
 * [[RefuseSwitchedOffScreens::refuses()]] থেকে ([[actions()]])।
 *
 * ── ⛔ দেয়াল ─────────────────────────────────────────────────────────────
 * সারিতে কেবল ঠিকানা থাকে ([[RecentPaper]])। ⭐ দেখানোর আগে প্রতিটা কাগজ
 * আজকের দেয়াল দিয়ে আবার তোলা হয়: কোম্পানি আর শাখার স্কোপ (মডেলের গ্লোবাল
 * স্কোপ), রুটের অনুমতি বা পলিসি ([[SearchEngine::maySee()]]), আর পর্দার
 * সুইচ। ⚠️ গতকাল খোলা যেত, আজ চাবি কাড়া হয়েছে — তাহলে আজ তালিকায় নেই।
 */
final class StartingPoints
{
    /** খালি বাক্সে সর্বোচ্চ কয়টা সাম্প্রতিক কাগজ */
    public const RECENT = 8;

    /** খালি বাক্সে সর্বোচ্চ কয়টা কাজ */
    public const ACTIONS = 8;

    /**
     * ⓘ মানুষ-প্রতি কোম্পানি-প্রতি কয়টা সারি রাখা হয়।
     *
     * ⚠️ দেখানো হয় ৮টা, রাখা হয় ৩০টা — কারণ কিছু সারি দেখানোর সময় দেয়ালে
     * আটকায় (চাবি গেছে, শাখা বদলেছে), আর তখনও তালিকাটা ভরা থাকা উচিত।
     */
    private const KEEP = 30;

    /** ⓘ খালি মডেলে বসানো আইডি — `drillRoute()` সেটা নিজের প্যারামিটারে ফেরায় কি না দেখতে */
    private const PROBE = 987654321;

    /**
     * রুটের নাম → [মডেলের শ্রেণি, রুটের প্যারামিটারের নাম]।
     *
     * ⓘ স্থির, কারণ উত্তরটা কেবল কোড থেকে আসে — ডাটা বা মানুষ থেকে নয়।
     *
     * @var array<string, array{0: class-string<Model&Drillable>, 1: string}>|null
     */
    private static ?array $byRoute = null;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly SearchEngine $search,
    ) {}

    /**
     * ⭐ একটা পাতা খোলা হলো — সেটা কি একটা কাগজ? হলে মনে রাখা।
     *
     * ⛔ কেবল সফল GET (২০০)। ⚠️ ৪০৩ বা ৪০৪ মনে রাখলে যে কাগজ খোলাই যায়নি
     * তার সারি বসত — দেখানোর সময় দেয়ালে আটকাত ঠিকই, কিন্তু সারিটা নিজেই
     * একটা মিথ্যা দাবি।
     *
     * ⓘ পিকও গোনা হয় ([[App\Core\Support\Peek]]): পপআপে খোলা কাগজও খোলা কাগজ।
     */
    public function remember(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200 || $request->expectsJson()) {
            return;
        }

        $user = $request->user();
        $route = $request->route();

        if (! $user instanceof User || ! $route instanceof RouteObject) {
            return;
        }

        $name = (string) $route->getName();

        if (! str_ends_with($name, '.show')) {
            return;
        }

        $paper = $this->papersByRoute()[$name] ?? null;
        $companyId = CompanyContext::id();

        if ($paper === null || $companyId === null) {
            return;
        }

        [$class, $param] = $paper;

        $value = $route->parameter($param);

        if ($value instanceof Model && ! $value instanceof $class) {
            return;
        }

        $id = $value instanceof Model ? $value->getKey() : $value;

        if (! is_numeric($id)) {
            return;
        }

        $keys = [
            'user_id' => $user->id,
            'company_id' => $companyId,
            'paper_type' => $class,
            'paper_id' => (int) $id,
        ];

        /*
         * ⓘ একই মুহূর্তে দুইটা ট্যাবে একই কাগজ — দ্বিতীয়টা একক-সূচিতে ধাক্কা
         * খায়। ⭐ তখন সারিটা আছেই, কেবল সময়টা বসানো বাকি।
         */
        try {
            RecentPaper::query()->updateOrCreate($keys, ['opened_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            RecentPaper::query()->where($keys)->update(['opened_at' => now()]);
        }

        $stale = RecentPaper::query()
            ->where('user_id', $user->id)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->skip(self::KEEP)
            ->take(100)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            RecentPaper::query()->whereKey($stale->all())->delete();
        }
    }

    /**
     * ⭐ এই মানুষ এই কোম্পানিতে শেষ যে কাগজগুলো খুলেছেন — যা আজও খুলতে পারেন।
     *
     * @return list<array{type: string, no: string, label: string, url: string}>
     */
    public function recent(User $user): array
    {
        if (CompanyContext::id() === null) {
            return [];
        }

        $rows = RecentPaper::query()
            ->where('user_id', $user->id)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::KEEP)
            ->get(['paper_type', 'paper_id']);

        /* ⛔ কেবল যে শ্রেণিগুলো আজও কাগজ হিসেবে ঘোষিত — বাকিগুলো ছোঁয়াই হয় না */
        $known = [];

        foreach ($this->papersByRoute() as [$class]) {
            $known[$class] = true;
        }

        $ids = [];

        foreach ($rows as $row) {
            if (isset($known[$row->paper_type])) {
                $ids[$row->paper_type][] = $row->paper_id;
            }
        }

        /*
         * ⭐ আজকের দেয়াল দিয়ে আবার তোলা — মডেলের গ্লোবাল স্কোপ কোম্পানি আর
         * শাখা দুইটাই ছাঁকে, ঠিক যেভাবে পাতাটার রুট-বাঁধাই ছাঁকে। ⚠️ যা আসে
         * না, পাতাটাও ৪০৪ দিত — তাই তালিকাতেও নেই।
         */
        $found = [];

        foreach ($ids as $class => $list) {
            $found[$class] = $class::query()
                ->when(method_exists($class, 'drillRelations'), fn ($q) => $q->with($class::drillRelations()))
                ->whereKey(array_values(array_unique($list)))
                ->get()
                ->keyBy(fn (Model $m) => (int) $m->getKey())
                ->all();
        }

        $switches = app(RefuseSwitchedOffScreens::class);
        $out = [];

        foreach ($rows as $row) {
            $record = $found[$row->paper_type][$row->paper_id] ?? null;

            if (! $record instanceof Drillable) {
                continue;
            }

            /* ⛔ রুটের অনুমতি বা পলিসি — খোঁজার একই প্রশ্ন, একই উত্তর */
            if (! $this->search->maySee($user, $record)) {
                continue;
            }

            [$name, $params] = $record->drillRoute() + [1 => []];

            /* ⛔ পর্দাটা এই কোম্পানি বা শাখায় বন্ধ — চাপলে ৪০৪ হত */
            if ($switches->refuses((string) $name, (array) $params)) {
                continue;
            }

            $hit = $this->search->hitFor($record);

            $out[] = [
                'type' => $hit->type,
                'no' => $hit->documentNo,
                'label' => $hit->label,
                'url' => $hit->url,
            ];

            if (count($out) >= self::RECENT) {
                break;
            }
        }

        return $out;
    }

    /**
     * ⭐ প্রস্তাবিত কাজ — এই মানুষ যা বানাতে পারেন।
     *
     * ── ⓘ দুইটা উৎস, কোনোটাই হাতে লেখা নয় ──────────────────────────────
     * ১. মেনুর `.create` সারি ([[MenuBuilder::forUser()]]) — অনুমতি, তিন স্তরের
     *    সুইচ আর শাখার মডিউল সেখানেই মাপা। নাম মেনুর নিজের ("সরাসরি বিক্রয়")।
     * ২. প্রতিটা ঘোষিত কাগজের পাশের `.create` রুট — "নতুন বিক্রয় বিল"।
     *    অনুমতি রুটের নিজের `can:` থেকে; ⛔ না জানা গেলে বাদ।
     *
     * ⓘ ক্রম: আগে মেনুর সারি, তারপর যে ধরনের কাগজ মানুষটা সম্প্রতি খুলেছেন,
     * তারপর বাকিগুলো মডিউলের ক্রমে।
     *
     * @param  list<array{type: string, no: string, label: string, url: string}>  $recent
     * @return list<array{type: string, no: string, label: string, url: string}>
     */
    public function actions(User $user, array $recent = []): array
    {
        $out = [];
        $seen = [];

        foreach (app(MenuBuilder::class)->forUser($user) as $module) {
            foreach ($module['groups'] as $items) {
                foreach ($items as $item) {
                    $url = $item['url'] ?? null;

                    if ($url === null || ($item['planned'] ?? false) || ! str_ends_with((string) $item['route'], '.create')) {
                        continue;
                    }

                    if (isset($seen[$url])) {
                        continue;
                    }

                    $seen[$url] = true;
                    $out[] = ['type' => (string) $module['label'], 'no' => '', 'label' => (string) $item['label'], 'url' => $url];
                }
            }
        }

        $recentTypes = array_flip(array_column($recent, 'type'));
        $first = [];
        $rest = [];

        foreach ($this->creatable($user) as $action) {
            if (isset($seen[$action['url']])) {
                continue;
            }

            $seen[$action['url']] = true;

            if (isset($recentTypes[$action['type']])) {
                $first[] = $action;
            } else {
                $rest[] = $action;
            }
        }

        return array_slice([...$out, ...$first, ...$rest], 0, self::ACTIONS);
    }

    /**
     * প্রতিটা ঘোষিত কাগজের "নতুন" পাতা — যেটা এই মানুষ খুলতে পারেন।
     *
     * @return list<array{type: string, no: string, label: string, url: string}>
     */
    private function creatable(User $user): array
    {
        $switches = app(RefuseSwitchedOffScreens::class);
        $out = [];

        foreach ($this->papersByRoute() as $show => [$class]) {
            $name = substr($show, 0, -strlen('.show')).'.create';
            $route = Route::getRoutes()->getByName($name);

            /* ⛔ প্যারামিটার-ওয়ালা "নতুন" পাতা (কোনো কাগজের ভিতরের) এখানে নয় */
            if ($route === null || ! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                continue;
            }

            if (! $this->mayCreate($user, $route, $class)) {
                continue;
            }

            if ($switches->refuses($name, [])) {
                continue;
            }

            /* ⚠️ কাগজের নাম অনুবাদে না থাকলে সারিটাই বাদ — পর্দায় কাঁচা স্লাগ নয় */
            $key = 'core.source.'.$class::drillSourceType();

            if (! Lang::has($key)) {
                continue;
            }

            $type = __($key);

            $out[] = [
                'type' => $type,
                'no' => '',
                'label' => __('core.search.new_paper', ['paper' => $type]),
                'url' => route($name),
            ];
        }

        return $out;
    }

    /**
     * ⛔ রুটের নিজের `can:` — অনুমতির নাম, বা `create` পলিসি। অন্য কিছু হলে না।
     *
     * @param  class-string  $class
     */
    private function mayCreate(User $user, RouteObject $route, string $class): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $clause = substr($middleware, 4);

            if (! str_contains($clause, ',')) {
                return $user->can($clause);
            }

            [$ability, $subject] = explode(',', $clause, 2);

            /* ⓘ `can:create,App\…\Model` — শ্রেণি ধরে পলিসি; অচেনা রূপে দরজা বন্ধ */
            return $ability === 'create' && class_exists($subject) && $user->can('create', $subject);
        }

        return false;
    }

    /**
     * ⭐ কোন `.show` রুট কোন কাগজের — মডিউলের ঘোষণা থেকে।
     *
     * ⓘ খালি মডেলে একটা চেনা আইডি বসিয়ে `drillRoute()` ডাকা হয়: যে মডেল
     * নিজের আইডিটাই একমাত্র প্যারামিটার হিসেবে ফেরায় আর রুটটা `.show`, সে
     * একটা কাগজের পাতা। ⚠️ ব্যাচ পণ্যের পাতা দেখায় (`product_id`), খরচ-কেন্দ্র
     * তালিকা দেখায় — ওরা এখানে আসে না, কারণ ঐ পাতা খোলা মানে ব্যাচ বা
     * কেন্দ্র খোলা নয়।
     *
     * @return array<string, array{0: class-string<Model&Drillable>, 1: string}>
     */
    public function papersByRoute(): array
    {
        if (self::$byRoute !== null) {
            return self::$byRoute;
        }

        $map = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->drillSources as $class) {
                if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Drillable::class)) {
                    continue;
                }

                try {
                    $probe = new $class;
                    $probe->setAttribute($probe->getKeyName(), self::PROBE);
                    [$name, $params] = $probe->drillRoute() + [1 => []];
                } catch (\Throwable) {
                    continue;
                }

                if (! is_string($name) || ! str_ends_with($name, '.show') || ! is_array($params) || count($params) !== 1) {
                    continue;
                }

                /*
                 * ⛔ ঘোষিত রুটটা সত্যিই নিবন্ধিত কি না — ২ অক্টোবর ২০২৬, মেপে ধরা।
                 * ⚠️ `Unit::drillRoute()` বলে `master_data.unit.show`, অথচ ঐ নামে
                 * রুট নেই। ⓘ না ছাঁকলে খালি বাক্সের গোটা উত্তরটাই ৫০০ হত, একটা
                 * সারির জন্য।
                 */
                if (! Route::has($name)) {
                    continue;
                }

                $param = array_key_first($params);

                if (! is_string($param) || $params[$param] !== self::PROBE) {
                    continue;
                }

                $map[$name] ??= [$class, $param];
            }
        }

        return self::$byRoute = $map;
    }
}
