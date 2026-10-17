<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Phone\PhonePaper;
use App\Core\Phone\PhonePaperRegistry;
use App\Core\Services\PhoneModules;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseSwitchedOffScreens;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * ⭐ ফোনের নিজের পর্দায় লেনদেন — তালিকা আর পাতা, সব ধরনের একটাই দরজা (মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app
 * style e zate bujazay sei vabe koro, direct webpage app e bujajayna"*; fe-র ধাপ ৪)।
 *
 *   `GET /api/v1/papers/{key}?from=&to=&branch=&status=&q=&page=` — সারি, এই পাতার মোট, ছাঁকনির সর্বমোট
 *   `GET /api/v1/papers/{key}/{public_id}` — ঘর, সারি, যোগফল, সই আর ছাপার ধরন
 *
 * ── ⛔ দরজার ক্রম — ওয়েব যে ক্রমে ফেরায় ([[DocumentApiController]]-এর মতো) ──────────────────
 *   ৪০৪  অচেনা ধরন
 *   ৪০৩  ফোনে মডিউল বন্ধ ([[PhoneModules::refuseUnlessReachable()]], কারণসহ)
 *   ৪০৪  ওয়েবে ঐ পর্দা বন্ধ ([[RefuseSwitchedOffScreens::refuses()]]) — ওয়েবের একই উত্তর
 *   ৪০৩  ওয়েবের তালিকার চাবি নেই
 *   ৪০৪  কাগজ নেই — অন্য কোম্পানির, শাখা বা ডিলারের দেয়ালের বাইরে (মডেলের নিজের স্কোপ), বা ক্রমিক `id`
 *
 * ⛔ দেয়াল এখানে লেখা নয় — [[PhonePaper::query()]] মডেলের নিজের কোয়েরি। শাখার ছাঁকনি সেই দেয়ালের **ভিতরে** সরু করে,
 * ভাউচারের ফোন-তালিকার মতো (শাখার public_id; নাগালের বাইরের শাখা = খালি তালিকা)।
 *
 * ⓘ লেখা ব্যবহারকারীর নিজের ভাষায়, বাকি ফোন-দরজার মতো ([[ResolveCompanyContext]]) — প্রতিটা ঘরের নাম মডিউলের lang/bn আর lang/en
 * থেকে; হাতে বাঁধা ভাষা নেই (fe, ১৭ অক্টোবর ২০২৬)। ⓘ অনলাইন-only — কাগজ সেই মুহূর্তের অবস্থা।
 */
final class PhonePaperApiController extends Controller
{
    public const PER_PAGE = 30;

    public function __construct(private readonly PhonePaperRegistry $papers) {}

    public function index(Request $request, string $key): JsonResponse
    {
        $paper = $this->open($request, $key);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(array_keys($paper->statuses()))],
            'branch' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = $paper->query();
        $paper->applyStatus($query, $data['status'] ?? null);

        $dated = $paper->dateColumn();
        [$from, $to] = [null, null];

        if ($dated !== null) {
            // ⓘ তারিখ না দিলে এই মাস — বিল আর চালানের ফোন-তালিকার মতো ([[SalesPaperApiController]])
            $to = $data['to'] ?? Carbon::today()->toDateString();
            $from = $data['from'] ?? Carbon::parse($to)->startOfMonth()->toDateString();

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            $query->whereDate($dated, '>=', $from)->whereDate($dated, '<=', $to);
        }

        $term = trim((string) ($data['q'] ?? ''));

        if ($term !== '' && $paper->searchable()) {
            $paper->search($query, $term);
        }

        $this->narrowToBranch($query, $paper, $data['branch'] ?? null);

        $sumColumn = $paper->sumColumn($user);
        $sum = $sumColumn === null ? null
            : (string) ((clone $query)->reorder()->select(\Illuminate\Support\Facades\DB::raw("COALESCE(SUM({$sumColumn}), 0) AS n"))->value('n') ?? '0');

        $table = $query->getModel()->getTable();
        $page = $query->with([...$paper->with(), ...($paper->branchColumn() !== null && method_exists($query->getModel(), 'branch') ? ['branch'] : [])])
            ->when($dated !== null, fn (Builder $q) => $q->orderByDesc($dated))
            ->orderByDesc($table.'.id')
            ->paginate(self::PER_PAGE);

        $rows = $page->getCollection()->map(fn (Model $m) => $this->rowOf($paper, $m, $user))->values();
        $pageSum = $sum === null ? null
            : $rows->reduce(fn (string $carry, array $r) => bcadd($carry, (string) ($r['total'] ?? '0'), 2), '0');

        return response()->json([
            'type' => $paper->key(),
            'filters' => [
                'dated' => $dated !== null,
                'from' => $from,
                'to' => $to,
                'branches' => $paper->branchColumn() !== null,
                'searchable' => $paper->searchable(),
                'statuses' => collect($paper->statuses())->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values(),
            ],
            'rows' => $rows,
            'count' => $page->total(),
            // ⓘ টাকা না থাকলে (বা দেখার চাবি না থাকলে) দুটোই null — ফোন যোগফলের পট্টি আঁকে না
            'page_sum' => $pageSum,
            'sum' => $sum === null ? null : bcadd($sum, '0', 2),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $key, string $id): JsonResponse
    {
        $paper = $this->open($request, $key);
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        /** @var User $user */
        $user = $request->user();
        $table = $paper->query()->getModel()->getTable();
        $model = $paper->query()->where($table.'.public_id', $id)->firstOrFail();

        return response()->json($this->rowOf($paper, $model, $user) + $paper->detail($model, $user) + [
            'signatures' => $this->signaturesOf($model, $user),
            'print_type' => $paper->printType(),
        ]);
    }

    /** দরজার চার পাহারা, ক্রমে — উপরের তালিকা দেখুন */
    private function open(Request $request, string $key): PhonePaper
    {
        $paper = $this->papers->find($key);
        abort_if($paper === null, 404);

        app(PhoneModules::class)->refuseUnlessReachable($paper->module());

        [$route, $params] = $paper->webRoute();
        abort_if(app(RefuseSwitchedOffScreens::class)->refuses($route, $params), 404, __('core.message.screen_switched_off'));

        abort_unless((bool) $request->user()?->can($paper->permission()), 403, __('core.error.body_403'));

        return $paper;
    }

    /** @return array<string, mixed> */
    private function rowOf(PhonePaper $paper, Model $model, User $user): array
    {
        $status = $paper->statusOf($model);
        $own = $paper->row($model, $user);
        $dated = $paper->dateColumn();
        $date = $dated === null ? null : $model->getAttribute(\Illuminate\Support\Str::afterLast($dated, '.'));

        return [
            'id' => (string) $model->getAttribute('public_id'),
            'no' => $paper->numberOf($model),
            'date' => $date === null ? null : Carbon::parse($date)->toDateString(),
            'date_label' => $date === null ? null : DateFormat::format(Carbon::parse($date)),
            'status' => $status,
            // ⓘ ছাঁকনির চিপ যে কাগজে অবস্থা নয় (পরিবহনের ট্যাব), সেখানে কাগজের নিজের অবস্থার নাম
            'status_label' => $paper->statuses()[$status] ?? __('core.status.'.$status),
            'branch' => $paper->branchColumn() === null ? null : PhonePaper::nameOf($model->getRelationValue('branch')),
            'party' => $own['party'] ?? null,
            'note' => $own['note'] ?? null,
            'total' => isset($own['total']) ? bcadd((string) $own['total'], '0', 2) : null,
            'total_label' => isset($own['total']) ? Money::format((string) $own['total']) : null,
        ];
    }

    /** শাখার ছাঁকনি — দেয়ালের ভিতরে সরু করে; অচেনা বা নাগালের বাইরের শাখা = খালি */
    private function narrowToBranch(Builder $query, PhonePaper $paper, ?string $branch): void
    {
        $column = $paper->branchColumn();

        if ($column === null || $branch === null || trim($branch) === '') {
            return;
        }

        $query->whereIn($column, Branch::query()->where('company_id', CompanyContext::id())
            ->where('public_id', trim($branch))->select('id'));
    }

    /**
     * কাগজের সই — প্রতিটা অনুরোধ, কোন ধাপে, কে কী বললেন, কখন; আর এখন যিনি দেখছেন তিনি সই দিতে পারেন কি না।
     *
     * ⓘ সই দেওয়া হয় অনুমোদনের পাতা থেকেই ([[ApprovalApiController]]) — এখানে কেবল তার চাবি (`approval_id`), দ্বিতীয় দরজা নয়।
     *
     * @return list<array<string, mixed>>
     */
    private function signaturesOf(Model $model, User $user): array
    {
        $engine = app(ApprovalEngine::class);

        return Approval::query()
            ->where('approvable_type', $model::class)
            ->where('approvable_id', $model->getKey())
            ->with(['decisions.user'])
            ->orderBy('id')
            ->get()
            ->map(function (Approval $a) use ($engine, $user): array {
                $canSign = $a->isPending() && $engine->canDecide($a, $user);
                $steps = $engine->stepNamesFor($a);

                return [
                    'status' => (string) $a->status,
                    'status_label' => __('approval::status.'.$a->status),
                    'level' => (int) $a->current_level,
                    'steps' => array_values(array_map('strval', $steps)),
                    'requested_by' => User::query()->whereKey($a->requested_by)->value('name'),
                    'requested_at' => $a->requested_at === null ? null : DateFormat::formatWithTime($a->requested_at),
                    'decisions' => $a->decisions->sortBy('id')->values()->map(fn (ApprovalDecision $d) => [
                        'level' => (int) $d->level,
                        'by' => $d->user?->name,
                        'decision' => (string) $d->decision,
                        'decision_label' => __('approval::status.'.$d->decision),
                        'remarks' => $d->remarks,
                        'at' => $d->decided_at === null ? null : DateFormat::formatWithTime($d->decided_at),
                    ])->all(),
                    'can_sign' => $canSign,
                    'approval_id' => $canSign ? (string) $a->public_id : null,
                ];
            })->all();
    }
}
