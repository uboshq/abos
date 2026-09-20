<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ঢোকার খাতা — শুধু পড়া।
 *
 * ── কেন ব্যর্থ চেষ্টাগুলো আগে দেখানো যায় ────────────────────────────
 * সফল ঢোকা রোজকার ঘটনা; একশো সারির মধ্যে নিরানব্বইটা। যেটা দেখা দরকার
 * সেটা হলো একই নামে পঁচিশটা ব্যর্থ চেষ্টা এক ঘণ্টায়, আর সেটা সফলগুলোর
 * ভিড়ে হারিয়ে যায়। তাই "কেবল ব্যর্থ" একটা ছাঁকনি, আর উপরে গোনাটাও।
 */
class LoginHistoryController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        /*
         * ⭐ নিজের চাবি — ১৮ সেপ্টেম্বর ২০২৬, নিরীক্ষার ধাপ ৩.৪।
         *
         * ⛔ আগে অডিটের চাবিতে খুলত। ⚠️ কিন্তু লগইনের খাতা আর ব্যবসার
         * খাতা এক জিনিস নয়: এখানে থাকে **কে কখন কোথা থেকে ঢুকেছে** —
         * ঠিকানা, যন্ত্র, ব্যর্থ চেষ্টা। ⓘ ওটা IT-র প্রশ্ন, হিসাবের নয়।
         *
         * ⭐ আর উল্টোটাও: নিরাপত্তা দেখার লোককে লগইন ইতিহাস দিতে গিয়ে
         * প্রতিটা বেতন ও দরও দিয়ে দিতে হত।
         */
        return [new Middleware('can:governance.login.view')];
    }

    public function index(Request $request): View
    {
        $rows = LoginAttempt::query()

            /*
             * ⛔ চলতি কোম্পানির লগইনগুলোই — ৬ সেপ্টেম্বর ২০২৬।
             *
             * ── কী ভাঙা ছিল ─────────────────────────────────────────
             * ছাঁকনি ছিল না। ⚠️ কোম্পানি ৪৬ থেকে `/governance/logins`
             * খুললে কোম্পানি ৪৫-এর **পুরো এক পাতা লগইন** দেখা যেত — কে
             * কখন ঢুকেছে, কোন ঠিকানা থেকে, কে ব্যর্থ হয়েছে।
             *
             * ⭐ আর এটা নীতি নয়, **ভুলে যাওয়া**: ঠিক পাশের
             * [[ErrorLogController]] (:৬০) ছাঁকা আছে। ⓘ একই ফোল্ডারে
             * একটা ঠিক আরেকটা ভুল মানে কেউ একটা লিখেছিলেন, অন্যটা
             * কপি করেননি।
             *
             * ⚠️ `whereHas('companies')` নয় — `login_attempts`-এ **নিজেরই
             * একটা `company_id`** আছে। ⓘ ভুল ছাঁচ বসালে কোয়েরিটা ভাঙত না,
             * শুধু **ফাঁকা ফেরত দিত** — আর সেটা আরও খারাপ, কারণ "কোনো
             * লগইন নেই" দেখে কেউ নিশ্চিন্ত হতেন।
             *
             * ── ⛔ `orWhereNull` কেন লাগে ────────────────────────────
             * **ব্যর্থ লগইনের কোনো কোম্পানি থাকে না** — তখনো কেউ ঢোকেনি,
             * তাই `company_id` খালি। ⚠️ প্রথমে আমি কেবল `where(...)`
             * লিখেছিলাম, আর তাতে ঐ সারিগুলো **সম্পূর্ণ অদৃশ্য** হয়ে
             * গিয়েছিল — অথচ নিরাপত্তার দিক থেকে **ওগুলোই সবচেয়ে
             * জরুরি**: কে ভুল পাসওয়ার্ড দিয়ে ঢুকতে চাইছে।
             *
             * ⓘ ধরা পড়েছে [[WhoGotInTest::test_the_journal_can_be_read]]-এ।
             * ⭐ পাশের [[ErrorLogController]] (:৬০) ঠিক এই কারণেই
             * `orWhereNull` রাখে — আমি ছাঁচটা **আধা নকল করেছিলাম**।
             */
            ->where(fn (Builder $q) => $q
                ->where('company_id', CompanyContext::id())
                ->orWhere(fn (Builder $w) => $w->whereNull('company_id')
                    ->whereIn('identifier', self::identifiersHere())))
            ->with('user')
            ->when($request->query('user'), fn (Builder $q, $id) => $q->where('user_id', (int) $id))
            ->when($request->query('only') === 'failed', fn (Builder $q) => $q->failed())
            ->when($request->query('from'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '>=', Carbon::parse((string) $d)->toDateString()))
            ->when($request->query('to'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '<=', Carbon::parse((string) $d)->toDateString()))
            ->latestFirst()
            ->paginate(50)
            ->withQueryString();

        return view('governance::login.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,

            /*
             * গত চব্বিশ ঘণ্টার ব্যর্থ চেষ্টা — পাতার মাথায়।
             *
             * ── কেন একটা সংখ্যা ─────────────────────────────────────
             * কেউ এই পর্দায় রোজ আসে না। যেদিন আসে, প্রথম প্রশ্নটা
             * "কিছু অস্বাভাবিক ঘটছে কি না" — আর সেই উত্তরটা তালিকা
             * পড়ে বের করতে হলে বেশিরভাগ দিন কেউ বের করত না।
             */
            'failedToday' => LoginAttempt::query()
                // ⚠️ উপরের তালিকার মতোই — এই সংখ্যাটাও কেবল এই কোম্পানির
                ->where(fn (Builder $q) => $q
                    ->where('company_id', CompanyContext::id())
                    ->orWhere(fn (Builder $w) => $w->whereNull('company_id')
                        ->whereIn('identifier', self::identifiersHere())))
                ->failed()
                ->where('created_at', '>=', now()->subDay())
                ->count(),

            'users' => User::query()
                /*
                 * ⚠️ ছাঁকনিটা **ভিতরের কোয়েরিতেও** লাগে।
                 *
                 * ⓘ এটা ছাঁকনির ড্রপডাউন — কার লগইন দেখব। ⛔ ভিতরের
                 * তালিকাটা না ছাঁকলে অন্য কোম্পানির **নামগুলো** ঐ
                 * ড্রপডাউনে বসত, আর তালিকা ছাঁকা থাকলেও পরিচয় ফাঁস হত।
                 */
                ->whereIn('id', LoginAttempt::query()
                    ->where(fn (Builder $q) => $q
                        ->where('company_id', CompanyContext::id())
                        ->orWhere(fn (Builder $w) => $w->whereNull('company_id')
                            ->whereIn('identifier', self::identifiersHere())))
                    ->distinct()->pluck('user_id')->filter())
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * ⛔ এই কোম্পানির মানুষগুলো লগইনে যা যা লেখেন — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ── ⚠️ কেন এটা দরকার হলো ────────────────────────────────────
     * আগে শর্তটা ছিল `orWhereNull('company_id')`, আর ছাঁচটা নকল করা
     * হয়েছিল ভুলের খাতা থেকে — যেখানে ওটা ঠিক: কোম্পানি-প্রসঙ্গহীন
     * ভুল সবার। ⛔ কিন্তু লগইনের সারিতে `company_id` খালি থাকে ঠিক
     * তখনই যখন **ইমেইলটা চেনা যায়নি** ([[LoginJournal::write()]] —
     * `$user?->current_company_id`), আর সারিটায় ইমেইল ও আইপি দুইটাই
     * বসে থাকে। ⓘ ফল: প্রতিটা কোম্পানি বাকি সবার ব্যর্থ লগইনের
     * ইমেইল ও আইপি পড়তে পারত।
     *
     * ⭐ পাহারার মূল্যটা হারায় না: কেউ **আপনার** লোকের নামে বারবার
     * চেষ্টা করলে সেটা তালিকায় থাকেই। ⚠️ কেবল অন্য কোম্পানির লোকের
     * নামে চেষ্টা আর আপনার ব্যাপার নয়।
     *
     * @return list<string>
     */
    private static function identifiersHere(): array
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->get(['email', 'login_id'])
            ->flatMap(fn (User $u) => [$u->email, $u->login_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
