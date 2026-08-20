<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\NotificationService;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * বিজ্ঞপ্তি খোলা ও পড়া।
 *
 * নিজের কোনো পর্দা নেই — খবরটা যেখানে নিয়ে যাওয়ার কথা, সেখানেই নিয়ে
 * যায়। "বিজ্ঞপ্তির তালিকা" নামে একটা আলাদা পাতা বানালে সেটা আরেকটা
 * ইনবক্স হত, আর মানুষ ইতিমধ্যেই যথেষ্ট ইনবক্স খোলেন।
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

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
