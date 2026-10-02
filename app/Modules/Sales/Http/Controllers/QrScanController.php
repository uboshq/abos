<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\PaperToken;
use App\Modules\Sales\Services\ScannedPaper;
use App\Modules\Sales\Services\TransportRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * এক কাগজে এক QR — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬।
 *
 * ── ⭐ কে কী পায় ────────────────────────────────────────────────────────
 * QR-এ কেবল সই-করা টোকেন ([[PaperToken]])। স্ক্যান করলে:
 *   ওয়েব — লগইন থাকুক বা না থাকুক, পুরনো স্ক্যান-দরজায় যায় ([[DeliveryScanController::open()]]):
 *          লগইন ছাড়া কিছুই দেখায় না; কর্মী তাঁর পাতায়, ডিলার তাঁর পোর্টালে।
 *   ফোন  — কাগজটা JSON-এ (দোকান, লাইন: পণ্য · পরিমাণ · ফ্রি · লট, গাড়ি, বিলের মোট); ভূমিকা ধরে বোতাম:
 *          গেটম্যান "মাল বেরোল", ডেলিভারিম্যান/SR "ডেলিভারি নিশ্চিত"; বাকি আর বিল কেবল যাঁর চাবি আছে।
 *
 * ── ⛔ গেট থেকে বেরোনো ────────────────────────────────────────────────────
 * গাড়ি/পরিবহন বসানো না থাকলে ফেরে ([[TransportRule]])। বাকির সীমা পার হলে ফেরে — বেরোনো মানে বিল
 * কাটা ([[DispatchBill]]), আর বিল দেয়াল পেরোয় না ([[CreditExposure::assertRoomLocked()]]); কেউ সীমা পার
 * করাতে পারেন না। একবারই — দ্বিতীয় স্ক্যান বলে কবে কে বের করেছিলেন। প্রতিটা স্ক্যান নিরীক্ষায়।
 *
 * ⓘ অন্য কোম্পানির টোকেন সই মেলে না (৪০৪); নিজের কোম্পানির অন্য শাখার কাগজ দেয়ালে আটকায় (৪০৪)।
 */
class QrScanController extends Controller
{
    public function __construct(
        private readonly PaperToken $tokens,
        private readonly DeliveryStageService $stages,
        private readonly TransportRule $transport,
        private readonly ScannedPaper $paper,
    ) {}

    /** `GET /q/{token}` — ওয়েব; কাগজের ঠিকানায় পাঠায়, নিজে কিছু দেখায় না */
    public function open(string $token): RedirectResponse
    {
        $publicId = $this->tokens->resolve($token) ?? abort(404);

        return redirect()->route('sales.scan', $publicId);
    }

    /** `GET /api/v1/sales/scan/{token}` */
    public function show(Request $request, string $token): JsonResponse
    {
        $challan = $this->challan($token);
        abort_unless($request->user()?->can('sales.delivery.view'), 403);

        app(AuditEngine::class)->recordAction($challan, 'qr.scanned');

        return response()->json($this->facts($request, $challan));
    }

    /** `POST /api/v1/sales/scan/{token}/gate-out` — "মাল বেরোল" */
    public function gateOut(Request $request, string $token): JsonResponse
    {
        $challan = $this->challan($token);
        abort_unless($request->user()?->can('sales.delivery.update'), 403);

        $out = $this->gatePass($challan);

        if ($out !== null) {
            return response()->json([
                'message' => __('sales::qr.already_out', [
                    'at' => $out->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                    'by' => $out->issuer?->name ?? '—',
                ]),
                'gate_out' => $this->outFacts($out),
            ], 409);
        }

        $this->transport->assertNamed($challan);

        DB::transaction(function () use ($challan) {
            $this->stages->move($challan, DeliveryStage::DISPATCHED, ['note' => __('sales::qr.gate_out_note')]);
            app(AuditEngine::class)->recordAction($challan, 'qr.gate_out');
        });

        return response()->json($this->facts($request, $challan->fresh()));
    }

