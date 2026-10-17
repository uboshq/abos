<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportFilters;
use App\Core\Engines\Report\ReportResult;
use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\DataScope;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * তেত্রিশটা রিপোর্ট, দুইটা দরজা — চুক্তি §৯।
 *
 * ── ⭐ রিপোর্ট চালায় [[ReportEngine]], এই ফাইল নয় ─────────────────────
 * ওয়েবের রিপোর্ট-পর্দা ([[SalesReportController]] ও তার ভাইয়েরা) যেভাবে
 * ডাকে, হুবহু সেভাবেই: `requestKeys()` দিয়ে ঠিকানার ঘর, `run()` দিয়ে
 * ফল, `columnsFor($user)` দিয়ে কলাম। ⛔ এখানে একটাও কোয়েরি লেখা নেই;
 * লিখলে একদিন ফোন আর ওয়েব একই রিপোর্টে দুই অঙ্ক বলত।
 *
 * ── ⛔ কে চালাতে পারেন — `ReportDefinition::$permission`, আর কিছু নয় ───
 * চুক্তি বলে তালিকা ছাঁকা হয় সংজ্ঞার `permission` ধরে। ⚠️ সংজ্ঞার নিজের
 * কথায় null মানে **"জানি না"**, "সবার জন্য খোলা" নয়। ⓘ তাই null হলে
 * রিপোর্টটা ফোনে আসেই না — কারও জন্যই না।
 *
 * ⚠️ কেন ওয়েবের কন্ট্রোলারের চাবি ধার করা হয়নি: ওয়েবের নিয়ম তিন রকম
 * জায়গায় ছড়ানো — মিডলওয়্যারের `can:`, স্লাগ-প্রতি `authorize()`
 * (ক্রয়ের নিষ্পত্তি), আর `show()`-এর ভিতরের `abort_unless` (লাভ-ক্ষতির
 * `accounts.report.final`)। ⛔ এখানে ওগুলোর নকল বসালে তৃতীয় একটা তালিকা
 * হত, যেটা একদিন ওয়েবের থেকে সরে যেত — আর সরে গেলে ফোন দেখাত লাভ-ক্ষতি,
 * ওয়েব যা এই মানুষকে দেখায় না। ⭐ ঠিক জায়গা সংজ্ঞা নিজেই, আর নির্ধারিত
 * রিপোর্টও ([[ScheduledReportRunner]]) ওখান থেকেই পড়ে।
 *
 * ── ⛔ বন্ধ মডিউলের রিপোর্ট ফোনেও বন্ধ ────────────────────────────────
 * ওয়েবে [[RefuseSwitchedOffScreens]] কোম্পানির আর শাখার মডিউল-সুইচ
 * দেখে ৪০৪ দেয়। ⓘ এই দরজা ওয়েবের রুট নয়, তাই ঐ মিডলওয়্যার এখানে চলে
 * না — আর না দেখলে বিক্রয় বন্ধ করা কোম্পানির বিক্রয়ের রিপোর্ট ফোনে দিব্যি
 * খুলত। ⚠️ মেনু-সারির নিজের সুইচটা মেলানো যায় না (সারিগুলো স্লাগ ধরে,
 * রিপোর্টের কী ধরে নয়) — জানা ফাঁক, রিপোর্টে লেখা।
 *
 * ── ⛔ ঢাকা কলাম উত্তরেই নেই ─────────────────────────────────────────
 * কলাম, প্রতিটা সারি, আর যোগফল — তিনটাই `columnsFor($user)`-এর তালিকা
 * ধরে গড়া। ⚠️ ইঞ্জিনের সারিতে বাড়তি ঘরও থাকে — ভিতরের ক্রমিক আইডি
 * (`customer_id`), ড্রিল-ডাউনের `source_type` — আর সেগুলোও বাদ পড়ে,
 * কারণ ভিতরের `id` তারে যায় না (§৩ ক)।
 */
