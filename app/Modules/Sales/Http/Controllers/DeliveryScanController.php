<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\DeliveryStage;
use App\Modules\Sales\Services\DeliveryStageService;
use App\Modules\Sales\Services\ScannedPaper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * বিল-চালানের QR স্ক্যান — একই কোড, দুই রকম মানুষ।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ekta qr add korbe zate mobile scane korei delivery dap gulo complate r porer daper nirdes
 * dite pare"* — কর্মী স্ক্যান করে ডেলিভারির পরের ধাপ দেন।
 * *"ekoi code dilar scane kore tar hisab r invoice dekte pare r confm korte pare"* — ডিলার একই
 * কোড স্ক্যান করে নিজের হিসাব আর বিল দেখেন, আর মাল পাওয়া নিশ্চিত করেন।
 *
 * ── ⚠️ চারটা দরজা, প্রতিটার নিজের পাহারা ──────────────────────────────
 * [[open()]] কোনো লগইন চায় না — কেবল ঠিক করে কে এসেছেন, তারপর তাঁর নিজের দরজায় পাঠায়:
 * কর্মী → [[staff()]] (`auth` আর `sales.delivery.view`, শাখার দেয়াল মডেলেই), ডিলার →
 * [[dealer()]] (`auth:portal`, কেবল নিজের চালান), কেউ না → কোন লগইন তা বাছার পাতা।
 * ⛔ `open()` নিজে কিছুই দেখায় না — চালান আছে কি না সেটাও না — তাই লগইন ছাড়া QR হাতে পেলে
 * কিছুই জানা যায় না।
 *
 * ── ⛔ GET কিছুই বদলায় না ─────────────────────────────────────────────
 * কর্মীর ধাপ বদলায় সেই পুরনো ফর্মেই ([[delivery.partials.actions]] → `sales.delivery.move`,
 * [[DeliveryStageController::move()]]) — রওনায় বিল আর গেট পাস সেখানেই বানানো হয়, তাই এখানে
 * দ্বিতীয় কোনো লেখার পথ নেই। ডিলারের নিশ্চিত করা কেবল POST-এ, CSRF-সহ ([[received()]])।
 * একটা লিংক খুললেই ধাপ এগোলে প্রিভিউ-করা চ্যাট অ্যাপও মাল "পৌঁছে" দিত।
 */
