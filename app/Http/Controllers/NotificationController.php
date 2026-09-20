<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationService;
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

        return view('notifications.settings', [
            'menu' => $this->menu->forUser($user),
            'kinds' => NotificationKinds::all(),
            'silenced' => NotificationChoice::silencedFor((int) $user->id),
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

        foreach (array_keys(NotificationKinds::all()) as $type) {
            if (in_array($type, $wanted, true)) {
                NotificationChoice::query()
                    ->where('user_id', $user->id)
                    ->where('type', $type)
                    ->delete();

                continue;
            }

            NotificationChoice::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['enabled' => false],
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
