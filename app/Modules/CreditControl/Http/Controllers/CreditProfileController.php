<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ গ্রাহকের বাকির পাতা — সীমা, বাকির দিন, অবস্থা, কী নিয়ে ব্যবহৃত, খোলা বিল, আর পুরো বদলের ইতিহাস (বাকি নিয়ন্ত্রণ ধাপ ১.৩)।
 *
 * ⛔ এখানে কোনো সূত্র নেই — অঙ্ক [[CreditExposure::positionOf()]] আর [[CreditExposure::openBills()]] থেকে, দেয়ালের একই হিসাব।
 *
 * ⓘ দেয়াল: কোম্পানি আর ডিলার — গ্রাহকের মডেলের নিজের স্কোপে (অন্যের ডিলার ৪০৪); শাখা — হেডারে বাছা আর ব্যবহারকারীর নাগাল
 * ([[Customer::scopeInViewedBranch()]]), না মিললে ৪০৪, কেবল লুকানো বোতাম নয়।
 */
class CreditProfileController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CreditExposure $credit,
    ) {}

    public function show(Request $request, int $customer): View
    {
        $customer = Customer::query()->inViewedBranch()->withOutstanding()->whereKey($customer)->firstOrFail();

        $history = CustomerCreditChange::query()
            ->where('customer_id', $customer->id)
            ->with(['changer', 'approval'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerCreditChange $c) => [
                'at' => $c->created_at,
                'what' => __('customer::credit_history.'.$c->field),
                'old' => $c->old_value,
                'new' => $c->new_value,
                'money' => $c->field === 'credit_limit',
                'who' => $c->changer?->name,
                'reason' => $c->reason,
                'approval' => $c->approval_id,
            ]);

        $blocks = AuditTrail::query()
            ->forRecord(Customer::class, (int) $customer->id)
            ->whereIn('action', ['credit_blocked', 'credit_unblocked'])
            ->with('user')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditTrail $t) => [
                'at' => $t->created_at,
                'what' => __('credit_control::profile.'.$t->action),
                'old' => null,
                'new' => null,
                'money' => false,
                'who' => $t->user?->name,
                'reason' => $t->reason,
                'approval' => null,
            ]);

        return view('credit_control::profile', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'position' => $this->credit->positionOf($customer),
            'bills' => $this->credit->openBills([(int) $customer->id => $customer])[(int) $customer->id] ?? [],
            'history' => $history->concat($blocks)->sortByDesc('at')->values(),
            'decisions' => CreditDecision::query()->where('customer_id', $customer->id)->latest('id')->limit(10)->get(),
            // ⓘ কাজের ঘরগুলো কেবল চাবি থাকলে আঁকা হয় — পাহারা আসলে দরজায় ([[creditDays()]])
            'canEdit' => $request->user()?->can('update', $customer) ?? false,
        ]);
    }

    /**
     * ⭐ বাকির দিন বদল — বাকির পাতা থেকে, কারণসহ (বাকি নিয়ন্ত্রণ ধাপ ২.২)।
     *
     * ⛔ দেয়াল সার্ভারে: দেখার একই দেয়াল (কোম্পানি, শাখা, ডিলার — না মিললে ৪০৪) আর গ্রাহক বদলানোর চাবি (`update` নীতি) —
     * কেবল লুকানো বোতাম নয়।
     */
    public function creditDays(Request $request, int $customer, CustomerService $customers): RedirectResponse
    {
        $customer = Customer::query()->inViewedBranch()->whereKey($customer)->firstOrFail();
        $this->authorize('update', $customer);

        $data = $request->validate([
            'credit_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $customers->setCreditDays($customer, (int) $data['credit_days'], $data['reason']);

        return back()->with('saved', __('credit_control::profile.credit_days_saved'));
    }
}
