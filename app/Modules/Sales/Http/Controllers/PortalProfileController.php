<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Services\PaperTrail;
use App\Http\Controllers\Controller;
use App\Models\DocumentDelivery;
use App\Modules\Customer\Models\ProfileRequest;
use App\Modules\Customer\Services\CustomerPortalService;
use App\Modules\Customer\Services\ProfileRequestService;
use App\Modules\Sales\Services\CustomerPapers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ গ্রাহকের প্রোফাইল — নিজের তথ্য দেখা, বদলের অনুরোধ, কাগজ তোলা, আর নিজের পাসওয়ার্ড বদলানো (গ্রাহক পোর্টাল, ধাপ ১)।
 *
 * ⛔ গ্রাহক নিজের মূল রেকর্ড নিজে বদলান না — মালিকের নিয়ম। তথ্য বদল অফিসে অনুরোধ হয়ে যায় ([[ProfileRequestService]]),
 * অফিস মিলিয়ে নিজের পর্দায় বসায়।
 */
class PortalProfileController extends Controller
{
    /** ⓘ `doc_deliveries`-এ গ্রাহকের নিজের তোলা কাগজের চিহ্ন */
    public const PAPER = 'customer_profile_paper';

    public function __construct(
        private readonly CustomerPapers $papers,
        private readonly CustomerPortalService $portal,
        private readonly ProfileRequestService $requests,
    ) {}

    public function show(): View
    {
        return view('sales::portal.profile', [
            'customer' => $this->papers->customer(),
            'requests' => $this->papers->profileRequests(),
        ]);
    }

    /**
     * ⭐ তথ্য বদলের অনুরোধ — অফিসে যায়, রেকর্ড নিজে বদলায় না ([[ProfileRequestService::askContact()]])।
     */
    public function requestContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner_name' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_bn' => ['nullable', 'string', 'max:500'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->requests->askContact($this->papers->customer(), Arr::except($data, ['note']), $data['note'] ?? null);

        return redirect()->route('sales.portal.profile')->with('status', __('sales::portal_profile.request_sent'));
    }

    /**
     * ⭐ ট্রেড লাইসেন্স / NID / BIN — সংযুক্তির খাতায়, অনুরোধ হিসেবে অফিসে ([[ProfileRequestService::uploadPaper()]])।
     */
    public function uploadPaper(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'paper_kind' => ['required', 'in:'.implode(',', ProfileRequest::PAPER_KINDS)],
            'paper' => ['required', 'file', 'max:'.intdiv(AttachmentEngine::SLIP_MAX_BYTES, 1024)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->requests->uploadPaper($this->papers->customer(), $data['paper_kind'], $request->file('paper'), $data['note'] ?? null);

        return redirect()->route('sales.portal.profile')->with('status', __('sales::portal_profile.paper_sent'));
    }

    /**
     * নিজের তোলা কাগজ আবার দেখা — অন্যের কাগজ ৪০৪; খোলাটা খাতায় ওঠে গ্রাহকের নামে ([[PaperTrail::record()]])।
     */
    public function paper(Request $request, string $id): StreamedResponse
    {
        $profileRequest = $this->papers->profileRequest($id);
        $response = $this->requests->stream($profileRequest);

        app(PaperTrail::class)->record(
            self::PAPER, (int) $profileRequest->id, 'file', DocumentDelivery::OPENED,
            __('customer::profile_request.paper_'.$profileRequest->paper_kind),
        );

        return $response;
    }

    /**
     * নিজের পাসওয়ার্ড বদল — আগেরটা জানলে তবেই, আর অফিসের সেই একই নিয়মে।
     *
     * ⓘ আগের পাসওয়ার্ড চাওয়া হয়, কারণ খোলা ফোনটা অন্য কারো হাতে থাকলে এক ধাপে দরজাটা তার হয়ে যেত।
     */
    public function password(Request $request): RedirectResponse
    {
        $customer = $this->papers->customer();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'max:191', 'confirmed', 'different:current_password',
                // ⓘ অফিস যখন পোর্টালের পাসওয়ার্ড বসায় ([[CustomerPortalController::store()]]) — সেই একই নিয়ম; কর্মীর দরজার চেয়ে
                // ঢিলা নয় ([[NoDoorIsWeakerThanTheStaffDoorTest]])
                Password::min(12)->letters()->numbers()->uncompromised(),
            ],
        ]);

        if (! Hash::check($data['current_password'], (string) $customer->portal_password)) {
            throw ValidationException::withMessages(['current_password' => __('sales::portal_profile.wrong_current')]);
        }

        $this->portal->changeOwnPassword($customer, $data['password']);

        return redirect()->route('sales.portal.profile')->with('status', __('sales::portal_profile.password_changed'));
    }
}
