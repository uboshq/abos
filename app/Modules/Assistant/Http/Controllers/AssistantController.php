<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\AssistantDismissal;
use App\Models\AssistantEvent;
use App\Models\User;
use App\Modules\Assistant\Watch\Brief;
use App\Modules\Assistant\Watch\Watch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ কোপাইলটের নিজের পাতা আর খাতা (পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2 — অংশ ১ আর ১২; ধাপ ০ ও ১)।
 *
 * ⓘ পাতা: সকালের সারাংশ (যার যার চাবি ধরে) আর নজর (চার বোর্ডের সমস্যা কেন্দ্র), "এটা ঠিক আছে" কারণসহ।
 *   প্রস্তাব, খসড়া আর প্রশ্ন পরের ধাপে — পাতায় তাদের ট্যাব "আসছে" বলে।
 * ⓘ খাতা: কে কী খুঁজল, কে কী জিজ্ঞেস করল, কোপাইলট কী বলল — কেবল মালিক; ছাঁকা যায়, বদলানো বা মোছা যায় না।
 */
class AssistantController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AssistantEngine $engine,
    ) {}

    public function today(Request $request, Watch $watch, Brief $brief): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('assistant::today', [
            'menu' => $this->menu->forUser($user),
            'name' => $this->engine->name(),
            'brief' => $this->engine->on('brief') ? $brief->for($user) : null,
            'findings' => $this->engine->on('watch') ? $watch->for($user) : null,
            'dismissed' => AssistantDismissal::query()->with('user:id,name')->where('until', '>=', Carbon::today()->toDateString())->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * ⭐ "এটা ঠিক আছে" — নজরটা [[AssistantDismissal::DAYS]] দিন চুপ, কারণ লেখা বাধ্যতামূলক; খাতায়ও ওঠে।
     * ⓘ নজরটা এই মানুষের চোখে পড়ার মতো হতে হয় — অন্যের উৎসের নজর চুপ করানো যায় না।
     */
    public function dismiss(Request $request, Watch $watch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'finding' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $seen = collect($watch->for($user))->firstWhere('id', $data['finding']);
        abort_if($seen === null, 404);

        AssistantDismissal::query()->create([
            'finding' => $data['finding'], 'reason' => trim($data['reason']),
            'until' => Carbon::today()->addDays(AssistantDismissal::DAYS - 1)->toDateString(), 'user_id' => $user->id,
        ]);
        $this->engine->record(AssistantEvent::DISMISS, $user, (string) $seen['title'], [
            'module' => (string) $seen['module'], 'reason' => mb_substr(trim($data['reason']), 0, 255), 'outcome' => $data['finding'],
        ]);

        return redirect()->route('assistant.today')->with('saved', __('assistant::page.dismissed'));
    }

    /** ⭐ খোঁজা ও কোপাইলটের খাতা — কেবল মালিকের চাবি (`assistant.audit`) */
    public function audit(Request $request): View
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'kind' => ['nullable', Rule::in(AssistantEvent::KINDS)],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $rows = AssistantEvent::query()->with('user:id,name')
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d)->addDay()->startOfDay()))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', (int) $u))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('text', 'like', '%'.$s.'%')->orWhere('page', 'like', '%'.$s.'%')))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('assistant::audit', [
            'menu' => $this->menu->forUser($request->user()),
            'name' => $this->engine->name(),
            'rows' => $rows,
            'filters' => $f,
            'people' => User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->whereIn('id', AssistantEvent::query()->whereNotNull('user_id')->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
