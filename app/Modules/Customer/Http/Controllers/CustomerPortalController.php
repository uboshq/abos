<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

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
             * ⛔ কর্মীর দরজার নিয়মটাই, হুবহু — ১২ সেপ্টেম্বর ২০২৬।
             *
             * ── কী ভাঙা ছিল ─────────────────────────────────────────
             * নিয়মটা ছিল `['required','string','min:8','max:191',
             * 'confirmed']` — শুধু দৈর্ঘ্য, গড়ন নিয়ে কিছু নয়। ⚠️ ফলে
             * **`12345678` গৃহীত হত**, আর মন্তব্যে লেখা ছিল "কর্মীর
             * পাসওয়ার্ডের মতোই" — যেটা সত্যি ছিল না: কর্মীর দরজা
             * (`UserController`) অক্ষর **আর** সংখ্যা দুইটাই চায়।
             *
             * ⓘ একই ভুল এই রিপোতে একবার ধরা পড়েছে ও সারানো হয়েছে —
             * ৩১ আগস্ট ২০২৬, কর্মীর দরজায়, ঐ মন্তব্যটা আজও
             * `UserController::validated()`-এ লেখা আছে। ⚠️ সারাইটা
             * তখন **ছড়ায়নি**, আর পোর্টাল পুরনো নিয়মেই থেকে গেল।
             *
             * ── ⭐ কেন পোর্টালেই এটা সবচেয়ে বেশি জরুরি ───────────────
             * ⓘ ভেতরের দরজাগুলোর পেছনে গোনা কয়েকজন কর্মী; পোর্টালের
             * পেছনে **প্রতিটা গ্রাহক**, অর্থাৎ সবচেয়ে বড় দলটা — আর
             * তাঁরাই ব্যবস্থাটার সবচেয়ে বাইরে। ⚠️ আর দরজাটা খুললে যা
             * দেখা যায় সেটা সাজসজ্জা নয়: **নিজের খতিয়ান আর বকেয়া**।
             *
             * ⛔ দ্বিতীয় কোনো জাল নেই: লগইনে পাহারা এক স্তরের, আর
             * `12345678` আন্দাজ করতে একটা চেষ্টাই লাগে — প্রথমটাই।
             *
             * ── কেন বড়-ছোট হরফ বা চিহ্ন চাওয়া হয় না ─────────────────
             * ⓘ কর্মীর দরজায় যে কারণটা লেখা, সেটা গ্রাহকের বেলায়
             * আরও জোরালো: মানুষ ফোনে টাইপ করেন, আর জটিল নিয়ম বসালে
             * পাসওয়ার্ড দোকানের খাতায় লেখা শুরু হয় — তখন নিয়মটা
             * নিরাপত্তা বাড়ায় না, কমায়।
             *
             * ── কেন `max:191` রয়ে গেল ───────────────────────────────
             * ⓘ কলামের জন্য নয় — যা জমা হয় সেটা ৬০ অক্ষরের bcrypt
             * হ্যাশ, আর bcrypt নিজেও ৭২ বাইটের পরে কাটে। ⭐ সীমাটা
             * **ঘরের** সীমা: একটা মেগাবাইট লম্বা "পাসওয়ার্ড" নেওয়ার
             * কোনো কারণ নেই, আর কর্মীর দরজায় ঠিক এই সংখ্যাটাই বসানো।
             * ⚠️ দুই দরজা এক রাখাই এখানে আসল কাজ —
             * [[NoDoorIsWeakerThanTheStaffDoorTest]] সেটাই পাহারা দেয়।
             */
            'password' => [
                'required', 'string', 'max:191', 'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
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
