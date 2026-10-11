<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CreditControl\Services\CreditLimitRequests;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ সীমা বাড়ানোর অনুরোধ আর মালিকের সরাসরি বসানো — গ্রাহকের বাকির পাতা থেকে (বাকি নিয়ন্ত্রণ ধাপ ২.৫)।
 *
 * ⛔ দেয়াল সার্ভারে: গ্রাহক খোঁজা হয় দেখার একই দেয়ালে (কোম্পানি, শাখা, ডিলার — না মিললে ৪০৪); চাবি আর মালিক-কি-না প্রশ্ন
 * সেবার ভিতরে ([[CreditLimitRequests]]), তাই যে দরজাই ডাকুক একই উত্তর।
 */
class CreditLimitRequestController extends Controller
{
    public function __construct(private readonly CreditLimitRequests $requests) {}

    public function store(Request $request, int $customer): RedirectResponse
    {
        $customer = Customer::query()->inViewedBranch()->whereKey($customer)->firstOrFail();
        $data = $this->validated($request);

        $this->requests->raise($customer, $data['kind'], (string) $data['requested_limit'], $data['temporary_until'] ?? null,
            $data['reason'], $request->file('file'), $request->user());

        return back()->with('saved', __('credit_control::request.saved'));
    }

    public function direct(Request $request, int $customer): RedirectResponse
    {
        $customer = Customer::query()->inViewedBranch()->whereKey($customer)->firstOrFail();
        $data = $this->validated($request, allowZero: true);

        $this->requests->setDirectly($customer, $data['kind'], (string) $data['requested_limit'], $data['temporary_until'] ?? null,
            $data['reason'], $request->user());

        return back()->with('saved', __('credit_control::request.direct_saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $allowZero = false): array
    {
        return $request->validate([
            'kind' => ['required', 'in:limit,temporary'],
            'requested_limit' => ['required', 'numeric', $allowZero ? 'min:0' : 'gt:0', 'max:999999999999'],
            'temporary_until' => ['nullable', 'required_if:kind,temporary', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);
    }
}
