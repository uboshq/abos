<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * ⭐ কে কার বেতন বা NID খুলে দেখলেন — নিরীক্ষার খাতায় (ঘাটতির তালিকা #১৮; ISO 27001 A.8.15, GDPR অনুচ্ছেদ ৩২; a6, ২০ অক্টোবর ২০২৬)।
 *
 * <p>⛔ আগে দেখা কোথাও লেখা থাকত না — দলিলের মডিউলে খোলা লেখা হত, HR-এ নয়: কেউ সবার বেতন ঘুরে দেখলেও প্রমাণ থাকত না।
 *
 * <p>⭐ খাতায় কে, কাকে, কী (বেতন / পরিচয় / বেতনশিট), কখন — ⛔ অঙ্ক বা নম্বর কখনো নয় (fe-র শর্ত): `recordAction`-এ বদলের সারি
 * খালি, কারণের লেখায় কেবল নাম। ⓘ একই মানুষ একই দিনে একই কাগজ বারবার খুললে একটাই লাইন — খাতা যেন ভরে না যায়;
 * নিজের বেতন নিজে দেখলে লেখা হয় না।
 */
final class SensitiveViews
{
    public function seen(Model $subject, string $what, Employee|int|null $about = null): void
    {
        $viewer = auth()->user();
        if (! $viewer instanceof User) {
            return;
        }

        // ⓘ নিজের — নিজের বেতন বা পরিচয় দেখা নজরদারির বিষয় নয়।
        // ⛔ কর্মীর সারি শাখার দেয়াল দিয়ে খোলা হলে সম্পর্কটা ফাঁকা আসতে পারত — তখন নিজের স্লিপও খাতায় উঠত; তাই আইডি দিলে
        // মালিকানা দেয়াল ছাড়াই পড়া হয় (Mac দৌড়, ২১ অক্টোবর ২০২৬)।
        $owner = is_int($about)
            ? Employee::query()->withoutGlobalScopes()->whereKey($about)->value('user_id')
            : $about?->user_id;
        if ($owner !== null && (int) $owner === (int) $viewer->id) {
            return;
        }

        $key = 'hr-seen:'.$viewer->id.':'.$subject->getMorphClass().':'.$subject->getKey().':'.$what.':'.now()->toDateString();
        if (! Cache::add($key, true, now()->endOfDay())) {
            return;
        }

        app(AuditEngine::class)->recordAction($subject, $what, __('hr::message.'.$what, ['viewer' => $viewer->name]));
    }
}
