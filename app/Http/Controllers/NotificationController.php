<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\MailReach;
use App\Core\Support\NotificationKinds;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * বিজ্ঞপ্তি খোলা ও পড়া।
 *
 * খবরটা যেখানে নিয়ে যাওয়ার কথা, সেখানেই নিয়ে যায়।
 *
 * ── ⭐ আর এখন নিজের একটা পাতাও — "আমার বিজ্ঞপ্তি" (মালিকের স্পেক §২, §৪, §৯খ; ১০ অক্টোবর ২০২৬) ──────────────
 * আগে নিজের পাতা ইচ্ছে করেই ছিল না ("আরেকটা ইনবক্স হত")। মালিকের স্পেক সেটা চায়: সব / না-পড়া / পড়া / আর্কাইভ,
 * খোঁজা, ছাঁকনি, সাজানো, বাছাগুলো একসাথে পড়া বা আর্কাইভ। ⓘ তবু এটা নিজের খবরেরই পাতা — কারও চাবি লাগে না, অন্যের
 * খবর দেখা যায় না, আর অন্য শাখার কাগজের খবর নাগালের বাইরে গেলে লুকায় ([[Notification::scopeVisibleTo()]])।
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MenuBuilder $menu,
    ) {}

    /**
     * কে কোন খবর পেতে চান — নিজের পছন্দ, নিজের পাতা।
     *
     * ── কেন এখানে অনুমতি লাগে না ─────────────────────────────────────
     * এটা নিজের প্রোফাইলের মতোই ব্যক্তিগত: কোম্পানির কোনো তথ্য নেই, আর
     * নিজের ঘণ্টা বন্ধ করতে কারো অনুমতি লাগার কথা নয়। ⛔ অনুমতি বসালে
     * প্রতিটা নতুন কর্মীকে "নিজের সেটিংস দেখার" চাবি আলাদা করে দিতে হত।
     */
    public function settings(Request $request): View
    {
        $user = $request->user();

        $chosen = NotificationChoice::mailChoicesFor((int) $user->id);

        /*
         * ⭐ পর্দায় প্রতিটা ধরনের জন্য চিঠির টিকটা **আগে থেকেই** ঠিক
         * অবস্থায় থাকে — তিনি কিছু বলে থাকলে তাঁর কথা, নাহলে ধরনটার
         * নিজের নিয়ম।
         *
         * ⛔ সবগুলো খালি দেখালে মানুষ ভাবতেন কোনো চিঠিই যায় না, আর
         * ⚠️ তারপর সংরক্ষণে চাপ দিলে **সত্যিই যাওয়া বন্ধ হয়ে যেত** —
         * একটা পর্দা যা দেখায় তা-ই সে সংরক্ষণ করে, আর এখানে দেখানোটা
         * ভুল হলে বন্ধ করাটা নীরব হত।
         */
        $mailed = [];

        foreach (array_keys(NotificationKinds::all()) as $type) {
            $mailed[$type] = $chosen[$type] ?? NotificationKinds::mailedByDefault($type);
        }

        return view('notifications.settings', [
            'menu' => $this->menu->forUser($user),
            'kinds' => NotificationKinds::all(),
            'silenced' => NotificationChoice::silencedFor((int) $user->id),
            'mailed' => $mailed,
            'postable' => ! MailReach::silent(),
            // ⭐ এই ব্রাউজারে Web Push — মাধ্যম সংযুক্ত হলে তবেই বোতাম (ধাপ ২)
            'pushKey' => $this->pushKey(),
        ]);
    }

    /** VAPID-এর প্রকাশ্য চাবি — Web Push সংযুক্ত না হলে `null` */
    private function pushKey(): ?string
    {
        $registry = app(ChannelRegistry::class);
        $company = (int) CompanyContext::id();

        if (! $registry->connected($company, NotificationChannel::WEB_PUSH)) {
            return null;
        }

        return $registry->config($company, NotificationChannel::WEB_PUSH)?->secret('vapid_public');
    }

    /**
     * ⓘ কেবল **বন্ধ**গুলোর সারি রাখা হয়, চালুগুলোর সারি মুছে ফেলা হয় —
     * তাই পরে নতুন ধরনের খবর যোগ হলে সেটা নিজে থেকেই চালু থাকে, আর
     * পুরনো একটা সারি নীরবে সেটা বন্ধ করে রাখে না।
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wanted = array_map('strval', (array) $request->input('kinds', []));
        $byMail = array_map('strval', (array) $request->input('mailed', []));

        foreach (array_keys(NotificationKinds::all()) as $type) {
            $bell = in_array($type, $wanted, true);
            $mail = in_array($type, $byMail, true);

            /*
             * ⭐ সারিটা মুছে ফেলা হয় কেবল তখন, যখন **দুইটা দিকই ডিফল্টে**।
             *
             * ⓘ মূল নিয়মটা মাইগ্রেশনে লেখা: সারি না থাকা মানে "যা স্বাভাবিক
             * তাই" — আর ⚠️ সেটা এখন দুইটা প্রশ্নের উত্তর, একটার নয়। ⛔ কেবল
             * ঘণ্টা দেখে মুছলে একজনের "এই খবরটা চিঠিতে চাই না" কথাটা নীরবে
             * হারিয়ে যেত, আর পরের মাসে চিঠি আবার আসতে শুরু করত।
             */
            if ($bell && $mail === NotificationKinds::mailedByDefault($type)) {
                NotificationChoice::query()
                    ->where('user_id', $user->id)
                    ->where('type', $type)
                    ->delete();

                continue;
            }

            /*
             * ⚠️ ঘণ্টা বন্ধ থাকলে চিঠিও বন্ধ — পর্দায় ঐ টিকটা তখন নিষ্ক্রিয়
             * থাকে, আর এখানেও সেটা মেনে নেওয়া হয়। ⛔ নাহলে একজন খবরটা বন্ধ
             * করে দিতেন অথচ **ইনবক্সে সেটা আসতেই থাকত**, আর তিনি ভাবতেন
             * সুইচটাই ভাঙা।
             */
            NotificationChoice::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $type],
                ['enabled' => $bell, 'by_email' => $bell && $mail],
            );
        }

        return back()->with('saved', __('core.notify.settings_saved'));
    }

    /** কত খবর এক পাতায় */
    private const PER_PAGE = 30;

    /**
     * ⭐ আমার বিজ্ঞপ্তি — নিজের সব খবর (স্পেক §৯খ)।
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'unread', 'read', 'archived'])],
            'category' => ['nullable', Rule::in(NotificationKinds::CATEGORIES)],
            'priority' => ['nullable', Rule::in(NotificationKinds::PRIORITIES)],
            'module' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'priority'])],
        ]);
        $tab = $f['tab'] ?? 'all';

        $query = $this->notifications->mine($user)
            ->when($tab === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->when($tab === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($tab === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$term.'%')
                ->orWhere('body', 'like', '%'.$term.'%')));

        match ($f['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            // ⓘ সবচেয়ে জরুরি আগে — ক্রম NotificationKinds::PRIORITIES-এর
            'priority' => $query->orderByRaw('FIELD(priority, ?, ?, ?, ?)', NotificationKinds::PRIORITIES)->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        // ⓘ পাতায় যা দেখানো হলো তা "দেখা" — খোলা নয়, তাই পড়া নয় (স্পেক: দেখা আর পড়া আলাদা)
        $this->notifications->markSeen($user, $rows->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());

        $counts = $this->notifications->mine($user)->whereNull('archived_at')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->where('notifications.company_id', CompanyContext::id())
            ->first();

        return view('notifications.index', [
            'menu' => $this->menu->forUser($user),
            'rows' => $rows,
            'tab' => $tab,
            'filters' => $f,
            'unread' => (int) ($counts->unread ?? 0),
            'total' => (int) ($counts->total ?? 0),
            'modules' => $this->notifications->mine($user)->distinct()->orderBy('module')->pluck('module')->filter()->values(),
        ]);
    }

    /**
     * ⭐ বাছা খবরগুলো — পড়া, না-পড়া, আর্কাইভ বা ফেরত (স্পেক §৪ "Bulk Read, Archive")।
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['read', 'unread', 'archive', 'restore'])],
        ]);

        $changed = $this->notifications->act($request->user(), array_map('intval', $data['ids']), $data['action']);

        app(NotificationAudit::class)->record('bulk_'.$data['action'], null, 'done', ['asked' => count($data['ids']), 'changed' => $changed]);

        return back()->with('saved', __('core.notify.bulk_done', ['count' => $changed]));
    }

    /** একটা খবর পড়া — খোলা ছাড়া (ঘণ্টার ✓ বোতাম) */
    public function read(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($this->notifications->markRead($notification, $request->user()), 403);

        return back();
    }

    /** একটা খবর আর্কাইভে, বা আর্কাইভ থেকে ফেরত */
    public function archive(Request $request, Notification $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless($notification->user_id === $user->id, 403);

        $action = $notification->isArchived() ? 'restore' : 'archive';
        $this->notifications->act($user, [(int) $notification->id], $action);
        app(NotificationAudit::class)->record($action, $notification);

        return back();
    }

    /**
     * ⭐ না-পড়া গোনা — ঘণ্টার নিরাপদ polling-এর জন্য (স্পেক §৯ক)। ⓘ সুইচ বন্ধ থাকলে (ডিফল্ট) পর্দা এটা ডাকেই না।
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->notifications->unreadCount($request->user())]);
    }

    /**
     * একটা খবর খোলা — পড়া হিসেবে বসিয়ে তার গন্তব্যে পাঠানো।
     *
     * অন্যের খবর খোলা যায় না। `markRead()` মালিকানা যাচাই করে, আর
     * এখানে সেই ফলটা ধরেই সিদ্ধান্ত হয় — নাহলে অন্যের খবরের লিংকে
     * ক্লিক করে তাঁর ঘণ্টা খালি করে দেওয়া যেত।
     */
    public function open(Request $request, Notification $notification): RedirectResponse|View
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            abort(403);
        }

        /*
         * ⛔ যে কাগজের খবর, সেটা আর তাঁর নাগালে নেই (শাখা সরানো হয়েছে, কাগজ মোছা) — খোলা নয়, আর কারণটা বলা হয়
         * (স্পেক §১৩)। ⓘ চেষ্টাটা নিরীক্ষার খাতায় যায়; খবরটা পড়া হিসেবে বসে, যাতে ঘণ্টায় ঝুলে না থাকে।
         */
        if (! $this->notifications->mayOpen($notification, $user)) {
            $this->notifications->markRead($notification, $user);
            app(NotificationAudit::class)->record('open_denied', $notification, 'denied');

            return view('notifications.no-access', ['menu' => $this->menu->forUser($user)]);
        }

        $this->notifications->markRead($notification, $user);

        return redirect()->to($notification->url ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        app(NotificationAudit::class)->record('read_all', null, 'done', ['changed' => $count]);

        return back();
    }
}
