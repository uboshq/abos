<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Services\CustomerPortalService;
use App\Modules\Sales\Services\CustomerPapers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ গ্রাহকের প্রোফাইল — নিজের তথ্য দেখা, আর নিজের পাসওয়ার্ড বদলানো (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ গ্রাহক নিজের মূল রেকর্ড নিজে বদলান না — মালিকের নিয়ম। নাম, ফোন, ঠিকানা এখানে কেবল দেখা।
 */
class PortalProfileController extends Controller
{
    public function __construct(
        private readonly CustomerPapers $papers,
        private readonly CustomerPortalService $portal,
    ) {}

    public function show(): View
    {
        return view('sales::portal.profile', [
            'customer' => $this->papers->customer(),
        ]);
    }

    /**
     * নিজের পাসওয়ার্ড বদল — আগেরটা জানলে তবেই, আর অফিসের সেই একই নিয়মে ([[CustomerPortalService::passwordRule()]])।
     *
     * ⓘ আগের পাসওয়ার্ড চাওয়া হয়, কারণ খোলা ফোনটা অন্য কারো হাতে থাকলে এক ধাপে দরজাটা তার হয়ে যেত।
     */
    public function password(Request $request): RedirectResponse
    {
        $customer = $this->papers->customer();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'max:191', 'confirmed', 'different:current_password', CustomerPortalService::passwordRule()],
        ]);

        if (! Hash::check($data['current_password'], (string) $customer->portal_password)) {
            throw ValidationException::withMessages(['current_password' => __('sales::portal_profile.wrong_current')]);
        }

        $this->portal->changeOwnPassword($customer, $data['password']);

        return redirect()->route('sales.portal.profile')->with('status', __('sales::portal_profile.password_changed'));
    }
}
