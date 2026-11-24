<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\ListExport;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\AbosHead;
use App\Core\Support\CompanyContext;
use App\Core\Support\PerPage;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ⭐ লগইন নিরাপত্তা — এক পাতায়: কার দুই-ধাপ চালু, গত সাত দিনের ভুল লগইন, কোন ফোনে কে ঢুকে আছেন, আর আপনাআপনি লগআউট (কন্ট্রোল
 * প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৯: "নিরাপত্তা ছড়িয়ে আছে")।
 *
 * ⓘ কেবল দেখায় আর ঠিক জায়গায় পাঠায় — দুই-ধাপ বাধ্যতামূলক বা রিসেট ব্যবহারকারীর পাতায়, লগআউটের মিনিট কন্ট্রোল প্যানেলে, পুরো
 * লগইনের খাতা নিয়ন্ত্রণ ও নিরীক্ষায়। ⛔ কোনো বদল এখানে নয় — একই কাজ দুই জায়গায় হলে কোনটা আসল প্রশ্ন ওঠে।
 * ⓘ কেবল এই কোম্পানির মানুষ (পিভট) আর তাঁদের চেষ্টা — অচেনা নামের চেষ্টা কোনো কোম্পানির নয়, আরেক কোম্পানির ইমেইল এখানে ফাঁস হতো;
 *   ওগুলো নিয়ন্ত্রণ ও নিরীক্ষার পুরো খাতায়।
 */
final class LoginSecurityController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.user.manage')];
    }

    public function show(Request $request): View
    {
        $people = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'mfa_secret', 'mfa_confirmed_at', 'two_step_required']);
        $ids = $people->pluck('id')->all();

        // ⓘ এই কোম্পানির খাতা — কোম্পানি বসার আগের চেষ্টায় company_id ফাঁকা, তাই সেগুলোও (মানুষটা তো এই কোম্পানিরই) [[EveryRawQueryNamesItsCompanyTest]]
        $lastIn = LoginAttempt::query()->where(fn ($q) => $q->where('company_id', CompanyContext::id())->orWhereNull('company_id'))->whereIn('user_id', $ids)->where('succeeded', true)
            ->groupBy('user_id')->selectRaw('user_id, MAX(created_at) as at')->pluck('at', 'user_id');

        // ⓘ "কোড দেওয়া হয়নি" ভুল নয় — দুই-ধাপের লগইনের প্রথম ধাপে কোড থাকেই না ([[LoginAttempt]]); গুনলে প্রতিটা ঠিক লগইন একবার ভুল দেখাত
        $wrong = fn () => LoginAttempt::query()->where(fn ($q) => $q->where('company_id', CompanyContext::id())->orWhereNull('company_id'))->where('succeeded', false)->where('reason', '!=', LoginAttempt::NEEDS_CODE)
            ->where('created_at', '>=', now()->subDays(7))->whereIn('user_id', $ids);
        $failedCount = $wrong()->count();

        $phones = DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $ids)
            ->groupBy('tokenable_id')->selectRaw('tokenable_id, COUNT(*) as n, MAX(last_used_at) as used')
            ->get()->keyBy('tokenable_id');

        $rows = $people->map(fn (User $u) => [
                'user' => $u,
                'two_step' => $u->mfa_secret !== null && $u->mfa_confirmed_at !== null,
                'required' => (bool) $u->two_step_required,
                'last_in' => $lastIn[$u->id] ?? null,
                'phones' => (int) ($phones[$u->id]->n ?? 0),
                'phone_used' => $phones[$u->id]->used ?? null,
        ]);
        $facts = [
            'menu' => $this->menu->forUser($request->user()),
            'failedCount' => $failedCount,
            'logoutMinutes' => (int) app(SettingsService::class)->get('system.auto_logout_minutes', 0),
        ];

        // ⭐ এক সারির বার কেবল তিন ABOS রূপে (মালিকের "ok", ২৫ নভেম্বর ২০২৬; নমুনা BNUbjUdEXdfYqxgyjbcn2m) — বাকি রূপে আগের পাতা
        if (! AbosHead::on()) {
            return view('system_admin::security.show', $facts + ['people' => $rows->all(), 'failed' => $wrong()->latest('id')->limit(10)->get(['identifier', 'reason', 'ip_address', 'created_at', 'user_id'])]);
        }

        // ⛔ নামানো যায় না — ঢোকার খাতার একই তথ্য ([[ListExport::refuse()]])
        app(ListExport::class)->refuse();

        return view('system_admin::security.bar', $facts + [
            'tab' => $request->query('tab') === 'failed' ? 'failed' : 'people',
            // ⓘ কার্ডের সংখ্যা গোটা কোম্পানির, ছাঁকা বা এক পাতার নয়
            'everyone' => $rows,
            'people' => $this->page($this->narrowed($rows, $request), $request),
            'failed' => $this->narrowedWrong($wrong(), $request)->paginate(PerPage::of(), ['identifier', 'reason', 'ip_address', 'created_at', 'user_id'])->withQueryString(),
        ]);
    }

    /** @return 'on'|'required'|'off' দুই-ধাপের অবস্থা — পাতার চিহ্নের একই তিনটা */
    public static function twoStepOf(array $row): string
    {
        return $row['two_step'] ? 'on' : ($row['required'] ? 'required' : 'off');
    }

    /**
     * মানুষের ট্যাব — খোঁজা, ছাঁকনি, সাজানো; মানুষগুলো উপরে এই কোম্পানিরই।
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function narrowed(Collection $rows, Request $request): Collection
    {
        $find = mb_strtolower($request->string('q')->trim()->value());
        $step = $request->string('two_step')->value();
        $state = $request->string('state')->value();

        $rows = $rows
            ->filter(fn (array $r): bool => $find === '' || str_contains(mb_strtolower($r['user']->name.' '.$r['user']->email), $find))
            ->filter(fn (array $r): bool => $step === '' || self::twoStepOf($r) === $step)
            ->filter(fn (array $r): bool => ! in_array($state, ['active', 'inactive'], true) || (bool) $r['user']->is_active === ($state === 'active'));

        return match ($request->query('sort')) {
            'last_in' => $rows->sortByDesc(fn (array $r): string => (string) $r['last_in']),
            'phones' => $rows->sortByDesc('phones'),
            default => $rows,
        };
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function page(Collection $rows, Request $request): LengthAwarePaginator
    {
        $size = PerPage::of();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator($rows->forPage($page, $size)->values(), $rows->count(), $size, $page, ['path' => $request->url()]))->withQueryString();
    }

    /** ভুল লগইনের ট্যাব — গত সাত দিনের ভিতরেই; খোঁজা এক বন্ধনীতে, যাতে কোম্পানির শর্ত না ভাঙে */
    private function narrowedWrong(Builder $wrong, Request $request): Builder
    {
        $f = $request->validate([
            'reason' => ['nullable', 'string', 'max:40'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $find = $request->string('q')->trim()->value();

        return $wrong
            ->when($find !== '', function (Builder $q) use ($find): void {
                $like = '%'.addcslashes($find, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('identifier', 'like', $like)->orWhere('ip_address', 'like', $like));
            })
            ->when($f['reason'] ?? null, fn (Builder $q, string $v) => $q->where('reason', $v))
            ->when($f['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('id', $request->query('sort') === 'oldest' ? 'asc' : 'desc');
    }
}
