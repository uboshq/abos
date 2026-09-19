<?php

declare(strict_types=1);

namespace App\Modules\Backup\Http\Controllers;

use App\Core\Services\BackupService;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * ব্যাকআপের চারটা "পরিকল্পিত" পর্দা — নীতি, যাচাই, ফেরানো, দুর্যোগ।
 *
 * ── ⭐ নিরীক্ষার ধাপ ৫.১, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * *"ব্যাকআপ মডিউলের ৪টি পর্দা সম্পূর্ণ করুন — ইঞ্জিন, কমান্ড ও পরীক্ষা
 * আগে থেকেই আছে, কেবল পর্দা নেই।"*
 *
 * ── ⚠️ চারটাই কেবল দেখার — আর কারণগুলো মাপা ───────────────────────────
 * নীতি: রাতের সময়, কতদিন রাখা, দ্বিতীয় কপি — সবই সার্ভারের
 * `config('abos.backup')` থেকে চলে। `bak_policies` টেবিলটা **কেউ পড়ে না**।
 * ⛔ সম্পাদনার পর্দা বানালে মানুষ বদলাতেন, "সংরক্ষিত" দেখতেন, আর রাতের
 * ব্যাকআপ আগের নিয়মেই চলত। ⓘ তাই পর্দাটা বলে **যা সত্যিই হয়**।
 *
 * ফেরানো: ফেরানো মানে আজকের সব কাজ মুছে যাওয়া। ⛔ ব্রাউজারে এক-ক্লিকে
 * সেটা রাখা মালিকের সিদ্ধান্ত, এই কোডের নয় — তাই পর্দায় থাকে কোন ফাইলটা
 * যাচাই-করা, আর হুবহু কমান্ডটা (যেটা ফেরানোর আগে নিজে একটা নিরাপত্তা-
 * ডাম্প নেয়)।
 */
class RecoveryController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BackupService $backups,
    ) {}

    /** নীতি — যা সত্যিই চলে, সার্ভারের সেটিং থেকে */
    public function policy(Request $request): View
    {
        return view('backup::policy', [
            'menu' => $this->menu->forUser($request->user()),
            'dailyAt' => (string) config('abos.backup.daily_at'),
            'keepDays' => (int) config('abos.backup.keep_days'),
            'directory' => (string) config('abos.backup.path'),
            'mirror' => $this->backups->mirrorPath(),
            'destinations' => BackupDestination::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * যাচাই — প্রতিটা রাত, আর রাতটা ফিরিয়ে এনে দেখা গিয়েছিল কি না।
     *
     * ⓘ তালিকাটা রাতের (BackupRun), যাচাইয়ের নয়: ডাম্পই না হওয়া রাতের
     * কোনো যাচাই থাকে না, অথচ সেই রাতটাই সবচেয়ে জরুরি লাল সারি।
     */
    public function verifications(Request $request): View
    {
        $runs = BackupRun::query()
            ->with('verifications')
            ->latest('started_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('backup::verifications', [
            'menu' => $this->menu->forUser($request->user()),
            'runs' => $runs,
            'since' => BackupRun::query()->min('started_at'),
        ]);
    }

    /** ফেরানো — কোন ফাইল, সেটা যাচাই-করা কি না, আর হুবহু কমান্ড */
    public function restore(Request $request): View
    {
        $checked = $this->checkedFiles();

        $files = collect($this->backups->all())
            ->map(fn (string $path) => [
                'name' => basename($path),
                'at' => is_file($path) ? Carbon::createFromTimestamp(filemtime($path), config('app.timezone')) : null,
                'bytes' => is_file($path) ? (int) filesize($path) : 0,
                'check' => $checked->get(basename($path)),
            ])
            ->sortByDesc('name')
            ->values();

        return view('backup::restore', [
            'menu' => $this->menu->forUser($request->user()),
            'files' => $files,
        ]);
    }

    /** দুর্যোগ — মেশিনটা গেলে কী হাতে থাকে, আর ফেরার ধাপ */
    public function disaster(Request $request): View
    {
        $newest = $this->backups->latest();

        $lastPassed = BackupVerification::query()
            ->where('status', 'passed')
            ->latest('verified_at')
            ->first();

        return view('backup::disaster', [
            'menu' => $this->menu->forUser($request->user()),
            'newestName' => $newest !== null ? basename($newest) : null,
            'newestAt' => $newest !== null && is_file($newest)
                ? Carbon::createFromTimestamp(filemtime($newest), config('app.timezone'))
                : null,
            'lastPassed' => $lastPassed,
            'mirror' => $this->backups->mirrorPath(),
            'mirroredAt' => $this->backups->mirroredAt(),
            'destinations' => BackupDestination::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * ফাইলের নাম → তার সবশেষ যাচাই।
     *
     * @return Collection<string, BackupVerification>
     */
    private function checkedFiles(): Collection
    {
        return BackupVerification::query()
            ->with('run')
            ->orderBy('verified_at')
            ->get()
            ->filter(fn (BackupVerification $v) => $v->run?->file !== null)
            ->keyBy(fn (BackupVerification $v) => (string) $v->run->file);
    }
}
