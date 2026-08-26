<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\BackupService;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * ব্যাকআপের পর্দা — যা রোজ চলছে, তা দেখার একটা জায়গা।
 *
 * ── কেন এটা এতদিন ছিল না, আর কেন এখন লাগল ────────────────────────────
 * ইঞ্জিনটা অনেক আগেই তৈরি: `BackupService` ডাম্প নেয়, যাচাই করে
 * (ফিরিয়ে এনে টেবিল গোনে), দ্বিতীয় গন্তব্যে কপি করে, পুরনো মোছে। একটা
 * কমান্ডও আছে যা রোজ চলে। **কেবল দেখার কোনো উপায় ছিল না।**
 *
 * মেনুতে সারিটা `planned` হিসেবে ঘোষিত ছিল, অর্থাৎ লুকানো — তাই কেউ
 * ক্লিক করে ভাঙত না, কিন্তু প্রতিশ্রুতিটা রয়ে গিয়েছিল আর জিনিসটা ছিল না।
 *
 * ২৫ আগস্ট ২০২৬-এ কারণটা বাস্তব হলো। ব্যাকআপ ঠিক আছে কি না জানতে
 * সার্ভারে ssh করে ফোল্ডার দেখতে হয়েছে, আর ফাইলগুলো সত্যিই কাজের কি না
 * জানতে হাতে একটা ডাটাবেজে ফিরিয়ে আনতে হয়েছে। **মালিকের ওই দুইটার
 * কোনোটাই করার কথা নয়** — আর যিনি করতে পারেন না, তিনি ধরে নেন সব ঠিক।
 *
 * ── কেন এখান থেকে ফিরিয়ে আনা যায় না ──────────────────────────────────
 * `BackupService::restore()` আছে, আর এখানে একটা বোতাম বসানো সহজ হত।
 * বসানো হয়নি: ফিরিয়ে আনা মানে **আজকের সব কাজ মুছে ফেলা**। একটা ভুল
 * ক্লিকের দাম গোটা দিনের বই।
 *
 * ওটা কমান্ড লাইনের কাজ, আর তখন পাশে একজন থাকে যিনি জানেন কী হচ্ছে।
 * তাই পর্দায় থাকে **নির্দেশটা**, বোতামটা নয়।
 */
class BackupController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BackupService $backups,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.backup.manage')];
    }

    public function index(Request $request): View
    {
        $files = $this->backups->all();

        return view('system_admin::backup.index', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * নতুনটা আগে — কারণ প্রশ্নটা প্রায় সবসময় "শেষটা কবে",
             * "প্রথমটা কবে" নয়।
             */
            'files' => collect($files)
                ->map(fn (string $path) => [
                    'name' => basename($path),
                    'bytes' => is_file($path) ? (int) filesize($path) : 0,
                    /*
                     * ঘড়িটা স্পষ্ট করে বলা — `createFromTimestamp()`
                     * কিছু না বললে **UTC** ধরে, অ্যাপের ঘড়ি নয়।
                     *
                     * ফল ছিল: ২৬ আগস্ট ভোর ০২:৩০-এর ডাম্পটা পর্দায়
                     * দেখাত "২৫/০৮ ০৮:৩০ PM" — ছয় ঘণ্টা আগে, আর
                     * ফাইলের নামের সাথেও মিলত না।
                     *
                     * ঠিক এই শ্রেণির ভুলই আজ সকালে গোটা অ্যাপে পাওয়া
                     * গেছে; এটা তারই পঞ্চম চেহারা, আর এবার আমার
                     * নিজের লেখা পর্দায়।
                     */
                    'at' => is_file($path)
                        ? Carbon::createFromTimestamp(filemtime($path), config('app.timezone'))
                        : null,
                ])
                ->sortByDesc('name')
                ->values(),

            'latest' => $this->backups->latest(),
            'mirrorPath' => $this->backups->mirrorPath(),
            'mirroredAt' => $this->backups->mirroredAt(),
            'keepDays' => (int) config('abos.backup.keep_days'),
            'dailyAt' => (string) config('abos.backup.daily_at'),
            'directory' => (string) config('abos.backup.path'),
        ]);
    }

    /**
     * এখনই একটা ব্যাকআপ।
     *
     * ── কেন এই বোতামটা থাকা দরকার ───────────────────────────────────
     * রোজকারটা রাতে চলে। কিন্তু কিছু মুহূর্তে মানুষ **এখনই** একটা কপি
     * চান — বছর বন্ধ করার আগে, দাম বদলানোর আগে, বা কেউ একটা বড়
     * আমদানি চালানোর আগে।
     *
     * ওই মুহূর্তে "রাত পর্যন্ত অপেক্ষা করুন" বলাটা উত্তর নয়, আর ssh
     * করতে বলাটাও নয়।
     *
     * ── কেন ব্যর্থতা পর্দাতেই বলা হয় ─────────────────────────────────
     * ব্যাকআপের ব্যর্থতা নীরব হলে সবচেয়ে বিপজ্জনক: সবাই ভাবে কপি আছে।
     * তাই ব্যতিক্রমের বার্তাটা হুবহু দেখানো হয় — `mysqldump` না পাওয়া
     * গেলে বা ডিস্ক ভরে গেলে কথাটা ওখানেই লেখা থাকে।
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $made = $this->backups->run(now());
        } catch (Throwable $e) {
            return back()->withErrors([
                'backup' => __('core.backup.failed', ['reason' => $e->getMessage()]),
            ]);
        }

        return back()->with('saved', __('core.backup.taken', [
            'name' => basename($made['file']),
            'size' => $this->size((int) $made['bytes']),
        ]));
    }

    private function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
