<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NoticeAcknowledgement;
use App\Core\Services\NoticeAnalytics;
use App\Core\Services\NoticeAudience;
use App\Core\Services\NoticeBoard;
use App\Core\Services\NoticeLifecycle;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * নোটিশ — প্রতিষ্ঠানের নিজের কথা।
 *
 * ── ⓘ একটা পর্দা, দুই রকম মানুষ ──────────────────────────────────────
 * যাঁর চাবি আছে (`system_admin.notice.manage`) তিনি লেখেন, বদলান, আর
 * দেখেন **কে পড়েছেন কে পড়েননি**। বাকিরা কেবল নিজেদের নোটিশগুলো পড়েন।
 *
 * ⚠️ দুইটা আলাদা পর্দা বানানো হয়নি ইচ্ছা করেই: একই জিনিসের দুইটা
 * তালিকা মানে একদিন একটায় নিয়ম বদলাত আর অন্যটায় নয়, আর তখন মালিক
 * এমন নোটিশ দেখতেন যেটা কর্মী দেখতেন না।
 *
 * ── ⛔ কেন পড়ার পর্দাটা চাবির পিছনে নয় ───────────────────────────────
 * নোটিশ **সবার জন্য** — চাবি চাইলে ঠিক তাঁরাই বাদ পড়তেন যাঁদের জন্য
 * নোটিশটা লেখা। ⓘ কে কোনটা দেখবেন সেটা ভূমিকা ঠিক করে
 * ([[NoticeBoard::forUser()]]), অনুমতি নয়।
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly NoticeBoard $board,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $mine = $user->can('system_admin.notice.manage');

        return view('system_admin::notice.index', [
            'menu' => $this->menu->forUser($user),

            /*
             * ⚠️ প্রশাসক **সবগুলো** দেখেন — মেয়াদ শেষ হওয়াগুলোসহ।
             * ⓘ নাহলে যে নোটিশটা কাল শেষ হয়েছে সেটা আর খুঁজেই পাওয়া
             * যেত না, অথচ *"কে পড়েছিল"* প্রশ্নটা ওঠে ঠিক তখনই।
             */
            'notices' => $mine
                /*
                 * ⓘ `audience`-ও সাথে — তালিকার প্রতিটা সারি ওটা পড়ে।
                 *
                 * ⚠️ ছাড়া পঞ্চাশ সারির পাতায় পঞ্চাশটা কোয়েরি হত — ⛔ আর
                 * এই ভুলটা এই খাতায় আগেও ধরা পড়েছে (সরবরাহকারীর প্রদেয়)।
                 */
                ? Notice::query()->with(['author', 'audience'])
                    ->orderByDesc('id')->paginate(50)->withQueryString()
                : null,

            'mine' => $mine ? null : $this->board->forUser($user),
            'unread' => $this->board->unreadIds($user),
            'canManage' => $mine,
        ]);
    }

    public function show(Request $request, int $notice): View
    {
        /** @var User $user */
        $user = $request->user();

        $entry = Notice::query()->with('author')->findOrFail($notice);

        /*
         * ⛔ দেখার অধিকার — চাবি নয়, শ্রোতা।
         *
         * ⚠️ প্রশাসক সবই দেখেন। বাকিদের বেলায় প্রশ্নটা *"এই নোটিশটা কি
         * আপনার জন্য"*, আর উত্তরটা [[NoticeBoard]] দেয় — এখানে দ্বিতীয়বার
         * নিয়ম লিখলে বারে দেখা নোটিশ খুলতে গিয়ে ৪০৩ পেতেন।
         */
        $canManage = $user->can('system_admin.notice.manage');

        abort_unless(
            $canManage || $this->board->forUser($user)->contains('id', $entry->id),
            404
        );

        // ⓘ খুলেছেন মানে পড়েছেন — দাগটা এখানেই পড়ে।
        $this->board->markRead($entry, $user);

        return view('system_admin::notice.show', [
            'menu' => $this->menu->forUser($user),
            'notice' => $entry,
            'canManage' => $canManage,

            /*
             * ⭐ এই মানুষটা এই নোটিশের সাথে কোথায় দাঁড়িয়ে।
             *
             * ⓘ পাতাটা এইমাত্র *পড়া* দাগ দিয়েছে, তাই উত্তরটা
             * সাধারণত `read` বা `acknowledged` হবে। ⚠️ সংখ্যাটা
             * [[NoticeAcknowledgement]] থেকে নেওয়া, এখানে গোনা নয় — ⓘ একই
             * প্রশ্ন API আর হিসাবের পর্দাতেও লাগে।
             */
            'standing' => app(NoticeAcknowledgement::class)->standingOf($entry, $user),

            /*
             * ⭐ কে পড়েছেন, কে পড়েননি — কেবল প্রশাসকের জন্য।
             *
             * ⚠️ সহকর্মী কে পড়েনি সেটা জানার কোনো কারণ নেই, আর ওটা
             * দেখালে নোটিশের পর্দা একটা নজরদারির পর্দা হয়ে যেত।
             */
            'readers' => $canManage ? $this->readers($entry) : null,
        ]);
    }

    /**
     * ⛔ বার থেকে সরিয়ে দেওয়া — পড়া নয়।
     *
     * ── ⓘ যে চাবিটা লাগে না ────────────────────────────
     * ⓘ নোটিশ লেখার চাবি (`notice.manage`) এখানে চাওয়া হয় না।
     * ⚠️ সরানোটা পাঠকের কাজ, লেখকের নয় — আর সরালে কেবল
     * **নিজের** পর্দা থেকে সরে।
     *
     * ⛔ আর যে নোটিশ এই মানুষটার দিকে তাক করা নয়, সেটা সরানোরও
     * কিছু নেই — [[NoticeAudience]] সেই প্রশ্নটারও একমাত্র উত্তরদাতা।
     */
    public function dismiss(Request $request, int $notice): RedirectResponse
    {
        $entry = Notice::query()->findOrFail($notice);
        $user = $request->user();

        abort_unless(app(NoticeAudience::class)->reaches($entry, $user), 403);

        $moved = app(NoticeBoard::class)->pushAside($entry, $user);

        return back()->with($moved ? 'saved' : 'error', __($moved
            ? 'core.notice.pushed_aside'
            : 'core.notice.cannot_push_aside'));
    }

    /**
     * ⭐ নোটিশের হিসাব — কতজন পড়েছেন, কতজন মেনেছেন।
     *
     * ⓘ সংখ্যাগুলো [[NoticeAnalytics]] থেকে, এই পর্দা নিজে গোনে না —
     * ⚠️ একই সংখ্যা রিপোর্ট আর API-তেও লাগে, আর তিন জায়গায়
     * তিনবার গুনলে একদিন তিনটা আলাদা উত্তর দেখাত।
     */
    public function analytics(Request $request): View
    {
        $numbers = app(NoticeAnalytics::class);

        return view('system_admin::notice.analytics', [
            'menu' => $this->menu->forUser($request->user()),
            'byStatus' => $numbers->byStatus(),
            'waiting' => $numbers->waitingOnSignatures(),
            'numbers' => $numbers,
        ]);
    }

    /**
     * ⭐ সই দেওয়া — পড়া নয়।
     *
     * ── ⓘ কেন এখানেও লেখার চাবি লাগে না ──────────────────
     * ⚠️ সই দেন পাঠক, লেখক নয় — আর লেখার চাবি চাইলে গুদামের
     * কেউ কোনোদিন সই দিতে পারতেন না, অথচ নোটিশটা তাঁদের জন্যই।
     *
     * ⓘ পাহারাটা [[NoticeAcknowledgement]]-এ: লক্ষ্যের বাইরের কেউ
     * সই দিতে পারেন না, আর যে নোটিশ সই চায় না তাতেও নয়।
     */
    public function sign(Request $request, int $notice): RedirectResponse
    {
        $entry = Notice::query()->findOrFail($notice);

        app(NoticeAcknowledgement::class)->sign($entry, $request->user(), $request);

        return back()->with('saved', __('core.notice.signed'));
    }

    /**
     * ⛔ প্রকাশের পরে ফিরিয়ে নেওয়া — মোছা নয়।
     *
     * ── ⭐ মালিকের স্পেক, ধারা ২৬ ───────────────────────────
     * *"Published Notice ভুল হলে Delete না করে Recall ব্যবহার করতে হবে"*।
     *
     * ⓘ কারণটা বাধ্যতামূলক, আর সেটা সেবায় বসানো — ⚠️ এখানে আবার
     * লিখলে API বা সময়ের কাজ থেকে প্রত্যাহার করলে কারণ ছাড়াই
     * হত।
     */
    public function recall(Request $request, int $notice): RedirectResponse
    {
        $entry = Notice::query()->findOrFail($notice);

        app(NoticeLifecycle::class)->recall($entry, (string) $request->input('reason', ''));

        return back()->with('saved', __('core.notice.recalled_done'));
    }

    /**
     * সংরক্ষণাগারে তুলে রাখা — খোঁজা যায়, দেখা যায় না।
     */
    public function archive(Request $request, int $notice): RedirectResponse
    {
        app(NoticeLifecycle::class)->archive(Notice::query()->findOrFail($notice));

        return back()->with('saved', __('core.notice.archived_done'));
    }

    /**
     * ⭐ সংরক্ষণাগার থেকে ফিরিয়ে আনা — খসড়া হয়ে।
     *
     * ⓘ সরাসরি প্রকাশে ফেরার পথ নেই: ⛔ থাকলে দুই বছরের পুরনো
     * একটা নোটিশ অনুমোদন ছাড়াই সবার চোখের সামনে চলে আসত।
     */
    public function restore(Request $request, int $notice): RedirectResponse
    {
        app(NoticeLifecycle::class)->restore(Notice::query()->findOrFail($notice));

        return back()->with('saved', __('core.notice.restored_done'));
    }

    public function create(Request $request): View
    {
        return view('system_admin::notice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'notice' => new Notice(['is_active' => true, 'in_ticker' => false]),
            'roles' => $this->roles(),
            'chosen' => [],
        ]);
    }

    public function edit(Request $request, int $notice): View
    {
        $entry = Notice::query()->findOrFail($notice);

        return view('system_admin::notice.form', [
            'menu' => $this->menu->forUser($request->user()),
            'notice' => $entry,
            'roles' => $this->roles(),
            'chosen' => $entry->audience()->pluck('role')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $entry = DB::transaction(function () use ($data) {
            /*
             * ⚠️ নোটিশটা [[NoticeLifecycle]] দিয়ে জন্মায়, `Notice::create()` দিয়ে নয়।
             *
             * ⓘ নম্বর (`NTC-…`) বসে, অবস্থা `DRAFT` হয়, আর অগ্রাধিকার দেখে
             * বারে যাবে কি না ঠিক হয়। ⛔ সরাসরি বানালে তিনটাই এই পর্দায়
             * আবার লিখতে হত, আর পরের পর্দায় আবার।
             */
            $life = app(NoticeLifecycle::class);

            $notice = $life->draft($data);

            $this->setAudience($notice, $data['roles'] ?? []);

            /*
             * ⓘ পর্দা থেকে লেখা নোটিশ সাথে সাথেই প্রকাশিত হয়।
             *
             * ⚠️ এটা অনুমোদনের শর্ত এড়ানো নয়: ⓘ নিজের জরুরি নোটিশ
             * নিজে অনুমোদন করা যায় না, আর সে পাহারাটা [[NoticeLifecycle]]-এ
             * বসানো — এই পথেও সেটাই আটকাবে।
             *
             * ⛔ এখানে কিছু না করলে পুরনো পর্দার প্রতিটা নোটিশ খসড়া হয়ে
             * পড়ে থাকত, আর কেউ বুঝতেন না কেন।
             */
            return $life->publish($life->approve($life->submit($notice)));
        });

        return redirect()
            ->route('system_admin.notice.show', $entry->id)
            ->with('saved', __('system_admin::notice.saved'));
    }

    public function update(Request $request, int $notice): RedirectResponse
    {
        $entry = Notice::query()->findOrFail($notice);
        $data = $this->validated($request);

        DB::transaction(function () use ($entry, $data) {
            /*
             * ⭐ প্রকাশিত নোটিশ বদলালে পুরনো লেখাটা রেখে দেওয়া হয়।
             *
             * ⓘ নোটিশটা ইতিমধ্যে মানুষ পড়েছে। ⛔ লেখাটা বদলে দিলে ছয়
             * মাস পরে *"আমি এটা পড়িনি"* বলা মানুষটাকে দেখানোর মতো কিছু
             * থাকত না।
             *
             * ⚠️ খসড়ায় সংস্করণ রাখা হয় না — ⓘ কেউ দেখেইনি, আর প্রতিটা
             * টাইপের ভুল সংস্করণ হলে তালিকাটাই অপাঠ্য হত।
             */
            if ($entry->status?->isLive()) {
                app(NoticeLifecycle::class)->reviseInPlace($entry, $data, $data['change_note'] ?? null);
            } else {
                $entry->update($data);
            }

            $this->setAudience($entry, $data['roles'] ?? []);
        });

        return redirect()
            ->route('system_admin.notice.show', $entry->id)
            ->with('saved', __('system_admin::notice.saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:4000'],
            'starts_on' => ['nullable', 'date'],

            /*
             * ⚠️ শেষ তারিখ শুরুর আগে নয়। ⛔ ছাড়া এমন নোটিশ বসানো যেত
             * যেটা **কোনোদিন** দেখা যায় না, আর লেখক ভাবতেন পাঠানো হয়েছে।
             */
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            'is_active' => ['nullable', 'boolean'],
            'in_ticker' => ['nullable', 'boolean'],
            'roles' => ['array'],
            'roles.*' => ['string', 'max:125'],

            /*
             * ⓘ অগ্রাধিকার — ঐচ্ছিক, আর খালি হলে সাধারণ।
             *
             * ⚠️ বাধ্যতামূলক করলে পুরনো ফর্ম থেকে আসা প্রতিটা অনুরোধ
             * ফিরে যেত — ⛔ আর ভুলটা দেখা যেত ডিপ্লয়ের পরে।
             */
            'priority' => ['nullable', 'string', 'max:16'],
            'summary' => ['nullable', 'string', 'max:300'],
            'ack_required' => ['nullable', 'boolean'],
            'ack_deadline' => ['nullable', 'date'],
            'change_note' => ['nullable', 'string', 'max:300'],
        ]);
    }

    /**
     * ⓘ শ্রোতা পুরোটা বদলে বসানো, সারি ধরে মেলানো নয়।
     *
     * ⚠️ মেলাতে গেলে "কোন সারিটা কোনটা" ঠিক করতে হত, আর একটা ভুল মিলে
     * ভুল ভূমিকা নোটিশটা পেয়ে যেত — যেটা কেউ খেয়াল করত না, কারণ
     * সংখ্যা ঠিকই থাকত।
     *
     * @param  list<string>  $roles
     */
    private function setAudience(Notice $notice, array $roles): void
    {
        $notice->audience()->delete();

        $known = array_keys($this->roles());

        $chosen = array_values(array_unique(array_intersect($roles, $known)));

        foreach ($chosen as $role) {
            NoticeRole::create(['notice_id' => $notice->id, 'role' => $role]);
        }

        /*
         * ⭐ নতুন ঘরেও বসে — আর এই দুইবার লেখাটা অস্থায়ী।
         *
         * ⓘ পুরনো `notice_roles` এখনো কয়েক জায়গা পড়ে
         * ([[Notice]]-এর `scopeForRoles`)। ⚠️ একই দিনে নতুন ঘর বসানো আর
         * পুরনো পথ কাটা করলে কাটাটা ধরা পড়ত ডিপ্লয়ের পরে।
         *
         * ⓘ পুরনো পথটা তোলার দিনে এই লাইন দুইটাও যাবে।
         */
        app(NoticeAudience::class)->aimAt(
            $notice,
            array_map(fn (string $role) => 'role:'.$role, $chosen),
        );
    }

    /**
     * ⓘ এই কোম্পানির ভূমিকাগুলো — নাম ধরে।
     *
     * @return array<string, string>
     */
    private function roles(): array
    {
        return Role::query()
            ->where('company_id', CompanyContext::id())
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * ⭐ কে পড়েছেন, আর কে পড়েননি।
     *
     * ⚠️ দুইটা তালিকাই দরকার, আর দ্বিতীয়টাই আসল: *"কয়জন পড়েছেন"* জেনে
     * কিছু করার নেই, *"কে পড়েননি"* জেনে তাঁকে বলা যায়।
     *
     * @return array{read: list<array{name: string, at: string}>, unread: list<string>}
     */
    private function readers(Notice $notice): array
    {
        $audience = $notice->audience()->pluck('role')->all();

        $people = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', CompanyContext::id()))
            ->when($audience !== [], fn ($q) => $q->whereHas(
                'roles', fn ($r) => $r->whereIn('roles.name', $audience)
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        $seen = $notice->reads()->pluck('read_at', 'user_id');

        $read = [];
        $unread = [];

        foreach ($people as $person) {
            isset($seen[$person->id])
                ? $read[] = ['name' => $person->name, 'at' => $seen[$person->id]->format('d M Y, H:i')]
                : $unread[] = $person->name;
        }

        return ['read' => $read, 'unread' => $unread];
    }
}
