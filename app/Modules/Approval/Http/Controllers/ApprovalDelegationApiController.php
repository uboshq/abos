<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Engines\Approval\DelegationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * ⭐ সইয়ের ভার দেওয়া, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*।
 *
 * ⓘ ওয়েবের পাতার ([[ApprovalDelegationController]]) একই সেবা ([[DelegationService::grant()]], `revoke()`), একই চাবি
 * `approval.decide`, একই যাচাই: কেবল এই কোম্পানির চালু মানুষ, শুরু আজ বা পরে, শেষ শুরুর পরে; ⛔ নিজেকে ভার দেওয়া যায় না
 * (সেবা নিজে ঠেকায়); ⛔ ভার ফেরাতে পারেন কেবল যিনি দিয়েছেন। ⓘ মানুষ আর ভার চেনা যায় public_id-তে (চুক্তি §৩ক)।
 */
final class ApprovalDelegationApiController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly DelegationService $delegations,
        private readonly ApprovalFlowService $flows,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:approval.decide')];
    }

    /** `GET /approvals/delegations` — আমার দেওয়া ভার, আমার পাওয়া চালু ভার, কাকে দেওয়া যায়, কোন কাজে */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'given' => ApprovalDelegation::query()
                ->where('from_user_id', $user->id)
                ->with('to')
                ->orderByDesc('starts_on')
                ->limit(100)
                ->get()
                ->map(fn (ApprovalDelegation $d) => $this->row($d, (string) ($d->to?->name ?? '')))
                ->values(),
            'held' => ApprovalDelegation::query()
                ->active()
                ->where('to_user_id', $user->id)
                ->with('from')
                ->get()
                ->map(fn (ApprovalDelegation $d) => $this->row($d, (string) ($d->from?->name ?? '')))
                ->values(),
            'people' => User::query()
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id())->where('company_user.is_active', true))
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['id', 'public_id', 'name'])
                ->map(fn (User $u) => ['id' => (string) $u->public_id, 'name' => (string) $u->name])
                ->values(),
            'modules' => collect($this->modules())->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values(),
        ]);
    }

    /** `POST /approvals/delegations` — {to, starts_on, ends_on, modules[], reason} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:64'],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in(array_keys($this->modules()))],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        // ⛔ কেবল এই কোম্পানির চালু মানুষ — ওয়েবের `exists:company_user`-এর একই শর্ত, public_id ধরে
        $to = User::query()
            ->where('public_id', $data['to'])
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id())->where('company_user.is_active', true))
            ->first();

        if ($to === null) {
            return response()->json(['message' => __('validation.exists', ['attribute' => __('approval::field.delegate_to')]),
                'errors' => ['to' => [__('validation.exists', ['attribute' => __('approval::field.delegate_to')])]]], 422);
        }

        $delegation = $this->delegations->grant(
            from: $request->user(),
            to: $to,
            startsOn: (string) $data['starts_on'],
            endsOn: (string) $data['ends_on'],
            modules: array_values($data['modules'] ?? []),
            reason: $data['reason'] ?? null,
        );

        return response()->json([
            'message' => __('approval::message.delegation_saved'),
            'delegation' => $this->row($delegation->load('to'), (string) ($delegation->to?->name ?? '')),
        ], 201);
    }

    /** `DELETE /approvals/delegations/{id}` — ⛔ কেবল যিনি দিয়েছেন */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $delegation = ApprovalDelegation::query()->where('public_id', $id)->firstOrFail();

        abort_unless((int) $delegation->from_user_id === (int) $request->user()->id, 403);

        $this->delegations->revoke($delegation);

        return response()->json(['message' => __('approval::message.delegation_revoked')]);
    }

    /** @return array<string, mixed> */
    private function row(ApprovalDelegation $d, string $person): array
    {
        $modules = $this->modules();
        $today = now()->toDateString();

        return [
            'id' => (string) $d->public_id,
            'person' => $person,
            'starts_on' => DateFormat::format($d->starts_on),
            'ends_on' => DateFormat::format($d->ends_on),
            'modules' => array_values(array_map(fn (string $c) => $modules[$c] ?? $c, (array) ($d->modules ?? []))),
            'reason' => (string) ($d->reason ?? ''),
            'revoked' => $d->revoked_at !== null,
            'active' => $d->revoked_at === null
                && $d->starts_on->toDateString() <= $today && $d->ends_on->toDateString() >= $today,
        ];
    }

    /** @return array<string, string> কাজের কোড → নাম, ওয়েবের পাতার একই তালিকা */
    private function modules(): array
    {
        $out = [];

        foreach ($this->flows->choices() as $code => $choice) {
            $out[$code] = (string) $choice['label'];
        }

        ksort($out);

        return $out;
    }
}
