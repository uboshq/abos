<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * পোর্টালের দরজা — মালিকের দিক থেকে।
 *
 * ── কেন `CustomerController`-এ আরেকটা পদ্ধতি নয় ─────────────────────
 * ওই কন্ট্রোলারের প্রতিটা পদ্ধতি `customer.update` অনুমতিতে চলে। চাবি
 * দেওয়া সেই দলের কাজ নয়: যিনি ফোন নম্বর শুধরান তিনি বাইরের একজনকে
 * ভেতরের সংখ্যা দেখার অধিকার দিতে পারবেন না। আলাদা কন্ট্রোলার মানে
 * আলাদা অনুমতি, আর সেটা ভুলে যাওয়ার উপায় নেই।
 */
class CustomerPortalController extends Controller
{
    public function __construct(private readonly CustomerPortalService $portal) {}

    /**
     * চালু করা, বা নতুন পাসওয়ার্ড বসানো।
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('managePortal', $customer);

        $data = $request->validate([
            /*
             * আট অক্ষর — কর্মীর পাসওয়ার্ডের মতোই।
             *
             * গ্রাহক ছোট পাসওয়ার্ড চাইবেন, কারণ ফোনে লিখতে হয়। কিন্তু
             * এই লগইনের পেছনে তাঁর নিজের বকেয়ার খাতা, আর পোর্টালটা
             * ইন্টারনেটে খোলা — চার অক্ষরের পাসওয়ার্ড কয়েক সেকেন্ডে
             * আন্দাজ করা যায়।
             */
            'password' => ['required', 'string', 'min:8', 'max:191', 'confirmed'],
        ]);

        $wasEnabled = (bool) $customer->portal_enabled;

        $this->portal->enable($customer, $data['password']);

        return redirect()
            ->route('customer.show', $customer)
            ->with('saved', __($wasEnabled
                ? 'customer::message.portal_password_set'
                : 'customer::message.portal_enabled', ['code' => $customer->code]));
    }

    /**
     * বন্ধ করা।
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('managePortal', $customer);

        $this->portal->disable($customer);

        return redirect()
            ->route('customer.show', $customer)
            ->with('saved', __('customer::message.portal_disabled'));
    }
}