class DeliveryScanController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ScannedPaper $paper,
        private readonly DeliveryStageService $stages,
        private readonly MenuBuilder $menu,
    ) {}

    /**
     * ⓘ কর্মীর দরজায় ডেলিভারির পাতার সেই একই চাবি ([[DeliveryStageController]])। ধাপ বসানোর চাবি
     * (`sales.delivery.update`) পুরনো `move` রুটেই — এই পাতা কেবল দেখায়।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:sales.delivery.view', only: ['staff'])];
    }

    /** কে এসেছেন দেখে নিজের দরজায় — নিজে কিছুই দেখায় না */
    public function open(Request $request, string $publicId): RedirectResponse|View
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('sales.delivery.scan', $publicId);
        }

        if (Auth::guard('portal')->check()) {
            return redirect()->route('sales.portal.scan', $publicId);
        }

        /*
         * ⓘ কেউ ঢোকা নেই — দুই লগইনের যেকোনোটার পরে ঠিক এই QR-এর পাতায় ফেরা। কর্মীর লগইন
         * `intended` মানে ([[LoginController]]); পোর্টালেরটাও মানে ([[PortalController::login()]])।
         */
        $request->session()->put('url.intended', route('sales.scan', $publicId));

        return view('sales::scan.who');
    }

    /** কর্মীর পাতা — চালানের সারাংশ, আর ডেলিভারির সময়রেখা ও পরের ধাপের সেই পুরনো ফর্ম */
    public function staff(Request $request, string $publicId): View
    {
        $challan = $this->paper->forStaff($publicId);
        $challan->load(['customer', 'warehouse', 'vehicle', 'lines.product']);

        return view('sales::scan.staff', [
            'menu' => $this->menu->forUser($request->user()),
            'challan' => $challan,
        ]);
    }

    /** ডিলারের পাতা — এই চালানের মাল, এই চালানের বিল, নিজের মোট বকেয়া, আর "মাল বুঝে পেয়েছি" */
    public function dealer(string $publicId): View
    {
        $challan = $this->paper->forDealer($publicId);
        $challan->load(['lines.product.unit']);
        $customer = $this->paper->dealer();

        return view('sales::scan.dealer', [
            'customer' => $customer,
            'challan' => $challan,
            'invoices' => $this->paper->invoicesOf($challan),
            'due' => $customer->outstanding(),
            'stage' => (string) $this->stages->ensure($challan)->stage,
            'canConfirm' => $this->dealerMayConfirm($challan),
        ]);
    }

    /**
     * ডিলার নিশ্চিত করলেন — "পৌঁছেছে", প্রাপক তিনি নিজে।
     *
     * ⓘ নিয়ম সবই [[DeliveryStageService::move()]]-এর: কোন ধাপ থেকে যাওয়া যায়, ট্রিপে থাকলে
     * ট্রিপই বলে, প্রাপকের নাম লাগে। ⛔ এখানে কোনো ছাড় নেই — বাতিল বা আগে-থেকেই-পৌঁছানো চালান
     * সার্ভিসই ফিরিয়ে দেয়, আর দুইবার চাপলে দ্বিতীয়বার "পৌঁছেছে → পৌঁছেছে" অনুমোদিত নয়।
     */
    public function received(string $publicId): RedirectResponse
    {
        $challan = $this->paper->forDealer($publicId);
        $customer = $this->paper->dealer();

        /*
         * ⛔ মাল রওনা না হলে "পেয়েছি" নয় — ডিলার কেবল পথে থাকা মাল নিশ্চিত করেন। ⓘ কর্মীর হাতে
         * "অপেক্ষায় → পৌঁছেছে" সরাসরি চলে (কাউন্টারে হাতে হাতে দেওয়া), কিন্তু ডিলারের ফোন থেকে সেটা
         * খুললে গুদাম থেকে না-বেরোনো মাল "পৌঁছেছে" হয়ে যেত।
         */
        if (! $this->dealerMayConfirm($challan)) {
            throw ValidationException::withMessages(['stage' => __('sales::scan.cannot_confirm')]);
        }

        /*
         * ⛔ `auth:portal` ডিফল্ট গার্ডকে পোর্টাল বানায়, তাই এখানে `auth()->id()` = **গ্রাহকের** আইডি —
         * আর ঘটনার `created_by` আর অডিটের `user_id` কর্মীর (`users`) দিকে দেখায়: গ্রাহকের আইডি সেখানে
         * বসলে FK ভাঙত (পাতা ৫০০), বা ভুল কর্মীর নামে বসত। ⭐ তাই লেখার সময় ডিফল্ট গার্ড কর্মীর —
         * সেখানে কেউ নেই, তাই দুইটাই null; কে নিশ্চিত করলেন তা নোটে আর প্রাপকের নামে থাকে।
         */
        Auth::shouldUse('web');

        DB::transaction(fn () => $this->stages->move($challan->fresh(), DeliveryStage::DELIVERED, [
            'receiver_name' => (string) $customer->name(),
            'receiver_phone' => (string) ($customer->phone ?? ''),
            'note' => __('sales::scan.dealer_note', ['code' => (string) $customer->code]),
        ]));

        return redirect()
            ->route('sales.portal.scan', $publicId)
            ->with('saved', __('sales::scan.received_saved'));
    }

    /**
     * ডিলার এখন নিশ্চিত করতে পারেন কি না — মাল পথে (রওনা বা আংশিক পৌঁছেছে), কোনো চলতি ট্রিপ নেই,
     * আর সার্ভিস "পৌঁছেছে" নিতে রাজি। ⓘ পাতার বোতাম আর [[received()]] দুইটাই এটাই জিজ্ঞেস করে।
     */
    private function dealerMayConfirm(\App\Modules\Sales\Models\DeliveryChallan $challan): bool
    {
        $stage = (string) $this->stages->ensure($challan)->stage;

        return in_array($stage, [DeliveryStage::DISPATCHED, DeliveryStage::PARTIALLY_DELIVERED], true)
            && in_array(DeliveryStage::DELIVERED, $this->stages->manualChoices($challan), true)
            && $this->stages->activeTrip($challan) === null;
    }
}
