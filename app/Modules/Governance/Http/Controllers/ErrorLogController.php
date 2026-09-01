<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ভুলের খাতা — শুধু পড়া, আর "দেখেছি" বলা।
 *
 * ── কেন এই পর্দাটা লাগে ─────────────────────────────────────────────
 * ভুলগুলো ফাইলেও লেখা হয়, কিন্তু ফাইল দেখতে ssh লাগে — আর যিনি ব্যবসা
 * চালান তিনি ssh করেন না। বিক্রির জন্য বানানো পণ্যে "সার্ভারে ঢুকে লগ
 * দেখুন" কোনো উত্তর নয়।
 *
 * ── কেন নিজের চাবি, `governance.audit.view` নয় ──────────────────────
 * অডিট বলে **কে কী বদলেছে** — ব্যবসার কথা। এই পর্দা দেখায় ফাইলের পথ,
 * লাইন নম্বর আর স্ট্যাক ট্রেস — ভিতরের গড়ন। দুইটা আলাদা প্রশ্ন, আর
 * হিসাবরক্ষককে দ্বিতীয়টা দেখানোর কোনো কারণ নেই।
 *
 * ── কেন মোছা যায় না ────────────────────────────────────────────────
 * মুছতে দিলে যে ভুলটা কেউ বুঝতে পারেনি সেটাই সবার আগে মুছে যেত।
 * "দেখেছি" বললে তালিকা পরিষ্কার হয়, ইতিহাস থাকে — আর কে দেখেছেন,
 * সেটাও লেখা থাকে।
 */
class ErrorLogController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:governance.error.view')];
    }

    public function index(Request $request): View
    {
        /*
         * কোম্পানির ছাঁকনি হাতে — [[ErrorEvent]] গ্লোবাল স্কোপ ব্যবহার
         * করে না, কারণ ভুল প্রসঙ্গ বসার আগেও ঘটতে পারে।
         *
         * প্রসঙ্গহীন ভুলগুলোও (company_id খালি) দেখানো হয়, কারণ
         * সেগুলোই সবচেয়ে গুরুতর — লগইনের পর্দা বা প্রসঙ্গ বসানোর
         * ব্যবস্থাটাই ভাঙলে ওখানেই লেখা থাকে। লুকিয়ে রাখলে ঠিক যে
         * ভুলটা সবচেয়ে জরুরি সেটাই কেউ দেখত না।
         */
        $company = CompanyContext::id();

        $rows = ErrorEvent::query()
            ->with(['user', 'acknowledger'])
            ->where(fn (Builder $q) => $q->where('company_id', $company)->orWhereNull('company_id'))
            ->when($request->query('only') !== 'all', fn (Builder $q) => $q->open())
            ->recentFirst()
            ->paginate(50)
            ->withQueryString();

        return view('governance::error.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,

            /*
             * গত চব্বিশ ঘণ্টায় কয়টা — পাতার মাথায়, একটা সংখ্যা।
             *
             * কেউ এই পর্দায় রোজ আসে না। যেদিন আসে, প্রথম প্রশ্ন
             * "এখন কিছু ভাঙা আছে কি না", আর সেটা তালিকা পড়ে বের করতে
             * হলে বেশিরভাগ দিন কেউ বের করত না।
             *
             * শূন্য হলে দেখানোই হয় না — রোজ "০টি ভুল" দেখলে সংখ্যাটা
             * অদৃশ্য হয়ে যায়, আর যেদিন ১৭ হবে সেদিনও চোখে পড়ত না।
             */
            'freshCount' => ErrorEvent::query()
                ->where(fn (Builder $q) => $q->where('company_id', $company)->orWhereNull('company_id'))
                ->open()
                ->where('last_seen_at', '>=', now()->subDay())
                ->count(),
        ]);
    }

    /**
     * "দেখেছি" — সারিটা তালিকা থেকে সরে, কিন্তু থেকে যায়।
     */
    public function acknowledge(Request $request, ErrorEvent $error): RedirectResponse
    {
        $error->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()?->getKey(),
        ])->save();

        return back()->with('saved', __('governance::message.error_acknowledged'));
    }
}
