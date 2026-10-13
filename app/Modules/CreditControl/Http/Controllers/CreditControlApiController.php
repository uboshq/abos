<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\CreditControl\Dashboard\CreditDashboard;
use App\Modules\CreditControl\Reports\CreditControlLists;
use App\Modules\CreditControl\Reports\CreditPositionReports;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CreditLimitRequests;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * ⭐ ফোনে বাকি নিয়ন্ত্রণ — ড্যাশবোর্ডের সংখ্যা, তালিকা, গ্রাহকের বাকির অবস্থা, সীমা বাড়ানোর অনুরোধ আর বাকি বন্ধ তোলা
 * (মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu")।
 *
 * ⛔ এখানে কোনো সূত্র নেই — ওয়েবের পাতাগুলো যা ডাকে, হুবহু তাই:
 *   · ড্যাশবোর্ড → [[CreditDashboard::figures()]] (মালিকের কেন্দ্রের বাকির চার সংখ্যাও এটাই পড়ে)
 *   · তালিকা → বাকি নিয়ন্ত্রণের নিজের রিপোর্ট, রিপোর্টের ইঞ্জিনে ([[CreditPositionReports]], [[CreditControlLists]])
 *   · গ্রাহকের পাতা → [[CreditExposure::positionOf()]], [[CreditExposure::openBills()]], [[CreditRisk::rate()]] — [[CreditProfileController]]-এর একই
 *   · অনুরোধ → [[CreditLimitRequests::raise()]] — [[CreditLimitRequestController]]-এর একই যাচাই; সই মালিকের, অনুমোদনের বাক্সে
 *   · বাকি বন্ধ তোলা → গ্রাহকের `update` নীতি + [[CustomerService::clearCreditBlock()]] (চেক ফেরতের বন্ধ কেবল মালিক)
 *
 * ⓘ দেয়াল: কোম্পানি, বিক্রয়কর্মীর নিজের ডিলার (গ্রাহকের মডেলের স্কোপ) আর হেডারের শাখা ([[Customer::scopeInViewedBranch()]]) —
 * না মিললে ৪০৪। ⛔ কেবল `public_id`।
 */
final class CreditControlApiController extends Controller implements HasMiddleware
{
    /** ফোনের তালিকার নাম → রিপোর্টের চাবি */
    public const LISTS = [
        'positions' => CreditPositionReports::POSITIONS,
        'over-limit' => CreditPositionReports::OVER_LIMIT,
        'overdue-stopped' => CreditPositionReports::OVERDUE_STOPPED,
        'blocked' => CreditPositionReports::BLOCKED,
        'overdue-bills' => CreditPositionReports::OVERDUE_BILLS,
        'high-risk' => CreditControlLists::HIGH_RISK,
    ];

    /** তালিকার সারি থেকে ফোনে যা যায় — রিপোর্টের নিজের ঘর */
    private const ROW_FIELDS = [
        'credit_limit', 'due', 'held', 'uncleared', 'exposure', 'available', 'used_percent', 'overdue', 'state',
        'level', 'score', 'reasons', 'document_no', 'due_on', 'days_overdue', 'bucket', 'unpaid',
    ];

    private const PER_PAGE = 50;

    public function __construct(private readonly CreditExposure $credit) {}

    public static function middleware(): array
    {
        return [new Middleware('can:credit_control.view')];
    }

    /** `GET /credit-control/dashboard` — ড্যাশবোর্ডের যোগফল, হুবহু */
    public function dashboard(): JsonResponse
    {
        $figures = CreditDashboard::figures();
        $t = $figures['totals'];

        return response()->json([
            // ⓘ সীমার যাচাই বন্ধ — অঙ্ক কেবল দেখার জন্য, কোনো কাগজ আটকায় না
            'on' => (bool) $figures['on'],
            'totals' => [
                'limit' => (string) $t['limit'],
                'exposure' => (string) $t['exposure'],
                'available' => (string) $t['available'],
                'overdue' => (string) $t['overdue'],
                'used_percent' => $t['used_percent'] === null ? null : (string) $t['used_percent'],
                'over_limit' => (int) $t['over_limit'],
                'overdue_stopped' => (int) $t['overdue_stopped'],
                'blocked' => (int) $t['blocked'],
                'pending_requests' => (int) $t['pending_requests'],
            ],
            'ageing' => array_map(fn (string $bucket) => [
                'key' => $bucket,
                'label' => __('credit_control::report.bucket_'.$bucket),
                'amount' => (string) $figures['ageing'][$bucket],
            ], array_keys($figures['ageing'])),
        ]);
    }

    /** `GET /credit-control/lists/{list}?q=&page=` — বাকি নিয়ন্ত্রণের নিজের রিপোর্ট, ফোনের মাপে */
    public function list(Request $request, string $list): JsonResponse
    {
        abort_unless(isset(self::LISTS[$list]), 404);

        $reports = app(ReportEngine::class);
        $definition = $reports->get(self::LISTS[$list]);
        abort_unless($definition->allows($request->user()), 403);

        $page = max(1, (int) PhoneInput::text($request, 'page', '1'));
        $result = $reports->run(self::LISTS[$list], ['q' => PhoneInput::text($request, 'q')], $page, self::PER_PAGE);

        // ⓘ রিপোর্টের সারিতে গ্রাহকের ক্রমিক id — ফোনে যায় কেবল public_id, আর কেবল দেখার দেয়ালের ভিতরের গ্রাহকের
        $publicIds = Customer::query()->inViewedBranch()
            ->whereIn('customers.id', array_map(fn (array $r) => (int) ($r['id'] ?? 0), $result->rows) ?: [0])
            ->pluck('customers.public_id', 'customers.id');

        return response()->json([
            'list' => $list,
            'title' => __($definition->title),
            'rows' => array_values(array_map(function (array $row) use ($publicIds): array {
                $out = [
                    'customer' => (string) ($publicIds[(int) ($row['id'] ?? 0)] ?? ''),
                    'code' => (string) ($row['customer_code'] ?? ''),
                    'name' => (string) ($row['customer_name'] ?? ''),
                ];

                foreach (self::ROW_FIELDS as $field) {
                    if (array_key_exists($field, $row)) {
                        $out[$field] = $row[$field] === null ? null : (string) $row[$field];
                    }
                }

                return $out;
            }, $result->rows)),
            'page' => $result->page,
            'last_page' => $result->lastPage(),
            'total_rows' => $result->totalRows,
        ]);
    }

    /** `GET /credit-control/customers/{public_id}` — গ্রাহকের বাকির পাতা */
    public function show(Request $request, string $id): JsonResponse
    {
        $customer = $this->customer($id);
        $position = $this->credit->positionOf($customer);
        $risk = app(CreditRisk::class)->rate($customer);
        $user = $request->user();

        return response()->json([
            'id' => (string) $customer->public_id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name(),
            'credit_days' => $customer->credit_days === null ? null : (int) $customer->credit_days,
            'position' => [
                'limit' => (string) $position['limit'],
                'base_limit' => (string) $position['base_limit'],
                'temp_limit' => $position['temp_limit'],
                'temp_until' => $position['temp_until'],
                'due' => (string) $position['due'],
                'held' => (string) $position['held'],
                'uncleared' => (string) $position['uncleared'],
                'exposure' => (string) $position['exposure'],
                'available' => (string) $position['available'],
                'used_percent' => $position['used_percent'],
                'state' => (string) $position['state'],
                'state_label' => __('credit_control::state.'.$position['state']),
                'stop' => $position['stop'],
            ],
            'risk' => [
                'level' => $risk['level'],
                'label' => __('credit_control::risk.'.$risk['level']),
                'score' => (int) $risk['score'],
                'reasons' => array_values($risk['reasons']),
            ],
            'block' => $customer->isCreditBlocked() ? [
                'reason' => (string) $customer->credit_block_reason,
                'bounced_cheque' => $customer->credit_block_kind === Customer::BLOCK_BOUNCED_CHEQUE,
            ] : null,
            'bills' => array_map(fn (array $b) => [
                'no' => (string) $b['no'],
                'unpaid' => (string) $b['unpaid'],
                'due_on' => (string) $b['due_on'],
                'days_overdue' => (int) $b['days_overdue'],
            ], $this->credit->openBills([(int) $customer->id => $customer])[(int) $customer->id] ?? []),
            'requests' => CustomerCreditRequest::query()->where('customer_id', $customer->id)
                ->with(['requester', 'approval'])->latest('id')->limit(10)->get()
                ->map(fn (CustomerCreditRequest $r) => [
                    'id' => (string) $r->public_id,
                    'kind' => (string) $r->kind,
                    'current_limit' => (string) $r->current_limit,
                    'requested_limit' => (string) $r->requested_limit,
                    'temp_until' => $r->temp_until?->toDateString(),
                    'reason' => (string) $r->reason,
                    'status' => $r->shownStatus(),
                    'requested_by' => (string) ($r->requester?->name ?? ''),
                    'requested_at' => $r->created_at?->toIso8601String(),
                ])->values(),
            // ⓘ বোতাম দেখানোর প্রশ্ন — ওয়েবের পাতার একই উত্তর; পাহারা দরজায় আর সেবায়
            'can_request' => (bool) $user?->can('credit_control.request_limit'),
            'may_lift' => $this->mayLift($request, $customer),
        ]);
    }

    /** `POST /credit-control/customers/{public_id}/limit-requests` — [[CreditLimitRequestController::store()]]-এর একই যাচাই আর সেবা */
    public function requestLimit(Request $request, string $id): JsonResponse
    {
        $customer = $this->customer($id);

        $data = $request->validate([
            'kind' => ['required', Rule::in([CustomerCreditRequest::LIMIT, CustomerCreditRequest::TEMPORARY])],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'temp_until' => ['nullable', 'required_if:kind,'.CustomerCreditRequest::TEMPORARY, 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $made = app(CreditLimitRequests::class)->raise(
            $customer,
            $data['kind'],
            (string) $data['amount'],
            $data['kind'] === CustomerCreditRequest::TEMPORARY ? ($data['temp_until'] ?? null) : null,
            $data['reason'],
            null,
            $request->user(),
        );

        return response()->json([
            'id' => (string) $made->public_id,
            'status' => $made->shownStatus(),
            // ⓘ সইয়ে গেল, নাকি (মালিক নিজে, সই বন্ধ কোম্পানিতে) সরাসরি বসল
            'waiting_signature' => $made->approval_id !== null,
            'message' => __($made->approval_id === null ? 'customer::credit_request.saved_direct' : 'customer::credit_request.saved'),
        ], 201);
    }

    /** `POST /credit-control/customers/{public_id}/lift-block` — গ্রাহকের পাতার একই নীতি আর সেবা */
    public function liftBlock(Request $request, string $id): JsonResponse
    {
        $customer = $this->customer($id);
        Gate::authorize('update', $customer);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        app(CustomerService::class)->clearCreditBlock($customer, $data['reason']);

        return response()->json(['message' => __('customer::credit_block.cleared')]);
    }

    private function customer(string $publicId): Customer
    {
        return Customer::query()->inViewedBranch()->withOutstanding()->where('customers.public_id', $publicId)->firstOrFail();
    }

    /** ⓘ [[CreditProfileController::show()]]-এর `mayLift` হুবহু */
    private function mayLift(Request $request, Customer $customer): bool
    {
        return $customer->isCreditBlocked() && (bool) $request->user()?->can('update', $customer)
            && ($customer->credit_block_kind !== Customer::BLOCK_BOUNCED_CHEQUE || CustomerService::isSuperAdmin($request->user()));
    }
}
