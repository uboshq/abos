<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\DataScope;
use App\Core\Services\FormBranchPin;
use App\Core\Services\FormIsNotSubmittedTwice;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDataScope;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ ফর্ম যে শাখায় খোলা, জমাও সেই শাখায় — RCV-0143 / মালিক: "কোনোভাবেই অন্য শাখায় নয়" (ec, ১৯ অক্টোবর ২০২৬)।
 *
 * কেবল নতুন কাগজের POST (`*.store`, [[FormBranchPin::guards()]]); PUT/PATCH/DELETE আর ফোনের দরজা (API) কখনো নয়।
 *
 *   ছাপ মেলে                       → যেমন ছিল
 *   ছাপ মেলে না                    → কিছু না লিখে ফর্মে ফেরত, ইনপুটসহ; বার্তায় দুই শাখার নাম আর "X-এই জমা দিন" বোতাম
 *   বোতাম চাপা (সই-করা চিরকুট)    → নাগাল দেখে, চিরকুট খরচ করে, বাকি অনুরোধ খোলার শাখায় ([[CompanyContext::inBranch()]])
 *   ছাপই নেই (ডেপ্লয়ের আগের পাতা) → ছাড়ের জানালায় একবার যেতে দেয়, সতর্ক করে, লগে লেখে; জানালার পরে থামায়
 *
 * ⚠️ প্রসঙ্গ আর পর্দার তালাগুলোর **পরে** বসে ([[ResolveCompanyContext]] শাখাটা বসায়), [[OneSubmitPerForm]]-এর
 * পাশে নয়: শাখা না জেনে মেলানো যায় না। ⓘ থামালে `_once`-এর দাবি ফেরত যায় ([[FormIsNotSubmittedTwice::release()]])।
 */
class KeepsAFormInItsOwnBranch
{
    public function __construct(
        private readonly FormBranchPin $pin,
        private readonly FormIsNotSubmittedTwice $forms,
        private readonly DataScope $scope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $present = $request->has(FormBranchPin::FIELD);
        $stamp = (string) $request->input(FormBranchPin::FIELD, '');
        $confirm = (string) $request->input(FormBranchPin::CONFIRM, '');

        // ⓘ যন্ত্রের ঘর — কোনো সেবার `$request->all()`-এ যেন না পড়ে (`_once`-এর একই কারণ)
        $request->request->remove(FormBranchPin::FIELD);
        $request->request->remove(FormBranchPin::CONFIRM);

        $user = $request->user();
        $companyId = CompanyContext::id();

        if (! $this->pin->guards($request) || ! $user instanceof User || $companyId === null) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        if ($confirm !== '') {
            return $this->confirmed($request, $next, $user, $companyId, $route, $confirm);
        }

        $current = CompanyContext::branchId();

        if (! $present) {
            if ($this->pin->inGrace()) {
                // ⓘ পরীক্ষায় লগ নয় — হাজারটা ছাপহীন POST ম্যাকের ডিস্ক ভরাত; সতর্কতাটা (নিচে) আগের মতোই
                if (! app()->runningUnitTests()) {
                    Log::warning('A form without its branch stamp was let through in the grace window', [
                        'user_id' => $user->id, 'route' => $route, 'branch_id' => $current,
                    ]);
                }

                session()->flash(FormBranchPin::NOTICE, __('core.branch_pin.unstamped', ['current' => $this->pin->nameOf($current)]));

                return $next($request);
            }

            return $this->refuse($request, __('core.branch_pin.unstamped_refused'));
        }

        if ($stamp === $this->pin->stamp()) {
            return $next($request);
        }

        $opened = $stamp === FormBranchPin::NONE ? null : Branch::acrossAllCompanies()
            ->where('public_id', $stamp)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        Log::notice('A form opened in one branch was stopped from landing in another', [
            'user_id' => $user->id, 'route' => $route, 'opened' => $opened?->id, 'current' => $current,
        ]);

        // ⓘ অন্য কোম্পানির, মুছে যাওয়া বা শাখাহীন — খোলার শাখায় পাঠানোর মতো কিছু নেই, বোতামও নেই
        if ($opened === null) {
            return $this->refuse($request, __('core.branch_pin.elsewhere', ['current' => $this->pin->nameOf($current)]));
        }

        $names = ['opened' => $opened->name(), 'current' => $this->pin->nameOf($current)];

        return $this->refuse($request, __('core.branch_pin.moved', $names), [
            ...$names,
            'confirm' => $this->pin->offer($user, $companyId, (int) $opened->id, $route, $request->path()),
            'action' => $request->url(),
            'files' => $request->allFiles() !== [],
        ]);
    }

