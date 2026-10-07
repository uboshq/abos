<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\PaperToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ ফোনে "আজকের ডেলিভারি" — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৭, ৬ অক্টোবর ২০২৬ (সমন্বয়কের প্রস্তাব ঘ)।
 *
 * ⓘ পথে থাকা চালান — রওনা হয়েছে, এখনো পৌঁছায়নি — দেখার শাখায়, আগে রওনা আগে। প্রতিটায় ক্রেতা, ফোন, ঠিকানা, গাড়ি আর
 * চালক, মালের সারি, আর কাগজের সই-করা টোকেন: "পৌঁছেছে" বা "আংশিক" সেই QR-এর একই দরজায় যায়
 * ([[QrScanController::deliver()]]) — দ্বিতীয় কোনো পথ নয়।
 *
 * ⛔ চাবি ডেলিভারি বদলানোর (`sales.delivery.update`) — যিনি পৌঁছানো লিখতে পারেন। ⓘ চালক এখনো সিস্টেমের ব্যবহারকারী নন
 * (কেবল নাম আর ফোন লেখা), তাই "আমার" নয়, শাখার সব — মালিকের উত্তর (প্র২) এলে বদলাবে।
 */
class DeliveryRunApiController extends Controller implements HasMiddleware
{
    private const PER_PAGE = 50;

    public function __construct(private readonly PaperToken $tokens) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.delivery.update')];
    }

    /** `GET /api/v1/sales/deliveries` */
    public function index(): JsonResponse
    {
        $onTheWay = DB::table('sal_delivery_states')->where('company_id', CompanyContext::id())
            ->where('stage', DeliveryStage::DISPATCHED)->select('delivery_challan_id');

        $challans = ViewedBranch::narrow(DeliveryChallan::query(), 'sal_challans.branch_id')
            ->whereIn('sal_challans.id', $onTheWay)
            // ⓘ গাড়ি আর বাহক পাতায় একবার — আগে প্রতিটা চালানে আলাদা ডাক ([[DeliveryChallan::transportFacts()]]; অডিট ফোন ⚠️১৭)
            ->with(['customer.location.parent', 'lines.product', 'vehicle.vehicleType', 'carrier'])
            ->orderBy('sal_challans.trx_date')->orderBy('sal_challans.id')
            // ⓘ পাতা ভাগ — ৫০টা করে, পরের পাতার নম্বরসহ ([[EveryListScreenPaginatesTest]])
            ->paginate(self::PER_PAGE);

        return response()->json([
            'next_page' => $challans->hasMorePages() ? $challans->currentPage() + 1 : null,
            'rows' => collect($challans->items())->map(function (DeliveryChallan $c): array {
                $t = $c->transportFacts();

                return [
                    'token' => $this->tokens->for($c),
                    'document_no' => (string) $c->document_no,
                    'sale_no' => $c->sale_no,
                    'date' => $c->trx_date?->toDateString(),
                    'customer' => (string) ($c->customer?->name() ?? ''),
                    // ⭐ পয়েন্ট — মালিক, ৭ অক্টোবর ২০২৬: "app e sob jaygay customer er pase obosoi point dibe" ([[Customer::pointName()]])
                    'customer_point' => $c->customer?->pointName(),
                    'phone' => (string) ($c->customer?->phone ?? ''),
                    'address' => (string) ($c->customer?->address() ?? ''),
                    'vehicle' => $t['vehicle_no'] ?? null,
                    'driver' => trim(implode(' · ', array_filter([$t['driver_name'] ?? null, $t['driver_phone'] ?? null]))) ?: null,
                    'lines' => $c->lines->map(fn ($l) => [
                        'line' => (int) $l->line_no,
                        'product' => $l->product?->name(),
                        'qty' => bcadd((string) $l->delivered_qty, '0', 4),
                    ])->values()->all(),
                ];
            })->values(),
        ]);
    }
}
