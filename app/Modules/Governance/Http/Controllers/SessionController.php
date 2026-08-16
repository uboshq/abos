<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * কোথায় কোথায় লগইন আছে — আর দূর থেকে বন্ধ করা।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * কাউন্টারের কম্পিউটারে লগইন রেখে কেউ বাড়ি চলে গেলে আজ কিছু করার নেই।
 * পাসওয়ার্ড বদলালেও পুরনো সেশনটা চলতেই থাকে — Laravel-এর সেশন
 * পাসওয়ার্ডের সাথে বাঁধা নয়। অর্থাৎ যে কর্মীকে আজ ছাঁটাই করা হলো,
 * তাঁর খোলা ব্রাউজারটা কাল সকালেও ঢুকতে পারে।
 *
 * ── কেন নিজেরটা আলাদা করে চেনানো হয় ────────────────────────────────
 * তালিকায় নিজের সেশনটাও থাকে, আর সেটা বন্ধ করা মানে নিজেই বেরিয়ে
 * যাওয়া। চিহ্ন না দিলে কেউ "সব বন্ধ করুন" চেপে নিজেই বেরিয়ে যেতেন আর
 * ভাবতেন কিছু ভেঙেছে।
 */
class SessionController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        /*
         * সেশনগুলো Eloquent দিয়ে নয়, কোয়েরি বিল্ডারে।
         *
         * `sessions` ফ্রেমওয়ার্কের নিজের টেবিল — ওটার কোনো মডেল নেই,
         * আর বানালে সেটা অডিটের পাহারায় ধরা পড়ত (কোম্পানি-স্কোপড নয়,
         * অডিটেডও নয়)। টেবিলটা আমাদের নয়, তাই মডেলও নয়।
         */
        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => (object) [
                'id' => $row->id,
                'ip' => $row->ip_address,
                'agent' => $this->readable($row->user_agent),
                'seen' => Carbon::createFromTimestamp($row->last_activity),
                'mine' => $row->id === $request->session()->getId(),
            ]);

        return view('governance::session.index', [
            'menu' => $this->menu->forUser($request->user()),
            'sessions' => $sessions,
        ]);
    }

    /**
     * একটা সেশন বন্ধ — সারিটা মুছে দিলেই যথেষ্ট।
     *
     * পরের অনুরোধে Laravel সেশনটা খুঁজে পায় না, আর ব্যবহারকারী লগইনের
     * পর্দায় ফিরে যান। কোনো "logout" বার্তা পাঠানোর দরকার নেই — ওই
     * ব্রাউজারটা হয়তো এখন বন্ধই।
     */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        /*
         * কেবল নিজের সেশন।
         *
         * অন্য কারও সেশন বন্ধ করা একটা প্রশাসনিক কাজ, আর সেটার নিজের
         * অনুমতি লাগে। id-টা অনুরোধেই আসে, তাই এই শর্তটা না থাকলে যে
         * কেউ যে কারও সেশনের id বসিয়ে তাঁকে বের করে দিতে পারতেন।
         */
        DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->delete();

        return back()->with('status', __('governance::message.session_ended'));
    }

    /**
     * সব বাদে এটা — "আমার অন্য সব জায়গা থেকে বেরোও"।
     *
     * নিজেরটা রেখে দেওয়া হয়, কারণ নাহলে বোতামটা চাপার সাথে সাথেই
     * ব্যবহারকারী লগইনের পর্দায় ফিরে যেতেন আর বুঝতেই পারতেন না কাজটা
     * হয়েছে কি না।
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '<>', $request->session()->getId())
            ->delete();

        return back()->with('status', __('governance::message.other_sessions_ended'));
    }

    /**
     * ব্রাউজারের লম্বা পরিচয়টা মানুষের ভাষায়।
     *
     * পুরো user-agent স্ট্রিং দুইশো অক্ষরের, আর তার একটাও কাজের নয়।
     * যিনি দেখছেন তিনি জানতে চান "এটা কি আমার ফোন, নাকি কাউন্টারের
     * কম্পিউটার" — ওইটুকুই।
     */
    private function readable(?string $agent): string
    {
        $agent = (string) $agent;

        $device = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('governance::message.unknown_device'),
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };

        return trim($device.' · '.$browser, ' ·');
    }
}
