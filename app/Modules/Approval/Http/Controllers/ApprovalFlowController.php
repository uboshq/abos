<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\ApprovalFlow;
use App\Models\User;
use App\Modules\Approval\Http\Requests\ApprovalFlowRequest;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * অনুমোদনের ছক সাজানো — সেটিংসে, এক জায়গায়।
 *
 * প্রতিটা মডিউলের পর্দায় নিজের নিজের "অনুমোদন লাগবে?" সুইচ বসালে
 * মালিককে সাতটা পর্দা ঘুরে দেখতে হত কোথায় কী বসানো আছে — আর একটা
 * ভুলে গেলে সেখানে অনুমোদন ছাড়াই সব চলত।
 */
class ApprovalFlowController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalFlowService $flows,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:approval.flow.manage')];
    }

    /**
     * ছকের তালিকা — খোঁজা ও পাতা ভাগসহ।
     *
     * ── ⛔ কেন দুইটাই লাগল, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
     * আগে গোটা তালিকাটা `get()` করে এক পর্দায় ঢালা হত, আর সেটা
     * চলত কারণ ছক ছিল হাতে গোনা। ⚠️ ঐদিন সকালে লাইভে **৭২টা ছক**
     * বসেছে (তিন কোম্পানিতে ২৪টা করে) — তখন এক পাতায় সব ঢালা মানে
     * মানুষ স্ক্রল করে খোঁজেন, আর খুঁজে না পেয়ে ধরে নেন নিয়মটা নেই।
     *
     * ── ⓘ খোঁজাটা কী কী দেখে ──────────────────────────────────
     * মডিউল, কাজ, সংকেত — আর **সইকারীর নাম**। ⚠️ শেষেরটা ছাড়া
     * প্রশ্নটার সবচেয়ে স্বাভাবিক রূপটাই কাজ করত না: *"রফিক কোথায়
     * কোথায় সই করেন"*।
     *
     * ⓘ নামটা ছাঁকা হয় **আগে তুলে রাখা তালিকা থেকে**, জয়েন করে নয় —
     * সইকারী হয় রোল নয় ব্যবহারকারী, দুইটা আলাদা টেবিল, আর জয়েনে
     * ওটা দুইবার লিখতে হত। ⚠️ তালিকাটা এমনিতেও পর্দার জন্য তোলা হয়,
     * তাই এতে বাড়তি কোনো কোয়েরি যায় না।
     */
    public function index(Request $request): View
    {
        $open = (int) $request->query('open', 0);

        return $this->page($request, $open > 0 ? ApprovalFlow::query()->with(['steps', 'conditions'])->findOrFail($open) : null);
    }

    /**
     * ⭐ নিয়মের পাতা — উপরে এক সারিতে একটা নিয়ম, খোলা নিয়মটা নিচে ধাপের কার্ডে (অনুমোদন পর্দা ভাগ ৩, দফা ১৭; পরিকল্পনার পাতা ৪)।
     *
     * ⛔ আগে তালিকা কার্ডে আর সম্পাদনা আলাদা পাতায়, ১১ ঘরের সারি ধরে: কোন ঘড়ি কোন ধাপের, কে কত টাকা থেকে সই দেন — খুলে
     * খুঁজতে হত। ⭐ এখন এক পাতায়: তালিকায় ধাপের রেখা, "কত টাকা থেকে", শেষ সই আর চালু/বন্ধ; নিচে খোলা নিয়মের কার্ড, "পরখ করুন"
     * আর "আরও ▾"। ⓘ সম্পাদনা আর নতুনের ঠিকানা আগের মতোই — এই পাতাই খোলে, নিয়মটা নিচে খোলা।
     */
    private function page(Request $request, ?ApprovalFlow $opened): View
    {
        $names = $this->approverNames();
        $q = trim((string) $request->query('q'));

        $flows = ApprovalFlow::query()
            ->with('steps')
            ->when($q !== '', function ($query) use ($q, $names) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

                /*
                 * ⚠️ সবগুলো একটা `where(fn …)`-এর ভিতরে।
                 *
                 * ⓘ বাইরে `orWhere` বসালে কোম্পানির গ্লোবাল স্কোপটা
                 * ভেঙে যেত, আর অন্য কোম্পানির ছকও মিলে যেত — ব্যবহারকারীর
                 * তালিকায় ঠিক এই ভুলটাই একবার ধরা পড়েছে।
                 */
                $query->where(fn ($inner) => $inner
                    ->where('module', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhereHas('steps', fn ($step) => $step
                        ->whereIn('approver_id', $this->approversNamed($names, $q))));
            })
            ->orderBy('module')
            ->orderBy('action')
            ->paginate(20)
            // ⓘ পাতা বদলালে খোঁজাটা হারায় না
            ->withQueryString();

        $choices = $this->flows->choices();

        $this->offerTheListAsAFile($flows, $choices, $names);

        return view('approval::flow.index', [
            'menu' => $this->menu->forUser($request->user()),
            'flows' => $flows,
            'choices' => $choices,
            'names' => $names,
            'q' => $q,
            'live' => ApprovalFlow::query()->where('is_active', true)->count(),
            'off' => ApprovalFlow::query()->where('is_active', false)->count(),
            'payroll' => ApprovalFlowService::PAYROLL,
            'opened' => $opened,
            ...($opened === null ? [] : [
                ...$this->formData(),
                'fields' => $this->conditionFields($choices, $opened),
                'branches' => \App\Models\Branch::query()->ordered()->get(),
                'lastChange' => $this->lastChangeOf($opened),
            ]),
        ]);
    }

    /**
     * ⭐ চালু/বন্ধের সুইচ — তালিকার সারিতেই (দফা ১৭)। ⓘ বন্ধ করার আগে নিশ্চিত করা পর্দায় ([[x-ui.state-toggle]]); বেতনের নিয়ম আর অপেক্ষার
     * কাগজ সেবার পাহারায় ([[ApprovalFlowService::switchTo()]])।
     */
    public function switch(Request $request, int $flow): RedirectResponse
    {
        $rule = ApprovalFlow::query()->with('steps')->findOrFail($flow);
        $on = ! $rule->is_active;

        $this->flows->switchTo($rule, $on);

        return redirect()->back()->with('saved', __($on ? 'approval::message.flow_switched_on' : 'approval::message.flow_switched_off',
            ['rule' => __($this->flows->choices()[$rule->module]['actions'][$rule->action] ?? $rule->action)]));
    }

    /**
     * ⭐ শর্তের ঘর তালিকা থেকে — হাতে লেখা নয় (দফা ১৭; পরিকল্পনার পাতা ৪: "তালিকা থেকে বাছাই, হাতে লিখতে হয় না")।
     *
     * ⛔ আগে ঘরের নাম হাতে লিখতে হত, আর ভুল নাম মানে নিয়ম কখনো ধরে না — নীরবে ([[ApprovalExceptions]] পরে ধরত)। ⓘ ঘরগুলো সেই
     * উৎস থেকেই যেখান থেকে ইঞ্জিন মাপে: এই কাজের সাম্প্রতিক অনুরোধে কাগজ যে ঘর পাঠিয়েছে (`fields_seen`, [[ApprovalEngine::fieldsOf()]])।
     * ⚠️ খোলা নিয়মের পুরনো শর্তের ঘর তালিকায় থাকেই — নইলে সংরক্ষণে নীরবে হারাত। নাম লেখা থাকলে বাংলায়, নইলে ঘরের নামই।
     *
     * @param  array<string, array{label: string, actions: array<string, string>}>  $choices
     * @return array<string, array<string, string>> "module|action" => [ঘর => নাম]
     */
    private function conditionFields(array $choices, ApprovalFlow $opened): array
    {
        /*
         * ⓘ দুই উৎস: অনুরোধে জমা `fields_seen` (পুরনো সারিতে payload-এর চাবি — [[ApprovalExceptions::flowsThatCanNeverCatch()]]-এর একই
         * নিয়ম), আর কাগজের নিজের টেবিলের কলাম — ইঞ্জিন ঠিক ঐ কলামগুলোই মাপে ([[ApprovalEngine::fieldsOf()]])। ⛔ কেবল প্রথমটা নিলে
         * ভাউচারে (payload জমা হয় না) তালিকা খালি থাকত, অথচ আগে হাতে লিখে শর্ত বসানো যেত।
         */
        $seen = \App\Models\Approval::query()
            ->latest('id')
            ->limit(500)
            ->get(['module', 'action', 'payload', 'approvable_type'])
            ->groupBy(fn ($a) => $a->module.'|'.$a->action)
            ->map(function ($group) {
                $fields = $group->flatMap(fn ($a) => (array) (((array) $a->payload)['fields_seen'] ?? array_keys((array) $a->payload)));

                foreach ($group->pluck('approvable_type')->filter()->unique() as $type) {
                    if (class_exists($type) && is_subclass_of($type, \Illuminate\Database\Eloquent\Model::class)) {
                        $columns = \Illuminate\Support\Facades\Schema::getColumnListing((new $type)->getTable());
                        $fields = $fields->merge(array_keys(\App\Core\Engines\Approval\ApprovalEngine::fieldsOf((new $type)->forceFill(array_fill_keys($columns, '')))));
                    }
                }

                return $fields->reject(fn ($field) => $field === 'fields_seen')->unique()->values()->all();
            });

        $out = [];

        foreach ($choices as $module => $entry) {
            foreach (array_keys($entry['actions']) as $action) {
                $key = $module.'|'.$action;
                $fields = $seen->get($key, []);

                if ($opened->module === $module && $opened->action === $action) {
                    $fields = [...$fields, ...$opened->conditions->pluck('field')->all()];
                }

                $named = [];

                foreach (array_unique($fields) as $field) {
                    $label = 'approval::condition.'.$field;
                    $named[(string) $field] = \Illuminate\Support\Facades\Lang::has($label) ? __($label) : str_replace('_', ' ', (string) $field);
                }

                asort($named);
                $out[$key] = $named;
            }
        }

        return $out;
    }

    /**
     * ⭐ "শেষ বদল: কে · কবে" — নিয়ম, তার ধাপ বা শর্তের সবচেয়ে নতুন নিরীক্ষা-সারি (দফা ১৭)।
     *
     * @return array{who: ?string, at: \Carbon\CarbonInterface}|null
     */
    private function lastChangeOf(ApprovalFlow $flow): ?array
    {
        if (! $flow->exists) {
            return null;
        }

        $trail = \App\Models\AuditTrail::query()
            ->where(fn ($q) => $q
                ->where(fn ($one) => $one->where('auditable_type', ApprovalFlow::class)->where('auditable_id', $flow->id))
                ->orWhere(fn ($steps) => $steps->where('auditable_type', \App\Models\ApprovalFlowStep::class)->whereIn('auditable_id', $flow->steps->pluck('id')))
                ->orWhere(fn ($terms) => $terms->where('auditable_type', \App\Models\ApprovalCondition::class)->whereIn('auditable_id', $flow->conditions->pluck('id'))))
            ->latest('id')
            ->first(['user_id', 'created_at']);

        return $trail === null ? null : [
            'who' => $trail->user_id === null ? null : User::query()->whereKey($trail->user_id)->value('name'),
            'at' => $trail->created_at,
        ];
    }

    /**
     * তালিকাটা ফাইল হিসেবেও দেওয়া — CSV ও Excel।
     *
     * ── ⛔ কেন এটা কন্ট্রোলারে, পর্দায় নয় ──────────────────────────
     * রপ্তানির ফাইলটা [[ListExport]] বানায় একটা **ধরা-টেবিল** থেকে, আর
     * সাধারণত সেটা ধরিয়ে দেয় `x-ui.table`। ⚠️ কিন্তু এই পর্দাটা ছক নয়,
     * **কার্ডের তালিকা** — একটা ছকের নিচে তার ধাপগুলো বসে, আর সেটা এক
     * সারিতে ধরে না।
     *
     * ⓘ তাই কার্ডগুলো ছকে বদলানোর বদলে টেবিলটা এখানেই বানানো হয়:
     * প্রতিটা ছক এক সারি, আর ধাপগুলো **এক ঘরে জোড়া লেগে** যায়
     * (*"১ · সুপারভাইজার — ব্যবস্থাপক"*)। ⭐ পর্দা যেমন ছিল তেমনই থাকে,
     * আর ফাইলটা স্প্রেডশিটে খোলার মতো হয়।
     *
     * ── ⚠️ কেবল চাওয়া হলেই ─────────────────────────────────────────
     * ⓘ প্রতিটা পাতা লোডে টেবিলটা বানানো মানে প্রতিবার সব ধাপের নাম
     * সাজানো — অথচ ফাইলটা কেউ চায় হাজারবারে একবার।
     *
     * ── ⓘ ফাইলে যায় এই পাতার সারিগুলো ─────────────────────────────
     * টুলবারের লেখাতেই বলা আছে (*"এই পাতায় যা দেখছেন, ঠিক তাই"*), আর
     * গোটা রিপোতে রপ্তানি এভাবেই চলে। ⚠️ খোঁজা দিয়ে ছেঁকে নিলে ছাঁকা
     * তালিকাটাই নামে — অর্থাৎ যা দরকার তা বের করে নেওয়া যায়।
     *
     * @param  LengthAwarePaginator<int, ApprovalFlow>  $flows
     * @param  array<string, array{label: string, actions: array<string, string>}>  $choices
     * @param  array{role: array<int, string>, user: array<int, string>}  $names
     */
    private function offerTheListAsAFile($flows, array $choices, array $names): void
    {
        $export = app(ListExport::class);

        if (! $export->wanted()) {
            return;
        }

        $columns = [
            ['key' => 'code', 'label' => __('approval::field.code'), 'render' => null],
            ['key' => 'module', 'label' => __('approval::field.module'), 'render' => null],
            ['key' => 'action', 'label' => __('approval::field.action'), 'render' => null],
            ['key' => 'threshold', 'label' => __('approval::field.threshold'), 'render' => null],
            ['key' => 'steps', 'label' => __('approval::field.steps'), 'render' => null],
            ['key' => 'active', 'label' => __('approval::field.active'), 'render' => null],
            ['key' => 'why', 'label' => __('approval::field.why'), 'render' => null],
        ];

        $export->capture($columns, $flows, function (ApprovalFlow $flow, array $column) use ($choices, $names) {
            return match ($column['key']) {
                'code' => $flow->code,
                'module' => $choices[$flow->module]['label'] ?? $flow->module,
                'action' => __($choices[$flow->module]['actions'][$flow->action] ?? $flow->action),

                // ⓘ সীমা না থাকা মানে "সবসময় লাগে" — খালি ঘর ওটা বলে না
                'threshold' => $flow->threshold_amount === null
                    ? __('approval::action.always')
                    : (string) $flow->threshold_amount,

                'steps' => $flow->steps
                    ->map(fn ($step) => trim(
                        $step->level
                        .($step->step_name !== null ? ' · '.$step->step_name : '')
                        .' — '.($names[$step->approver_type][$step->approver_id] ?? '')
                    ))
                    ->implode('; '),

                'active' => __($flow->is_active ? 'core.state.active' : 'core.state.inactive'),
                'why' => $flow->remarks,
                default => '',
            };
        });
    }

    /**
     * যাদের নামে এই লেখাটা আছে, তাঁদের আইডি — রোল ও ব্যবহারকারী একসাথে।
     *
     * ⚠️ খালি অ্যারে ফেরত দিলে `whereIn` কিছুই মেলায় না, আর সেটাই
     * ঠিক: নামটা কারো সাথে না মিললে সইকারী ধরে কোনো ছকও মেলার কথা নয়।
     *
     * ⓘ রোল আর ব্যবহারকারীর আইডি একই ঘরে (`approver_id`) বসে, তাই
     * দুইটা তালিকা এক করে দেওয়া যায় — ⚠️ এতে রোল ৩ আর ব্যবহারকারী ৩
     * গুলিয়ে যেতে পারে, কিন্তু ফলটা কেবল **বেশি** সারি দেখায়, ভুল
     * কোম্পানির নয়; আর খোঁজা কড়া ছাঁকনি নয়, সাহায্য।
     *
     * @param  array{role: array<int, string>, user: array<int, string>}  $names
     * @return list<int>
     */
    private function approversNamed(array $names, string $q): array
    {
        $found = [];

        /*
         * ⛔ দুইটা তালিকা আলাদা করে ঘোরা হয়, জোড়া লাগিয়ে নয়।
         *
         * ── ⚠️ প্রথম চেষ্টাটা এখানেই ভেঙেছিল, আর মেপে ধরা পড়েছে ──────
         * লেখা ছিল `foreach ([...$names['role'], ...$names['user']] …)`।
         * ⓘ কিন্তু PHP-র spread **পূর্ণসংখ্যার চাবি নতুন করে নম্বর দেয়** —
         * তাই `$id` হয়ে যেত তালিকার **অবস্থান**, রোলের আইডি নয়।
         *
         * ⛔ ফল: `whereIn('approver_id', [0])`, আর সইকারীর নাম ধরে খোঁজা
         * কোনোদিন কিছু মেলাত না। ⚠️ কোনো ত্রুটি ওঠেনি, পর্দা শান্তভাবে
         * "কিছু পাওয়া যায়নি" বলত — আর সেটা পড়ে মানুষ ভাবতেন নিয়মটাই নেই।
         */
        foreach ([$names['role'], $names['user']] as $list) {
            foreach ($list as $id => $name) {
                if (mb_stripos((string) $name, $q) !== false) {
                    $found[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * ⭐ কোথায় সই বসানো যায়, আর কোথায় বসানো আছে।
     *
     * ── ⛔ কেন এই পর্দাটা লাগল, ২২ সেপ্টেম্বর ২০২৬ ─────────────
     * মালিক প্রশ্ন করেছিলেন *"কোথায় কোথায় সই চান"* — আর তাঁকে
     * একজন সহকর্মীকে জিজ্ঞেস করতে হয়েছিল, কারণ তালিকাটা
     * **কোডে**। ⚠️ পর্দায় সেটা দেখা যেত কেবল নতুন নিয়ম বানানোর
     * সময়, একটা ড্রপডাউনের ভিতরে — অর্থাৎ দেখতে হলে আগে
     * একটা বানানো শুরু করতে হত।
     *
     * ⛔ আর ফলটা নীরব ছিল: `assertClear()` কোনো ছক না পেলে চুপচাপ
     * ছেড়ে দেয়, তাই **একটাও নিয়ম বসানো না থাকলেও সব স্বাভাবিক
     * দেখায়**। ℹ ২২ সেপ্টেম্বর সকাল পর্যন্ত লাইভে তিনটা ছক ছিল, আর
     * মালিক যে কোম্পানিতে বসেন সেখানে **একটাও নয়**।
     *
     * ── ℹ তাই পর্দাটা অনুপস্থিতিও দেখায় ────────────────────────
     * তালিকায় কেবল যেগুলো বসানো আছে সেগুলো দেখালে প্রশ্নটার
     * উত্তর মিলত না — *"কোথায় নেই"* জানতে হলে দুইটা তালিকা
     * মিলানো লাগত। ⭐ এখানে দুইটাই এক পাতায়।
     */
    public function coverage(Request $request): View
    {
        $choices = $this->flows->choices();

        /*
         * ℹ ছকগুলো চলতি কোম্পানির — [[ApprovalFlow]]-এ গ্লোবাল স্কোপ।
         * ⚠️ তাই পর্দাটা বলে *"এই কোম্পানিতে কোথায় বসানো"*, সব
         * কোম্পানির কথা নয় — আর সেটাই কাজের, কারণ নিয়মগুলোও
         * কোম্পানি ধরেই খাটে।
         */
        $flows = ApprovalFlow::query()->with('steps')->get()
            ->groupBy(fn (ApprovalFlow $flow) => $flow->module.'.'.$flow->action);

        $rows = [];
        $covered = 0;
        $total = 0;

        foreach ($choices as $module => $entry) {
            foreach ($entry['actions'] as $action => $key) {
                $total++;
                $here = $flows->get($module.'.'.$action, collect());
                $live = $here->firstWhere('is_active', true);

                if ($live !== null) {
                    $covered++;
                }

                $rows[$module]['label'] = $entry['label'];
                $rows[$module]['actions'][] = [
                    'module' => $module,
                    'action' => $action,
                    'label' => __($key),
                    'flow' => $live ?? $here->first(),
                    'dormant' => $live === null && $here->isNotEmpty(),
                ];
            }
        }

        return view('approval::flow.coverage', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'covered' => $covered,
            'total' => $total,
            'names' => $this->approverNames(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->page($request, new ApprovalFlow(['is_active' => true]));
    }

    public function store(ApprovalFlowRequest $request): RedirectResponse
    {
        $this->flows->create($request->validated(), $request->steps(), $request->conditions());

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_saved'));
    }

    public function edit(Request $request, int $flow): View
    {
        return $this->page($request, ApprovalFlow::query()->with(['steps', 'conditions'])->findOrFail($flow));
    }

    public function update(ApprovalFlowRequest $request, int $flow): RedirectResponse
    {
        $this->flows->update(
            ApprovalFlow::query()->findOrFail($flow),
            $request->validated(),
            $request->steps(),
            $request->conditions(),
        );

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_saved'));
    }

    public function destroy(int $flow): RedirectResponse
    {
        $this->flows->delete(ApprovalFlow::query()->findOrFail($flow));

        return redirect()->route('approval.flow.index')->with('saved', __('approval::message.flow_deleted'));
    }

    /**
     * অনুমোদনকারীদের নাম — ধরন ধরে।
     *
     * ছকের তালিকায় শুধু id দেখালে মালিককে মনে রাখতে হত কোন নম্বরটা কে,
     * আর তখন ছকটা পড়াই যেত না।
     *
     * @return array<string, array<int, string>>
     */
    private function approverNames(): array
    {
        return [
            /*
             * রোলে কোম্পানির ছাঁকনি নেই, আর সেটা ঠিক — মেপে দেখা হয়েছে।
             *
             * `roles` টেবিলে `company_id` নেই আর Spatie-র teams বন্ধ,
             * অর্থাৎ রোলগুলো গোটা ইনস্টলেশনের, কোম্পানিভিত্তিক নয়।
             * ⓘ পাশাপাশি দুইটা লাইনের একটায় ছাঁকনি আছে আর অন্যটায় নেই —
             * সেটা ধরে নেওয়া যায় না, তাই লিখে রাখা।
             */
            /* ⛔ কেবল এই কোম্পানির রোল — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১৫): আগে সব কোম্পানির রোল দেখাত, আর ভুল রোল বাছলে সীমা নীরবে অকেজো হত */
            'role' => Role::query()->where('company_id', CompanyContext::id())->pluck('name', 'id')->all(),

            'user' => $this->companyUsers()->pluck('name', 'id')->all(),
        ];
    }

    /**
     * এই কোম্পানির ব্যবহারকারীরাই — বহু-টেন্যান্টে এটা সুবিধা নয়, শর্ত।
     *
     * ⚠️ ── কী ভাঙা ছিল ──────────────────────────────────────────────
     * এখানে ছিল সরল `User::query()`, আর `User`-এ কোনো global scope নেই
     * (একজন মানুষ একাধিক কোম্পানিতে থাকতে পারেন, তাই ওটা pivot-এ)।
     * ফল: ছকের ফর্মে **অন্য কোম্পানির মানুষের নাম** দেখা যেত।
     *
     * ⛔ আর ক্ষতিটা নাম দেখার চেয়ে বড়: ওই তালিকা থেকে অন্য কোম্পানির
     * কাউকে **অনুমোদনের ছকে বসিয়েও দেওয়া যেত**, আর তখন তাঁর কাছে এই
     * কোম্পানির কাগজ সইয়ের জন্য যেত।
     *
     * ⓘ ছাঁচটা `CashTillController::holderOptions()`-এর — নতুন কিছু
     * বানানো হয়নি, কারণ দুই রকম করলে তৃতীয় জায়গায় তৃতীয় রকম হয়।
     *
     * @return Builder<User>
     */
    private function companyUsers(): Builder
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->orderBy('name');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'choices' => $this->flows->choices(),
            'roles' => Role::query()->where('company_id', CompanyContext::id())->orderBy('name')->get(),

            // ব্যক্তি ধরে ছক বসানো যায়, কিন্তু রোল ধরে বসানোই টেকে:
            // মানুষ চাকরি ছাড়েন, রোল থেকে যায়
            //
            // ⚠️ এই কোম্পানির মানুষই — কারণটা [[companyUsers]]-এ
            'users' => $this->companyUsers()->get(),
        ];
    }
}
