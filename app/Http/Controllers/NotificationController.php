<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationService;
use App\Core\Support\MailReach;
use App\Core\Support\NotificationKinds;
use App\Models\Notification;
use App\Models\NotificationChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * বিজ্ঞপ্তি খোলা ও পড়া।
 *
 * নিজের কোনো পর্দা নেই — খবরটা যেখানে নিয়ে যাওয়ার কথা, সেখানেই নিয়ে
 * যায়। "বিজ্ঞপ্তির তালিকা" নামে একটা আলাদা পাতা বানালে সেটা আরেকটা
 * ইনবক্স হত, আর মানুষ ইতিমধ্যেই যথেষ্ট ইনবক্স খোলেন।
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MenuBuilder $menu,
    ) {}

    /**
     * কে কোন খবর পেতে চান — নিজের পছন্দ, নিজের পাতা।
     *
     * ── কেন এখানে অনুমতি লাগে না ─────────────────────────────────────
     * এটা নিজের প্রোফাইলের মতোই ব্যক্তিগত: কোম্পানির কোনো তথ্য নেই, আর
     * নিজের ঘণ্টা বন্ধ করতে কারো অনুমতি লাগার কথা নয়। ⛔ অনুমতি বসালে
     * প্রতিটা নতুন কর্মীকে "নিজের সেটিংস দেখার" চাবি আলাদা করে দিতে হত।
     */
    public function settings(Request $request): View
    {
        $user = $request->user();

        $chosen = NotificationChoice::mailChoicesFor((int) $user->id);

        /*
         * ⭐ পর্দায় প্রতিটা ধরনের জন্য চিঠির টিকটা **আগে থেকেই** ঠিক
         * অবস্থায় থাকে — তিনি কিছু বলে থাকলে তাঁর কথা, নাহলে ধরনটার
         * নিজের নিয়ম।
         *
         * ⛔ সবগুলো খালি দেখালে মানুষ ভাবতেন কোনো চিঠিই যায় না, আর
         * ⚠️ তারপর সংরক্ষণে চাপ দিলে **সত্যিই যাওয়া বন্ধ হয়ে যেত** —
         * একটা পর্দা যা দেখায় তা-ই সে সংরক্ষণ করে, আর এখানে দেখানোটা
         * ভুল হলে বন্ধ করাটা নীরব হত।
         */
        $mailed = [];

        foreach (array_keys(NotificationKinds::all()) as $type) {
            $mailed[$type] = $chosen[$type] ?? NotificationKinds::mailedByDefault($type);
        }

        return view('notifications.settings', [
            'menu' => $this->menu->forUser($user),
            'kinds' => NotificationKinds::all(),
            'silenced' => NotificationChoice::silencedFor((int) $user->id),
            'mailed' => $mailed,
            'postable' => ! MailReach::silent(),
        ]);
    }

    /**
     * ⓘ কেবল **বন্ধ**গুলোর সারি রাখা হয়, চালুগুলোর সারি মুছে ফেলা হয় —
     * তাই পরে নতুন ধরনের খবর যোগ হলে সেটা নিজে থেকেই চালু থাকে, আর
     * পুরনো একটা সারি নীরবে সেটা বন্ধ করে রাখে না।
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wanted = array_map('strval', (array) $request->input('kinds', []));
        $byMail = array_map('strval', (array) $request->input('mailed', []));

        foreach (array_keys(NotificationKinds::all()) as $type) {
            $bell = in_array($type, $wanted, true);
            $mail = in_array($type, $byMail, true);

            /*
             * ⭐ সারিটা মুছে ফেলা হয় কেবল তখন, যখন **দুইটা দিকই ডিফল্টে**।
             *
             * ⓘ মূল নিয়মটা মাইগ্রেশনে লেখা: সারি না থাকা মানে "যা স্বাভাবিক
             * তাই" — আর ⚠️ সেটা এখন দুইটা প্রশ্নের উত্তর, একটার নয়। ⛔ কেবল
             * ঘণ্টা দেখে মুছলে একজনের "এই খবরটা চিঠিতে চাই না" কথাটা নীরবে
             * হারিয়ে যেত, আর পরের মাসে চিঠি আবার আসতে শুরু করত।
             */
            if ($bell && $mail === NotificationKinds::mailedByDefault($type)) {
                NotificationChoice::query()
                    ->where('user_id', $user->id)
                    ->where('type', $type)
                    ->delete();

                continue;
            }

            /*
             * ⚠️ ঘণ্টা বন্ধ থাকলে চিঠিও বন্ধ — পর্দায় ঐ টিকটা তখন নিষ্ক্রিয়
             * থাকে, আর এখানেও সেটা মেনে নেওয়া হয়। ⛔ নাহলে একজন খবরটা বন্ধ
             * করে দিতেন অথচ **ইনবক্সে সেটা আসতেই থাকত**, আর তিনি ভাবতেন
             * সুইচটাই ভাঙা।
             */
            NotificationChoice::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['enabled' => $bell, 'by_email' => $bell && $mail],
            );
        }

        return back()->with('saved', __('core.notify.settings_saved'));
    }

    /**
     * একটা খবর খোলা — পড়া হিসেবে বসিয়ে তার গন্তব্যে পাঠানো।
     *
     * অন্যের খবর খোলা যায় না। `markRead()` মালিকানা যাচাই করে, আর
     * এখানে সেই ফলটা ধরেই সিদ্ধান্ত হয় — নাহলে অন্যের খবরের লিংকে
     * ক্লিক করে তাঁর ঘণ্টা খালি করে দেওয়া যেত।
     */
    public function open(Request $request, Notification $notification): RedirectResponse
    {
        if (! $this->notifications->markRead($notification, $request->user())) {
            abort(403);
        }

        return redirect()->to($notification->url ?? route('dashboard'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($request->user());

        return back();
    }
}
