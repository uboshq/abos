<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SaleTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ফোনের "ডেলিভারি ট্র্যাকিং" — `/api/v1/sales/tracking` (মালিক, ২ অক্টোবর ২০২৬)।
 *
 * ⛔ চাবি: ডেলিভারি দেখা বা আদেশ দেখা — যেকোনো একটা (`sales.delivery.view` | `sales.order.view`)।
 * অন্য কোম্পানি বা শাখার বিক্রি মডেলের নিজের দেয়ালে আটকায় (৪০৪)। ⓘ কোন ধাপ, কে কখন — [[SaleTracking]]।
 */
class SaleTrackingController extends Controller
{
    public function __construct(private readonly SaleTracking $tracking) {}

    /** `GET /api/v1/sales/tracking?q=&step=&customer=` */
    public function index(Request $request): JsonResponse
    {
        $this->mayTrack($request);

        $customer = trim(PhoneInput::text($request, 'customer', ''));
        // ⓘ ফোনে বাছা শাখার গ্রাহকই ([[ViewedBranch]])
        $customerId = $customer === '' ? null : (int) Customer::query()->inViewedBranch()
            ->when(Str::isUuid($customer), fn ($q) => $q->where('public_id', $customer), fn ($q) => $q->whereKey((int) $customer))
            ->firstOrFail()->id;

        return response()->json($this->tracking->list(
            trim(PhoneInput::text($request, 'q', '')) ?: null,
            $request->query('step') !== null ? PhoneInput::text($request, 'step') : null,
            $customerId,
        ));
    }

    /** `GET /api/v1/sales/tracking/{kind}/{id}` — kind: challan | order */
    public function show(Request $request, string $kind, string $id): JsonResponse
    {
        $this->mayTrack($request);

        return response()->json($this->tracking->story($this->inReach($request, $kind, $id)));
    }

    /**
     * `GET /sales/tracking` — ওয়েবের তালিকা, সাধারণ টুলবার আর টেবিলে (মালিক: "Delivery tracking টুলবার dibe")।
     * ⓘ খোঁজা, ধাপ (ট্যাব), তারিখ, গ্রাহক, শাখা, সাজানো; কলাম, রপ্তানি, ছাপা টেবিল নিজেই করে ([[x-ui.table]])।
     */
    public function page(Request $request, MenuBuilder $menu): View
    {
        $this->mayTrack($request);

        $step = in_array($request->query('step'), SaleTracking::STEPS, true) ? PhoneInput::text($request, 'step') : null;
        $term = trim(PhoneInput::text($request, 'q', '')) ?: null;
        $date = fn (string $key): ?string => ($v = trim(PhoneInput::text($request, $key, ''))) !== '' && strtotime($v) !== false
            ? date('Y-m-d', (int) strtotime($v)) : null;
        $dates = ['from' => $date('from'), 'to' => $date('to')];
        if ($dates['from'] !== null && $dates['to'] !== null && $dates['from'] > $dates['to']) {
            $dates = ['from' => $dates['to'], 'to' => $dates['from']];
        }
        $sort = in_array($request->query('sort'), ['recent', 'oldest', 'largest', 'customer'], true) ? PhoneInput::text($request, 'sort') : 'recent';

        $list = $this->tracking->list($term, $step, ((int) $request->query('customer')) ?: null, 500, [
            ...$dates,
            'branch_id' => ((int) $request->query('branch')) ?: null,
            'sort' => $sort,
        ]);

        $rows = collect($list['rows']);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, 50)->values(), $rows->count(), 50, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('sales::tracking.index', [
            'menu' => $menu->forUser($request->user()),
            'list' => $list,
            'rows' => $paginator,
            'grand' => ['total' => $rows->reduce(fn (string $sum, array $r) => bcadd($sum, (string) $r['total'], 2), '0.00')],
            'step' => $step,
            'q' => $term,
            'dates' => $dates,
            'sortOptions' => [
                'recent' => __('sales::sort.recent'), 'oldest' => __('sales::sort.oldest'),
                'largest' => __('sales::sort.largest'), 'customer' => __('sales::sort.customer'),
            ],
            'customers' => Customer::query()->inViewedBranch()->active()->with(Customer::PLACE)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_bn', 'location_id', 'branch_id', 'is_active']),
            'branches' => \App\Models\Branch::query()->orderBy('name_en')->get(),
        ]);
    }

    /** `GET /sales/tracking/{kind}/{id}` — একটা বিক্রির সময়রেখা; তালিকা থেকে পপ-আপে খোলে */
    public function story(Request $request, MenuBuilder $menu, string $kind, string $id): View
    {
        $this->mayTrack($request);

        return view('sales::tracking.show', [
            'menu' => $menu->forUser($request->user()),
            'sale' => $this->tracking->story($this->inReach($request, $kind, $id)),
        ]);
    }

    /**
     * ⛔ বিক্রিটা মানুষের শাখার নাগাল ধরে খোঁজা, হেডারে বাছা শাখা ধরে নয় — লাইভ-হটফিক্স, ২৩ অক্টোবর ২০২৬ (মালিকের অভিযোগ:
     * "নোটিফিকেশনে ক্লিক করলে দেখা যায় না")। ⓘ ডেলিভারির ধাপের নোটিফিকেশন এই ঠিকানায় আনে; আগে খোঁজা হত শাখার দেয়াল দিয়ে
     * ([[ScopedToUserBranch]] — হেডারে এক শাখা বাছা থাকলে কেবল সেই শাখা), তাই মালিক হেডারে এক শাখায় থাকলে অন্য শাখার চালান ৪০৪
     * দিত। ভাউচারের একই ভুল একই নিয়মে সারানো ([[OpensInReach::opensFor()]]): মালিক ও সীমাহীন মানুষ সব শাখা, শাখায় সীমিত মানুষ
     * নিজের শাখা, শাখাহীন কাগজ সবাই; নাগালের বাইরে আগের মতো ৪০৪। ⓘ ডিলারের দেয়াল ([[ScopedToUserDealers]]) আলাদা স্কোপ, খাটে।
     */
    private function inReach(Request $request, string $kind, string $id): DeliveryChallan|SalesOrder
    {
        $class = match ($kind) {
            'challan' => DeliveryChallan::class,
            'order' => SalesOrder::class,
            default => abort(404),
        };

        $sale = $class::acrossBranches()->where('public_id', $id)->first();
        $branch = $sale?->getAttribute('branch_id');

        abort_unless($sale !== null && app(DataScope::class)->allows($request->user(), UserDataScope::BRANCH, $branch === null ? null : (int) $branch), 404);

        return $sale;
    }

    private function mayTrack(Request $request): void
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('sales.delivery.view') || $user->can('sales.order.view')), 403);
    }
}
