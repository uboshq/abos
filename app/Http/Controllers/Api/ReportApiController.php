<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Report\ReportColumn;
use App\Core\Engines\Report\ReportDefinition;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Engines\Report\ReportResult;
use App\Core\Module\ModuleDefinition;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\BranchModule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        abort_unless(in_array($key, $this->reports->keys(), true), 404);

        $definition = $this->reports->get($key);

        abort_if($this->switchedOff($this->moduleOfEachReport()[$key] ?? null), 404);

        /*
         * ⛔ ফোনে বন্ধ মডিউল — ৪০৩ `module_off` ([[PhoneModules]])। ⓘ রপ্তানিও এই
         * দরজা দিয়েই যায় ([[ReportExportApiController]]), তাই একটা দেয়ালেই দুইটা।
         */
        app(PhoneModules::class)
            ->refuseUnlessReachable(($this->moduleOfEachReport()[$key] ?? null)?->code);

        abort_unless($this->mayRun($user, $definition), 403);

        /*
         * ⚠️ তারিখ এখানে যাচাই হয়, ইঞ্জিনে নয়।
         *
         * ইঞ্জিন ভুল তারিখে `Carbon::parse()`-এ আর উল্টো পরিসরে নিজের
         * `RuntimeException`-এ ভাঙে — দুইটাই ৫০০। ⓘ `api/*` বলে যাচাইয়ের
         * ব্যর্থতা ৪২২ JSON হয়ে ফেরে, আর ফোন বার্তাটা দেখাতে পারে।
         * ⓘ "শেষ" না দিলে ইঞ্জিন আজকের দিন বসায়, তাই "শুরু" তার সাথেই মেলানো।
         */
        if ($definition->hasFilter('date_range')) {
            $request->validate([
                'from' => ['nullable', 'date_format:Y-m-d',
                    'before_or_equal:'.($request->query('to') ?: Carbon::today()->toDateString())],
                'to' => ['nullable', 'date_format:Y-m-d'],
            ]);
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('perPage', self::MAX_PER_PAGE)));

        $result = $this->reports->run(
            $key,
            // ⭐ ঘরগুলো ঘোষণা থেকেই — ওয়েবের পর্দা ঠিক এটাই পাঠায়
            $request->only($definition->requestKeys()),
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
            'page' => $result->page,
            'perPage' => $result->perPage,
            'totalRows' => $result->totalRows,
            'lastPage' => $result->lastPage(),
            'filters' => (object) $this->filtersEcho($definition, $result),
        ]);
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

        if (in_array($column->type, [ReportColumn::MONEY, ReportColumn::QUANTITY], true) && is_numeric($value)) {
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