    /** `POST /api/v1/sales/scan/{token}/deliver` — "ডেলিভারি নিশ্চিত" */
    public function deliver(Request $request, string $token): JsonResponse
    {
        $challan = $this->challan($token);
        abort_unless($request->user()?->can('sales.delivery.update'), 403);

        $data = $request->validate([
            'receiver_name' => ['required', 'string', 'max:120'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
        ]);

        DB::transaction(function () use ($challan, $data) {
            $this->stages->move($challan, DeliveryStage::DELIVERED, $data + ['note' => __('sales::qr.delivered_note')]);
            app(AuditEngine::class)->recordAction($challan, 'qr.delivered');
        });

        return response()->json($this->facts($request, $challan->fresh()));
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** সই মিলিয়ে, তারপর সাধারণ দেয়ালের ভেতর দিয়ে — অন্য কোম্পানি/শাখা হলে ৪০৪ */
    private function challan(string $token): DeliveryChallan
    {
        $publicId = $this->tokens->resolve($token) ?? abort(404);

        return $this->paper->forStaff($publicId);
    }

    private function gatePass(DeliveryChallan $challan): ?GatePass
    {
        return GatePass::query()->withoutGlobalScope('user-branch')
            ->where('delivery_challan_id', $challan->id)
            ->where('status', '!=', DocumentStatus::CANCELLED)
            ->with('issuer')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function facts(Request $request, DeliveryChallan $challan): array
    {
        $challan->loadMissing(['customer', 'lines.product', 'lines.batch']);
        $user = $request->user();
        $stage = (string) $this->stages->ensure($challan)->stage;
        $out = $this->gatePass($challan);
        $mayMove = (bool) $user?->can('sales.delivery.update');
        $invoices = $this->paper->invoicesOf($challan)->whereIn('status', DocumentStatus::POSTED);
        $seesMoney = (bool) $user?->can('customer.view');

        return [
            'document_no' => $challan->document_no,
            'sale_no' => $challan->sale_no ?? null,
            'trx_date' => $challan->trx_date?->toDateString(),
            'status' => (string) $challan->status,
            'stage' => $stage,
            'customer' => ['name' => $challan->customer?->name(), 'code' => $challan->customer?->code],
            'lines' => $challan->lines->map(fn ($l) => [
                'product' => $l->product?->name(),
                'qty' => bcadd((string) $l->delivered_qty, '0', 4),
                'free_qty' => bcadd((string) ($l->free_qty ?? '0'), '0', 4),
                'lot' => $l->batch?->batch_no,
            ])->values()->all(),
            // ⭐ গেট পাসের কাগজের হুবহু তথ্য ([[DeliveryChallan::transportFacts()]], ২ অক্টোবর ২০২৬)
            'transport' => (function () use ($challan): array {
                $t = $challan->transportFacts();

                return [
                    'named' => TransportRule::named($challan),
                    'mode' => $t['mode'],
                    'vehicle' => $t['vehicle_no'],
                    'vehicle_type' => $t['vehicle_type'],
                    'driver' => $t['driver_name'],
                    'driver_phone' => $t['driver_phone'],
                    'carrier' => $t['carrier'],
                    'cost' => $t['cost'],
                    'own' => (bool) $challan->own_transport,
                ];
            })(),
            'gate_out' => $out !== null ? $this->outFacts($out) : null,
            'bill_total' => $seesMoney || $mayMove
                ? $invoices->reduce(fn (string $sum, $i) => bcadd($sum, (string) $i->total, 4), '0.0000')
                : null,
            'customer_balance' => $seesMoney ? bcadd((string) $challan->customer?->outstanding(), '0', 4) : null,
            'actions' => [
                'gate_out' => $mayMove && $out === null && in_array($stage, [DeliveryStage::ALLOCATED, DeliveryStage::PICKING, DeliveryStage::PACKED], true),
                'deliver' => $mayMove && in_array($stage, [DeliveryStage::DISPATCHED], true),
            ],
        ];
    }

    /** @return array{at: ?string, by: ?string, vehicle: ?string} */
    private function outFacts(GatePass $out): array
    {
        return [
            'at' => $out->issued_at?->toIso8601String(),
            'by' => $out->issuer?->name,
            'vehicle' => $out->vehicle_no,
        ];
    }
}
