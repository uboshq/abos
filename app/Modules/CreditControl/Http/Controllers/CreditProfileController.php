<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\CreditControl\Services\CreditRisk;
use App\Models\AuditTrail;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerCreditChange;
use App\Modules\Customer\Models\CustomerCreditRequest;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Models\CreditDecision;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ গ্রাহকের বাকির পাতা — সীমা, বাকির দিন, অবস্থা, কী নিয়ে ব্যবহৃত, খোলা বিল, আর পুরো বদলের ইতিহাস (বাকি নিয়ন্ত্রণ ধাপ ১.৩)।
 *
 * ⛔ এখানে কোনো সূত্র নেই — অঙ্ক [[CreditExposure::positionOf()]] আর [[CreditExposure::openBills()]] থেকে, দেয়ালের একই হিসাব।
 *
 * ⓘ দেয়াল: কোম্পানি আর ডিলার — গ্রাহকের মডেলের নিজের স্কোপে (অন্যের ডিলার ৪০৪); শাখা — ব্যবহারকারীর নাগাল
 * ([[DataScope::allows()]]; ২৪ অক্টোবর ২০২৬ পর্যন্ত হেডারে বাছা শাখা), না মিললে ৪০৪, কেবল লুকানো বোতাম নয়।
 */
class CreditProfileController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CreditExposure $credit,
    ) {}

    public function show(Request $request, int $customer): View
    {
        /*
         * ⭐ শাখা নাগাল ধরে, হেডারে বাছা শাখা ধরে নয় (মালিক: "নোটিফিকেশনে ক্লিক করলে দেখা যায় না", ২৪ অক্টোবর ২০২৬) — বাকির
         * সীমা ফুরোনোর নোটিশ (`credit_control.temporary_limit_ending`) এই পাতায় আনে; আগে `inViewedBranch()` হেডারের এক শাখাই
         * দেখত, তাই হেডারে MMS রাখা মালিক NTK-র গ্রাহকের নোটিশে ৪০৪ পেতেন। ⓘ এখন [[DataScope::allows()]] — ভাউচারের
         * [[OpensInReach]]-এর একই নিয়ম: নাগালের বাইরে ৪০৪, আগের মতোই; কোম্পানি আর ডিলারের দেয়াল মডেলের নিজের স্কোপে।
         */
        $customer = Customer::query()->withOutstanding()->whereKey($customer)->firstOrFail();
        $branch = $customer->getAttribute('branch_id');

        abort_unless(app(DataScope::class)->allows(
            $request->user(), UserDataScope::BRANCH, $branch === null ? null : (int) $branch,
        ), 404);

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
                'money' => in_array($c->field, CustomerCreditChange::MONEY, true),
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

        $bills = $this->credit->openBills([(int) $customer->id => $customer])[(int) $customer->id] ?? [];

        return view('credit_control::profile', [
            'menu' => $this->menu->forUser($request->user()),
            // ⭐ মাথায় "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম)
            'customer' => $customer->loadMissing(Customer::PLACE),
            'position' => $this->credit->positionOf($customer),
            // ⭐ ঝুঁকির মাত্রা আর তার কারণ — কেবল জানায় (ধাপ ৩.১)
            'risk' => app(CreditRisk::class)->rate($customer),
            'bills' => $bills,
            // ⭐ খোলা বিলের মোট বাকি — পূর্ণ নিরীক্ষা §৫ (১৮ অক্টোবর ২০২৬); আগে সারিই ছিল, যোগ নেই
            'billsTotal' => array_reduce($bills, fn (string $sum, array $b) => bcadd($sum, (string) $b['unpaid'], 4), '0'),
            'history' => $history->concat($blocks)->sortByDesc('at')->values(),
            'decisions' => CreditDecision::query()->where('customer_id', $customer->id)->latest('id')->limit(10)->get(),

            // ⭐ সীমা বাড়ানোর অনুরোধ — লেখার ঘর আর সবগুলোর তালিকা, না হওয়াগুলোসহ (ধাপ ২.৫)
            'requests' => CustomerCreditRequest::query()->where('customer_id', $customer->id)
                ->with(['requester', 'approval'])->latest('id')->limit(10)->get(),
            'canRequest' => (bool) $request->user()?->can('credit_control.request_limit'),
            // ⓘ চেক ফেরতের বন্ধ খোলার ঘর কেবল মালিকের পর্দায়; পাহারা সার্ভারে ([[CustomerService::clearCreditBlock()]])
            'mayLift' => $customer->isCreditBlocked() && (bool) $request->user()?->can('liftCreditBlock', $customer)
                && ($customer->credit_block_kind !== Customer::BLOCK_BOUNCED_CHEQUE || CustomerService::isSuperAdmin($request->user())),
        ]);
    }
}