final class ReportApiController extends Controller
{
    /**
     * এক পাতায় সর্বোচ্চ কয়টা সারি।
     *
     * ⓘ ওয়েবের পর্দা ইঞ্জিনের নিজের মান (১০০) ছাড়া কিছু চায় না, আর
     * চুক্তির উদাহরণও ১০০। ⛔ সীমা না থাকলে `?perPage=50000` দিয়ে গোটা
     * খতিয়ান এক পাতায় আসত — ঠিক যে কারণে পাতা-ভাগ বাধ্যতামূলক (নিয়ম ঘ)।
     */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly ModuleRegistry $registry,
        private readonly SettingsService $settings,
    ) {}

    /**
     * এই মানুষ যেগুলো চালাতে পারেন — আর কেবল সেগুলো।
     *
     * ⓘ ফোন তালিকাটা এখান থেকেই বানায়; নতুন রিপোর্ট নিবন্ধিত হলে নতুন
     * রিলিজ ছাড়াই আসে।
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $modules = $this->moduleOfEachReport();

        $list = [];

        foreach ($this->reports->keys() as $key) {
            $definition = $this->reports->get($key);
            $module = $modules[$key] ?? null;

            if ($this->switchedOff($module) || ! $this->mayRun($user, $definition)) {
                continue;
            }

            /* ⭐ ফোনে বন্ধ মডিউলের রিপোর্ট তালিকাতেই নেই — [[PhoneModules]] */
            if ($module !== null && ! app(PhoneModules::class)->isReachable($module->code)) {
                continue;
            }

            $list[] = [
                'key' => $key,
                'module' => $module?->code,
                'title' => __($definition->title),
                'filters' => array_values($definition->filters),
            ];
        }

        return response()->json($list);
    }

    /**
     * একটা রিপোর্টের একটা পাতা।
     *
     * ⚠️ দরজার ক্রম ওয়েবের মতোই: অচেনা কী → ৪০৪, বন্ধ মডিউল → ৪০৪, চাবি
     * নেই → ৪০৩। ⛔ অচেনা কী-তে ইঞ্জিন ব্যতিক্রম ছোঁড়ে, তাই আগে দেখা —
     * নাহলে টাইপো মানে ৫০০, আর ৫০০ দেখে ফোন ভাবত সার্ভার ভাঙা।
     */
    public function show(Request $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $definition = $this->opened($user, $key);

        /*
         * ⚠️ তারিখ এখানে যাচাই হয়, ইঞ্জিনে নয়।
         *
         * ইঞ্জিন ভুল তারিখে `Carbon::parse()`-এ আর উল্টো পরিসরে নিজের
         * `RuntimeException`-এ ভাঙে — দুইটাই ৫০০। ⓘ `api/*` বলে যাচাইয়ের
         * ব্যর্থতা ৪২২ JSON হয়ে ফেরে, আর ফোন বার্তাটা দেখাতে পারে।
         * ⓘ "শেষ" না দিলে ইঞ্জিন আজকের দিন বসায়, তাই "শুরু" তার সাথেই মেলানো।
         */
        if ($definition->hasFilter('date_range')) {
            // ⛔ `to[]=…` এলে নিয়মের লেখায় অ্যারে জুড়ে ৫০০ দিত — তাই কেবল লেখা হলে (পুনঃনিরীক্ষা, ৯ অক্টোবর ২০২৬)
            $to = $request->query('to');

            $request->validate([
                'from' => ['nullable', 'date_format:Y-m-d',
                    'before_or_equal:'.(is_string($to) && $to !== '' ? $to : Carbon::today()->toDateString())],
                'to' => ['nullable', 'date_format:Y-m-d'],
            ]);
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('perPage', self::MAX_PER_PAGE)));

        $result = $this->reports->run(
            $key,
            // ⭐ ঘরগুলো ঘোষণা থেকেই — ওয়েবের পর্দা ঠিক এটাই পাঠায়; ফোনের শাখা public_id ধরে ([[phoneFilters()]])
            $this->phoneFilters($request, $user, $definition),
            page: max(1, (int) $request->query('page', 1)),
            perPage: $perPage,
        );

        $columns = $result->columnsFor($user);

        return response()->json([
            'key' => $key,
            'title' => __($definition->title),
            'columns' => array_map(fn (ReportColumn $column): array => [
                'key' => $column->key,
                'label' => __($column->label),
                'type' => $column->type,
                'total' => $column->total,
            ], $columns),
            // ⓘ `(object)` — খালি হলেও JSON-এ `{}`, `[]` নয়; ফোন ঘরটাকে মানচিত্র হিসেবেই পড়ে
            'rows' => array_map(fn (array $row): object => (object) $this->row($row, $columns), $result->rows),
            'totals' => (object) $this->totals($result, $columns),
            /*
             * ⭐ এই পাতার মোট — সর্বমোটের (`totals`) পাশে, সার্ভারের কষা (মালিক, ১৭ অক্টোবর ২০২৬: "প্রতিটা পর্দায় মোট আর
             * সর্বমোট")। ⛔ ফোন যোগ করে না; ঢাকা কলামের পাতা-মোটও ঢাকা, যোগফলের একই নিয়মে।
             */
            'pageTotals' => (object) $this->pageTotals($result, $columns),
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalRows' => $result->totalRows,
            'lastPage' => $result->lastPage(),
            'filters' => (object) $this->filtersEcho($definition, $result),
        ]);
    }

    /**
     * `GET /reports/{key}/choices` — এই রিপোর্টের ছাঁকনির বাছাই-তালিকা, ফোনের পর্দার মাথার জন্য (১৭ অক্টোবর ২০২৬)।
     *
     * ⓘ ওয়েবের পাতা যে উৎস থেকে বাছাই-ঘর আঁকে ([[ReportFilters]]) সেখান থেকেই; দেয়ালও সেটাই (কোম্পানি, শাখা, পরিধি)।
     * ⛔ ভিতরের আইডি তারে যায় না (§৩ ক): শাখা যায় public_id-তে, বাকি বাছাই "কোড · নাম" লেখায় — ইঞ্জিন ঐ লেখাই
     * চেনে ([[ReportFilterSource::resolve()]])। ⓘ যে ছাঁকনি ফোন এখনো জিজ্ঞেস করতে পারে না সেটাও তালিকায় থাকে
     * (`kind: web`), যাতে পর্দা চুপ করে না থেকে বলতে পারে "এই ছাঁকনি ওয়েবে"।
     */
    public function choices(Request $request, string $key): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $definition = $this->opened($user, $key);
        $sources = app(ReportFilters::class);
        $out = [];

        foreach ($definition->filters as $filter) {
            if ($filter === 'date_range') {
                $out[] = ['key' => 'date_range', 'kind' => 'date_range', 'label' => 'তারিখ', 'options' => []];

                continue;
            }

            if ($filter === 'branch') {
                $out[] = ['key' => 'branch', 'kind' => 'branch', 'label' => 'শাখা',
                    'options' => array_map(fn (Branch $b): array => ['value' => (string) $b->public_id, 'label' => $b->name()],
                        $this->reachableBranches($user))];

                continue;
            }

            /* ⭐ মাস — "বছর-মাস" (`2026-10`); ফোন মাস বাছার ঘর আঁকে, ইঞ্জিন নিজে ধরন যাচাই করে (মূল কোম্পানির কমিশন) */
            if ($filter === 'month') {
                $out[] = ['key' => 'month', 'kind' => 'month', 'label' => 'মাস', 'options' => []];

                continue;
            }

            /* ⭐ নিজের সংকেতের ছাঁকনি — সংজ্ঞায় ঘোষিত ([[ReportDefinition::$choices]]); মান সংকেতটাই, নাম অনুবাদে */
            if (isset($definition->choices[$filter])) {
                $out[] = ['key' => $filter, 'kind' => 'list', 'label' => $this->choiceLabel($filter),
                    'options' => array_map(fn (string $code, string $label): array => ['value' => $code, 'label' => __($label)],
                        array_keys($definition->choices[$filter]), $definition->choices[$filter])];

                continue;
            }

            /*
             * ⓘ তালিকার ছাঁকনি — উৎস নিজের নামে (`warehouse_id`), নয়তো ইঞ্জিন যে নামে পড়ে (`account` → `account_id`)।
             * ⚠️ চাবি তখন ইঞ্জিনের নাম, যাতে ফোন ঐ নামেই পাঠায় ([[phoneFilters()]] লেখাটা নম্বরে বদলায়)।
             */
            $wire = $sources->for($filter) !== null ? $filter : ($definition->askedAs($filter)[0] ?? $filter);
            $source = $sources->for($wire);
            if ($source !== null) {
                $out[] = ['key' => $wire, 'kind' => 'list', 'label' => __($source->label()),
                    'options' => array_values(array_map(fn (string $label): array => ['value' => $label, 'label' => $label],
                        $source->options()))];

                continue;
            }

            $out[] = ['key' => $filter, 'kind' => 'web', 'label' => $filter, 'options' => []];
        }

        return response()->json(['key' => $key, 'filters' => $out]);
    }

    /**
     * দরজার ক্রম ওয়েবের মতোই: অচেনা কী → ৪০৪, বন্ধ মডিউল → ৪০৪, ফোনে বন্ধ → ৪০৩ `module_off`, চাবি নেই → ৪০৩।
     * ⓘ রপ্তানিও [[show()]]-এর দরজা দিয়ে যায় ([[ReportExportApiController]]), তাই একটা দেয়ালেই সব।
     */
    private function opened(User $user, string $key): ReportDefinition
    {
        abort_unless(in_array($key, $this->reports->keys(), true), 404);

        $definition = $this->reports->get($key);

        abort_if($this->switchedOff($this->moduleOfEachReport()[$key] ?? null), 404);

        app(PhoneModules::class)
            ->refuseUnlessReachable(($this->moduleOfEachReport()[$key] ?? null)?->code);

        abort_unless($this->mayRun($user, $definition), 403);

        return $definition;
    }

    /** @return list<Branch> এই মানুষের নাগালের সক্রিয় শাখা — `/me`-র একই তালিকা */
    private function reachableBranches(User $user): array
    {
        $allowed = app(DataScope::class)->idsFor($user, UserDataScope::BRANCH);

        return Branch::query()->active()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->ordered()->get()->all();
    }

    /**
     * ⭐ ফোনের শাখা `branch=<public_id>` → ইঞ্জিনের `branch_id`। ⛔ নাগালের বাইরের বা অচেনা শাখা ৪২২, চুপ করে "সব শাখা" নয়
     * — নাহলে একজন একটা শাখা বেছে গোটা কোম্পানির অঙ্ক নিজের শাখার বলে পড়তেন।
     *
     * ⓘ রপ্তানিও এটাই ডাকে ([[ReportExportApiController]]) — পর্দা আর ছাপা একই ছাঁকনিতে।
     *
     * @return array<string, mixed>
     */
    public function phoneFilters(Request $request, User $user, ReportDefinition $definition): array
    {
        $input = $this->resolvedChoices($definition, $request->only($definition->requestKeys()));
        $wanted = $request->query('branch');

        if (! $definition->hasFilter('branch') || $wanted === null || $wanted === '') {
            return $input;
        }

        $branch = is_string($wanted)
            ? collect($this->reachableBranches($user))->first(fn (Branch $b) => (string) $b->public_id === $wanted)
            : null;

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => __('core.report.filter_not_allowed', ['filter' => 'শাখা']),
            ]);
        }

        return [...$input, 'branch_id' => (int) $branch->id];
    }

    /**
     * ⭐ ফোনের বাছাই ইঞ্জিনের ভাষায় — ১৭ অক্টোবর ২০২৬।
     *
     * ⓘ ইঞ্জিনের নামে আসা তালিকার ছাঁকনি (`account_id`, `party_type_id`, `cost_center_id`) "কোড · নাম" লেখা হলে উৎস দিয়ে
     * নম্বরে — দেয়ালের ভিতরের তালিকা থেকেই; ঘোষিত সংকেতের ছাঁকনি তালিকায় থাকলেই। ⛔ বাইরের কিছু ৪২২ — চুপ করে ছাঁকনি
     * ফেলে দিয়ে গোটা রিপোর্ট দেখানো নয়।
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function resolvedChoices(ReportDefinition $definition, array $input): array
    {
        $sources = app(ReportFilters::class);

        foreach ($definition->filters as $filter) {
            $raw = $input[$filter] ?? null;
            if (isset($definition->choices[$filter]) && $raw !== null && $raw !== '') {
                if (! is_string($raw) || ! array_key_exists($raw, $definition->choices[$filter])) {
                    throw ValidationException::withMessages([
                        $filter => __('core.report.filter_not_allowed', ['filter' => $this->choiceLabel($filter)]),
                    ]);
                }

                continue;
            }

            foreach ($definition->askedAs($filter) as $asked) {
                $source = $asked === $filter ? null : $sources->for($asked);
                $value = $input[$asked] ?? null;
                if ($source === null || $value === null || $value === '') {
                    continue;
                }

                $id = is_scalar($value) ? $source->resolve((string) $value) : null;
                if ($id === null) {
                    throw ValidationException::withMessages([
                        $asked => __('core.report.filter_not_allowed', ['filter' => __($source->label())]),
                    ]);
                }

                $input[$asked] = $id;
            }
        }

        return $input;
    }

    /** নিজের সংকেতের ছাঁকনির নাম — ফোনের বোতামে */
    private function choiceLabel(string $filter): string
    {
        return match ($filter) {
            'status' => 'অবস্থা',
            'direction' => 'দিক',
            'group_by' => 'কী ধরে',
            'period' => 'সময়ের ভাগ',
            'state' => 'অবস্থা',
            default => $filter,
        };
    }

    /**
     * এই পাতার সারির যোগ — কেবল দেখা যায় এমন যোগ-কলামের।
     *
     * @param  list<ReportColumn>  $columns
     * @return array<string, string|null>
     */
    private function pageTotals(ReportResult $result, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            if (! $column->total || ! array_key_exists($column->key, $result->totals)) {
                continue;
            }

            $sum = '0';
            foreach ($result->rows as $row) {
                $value = $row[$column->key] ?? null;
                if (is_numeric($value)) {
                    $sum = bcadd($sum, (string) $value, 4);
                }
            }

            $out[$column->key] = $this->value($sum, $column);
        }

        return $out;
    }

    /**
     * ⛔ null মানে "জানি না", তাই না — [[ReportDefinition::$permission]]-এর
     * নিজের কথা। ওয়েবের চেয়ে বেশি দেখানোর চেয়ে কম দেখানো ভালো।
     */
    private function mayRun(User $user, ReportDefinition $definition): bool
    {
        return $definition->allows($user);
    }

    /**
     * কোন রিপোর্ট কোন মডিউলের — মডিউলের নিজের ঘোষণা থেকে।
     *
     * ⓘ `module.php`-র `reports` তালিকার প্রোভাইডারগুলো একটা ফাঁকা
     * ইঞ্জিনে নিবন্ধন করিয়ে দেখা হয় কোন কী কার। ⚠️ কী-র উপসর্গ ধরে
     * অনুমান করা যেত, কিন্তু সেটা একটা রীতি, ঘোষণা নয় — আর যে রিপোর্ট
     * রীতি মানে না তার মডিউলের সুইচ তখন নীরবে দেখা হত না।
     *
     * @return array<string, ModuleDefinition>
     */
    private function moduleOfEachReport(): array
    {
        $owners = [];

        foreach ($this->registry->all() as $module) {
            $probe = new ReportEngine;

            foreach ($module->reports as $provider) {
                $provider::registerAll($probe);
            }

            foreach ($probe->keys() as $key) {
                $owners[$key] = $module;
            }
        }

        return $owners;
    }

    /**
     * মডিউলটা এই কোম্পানিতে বা এই শাখায় বন্ধ কি না — [[RefuseSwitchedOffScreens]]-এর
     * প্রথম দুই স্তর।
     *
     * ⓘ অপরিহার্য মডিউল কোনো সুইচেই বন্ধ হয় না, ওয়েবের মতোই।
     */
    private function switchedOff(?ModuleDefinition $module): bool
    {
        if ($module === null || $module->essential) {
            return false;
        }

        if (! $this->settings->get($module->code.'.enabled', true)) {
            return true;
        }

        return in_array($module->code, BranchModule::switchedOffIn(CompanyContext::branchId()), true);
    }

    /**
     * একটা সারি — কেবল দেখা যায় এমন কলামের ঘর, টাকা আর পরিমাণ স্ট্রিংয়ে।
     *
     * @param  array<string, mixed>  $row
     * @param  list<ReportColumn>  $columns
     * @return array<string, mixed>
     */
    private function row(array $row, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            $out[$column->key] = $this->value($row[$column->key] ?? null, $column);
        }

        return $out;
    }

    /**
     * ⛔ যোগফল সার্ভারের, আর ঢাকা কলামের যোগফলও ঢাকা (নিয়ম ক ও খ)।
     *
     * ⚠️ ইঞ্জিন **সব** যোগ-কলামের যোগফল গোনে, অনুমতি না দেখে — ওয়েবের
     * পর্দা কেবল দেখা কলামগুলোর নিচে যোগফল আঁকে। ⛔ এখানে পুরো `totals`
     * পাঠালে সারি থেকে মুনাফা ঢাকা থাকত, আর যোগফলে মোট মুনাফা খোলা।
     *
     * @param  list<ReportColumn>  $columns
     * @return array<string, string|null>
     */
    private function totals(ReportResult $result, array $columns): array
    {
        $totals = [];

        foreach ($columns as $column) {
            if ($column->total && array_key_exists($column->key, $result->totals)) {
                $totals[$column->key] = $this->value($result->totals[$column->key], $column);
            }
        }

        return $totals;
    }

    /**
     * টাকা ও পরিমাণ চার ঘরের স্ট্রিং (নিয়ম গ); বাকি সব যেমন আছে।
     *
     * ⚠️ null থাকে null — শূন্য নয়। "কিছু নেই" আর "শূন্য" এক কথা নয় (§৮ ক)।
     * ⓘ অচেনা ধরন কাঁচা যায়, লুকানো হয় না (নিয়ম ঙ)।
     */
    private function value(mixed $value, ReportColumn $column): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($column->type, [ReportColumn::MONEY, ReportColumn::QUANTITY, ReportColumn::DR_CR], true) && is_numeric($value)) {
            return bcadd((string) $value, '0', 4);
        }

        if ($column->type === ReportColumn::PERCENT && is_numeric($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * কোন পরিসরের ফল — ইঞ্জিন যা বসিয়েছে, ফোন যা চেয়েছিল তা নয়।
     *
     * ⓘ না চাইলে ইঞ্জিন মাসের শুরু থেকে আজ বসায়; পর্দাকে সেটা বলতেই হবে।
     * ⛔ `company_id`, `branch_id` ইত্যাদি এখানে ফেরে না — ভিতরের আইডি
     * তারে যায় না (§৩ ক)।
     *
     * @return array<string, string>
     */
    private function filtersEcho(ReportDefinition $definition, ReportResult $result): array
    {
        if (! $definition->hasFilter('date_range')) {
            return [];
        }

        return [
            'from' => (string) $result->filters['from'],
            'to' => (string) $result->filters['to'],
        ];
    }
}
