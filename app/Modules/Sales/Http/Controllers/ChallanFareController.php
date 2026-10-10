<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Services\FarePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * ⭐ চালানের পরিবহন পর্দায় ভাড়া — লেখা আর পরে দেওয়া (মালিক, ৭ অক্টোবর ২০২৬; [[FarePayment]])।
 *
 * ⓘ দুই দরজা: পাকা চালানে প্রথমবার ভাড়া লেখা ([[FarePayment::recordOnConfirmed()]]), আর পরে-দেব ভাড়া দেওয়া
 * ([[FarePayment::payDue()]] — PV, সই আর লেখক ≠ পাকাকারী-সহ)। ⓘ টাকা নড়ে, তাই দুই চাবি: চালানের (`sales.challan.create`,
 * পরিবহন পর্দার একই) আর ভাউচার লেখার (`create`, [[VoucherPolicy]])। ⓘ গেট পাস হলেও চলে — ভাড়া প্রায়ই মাল বেরোনোর পরে মেটে।
 */
final class ChallanFareController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FarePayment $fares) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.challan.create'),
            new Middleware('can:create,'.Voucher::class),
        ];
    }

    public function record(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'fare_paid_by' => ['required', 'in:us,customer,none'],
            'transport_cost' => ['nullable', 'numeric', 'min:0'],
            'carrier_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('suppliers', 'id')
                ->where('company_id', \App\Core\Support\CompanyContext::id())],
        ]);

        $this->fares->recordOnConfirmed($challan, $data);

        return back()->with('saved', __('sales::fare.recorded'));
    }

    public function pay(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        [$voucher, $state] = $this->fares->payDue($challan, $request->validate($this->rules()));

        return back()->with('saved', match (true) {
            $state === true => __('sales::fare.paid_waits_signature', ['no' => $voucher->document_no]),
            $state === 'checker' => __('sales::fare.paid_waits_checker', ['no' => $voucher->document_no]),
            default => __('sales::fare.paid', ['no' => $voucher->document_no]),
        });
    }

    /** ⭐ ট্রিপের পরে-দেব ভাড়া দেওয়া — চালানের একই পথ, একই নিয়ম (সিদ্ধান্ত ঘ; [[FarePayment::payDue()]]) */
    public function payTrip(Request $request, \App\Modules\Sales\Models\Shipment $shipment): RedirectResponse
    {
        [$voucher, $state] = $this->fares->payDue($shipment, $request->validate($this->rules()));

        return back()->with('saved', match (true) {
            $state === true => __('sales::fare.paid_waits_signature', ['no' => $voucher->document_no]),
            $state === 'checker' => __('sales::fare.paid_waits_checker', ['no' => $voucher->document_no]),
            default => __('sales::fare.paid', ['no' => $voucher->document_no]),
        });
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'fare_when' => ['nullable', 'in:now,later'],
            'fare_account_id' => ['nullable', 'integer'],
            'fare_reference' => ['nullable', 'string', 'max:64'],
            'fare_payer_id' => ['nullable', 'integer'],
        ];
    }
}
