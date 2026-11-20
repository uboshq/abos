<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\ChallanLoading;
use App\Modules\Supplier\Services\LabourCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ পাকা চালানে লোডিং লেখা — চালানের পরিবহন পর্দা থেকে (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; [[ChallanLoading::recordOnConfirmed()]])।
 *
 * ⓘ লোডিং ঐচ্ছিক হলে প্রায়ই মাল তোলার পরে জানা যায় — ভাড়ার পর্দার ([[ChallanFareController]]) একই জায়গা, একই চাবি।
 */
final class ChallanLoadingController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ChallanLoading $loading) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.challan.create'),
        ];
    }

    public function record(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $data = $request->validate([
            ...LabourCost::rules('loading_', LabourCost::LOADING),
            'loading_mode' => ['required', 'string', 'in:'.implode(',', LabourCost::LOADING)],
        ]);

        $this->loading->recordOnConfirmed($challan, $data);

        return back()->with('saved', __('supplier::labour.saved'));
    }
}
