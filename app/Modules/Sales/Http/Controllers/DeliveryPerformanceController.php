<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\DeliveryPerformance;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ডেলিভারির মাপকাঠি — OTIF %, আদেশ থেকে রওনার গড় সময়, দেরির তালিকা (মালিকের বিক্রয়-পরিকল্পনা, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ হিসাব সব [[DeliveryPerformance]]-এ; এখানে কেবল সময়ের সীমা। সীমা না দিলে এ মাস — শুরু থেকে আজ।
 * ⛔ চাবি: ডেলিভারির তালিকার একই (`sales.delivery.view`) — যিনি ডেলিভারি দেখেন, তিনিই তার মাপকাঠি দেখেন।
 */
final class DeliveryPerformanceController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DeliveryPerformance $performance,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.delivery.view')];
    }

    public function index(Request $request): View
    {
        $from = $this->date($request->query('from')) ?? Carbon::today()->startOfMonth()->toDateString();
        $to = $this->date($request->query('to')) ?? Carbon::today()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return view('sales::delivery-performance.index', [
            'menu' => $this->menu->forUser($request->user()),
            'dates' => ['from' => $from, 'to' => $to],
            'summary' => $this->performance->summary($from, $to),
            'late' => $this->performance->late($from, $to)->paginate(50)->withQueryString(),
        ]);
    }

    private function date(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 && strtotime($raw) !== false ? $raw : null;
    }
}
