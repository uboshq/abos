<?php

declare(strict_types=1);

namespace App\Modules\Backup\Http\Controllers;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\BackupService;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Services\BackupRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * ব্যাকআপের পর্দা — যা রোজ চলছে, তা দেখার আর হাতে চালানোর জায়গা।
 *
 * ── এই ফাইলটা SystemAdmin থেকে এসেছে, ৩ সেপ্টেম্বর ২০২৬ ──────────────
 * আগের ফাইলের যুক্তিগুলো এখনো খাটে, আর সেগুলো এখানে রাখা হয়েছে —
 * বিশেষত **কেন পর্দা থেকে restore করা যায় না**: ফিরিয়ে আনা মানে আজকের
 * সব কাজ মুছে ফেলা, আর একটা ভুল ক্লিকের দাম গোটা দিনের বই। ওটা কমান্ড
 * লাইনের কাজ, যখন পাশে একজন থাকেন যিনি জানেন কী হচ্ছে।
 *
 * ── যা নতুন ──────────────────────────────────────────────────────────
 * ১. প্রতিটা রান এখন `bak_runs`-এ লেখা হয় — ফোল্ডার দেখে ইতিহাস
 *    বোঝার দিন শেষ।
 * ২. একাধিক গন্তব্য, আর কোনটায় পৌঁছেছে কোনটায় নয় তা আলাদা করে।
 * ৩. ⚠️ **নামানোর বোতাম**, সবচেয়ে কড়া অনুমতির পেছনে।
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BackupService $backups,
        private readonly BackupRunner $runner,
    ) {}

    public function index(Request $request): View
    {
        $files = $this->backups->all();

        return view('backup::index', [
            'menu' => $this->menu->forUser($request->user()),

            /*
             * নতুনটা আগে — প্রশ্নটা প্রায় সবসময় "শেষটা কবে", "প্রথমটা
             * কবে" নয়।
             */
            'files' => collect($files)
                ->map(fn (string $path) => [
                    'name' => basename($path),
                    'bytes' => is_file($path) ? (int) filesize($path) : 0,

                    /*
                     * ⚠️ ঘড়িটা স্পষ্ট করে বলা — `createFromTimestamp()`
                     * কিছু না বললে **UTC** ধরে, অ্যাপের ঘড়ি নয়।
                     *
                     * আগে এই ভুলে ২৬ আগস্ট ভোর ০২:৩০-এর ডাম্প পর্দায়
                     * "২৫/০৮ ০৮:৩০ PM" দেখাত — ছয় ঘণ্টা আগে, আর
                     * ফাইলের নামের সাথেও মিলত না।
                     */
                    'at' => is_file($path)
                        ? Carbon::createFromTimestamp(filemtime($path), config('app.timezone'))
                        : null,
                ])
                ->sortByDesc('name')
                ->values(),

            'runs' => BackupRun::query()
                ->latest('started_at')
                ->limit(20)
                ->get(),

            'destinations' => BackupDestination::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),

            'latest' => $this->backups->latest(),
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
     * চান — বছর বন্ধ করার আগে, দাম বদলানোর আগে, বা একটা বড় আমদানির
     * আগে। ওই মুহূর্তে "রাত পর্যন্ত অপেক্ষা করুন" উত্তর নয়।
     *
     * ── কেন ব্যর্থতা পর্দাতেই বলা হয় ─────────────────────────────────
     * ⚠️ ব্যাকআপের ব্যর্থতা নীরব হলে সবচেয়ে বিপজ্জনক: সবাই ভাবে কপি
     * আছে, আর ভুলটা ধরা পড়ে কেবল যেদিন ফাইলটা দরকার হয়।
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $run = $this->runner->runNow($request->user(), 'manual');
        } catch (Throwable $e) {
            return back()->withErrors([
                'backup' => __('core.backup.failed', ['reason' => $e->getMessage()]),
            ]);
        }

        /*
         * ⚠️ বার্তাটা বলে **কয়টা গন্তব্যে পৌঁছেছে**, কেবল "সফল" নয়।
         *
         * শূন্য মানে ফাইলটা কেবল সার্ভারেই আছে — অর্থাৎ মেশিনটা গেলে
         * ব্যাকআপও যাবে। "ব্যাকআপ নেওয়া হয়েছে" পড়ে কেউ যেন সেটাকে
         * নিরাপত্তা না ভাবেন।
         */
        return back()->with('saved', __('backup::message.taken', [
            'name' => basename((string) $run->file),
            'size' => $this->size((int) $run->bytes),
            'copies' => $run->copiesLanded(),
        ]));
    }

    /**
     * ⚠️ ফাইলটা নামানো — owner / super admin ছাড়া কেউ নয়।
     *
     * ── কেন এত কড়া ───────────────────────────────────────────────────
     * এই একটা ফাইল মানে **গোটা কোম্পানির ডাটাবেস**: প্রতিটা দর, প্রতিটা
     * বেতন, প্রতিটা গ্রাহকের বকেয়া — এমনকি যেসব ঘর [[FieldSecurity]]
     * দিয়ে আড়াল করা, সেগুলোও। অর্থাৎ নামানোর অনুমতি কার্যত **সবকিছু
     * দেখার অনুমতি**, আর সেটা Backup Operator-এর হাতে থাকা উচিত নয়:
     * তিনি ব্যাকআপ *চালাতে* পারবেন, *নিয়ে যেতে* নয়।
     *
     * ── তবু বোতামটা লাগবেই ──────────────────────────────────────────
     * সার্ভার যখন ডেটা সেন্টারে, গ্রাহকের নিজের পেনড্রাইভে কপি নেওয়ার
     * **একমাত্র** পথ এটাই — সার্ভার তাঁর ল্যাপটপের ড্রাইভ দেখতে পায় না।
     *
     * ── নামটা যাচাই করা হয়, জোড়া হয় না ──────────────────────────────
     * ⚠️ `basename()` দিয়ে ছেঁটে তারপর তালিকার সাথে মেলানো হয়।
     * সরাসরি পথ জুড়লে `../../.env` জাতীয় নাম পাঠিয়ে যেকোনো ফাইল
     * নামিয়ে নেওয়া যেত — আর এই দরজাটার পেছনে সবচেয়ে সংবেদনশীল
     * ফাইলগুলোই থাকে।
     */
    public function download(Request $request, string $name): BinaryFileResponse
    {
        $wanted = basename($name);

        $path = collect($this->backups->all())
            ->first(fn (string $p): bool => basename($p) === $wanted);

        abort_if($path === null || ! is_file($path), 404);

        /*
         * প্রতিটা নামানো অডিটে — কে, কখন, কোন ফাইল, কোন ঠিকানা থেকে।
         *
         * ⚠️ এটা ঐচ্ছিক নয়। একটা ব্যাকআপ ফাইল বাইরে চলে যাওয়া মানে
         * গোটা কোম্পানির তথ্য বাইরে যাওয়া, আর সেটা যদি কোনোদিন হয়,
         * প্রথম প্রশ্নটা হবে **কে নিয়েছিল** — উত্তরটা তখন থাকতে হবে।
         */
        app(AuditEngine::class)->recordAction(
            /*
             * বিষয় হিসেবে **ব্যবহারকারী**, ব্যাকআপের সারি নয়।
             *
             * ── কেন ─────────────────────────────────────────────
             * `bak_runs` টেবিলটা আজ তৈরি হলো, কিন্তু ফোল্ডারে
             * এর আগের ৭৩টা ফাইল পড়ে আছে যাদের কোনো সারি নেই।
             * সারিটাকে বিষয় বানালে পুরনো ফাইল নামানো **অডিট ছাড়াই**
             * হয়ে যেত — অর্থাৎ ঠিক যে ফাইলগুলোর ইতিহাস সবচেয়ে
             * অস্পষ্ট, সেগুলোই পাহারার বাইরে থাকত।
             *
             * আর কাজটা আসলে মানুষের: "কে গোটা ডাটাবেস নিয়ে গেল"।
             */
            $request->user(),
            'backup.downloaded',
            $wanted,
        );

        return response()->download($path);
    }

    private function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