    /** "X-এই জমা দিন" — চিরকুট এই অনুরোধের, নাগাল আছে, আগে খরচ হয়নি; তবেই খোলার শাখায়। */
    private function confirmed(Request $request, Closure $next, User $user, int $companyId, string $route, string $token): Response
    {
        $payload = $this->pin->read($token, $user, $companyId, $route, $request->path());

        if ($payload === null) {
            return $this->refuse($request, __('core.branch_pin.confirm_refused'));
        }

        $opened = Branch::acrossAllCompanies()
            ->whereKey($payload['b'])
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if ($opened === null || ! $this->scope->allows($user, UserDataScope::BRANCH, (int) $opened->id)) {
            return $this->refuse($request, __('core.branch_pin.no_reach', ['opened' => $opened?->name() ?? '—']));
        }

        if (! $this->pin->spend($payload, $user, $route)) {
            return $this->refuse($request, __('core.branch_pin.confirm_refused'));
        }

        // ⓘ উপরে একটা শাখা দেখা থাকলে নতুন কাগজটা ঐ পাতায় দেখা যাবে না — তখন ফর্মে ফেরা, খবরসহ (নিচে)
        $hiddenAfter = $this->scope->viewsOneBranch($user) && CompanyContext::branchId() !== (int) $opened->id;
        $errorsBefore = $this->errors();

        Log::info('A form was submitted in the branch it was opened in, on request', [
            'user_id' => $user->id, 'route' => $route, 'opened' => $opened->id, 'current' => CompanyContext::branchId(),
        ]);

        $response = CompanyContext::inBranch((int) $opened->id, fn () => $next($request));

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        if ($this->errors() !== $errorsBefore) {
            session()->flash(FormBranchPin::NOTICE, __('core.branch_pin.confirm_failed', ['opened' => $opened->name()]));

            return $response;
        }

        if ($hiddenAfter) {
            session()->flash(FormBranchPin::NOTICE, __('core.branch_pin.saved_elsewhere', ['opened' => $opened->name()]));

            return back();
        }

        return $response;
    }

    /** সেশনের ভুলগুলোর ছবি — ⚠️ বস্তু মেলানো নয়: `withErrors()` আগের থলেটাই বদলে আবার রাখে। */
    private function errors(): string
    {
        $bag = session()->get('errors');

        return (string) json_encode(is_object($bag) && method_exists($bag, 'getBags') ? $bag->getBags() : null);
    }

    /**
     * কিছু না লিখে ফর্মে ফেরত — ইনপুটসহ (পাসওয়ার্ড ছাড়া), বার্তা আর (থাকলে) বোতামসহ।
     *
     * @param  array<string, mixed>  $offer
     */
    private function refuse(Request $request, string $message, array $offer = []): Response
    {
        $token = $request->attributes->get(FormIsNotSubmittedTwice::FIELD);

        if (is_string($token) && $token !== '') {
            $this->forms->release($token);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        return back()
            ->withInput(Arr::except($request->input(), FormBranchPin::NEVER_FLASHED))
            ->with(FormBranchPin::OFFER, ['message' => $message, ...$offer]);
    }
}
