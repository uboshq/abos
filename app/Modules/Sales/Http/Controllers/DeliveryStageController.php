<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
        $rows = DeliveryState::query()
            ->inTab($tab)
            ->whereHas('challan', fn (Builder $q) => $q->search($term === '' ? null : $term))
            ->with(['challan.customer'])
            ->orderByDesc('stage_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('sales::delivery.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
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
        ]);

        $state = $this->stages->move($challan, $data['stage'], $data);

        return redirect()
            ->back(fallback: route('sales.delivery.show', $challan))
            ->with('saved', __('sales::delivery.saved', ['stage' => $state->label()]));
    }
}
