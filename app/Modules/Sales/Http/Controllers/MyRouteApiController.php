<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\RouteVisit;
use App\Modules\Sales\Services\RouteMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনে "আমার আজকের রুট" — সাপ্তাহিক ছকে আজ যে রুটে আমার নাম, সেই রুটের দোকানগুলো (মালিকের মোবাইল-চেকলিস্ট §২
 * "রুট প্ল্যান, দৈনিক কাজ"; সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
 *
 * ⓘ ছক ওয়েবের রুট-পাতার ([[RouteAccountController]], [[RouteVisitService]]): রুট, মানুষ, সপ্তাহের দিন, কবে থেকে কবে।
 * দোকান প্রতি অঙ্ক রুট-পাতার একই হিসাবে ([[RouteMetrics::forCustomers()]]) — মাসের বিক্রি, আদায় আর বকেয়া; ফোনে
 * নিজে গোনা নয়।
 * ⛔ কেবল নিজের ছক — অন্যের রুট এখানে নেই; দোকানের তালিকা ফোনে বাছা শাখার ভিতরে ([[ViewedBranch]])।
 */
class MyRouteApiController extends Controller implements HasMiddleware
{
    public function __construct(private readonly RouteMetrics $metrics) {}

    public static function middleware(): array
    {
        // ⓘ মাঠের মানুষের চাবি — আদেশ দেখার; রুট-পাতার নিজের চাবি (`sales.route.view`) ব্যবস্থাপকের
        return [new Middleware('can:sales.order.view')];
    }

    /** `GET /my-route?date=YYYY-MM-DD` — না দিলে আজ */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::today();

        $visits = RouteVisit::query()
            ->with('route')
            ->where('user_id', $request->user()->id)
            ->where('weekday', $day->dayOfWeek)
            ->activeOn($day)
            ->get()
            ->filter(fn (RouteVisit $v) => $v->route !== null)
            ->unique('route_id')
            ->values();

        $from = $day->copy()->startOfMonth()->toDateString();
        $to = $day->toDateString();

        // ⭐ দোকান পাতায় ৫০ — সব রুট মিলিয়ে, রুটের ক্রমে তারপর কোডে; প্রতিটা দোকান বলে কোন রুটের
        $shops = Customer::query()
            ->inViewedBranch()
            ->active()
            ->with('location:id,public_id')
            ->whereIn('location_id', $visits->pluck('route_id')->all() ?: [0])
            ->orderBy('location_id')->orderBy('code')
            ->paginate(50);
        $figures = $this->metrics->forCustomers($shops->getCollection()->modelKeys(), $from, $to);

        return response()->json([
            'date' => $day->toDateString(),
            'weekday' => $day->dayOfWeek,
            'routes' => $visits->map(fn (RouteVisit $visit) => [
                'id' => (string) $visit->route->public_id,
                'name' => (string) ($visit->route->name_bn ?: $visit->route->name_en),
            ])->values()->all(),
            'shops' => collect($shops->items())->map(fn (Customer $c) => [
                'id' => (string) $c->public_id,
                'route' => (string) $c->location?->public_id,
                'code' => (string) $c->code,
                'name' => $c->name(),
                'phone' => $c->phone,
                'address' => $c->address_bn ?: $c->address_en,
                // ⓘ মাসের শুরু থেকে এই দিন পর্যন্ত, রুট-পাতার একই অঙ্ক; বকেয়া এই দিনে
                'sales' => bcadd($figures[(int) $c->id]['sales'] ?? '0', '0', 2),
                'collections' => bcadd($figures[(int) $c->id]['collections'] ?? '0', '0', 2),
                'outstanding' => bcadd($figures[(int) $c->id]['outstanding'] ?? '0', '0', 2),
            ])->values()->all(),
            'next_page' => $shops->hasMorePages() ? $shops->currentPage() + 1 : null,
        ]);
    }
}
