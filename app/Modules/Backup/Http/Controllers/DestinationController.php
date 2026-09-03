<?php

declare(strict_types=1);

namespace App\Modules\Backup\Http\Controllers;

use App\Core\Engines\Backup\DestinationFactory;
use App\Core\Engines\Backup\DriveScanner;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Backup\Models\BackupDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * গন্তব্য — কোথায় কপি যাবে, আর সেটা গ্রাহক নিজে ঠিক করেন।
 *
 * ── কেন এই পর্দাটা লাগে ──────────────────────────────────────────────
 * মালিকের কথা, ৩ সেপ্টেম্বর ২০২৬: *"user zate nijei pendrive, others
 * drive egulo select korte pare"*।
 *
 * আজ গন্তব্য বসে `.env`-এ (`ABOS_BACKUP_MIRROR`) — অর্থাৎ **কেবল
 * ডেভেলপার**। ABOS বিক্রি হয় এমন মানুষের কাছে যাঁরা `.env` খোলেন না,
 * আর যে ধাপে আমাদের হাত লাগে সেটা ক্রেতার ইনস্টলে **অসমাপ্ত**।
 *
 * ── ⚠️ আর আজ লাইভে যা সত্যিই ভাঙা ────────────────────────────────────
 * ৭৩টা ব্যাকআপ আছে, **সবগুলো একই ডিস্কে**। ইঞ্জিন নিজেই প্রতিটা রানে
 * বলে: *"দ্বিতীয় কোনো গন্তব্য বলা নেই — একই ডিস্ক নষ্ট হলে ব্যাকআপও
 * হারাবে।"* ৩-২-১ নিয়মের একটা শর্তও পূরণ হয় না।
 */
class DestinationController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DriveScanner $drives,
        private readonly DestinationFactory $factory,
    ) {}

    public function index(Request $request): View
    {
        return view('backup::destinations', [
            'menu' => $this->menu->forUser($request->user()),

            'destinations' => BackupDestination::query()
                ->orderBy('kind')
                ->orderBy('name')
                ->get(),

            /*
             * সার্ভার যে ড্রাইভগুলো দেখতে পায় — একটা সুবিধা, একমাত্র
             * পথ নয়।
             *
             * ⚠️ এগুলো **সার্ভারের** ড্রাইভ, ব্রাউজারের মেশিনের নয়।
             * অফিসের মেশিনে ABOS চললে পেনড্রাইভটা ওখানেই লাগানো থাকে
             * আর তালিকাটা কাজে লাগে; সার্ভার ডেটা সেন্টারে থাকলে
             * গ্রাহকের নিজের পেনড্রাইভ এখানে কোনোদিন দেখা যাবে না —
             * তখন পথটা "ব্যাকআপ নামান" বোতাম।
             *
             * খালি তালিকা তাই বৈধ উত্তর, ব্যর্থতা নয়।
             */
            'drives' => $this->drives->drives(),

            'drivers' => DestinationFactory::DRIVERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in(DestinationFactory::DRIVERS)],
            'kind' => ['required', Rule::in(['primary', 'secondary', 'offsite', 'offline'])],
            'path' => ['required_if:driver,local', 'nullable', 'string', 'max:500'],
        ]);

        /*
         * ⚠️ পথের যাচাই সংরক্ষণের মুহূর্তে, তালিকা বানানোর সময় নয়।
         *
         * পর্দায় ড্রপডাউন আছে, কিন্তু গ্রাহক হাতেও পথ লিখতে পারেন, আর
         * ফর্ম বাইপাস করেও পাঠানো যায়। `C:\Windows` বা অ্যাপের নিজের
         * ফোল্ডার বেছে ফেললে প্রথমটা সিস্টেম ভাঙে, দ্বিতীয়টা আরও
         * সূক্ষ্মভাবে খারাপ: **ব্যাকআপ নিজের ভেতরে নিজেকে রাখতে পারে
         * না**, আর প্রতিটা পরের ব্যাকআপ আগেরটাকে ভেতরে নিয়ে ফুলতে থাকত।
         */
        if (($data['driver'] ?? '') === 'local'
            && ! $this->drives->isAcceptable((string) ($data['path'] ?? ''))) {
            throw ValidationException::withMessages([
                'path' => __('backup::validation.path_not_allowed'),
            ]);
        }

        BackupDestination::create([
            'company_id' => CompanyContext::id(),
            'name' => $data['name'],
            'driver' => $data['driver'],
            'kind' => $data['kind'],
            'config' => ['path' => $data['path'] ?? null, 'label' => $data['name']],
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('saved', __('backup::message.destination_added'));
    }

    /**
     * "পরীক্ষা করুন" — আর এটা সুবিধা নয়, নকশার অংশ।
     *
     * ⚠️ **যে গন্তব্য কোনোদিন পরীক্ষা করা হয়নি, সেটা গন্তব্য নয় — একটা
     * আশা।** একটা ভুল পথ, একটা মেয়াদোত্তীর্ণ চাবি বা একটা খোলা পেনড্রাইভ
     * — তিনটাই সেটিংসের পাতায় নিখুঁত দেখায়, আর তিনটাই বিপদের দিনে ফাঁকা।
     *
     * ⓘ পরীক্ষাটা সত্যিই **লিখে দেখে**: `is_dir()` আর `is_writable()`
     * দুইটাই সত্যি বলতে পারে অথচ read-only mount বা ভরা ডিস্কে লেখা
     * ব্যর্থ হয় — আর সেটা ধরা পড়ত ব্যাকআপের রাতে।
     */
    public function test(Request $request, BackupDestination $destination): RedirectResponse
    {
        try {
            $health = $this->factory
                ->make($destination->driver, $destination->config ?? [])
                ->health();
        } catch (Throwable $e) {
            $destination->forceFill([
                'last_checked_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return back()->withErrors(['destination' => $e->getMessage()]);
        }

        $destination->forceFill([
            'last_checked_at' => now(),
            'last_ok_at' => $health->reachable ? now() : $destination->last_ok_at,
            'last_error' => $health->reachable ? null : __($health->reason ?? ''),
        ])->save();

        return $health->reachable
            ? back()->with('saved', __('backup::message.destination_ok', [
                'name' => $destination->name,
            ]))
            : back()->withErrors([
                'destination' => __($health->reason ?? 'backup::health.unknown'),
            ]);
    }

    /**
     * একটা গন্তব্য সরানো।
     *
     * ⚠️ soft delete — আর কারণটা ইতিহাসের: `bak_runs`-এ লেখা আছে কোন
     * কপি কোন গন্তব্যে গিয়েছিল। সারিটা সত্যিই মুছে ফেললে পুরনো রানগুলো
     * এমন একটা নাম দেখাত যার আর কোনো অস্তিত্ব নেই, আর "ওই কপিটা
     * কোথায় গিয়েছিল" প্রশ্নের উত্তর হারাত।
     */
    public function destroy(BackupDestination $destination): RedirectResponse
    {
        $destination->delete();

        return back()->with('saved', __('backup::message.destination_removed'));
    }
}
