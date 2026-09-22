<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\NoticeBoard;
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
                ? Notice::query()->with('author')->orderByDesc('id')->paginate(50)->withQueryString()
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
             * ⭐ কে পড়েছেন, কে পড়েননি — কেবল প্রশাসকের জন্য।
             *
             * ⚠️ সহকর্মী কে পড়েনি সেটা জানার কোনো কারণ নেই, আর ওটা
             * দেখালে নোটিশের পর্দা একটা নজরদারির পর্দা হয়ে যেত।
             */
            'readers' => $canManage ? $this->readers($entry) : null,
        ]);
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

        $entry = DB::transaction(function () use ($data, $request) {
            $notice = Notice::create([
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'in_ticker' => (bool) ($data['in_ticker'] ?? false),
                'created_by' => $request->user()?->id,
            ]);

            $this->setAudience($notice, $data['roles'] ?? []);

            return $notice;
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
            $entry->update([
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'in_ticker' => (bool) ($data['in_ticker'] ?? false),
            ]);

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

        foreach (array_unique(array_intersect($roles, $known)) as $role) {
            NoticeRole::create(['notice_id' => $notice->id, 'role' => $role]);
        }
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
