<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * জমার ধরন — FDR · DPS · সঞ্চয়পত্র · বন্ড, আর প্রতিষ্ঠান যা নতুন নামে আনে।
 *
 * ── ⭐ অর্থের মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────
 * ⛔ ধরনগুলো ডিপ্লয়ে বসানো হত ([[DepositKindInstaller]]) আর তারপর আর
 * ছোঁয়া যেত না। ⚠️ কিন্তু ব্যাংক প্রতি বছর নতুন স্কিম আনে, আর নাম-হারও
 * বদলায় — তখন ব্যবহারকারীর হাতে কোনো পথ ছিল না, কেবল ডেভেলপারের।
 *
 * ── ⚠️ মোছা নয়, নিষ্ক্রিয় ─────────────────────────────────────────────
 * ⛔ যে ধরনে জমা খোলা হয়ে গেছে সেটা মুছলে পুরনো কাগজগুলো অনাথ হত —
 * "এই FD-টা কোন স্কিমের" প্রশ্নের উত্তর চিরতরে হারাত। ⓘ তাই ব্যবহৃত
 * ধরন কেবল নিষ্ক্রিয় হয়: নতুন কাগজে আর আসে না, পুরনোগুলো অটুট থাকে।
 */
class DepositKindController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:finance.deposit.view', only: ['index']),
            new Middleware('can:finance.deposit_kind.manage',
                only: ['create', 'store', 'edit', 'update', 'toggle', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q'));

        $issuer = in_array($request->query('tab'), DepositKind::ISSUERS, true)
            ? (string) $request->query('tab')
            : 'all';

        $kinds = DepositKind::query()
            ->when($issuer !== 'all', fn ($q) => $q->where('issuer', $issuer))
            ->when($term !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('code', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('name_bn', 'like', "%{$term}%"),
            ))
            ->withCount('deposits')
            ->orderBy('issuer')
            ->orderBy('sort')
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $counts = ['all' => DepositKind::query()->count()];

        foreach (DepositKind::ISSUERS as $each) {
            $counts[$each] = DepositKind::query()->where('issuer', $each)->count();
        }

        return view('finance::deposit-kind.index', [
            'menu' => $this->menu->forUser($request->user()),
            'kinds' => $kinds,
            'tab' => $issuer,
            'counts' => $counts,
        ]);
    }

    public function create(Request $request): View
    {
        return view('finance::deposit-kind.form', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => new DepositKind(['is_active' => true, 'sort' => 0]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = DepositKind::query()->create([
            'company_id' => CompanyContext::id(),
            ...$this->validated($request, null),
        ]);

        return redirect()
            ->route('finance.deposit_kind.index')
            ->with('saved', __('finance::message.kind_saved', ['name' => $kind->name_en]));
    }

    public function edit(Request $request, DepositKind $depositKind): View
    {
        return view('finance::deposit-kind.form', [
            'menu' => $this->menu->forUser($request->user()),
            'kind' => $depositKind,
        ]);
    }

    public function update(Request $request, DepositKind $depositKind): RedirectResponse
    {
        $data = $this->validated($request, $depositKind);

        /*
         * ⛔ যে ধরনে জমা খোলা হয়েছে, তার আকার, প্রতিষ্ঠান আর "কেবল ব্যক্তির নামে" বদলায় না — লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
         * ⓘ আগে পাঁচটা চালু FDR-এর ধরন কিস্তির আকারে বদলালে সেগুলো হঠাৎ কিস্তি নিত আর DPS-এর সূচিতে "০ বাকি" দেখাত; "কেবল
         * ব্যক্তি" চালু করলে ব্যবসার নামের পুরনো জমা আইনের বাইরে পড়ত। নাম আর ক্রম বদলানো চলে।
         */
        $changed = $data['shape'] !== $depositKind->shape
            || $data['issuer'] !== $depositKind->issuer
            || (array_key_exists('personal_only', $data) && (bool) $data['personal_only'] !== (bool) $depositKind->personal_only);

        if ($changed && Deposit::query()->where('kind_id', $depositKind->id)->exists()) {
            throw ValidationException::withMessages([
                'shape' => __('finance::validation.kind_shape_in_use', ['name' => $depositKind->name_en]),
            ]);
        }

        $depositKind->update($data);

        return redirect()
            ->route('finance.deposit_kind.index')
            ->with('saved', __('finance::message.kind_saved', ['name' => $depositKind->name_en]));
    }

    /**
     * চালু বা বন্ধ — ⓘ মোছার বদলে এটাই স্বাভাবিক পথ।
     */
    public function toggle(DepositKind $depositKind): RedirectResponse
    {
        $depositKind->forceFill(['is_active' => ! $depositKind->is_active])->save();

        return redirect()
            ->route('finance.deposit_kind.index')
            ->with('saved', __($depositKind->is_active
                ? 'finance::message.kind_on'
                : 'finance::message.kind_off', ['name' => $depositKind->name_en]));
    }

    /**
     * মোছা — ⛔ কেবল যেটায় একটাও জমা খোলা হয়নি।
     *
     * ⚠️ ব্যবহৃত ধরন মুছলে পুরনো কাগজ অনাথ হত, তাই বার্তাটা পথ দেখায়:
     * নিষ্ক্রিয় করুন।
     */
    public function destroy(DepositKind $depositKind): RedirectResponse
    {
        if (Deposit::query()->where('kind_id', $depositKind->id)->exists()) {
            throw ValidationException::withMessages([
                'kind' => __('finance::validation.kind_in_use', ['name' => $depositKind->name_en]),
            ]);
        }

        $depositKind->delete();

        return redirect()
            ->route('finance.deposit_kind.index')
            ->with('saved', __('finance::message.kind_removed', ['name' => $depositKind->name_en]));
    }

    /**
     * ঘরগুলোর যাচাই।
     *
     * ⚠️ কোডটা কোম্পানির ভিতরে অনন্য — একই কোডে দুইটা ধরন থাকলে
     * তালিকায় কোনটা কোনটা বোঝা যেত না, আর পুরনো কাগজের জোড়াও ঘোলাটে হত।
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DepositKind $kind): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16',
                Rule::unique('fin_deposit_kinds', 'code')
                    ->where('company_id', CompanyContext::id())
                    ->ignore($kind?->id)],
            'name_en' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'shape' => ['required', 'string', Rule::in(DepositKind::SHAPES)],
            'issuer' => ['required', 'string', Rule::in(DepositKind::ISSUERS)],
            'personal_only' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
