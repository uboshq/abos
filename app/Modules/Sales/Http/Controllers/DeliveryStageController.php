<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Core\Support\CompanyContext;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ডেলিভারি — কোন চালানের মাল এখন কোথায়, আর হাতে ধাপ বসানো।
 *
 * ── ⓘ কেন চালানের চাবি থেকে আলাদা চাবি ───────────────────────────────
 * চালান কাটেন বিক্রয়ের লোক; মাল তোলা, প্যাক করা আর "পৌঁছেছে" লেখা
 * গুদাম ও ডেলিভারির লোকের কাজ — ট্রিপের চাবি যেমন আলাদা, তেমনই।
 * ⚠️ দেখা ও বসানোও আলাদা: বিক্রয়কর্মী জানতে চান মাল পৌঁছাল কি না,
 * কিন্তু তিনি নিজে "পৌঁছেছে" লিখতে পারলে প্রমাণটার দাম থাকত না।
 */
class DeliveryStageController extends Controller implements HasMiddleware
{
    use GrandTotals;

    public function __construct(
        private readonly DeliveryStageService $stages,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.delivery.view', only: ['index', 'show']),
            new Middleware('can:sales.delivery.update', only: ['move']),
        ];
    }

    public function index(Request $request): View
    {
        $tabs = ['open', ...DeliveryStage::ALL];
        $tab = in_array($request->query('stage'), $tabs, true) ? (string) $request->query('stage') : 'open';
        $term = trim((string) $request->query('q'));

        /*
         * ⓘ `whereHas('challan')` — চালানের কোম্পানি ও শাখার ছাঁকনি দুইটাই
         * এখানে খাটে; ধাপের সারির নিজের কেবল কোম্পানির ছাঁকনি আছে।
         */
        $list = DeliveryState::query()
            ->inTab($tab)
            ->whereHas('challan', fn (Builder $q) => $q->search($term === '' ? null : $term))
            ->with(['challan.customer', 'challan.vehicle'])
            ->orderByDesc('stage_at')
            ->orderByDesc('id');

        // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে, সারির টাকা চালানের ([[GrandTotals]])
        $grand = $this->grandTotals($list, ['total' => '(SELECT COALESCE(c.total, 0) FROM sal_challans c WHERE c.id = t.delivery_challan_id)']);
        $rows = $list->paginate(50)->withQueryString();

        /*
         * ⭐ সারির "পরের ধাপ" — কেবল ধাপ বদলানোর চাবিধারীর জন্য (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।
         * ⓘ বোতামের তালিকা সেবার নিয়মেই ([[DeliveryStageService::manualChoices()]]), আর ট্রিপে থাকা
         * চালানে বোতাম নেই — ওর খবর ট্রিপ দেয়। পাতাপ্রতি ৫০ সারি, তাই সারি ধরে প্রশ্ন সয়।
         */
        $canMove = (bool) $request->user()?->can('sales.delivery.update');
        $next = [];

        if ($canMove) {
            foreach ($rows->items() as $row) {
                if ($row->challan !== null) {
                    $next[$row->id] = [
                        'choices' => $this->stages->manualChoices($row->challan),
                        'trip' => $this->stages->activeTrip($row->challan),
                    ];
                }
            }
        }

        return view('sales::delivery.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'grand' => $grand,
            'next' => $next,
            'vehicles' => $canMove
                ? Vehicle::query()->where('is_active', true)->orderBy('registration_no')->get()
                : collect(),
            'tabs' => $tabs,
            'tab' => $tab,
            'counts' => $this->stages->counts(),
            'q' => $term,
        ]);
    }

    public function show(Request $request, DeliveryChallan $challan): View
    {
        $challan->load(['customer', 'warehouse', 'vehicle', 'lines.product']);

        return view('sales::delivery.show', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => $challan,
        ]);
    }

    /**
     * হাতে একটা ধাপ বসানো।
     *
     * ⓘ এখানে কেবল আকার দেখা হয়; নিয়ম — কোন ধাপ থেকে কোথায়, কারণ, প্রমাণ,
     * পরিমাণ — সবই সেবায়, যাতে পর্দা ছাড়া অন্য পথেও (মোবাইল, কনসোল) একই
     * নিয়ম খাটে।
     */
    public function move(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'string', Rule::in(DeliveryStage::ALL)],
            'note' => ['nullable', 'string', 'max:500'],
            'reason_code_id' => ['nullable', 'integer'],
            'receiver_name' => ['nullable', 'string', 'max:191'],
            'receiver_phone' => ['nullable', 'string', 'max:32'],
            'lines' => ['nullable', 'array'],
            // ⓘ আকার সেবা দেখে ([[DeliveryStageService::partialLines()]]) — ঋণাত্মক, বেশি, অন্য চালানের সারি
            'lines.*' => ['nullable'],
            // ⭐ ভাঙা পৌঁছানো পরিমাণ, সারি ধরে (ধাপ ৭) — আকার সেবা দেখে
            'damaged' => ['nullable', 'array'],
            'damaged.*' => ['nullable'],
            // ⓘ রওনার গাড়ি ও চালক — সারির ছোট ঘর থেকে; গেট পাস ঐ ছবিটাই নেয় ([[GatePassService]])
            'vehicle_id' => ['nullable', 'integer',
                Rule::exists('mdm_vehicles', 'id')->where('company_id', CompanyContext::id())],
            'vehicle_no' => ['nullable', 'string', 'max:64'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'driver_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $state = DB::transaction(function () use ($challan, $data) {
            if ($data['stage'] === DeliveryStage::DISPATCHED) {
                $this->stampTransport($challan, $data);
            }

            return $this->stages->move($challan->fresh(), $data['stage'], $data);
        });

        return redirect()
            ->back(fallback: route('sales.delivery.show', $challan))
            ->with('saved', __('sales::delivery.saved', ['stage' => $state->label()]));
    }

    /**
     * রওনার আগে চালানে গাড়ি ও চালক — যা লেখা হয়েছে কেবল সেটুকু; বহরের গাড়ি বাছলে আর চালক না
     * লিখলে গাড়ির নিজের চালক। ⓘ নিরীক্ষায় যায় (চালান [[IsAudited]]); মাল বা টাকা নড়ে না।
     *
     * @param  array<string, mixed>  $data
     */
    private function stampTransport(DeliveryChallan $challan, array $data): void
    {
        $vehicle = filled($data['vehicle_id'] ?? null) ? Vehicle::query()->find($data['vehicle_id']) : null;
        $changes = [];

        if ($vehicle !== null) {
            $changes['vehicle_id'] = $vehicle->id;
            $changes['driver_name'] = $vehicle->driver_name;
            $changes['driver_phone'] = $vehicle->driver_phone;
        }

        foreach (['vehicle_no', 'driver_name', 'driver_phone'] as $key) {
            if (filled($data[$key] ?? null)) {
                $changes[$key] = trim((string) $data[$key]);
            }
        }

        if ($changes !== []) {
            $challan->forceFill($changes)->save();
        }
    }
}
