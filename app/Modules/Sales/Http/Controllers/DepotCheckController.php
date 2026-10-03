<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\CounterSaleSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ডিপোর যাচাই — হিসাবে অনুমোদিত DO-র তালিকা (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)।
 *
 * ⭐ কাউন্টারের লোক বা ম্যানেজার সতর্কবার্তা দেখেন (মজুদ কম "অর্ডার কমান", দর লটের সাথে মেলে না, পরিমাণ বদলানো,
 * সীমার কাছে, লটের মেয়াদ — abos-86-এর `accounts_warnings`), তারপর "যাচাই করে বিক্রয়ে খুলুন" — সরাসরি বিক্রয়ের পাতা,
 * সারি আগে থেকে ভরা ([[DirectSaleController::create()]] `?source=do`)।
 *
 * ⓘ DeliveryOrder (abos-2c) আসার আগেও পাতাটা চলে — `class_exists`, আর তখন খালি তালিকায় কারণটা লেখা; মডেল এলে নিজে
 * থেকেই ভরে। ⛔ কোম্পানি আর শাখার দেয়াল মডেলের নিজের স্কোপে। চাবি কাউন্টারেরই — যিনি বেচেন তিনিই যাচাই করেন।
 */
final class DepotCheckController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** নাম লেখা, `use` নয় — ক্লাস দুইটা না থাকলেও এই ফাইল চলে */
    private const DELIVERY_ORDER = 'App\\Modules\\Sales\\Models\\DeliveryOrder';

    private const STATUS = 'App\\Modules\\Sales\\Support\\DeliveryOrderStatus';

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.challan.create')];
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $model = self::DELIVERY_ORDER;
        $ready = class_exists($model) && class_exists(self::STATUS);

        $query = $ready
            ? $model::query()
                ->whereIn('status', [constant(self::STATUS.'::ACCOUNTS_APPROVED'), constant(self::STATUS.'::DEPOT_CHECK')])
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                    ->where('document_no', 'like', '%'.$q.'%')
                    ->orWhereHas('customer', fn ($c) => $c->where(fn ($n) => $n
                        ->where('name_en', 'like', '%'.$q.'%')
                        ->orWhere('name_bn', 'like', '%'.$q.'%')
                        ->orWhere('code', 'like', '%'.$q.'%')))))
            : null;

        return view('sales::depot_check.index', [
            'menu' => $this->menu->forUser($request->user()),
            'orders' => $query === null
                ? new LengthAwarePaginator([], 0, 50)
                : (clone $query)->with(['customer', 'lines'])->orderBy('id')->paginate(50)->withQueryString(),
            // ⓘ সর্বমোট — গোটা ছাঁকা তালিকার, সব পাতার ([[GrandTotals]])
            'grand' => $query === null ? [] : $this->grandTotals($query, ['total' => 't.total']),
            'ready' => $ready,
        ]);
    }

    /**
     * ⭐ "যাচাই করে বিক্রয়ে খুলুন" — POST, কারণ এটা অবস্থা বদলায় (সমন্বয়ক, ৩ অক্টোবর ২০২৬: GET কিছু বদলায় না)।
     *
     * ⓘ উৎস খোঁজা (⛔ না পেলে ৪০৪ — দেয়াল মডেলের নিজের স্কোপে) → এক লেনদেনে, তালা দিয়ে: "খোলার মতো?" আর ডিপো
     * যাচাইয়ে তোলা ([[CounterSaleSource::enterDepotCheck()]]) → সরাসরি বিক্রয়ের পাতা, সারি ভরা। খোলার মতো না হলে
     * তালিকায় ফেরে, কারণসহ।
     */
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:16'],
            'source_id' => ['required', 'integer', 'min:1'],
        ]);

        $source = app(CounterSaleSources::class)->find($data['source'], (int) $data['source_id']);

        abort_if($source === null, 404);

        try {
            DB::transaction(function () use ($source): void {
                $source->assertReadyForCounter();
                $source->enterDepotCheck();
            });
        } catch (ValidationException $e) {
            return redirect()->route('sales.direct.depot_check')->withErrors($e->errors());
        }

        return redirect()->route('sales.direct.create', ['source' => $data['source'], 'source_id' => (int) $data['source_id']]);
    }

    /**
     * সতর্কবার্তার এক লাইন — abos-86-এর ধরন ধরে ({kind, line_id, …}); অচেনা ধরন হলে নামটাই।
     *
     * @param  array<string, mixed>  $warning
     */
    public static function warningText(array $warning): string
    {
        $kind = (string) ($warning['kind'] ?? '');
        $known = ['stock_short', 'rate_vs_lot', 'qty_changed', 'limit_near', 'lot_expiring'];
        $values = array_map(
            fn ($v) => is_scalar($v) ? (string) $v : '',
            array_filter($warning, fn ($k) => $k !== 'kind', ARRAY_FILTER_USE_KEY),
        );

        return in_array($kind, $known, true)
            ? __('sales::counter_source.warning.'.$kind, $values)
            : __('sales::counter_source.warning.other', ['kind' => $kind]);
    }
}
