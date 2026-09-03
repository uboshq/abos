<?php

declare(strict_types=1);

namespace App\Modules\Backup\Dashboard;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Engines\Dashboard\Tile;
use App\Core\Services\BackupService;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use Illuminate\Support\Carbon;

/**
 * ব্যাকআপের ড্যাশবোর্ড — চারটা সংখ্যা, আর প্রতিটাই একটা প্রশ্নের উত্তর।
 *
 * ── কোন প্রশ্নগুলো, আর কেন এই ক্রমে ───────────────────────────────────
 *
 *   ১. শেষ ব্যাকআপ কবে?        "কিছু হারালে কতটা ফেরত পাব"
 *   ২. কয়টা জায়গায় কপি আছে?   "মেশিনটা গেলে কী বাঁচবে"
 *   ৩. শেষ যাচাই কবে?          "কপিটা আদৌ ফেরে কি না"
 *   ৪. শেষ ব্যর্থতা কবে?       "কিছু চুপচাপ ভেঙে আছে কি"
 *
 * প্রথমটা সবচেয়ে চেনা প্রশ্ন। **দ্বিতীয়টা সবচেয়ে জরুরি**, আর সেটাই
 * আজ লাইভে শূন্য: ৭৩টা ব্যাকআপ, সবগুলো একই ডিস্কে।
 *
 * ── ⚠️ কেন "সফল" শব্দটা কোথাও নেই ────────────────────────────────────
 * ব্লুপ্রিন্টে "Backup Health ৯৬%" জাতীয় একটা স্কোর চাওয়া হয়েছিল।
 * স্কোরটা পরে আসবে, কিন্তু নিয়মটা এখনই: **প্রতিটা সংখ্যা এমন হতে হবে
 * যেটা ক্লিক করে উৎসে যাওয়া যায়।** "৯৬%" লেখা একটা টাইল কাউকে কিছু
 * করতে সাহায্য করে না; "শেষ কপি ৭ দিন আগে" করে।
 */
final class BackupDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        $latest = app(BackupService::class)->latest();

        $latestAt = $latest !== null && is_file($latest)
            ? Carbon::createFromTimestamp(filemtime($latest), config('app.timezone'))
            : null;

        $lastRun = BackupRun::query()->latest('started_at')->first();

        return new DashboardDefinition(
            title: __('backup::dashboard.title'),
            subtitle: __('backup::dashboard.subtitle'),

            tiles: [
                new Tile(label: __('backup::menu.backups'), href: route('backup.index'),
                    permission: 'backup.view', icon: 'backup'),
                new Tile(label: __('backup::menu.destinations'), href: route('backup.destination.index'),
                    permission: 'backup.configure', icon: 'settings'),
            ],

            stats: [
                new Stat(
                    label: __('backup::dashboard.last_backup'),
                    value: $latestAt === null
                        ? __('core.backup.none_yet')
                        : __('core.backup.days_ago', ['days' => (int) $latestAt->diffInDays(now())]),
                    hint: __('backup::dashboard.last_backup_hint'),
                    href: route('backup.index'),
                ),

                /*
                 * ⚠️ এই সংখ্যাটাই সবচেয়ে জরুরি, আর আজ এটা শূন্য।
                 *
                 * "ব্যাকআপ আছে" আর "ব্যাকআপ অন্য কোথাও আছে" এক কথা নয়।
                 * শূন্য মানে প্রতিটা কপি ওই একই মেশিনে — অর্থাৎ ঠিক যে
                 * একটা ক্ষেত্রে ব্যাকআপ সবচেয়ে দরকার, সেখানেই কিছু নেই।
                 */
                new Stat(
                    label: __('backup::dashboard.destinations'),
                    value: (string) BackupDestination::query()->where('is_active', true)->count(),
                    hint: __('backup::dashboard.destinations_hint'),
                    href: route('backup.destination.index'),
                ),

                /*
                 * শেষ কবে কপিটা সত্যিই ফিরিয়ে আনা গেছে।
                 *
                 * ⓘ নিচের ইঞ্জিন প্রতিটা রানেই এটা করে, কিন্তু ফলটা
                 * এতদিন কোথাও লেখা হত না — তাই প্রশ্নটার উত্তর কারও
                 * কাছে ছিল না। এখন আছে।
                 */
                new Stat(
                    label: __('backup::dashboard.last_verified'),
                    value: $lastRun?->restoreWasTested()
                        ? __('backup::dashboard.verified_yes')
                        : __('backup::dashboard.verified_no'),
                    hint: __('backup::dashboard.last_verified_hint'),
                    href: route('backup.index'),
                ),
            ],
        );
    }
}
