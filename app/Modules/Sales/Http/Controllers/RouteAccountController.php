<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\Sales\Models\RouteVisit;
use App\Modules\Sales\Services\RouteMetrics;
use App\Modules\Sales\Services\RouteVisitService;
use App\Modules\Sales\Services\SalesTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * রুটের খাতা — তালিকা, একটা রুটের পাতা, সাপ্তাহিক ছক আর মাসের লক্ষ্য (NEXUS §২৭)।
 *
 * ── দুইটা চাবি ──────────────────────────────────────────────────────
 * দেখা (`sales.route.view`) আর ছক/লক্ষ্য বসানো (`sales.route.manage`) আলাদা:
 * নিজের রুটের লক্ষ্য নিজে বদলাতে পারলে ওটা আর লক্ষ্য নয়, ইচ্ছা
 * ([[SalesTargetController]]-এর একই যুক্তি)।
 *
 * ⚠️ বিক্রয়কর্মী কেবল নিজের এলাকা দেখবেন (মালিক, ২৬ সেপ্টেম্বর) — সেই ছাঁকনি
 * বাঁধনের কাজ, এখানে নয়। তাই এই পর্দার চাবি আপাতত কেবল ম্যানেজারের ধাপে।
 */
class RouteAccountController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly RouteMetrics $metrics,
        private readonly RouteVisitService $visits,
        private readonly SalesTargetService $targets,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.route.view', only: ['index', 'show']),
            new Middleware('can:sales.route.manage', only: ['assign', 'end', 'target']),
        ];
    }

    /**
     * সব রুট, এক মাসের অঙ্কসহ — পাতা ভাগ করে, আর অঙ্ক কেবল চোখের সামনের রুটগুলোর।
     */
    public function index(Request $request): View
    {
        [$month, $from, $to] = $this->period($request);

        $routes = Location::query()
            ->atLevel(Location::ROUTE)
            ->with('parent')
            ->when($this->text($request, 'q'), function ($q, string $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

                $q->where(fn ($w) => $w->where('code', 'like', $like)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_bn', 'like', $like));
            })
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $ids = $routes->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return view('sales::route.index', [
            'menu' => $this->menu->forUser($request->user()),
            'month' => $month,
            'q' => $this->text($request, 'q'),
            'routes' => $routes,
            'figures' => $this->metrics->forRoutes($ids, $from, $to),
            'targets' => $this->metrics->targetsFor($ids, $month),
            'people' => $this->metrics->salespeopleOn($ids, $this->asOf($to)),
            'unrouted' => $this->metrics->unroutedCount(),
        ]);
    }

    /**
     * একটা রুট — তার ডিলার, ছক, লক্ষ্য।
     *
     * ⓘ রাউট-বাইন্ডিং Eloquent-এ, কোম্পানির স্কোপসহ — অন্য কোম্পানির রুট ৪০৪।
     */
    public function show(Request $request, Location $location): View
    {
        abort_unless($location->isRoute(), 404);

        [$month, $from, $to] = $this->period($request);

        $customers = Customer::query()
            ->inViewedBranch()
            ->where('location_id', $location->id)
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $customerIds = $customers->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $schedule = RouteVisit::query()
            ->with('user:id,name')
            ->where('route_id', $location->id)
            ->orderByRaw('effective_to IS NULL DESC')
            ->orderByDesc('effective_from')
            ->get();

        return view('sales::route.show', [
            'menu' => $this->menu->forUser($request->user()),
            'route' => $location->loadMissing('parent'),
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'totals' => $this->metrics->forRoutes([(int) $location->id], $from, $to)[(int) $location->id],
            'target' => $this->metrics->targetsFor([(int) $location->id], $month)[(int) $location->id],
            'customers' => $customers,
            'figures' => $this->metrics->forCustomers($customerIds, $from, $to),
            'schedule' => $schedule,
            'today' => Carbon::today(),
            'candidates' => $request->user()->can('sales.route.manage') ? $this->visits->candidates() : collect(),
        ]);
    }

    public function assign(Request $request, Location $location): RedirectResponse
    {
        abort_unless($location->isRoute(), 404);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['required', 'integer', 'between:0,6'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
        ], [], $this->attributes());

        $this->visits->assign($location, $data);

        return redirect()
            ->route('sales.route.show', $location)
            ->with('saved', __('sales::route.saved_visit'));
    }

    /**
     * ছকের একটা ঘর শেষ — মোছা নয় (হাতবদলের ইতিহাস থাকে)।
     *
     * ⓘ `{visit}` কোম্পানির স্কোপে বাঁধা — অন্য কোম্পানির ঘর ৪০৪।
     */
    public function end(Request $request, RouteVisit $visit): RedirectResponse
    {
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
        ], [], $this->attributes());

        $this->visits->end($visit, $data['effective_to']);

        return redirect()
            ->route('sales.route.show', $visit->route_id)
            ->with('saved', __('sales::route.saved_end'));
    }

    public function target(Request $request, Location $location): RedirectResponse
    {
        abort_unless($location->isRoute(), 404);

        $data = $request->validate([
            'month' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ], [], $this->attributes());

        $month = $this->targets->readMonth($data['month']);
        $this->targets->assertMonthIsSane($month);

        $this->visits->setTarget($location, $month, isset($data['amount']) ? (string) $data['amount'] : null);

        return redirect()
            ->route('sales.route.show', [$location, 'month' => $month->format('Y-m')])
            ->with('saved', __('sales::route.saved_target'));
    }

    /**
     * মাস — আজে-বাজে লেখা এলে চলতি মাস ([[SalesTargetService::readMonth()]])।
     *
     * ⓘ চলতি মাসে শেষ তারিখ আজ, মাসের শেষ নয় — নাহলে "শেষ জের" ভবিষ্যতের
     * একটা তারিখের নামে দেখাত।
     *
     * @return array{0: Carbon, 1: string, 2: string}
     */
    private function period(Request $request): array
    {
        $month = $this->targets->readMonth($this->text($request, 'month'));
        $end = $month->copy()->endOfMonth();

        if ($end->isFuture()) {
            $end = Carbon::today()->max($month);
        }

        return [$month, $month->toDateString(), $end->toDateString()];
    }

    private function asOf(string $to): Carbon
    {
        return Carbon::parse($to);
    }

    /**
     * ভুলের বার্তায় পর্দার নাম — কলামের নাম নয়।
     *
     * ⓘ না দিলে Laravel লিখত "effective from", আর ঐ শব্দ পর্দার কোথাও নেই
     * ([[ARefusalSpeaksTheUsersLanguageTest]]-এর একই কারণ)।
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'user_id' => __('sales::route.who'),
            'weekdays' => __('sales::route.weekday'),
            'weekdays.*' => __('sales::route.weekday'),
            'effective_from' => __('sales::route.from'),
            'effective_to' => __('sales::route.to'),
            'narration' => __('sales::route.narration'),
            'month' => __('sales::route.month'),
            'amount' => __('sales::route.target'),
        ];
    }

    /**
     * ঠিকানার একটা ঘর — কেবল লেখা হলে।
     *
     * ⛔ `?month[]=x` বা `?q[]=x` লিখলে অ্যারে আসে; `(string)` করলে PHP
     * সতর্কবার্তা দেয় আর পাতাটা ৫০০ — তাই অ্যারে মানে "কিছু লেখা হয়নি"।
     */
    private function text(Request $request, string $key): string
    {
        $raw = $request->query($key);

        return is_string($raw) ? trim($raw) : '';
    }
}
