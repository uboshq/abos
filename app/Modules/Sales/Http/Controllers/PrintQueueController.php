<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\PrintQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * যে কাগজগুলো এখনো বেরোয়নি।
 *
 * ── কেন এই পর্দাটা দরকার ছিল ─────────────────────────────────────────
 * `PrintQueue` সারিটা রাখত, গোনাটা রাখত, ব্যর্থ চেষ্টার কারণও রাখত —
 * আর কেউ কখনো সেটা দেখতে পেত না। যে তালিকা কেউ দেখে না তা তালিকা নয়,
 * শুধু একটা টেবিল যেখানে সারি জমে।
 *
 * ── কেন এখানে "আবার ছাপো" বোতাম নেই, লিংক আছে ───────────────────────
 * সার্ভার নিজে কাগজ বের করতে পারে না — ছাপা হয় ব্রাউজারে, ব্যবহারকারীর
 * প্রিন্টারে। একটা "ছাপাও" বোতাম বসালে সেটা মিথ্যা বলত: কর্মী চাপতেন,
 * সার্ভার সারিটা "ছাপা হয়েছে" চিহ্নিত করত, আর কাগজ বেরোত না।
 *
 * তাই প্রতিটা সারি ছাপার পাতাটার দিকে একটা লিংক। কাগজ সত্যিই বেরোলে
 * ওই পাতাটাই গোনাটা বাড়ায় — যেমনটা প্রথমবারেও হয়েছিল।
 *
 * ── কেন কাউন্টারের অনুমতি নয় ────────────────────────────────────────
 * বিল ও চালান কাউন্টারের বাইরেও ছাপা হয় — অফিসের ডেস্ক থেকে, ম্যানেজারের
 * পর্দা থেকে। কাউন্টারের অনুমতির পেছনে রাখলে যে মানুষটা কাগজটা খুঁজছেন
 * তিনিই তালিকাটা দেখতে পেতেন না।
 */
class PrintQueueController extends Controller implements HasMiddleware
{
    public function __construct(private readonly PrintQueue $queue) {}

    public static function middleware(): array
    {
        return [new Middleware('can:sales.invoice.view')];
    }

    public function index(Request $request): View
    {
        return view('sales::print_queue.index', [
            'menu' => app(MenuBuilder::class)->forUser($request->user()),
            'jobs' => $this->queue->pending(),
        ]);
    }

    /**
     * "এই কাগজটা বেরিয়ে গেছে" — হাতে চিহ্নিত করা।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * প্রিন্টার আটকে গিয়ে অর্ধেক ছাপা হলে ব্যবহারকারী পরে হাতে লিখে
     * দেন, বা অন্য মেশিন থেকে ছাপেন। তখন সারিটা চিরকাল "বেরোয়নি"
     * দেখাত, আর তালিকাটা একদিন এত লম্বা হত যে কেউ আর খুলত না।
     *
     * গোনাটা বাড়ানো হয় না — কাগজটা এই ব্যবস্থার ভেতর দিয়ে বেরোয়নি,
     * তাই "কতবার ছাপা হলো" সংখ্যাটাও বাড়ার কথা নয়। শুধু সারিটা
     * অপেক্ষার তালিকা থেকে সরে।
     */
    public function settle(Request $request, PrintJob $job): RedirectResponse
    {
        $this->assertOurs($job);

        $job->update(['status' => PrintJob::PRINTED, 'failure' => null]);

        return back()->with('status', __('sales::message.print_job_settled', [
            'no' => $job->document_no ?? '',
        ]));
    }

    /**
     * অন্য কোম্পানির সারি এখানে পৌঁছায়ই না — `BelongsToCompany` স্কোপ
     * রুট-মডেল বাইন্ডিংয়েও চলে। তবু ধরাটা লেখা থাকে, কারণ স্কোপ একদিন
     * সরে গেলে এই লাইনটাই বাকি থাকত।
     */
    private function assertOurs(PrintJob $job): void
    {
        abort_unless($job->exists, 404);
    }
}
