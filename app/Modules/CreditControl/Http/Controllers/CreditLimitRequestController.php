<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CreditLimitRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ⭐ গ্রাহকের বাকির পাতা থেকে সীমা বাড়ানোর অনুরোধ — হিসাব বা ম্যানেজার লেখেন, মালিক সই দেন (ধাপ ২.৫; [[CreditLimitRequests]])।
 *
 * ⓘ চাবি `credit_control.request_limit` (রুটে); দেয়াল — কোম্পানি, ডিলার (গ্রাহকের নিজের স্কোপ) আর হেডারের শাখা, না মিললে ৪০৪।
 */
class CreditLimitRequestController extends Controller
{
    public function __construct(private readonly CreditLimitRequests $requests) {}

    public function store(Request $request, int $customer): RedirectResponse
    {
        $customer = Customer::query()->inViewedBranch()->whereKey($customer)->firstOrFail();

        $data = $request->validate([
            'kind' => ['required', Rule::in([CustomerCreditRequest::LIMIT, CustomerCreditRequest::TEMPORARY])],
            'amount' => ['required', 'numeric', \App\Modules\Sales\Http\Requests\PlainNumber::RULE, 'min:0', 'max:999999999999'],
            'temp_until' => ['nullable', 'required_if:kind,'.CustomerCreditRequest::TEMPORARY, 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        $made = $this->requests->raise(
            $customer,
            $data['kind'],
            (string) $data['amount'],
            $data['kind'] === CustomerCreditRequest::TEMPORARY ? ($data['temp_until'] ?? null) : null,
            $data['reason'],
            $request->file('attachment'),
            $request->user(),
        );

        return redirect()
            ->route('credit_control.customer.show', $customer->id)
            ->with('saved', __($made->approval_id === null ? 'customer::credit_request.saved_direct' : 'customer::credit_request.saved'));
    }
}
