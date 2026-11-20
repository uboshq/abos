<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\AllBranchesView;
use App\Core\Services\FormBranchPin;
use App\Core\Services\FormIsNotSubmittedTwice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ "সব শাখা"-তে কিছু লেখা যায় না — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[AllBranchesView]])।
 *
 * লাইভে: "সব শাখা" বাছা অবস্থায় আদায় Super-এর বদলে TD-তে (লুকানো কাজের শাখায়) বসল, তারপর আবার লেখা হলো।
 * ⓘ পাতার ধূসর বোতাম কেবল সাহায্য — পুরনো খোলা পাতা, অন্য ট্যাব বা হাতে বানানো অনুরোধ এখানেই থামে।
 *
 *   GET/HEAD                       → যেমন ছিল (দেখা, খোঁজা, ছাপা, CSV)
 *   ছাড়ের দরজা ([[AllBranchesView::ALLOWED]]) → যেমন ছিল (অনুমোদনের সিদ্ধান্ত, শাখা বদল, নিজের পছন্দ …)
 *   বাকি লেখা, "সব শাখা"-তে      → কিছু না লিখে ফেরত, ইনপুটসহ, বার্তায় "ওপর থেকে একটা শাখা বাছুন"; JSON হলে ৪০৯
 *
 * ⚠️ প্রসঙ্গের **পরে** বসে ([[ResolveCompanyContext]] কোম্পানি বসায়), [[KeepsAFormInItsOwnBranch]]-এর **আগে** — ছাপ মেলানোর
 * আগেই "এখানে লেখাই চলে না"। ⓘ থামালে `_once`-এর দাবি ফেরত যায় ([[FormIsNotSubmittedTwice::release()]]), যাতে শাখা বেছে
 * একই ফর্ম আবার জমা দেওয়া যায়। ⓘ ফোনের দরজা (`/api`) এর বাইরে — ফোন নিজের শাখা পাঠায়।
 */
class RefusesWritesInAllBranchesView
{
    public function __construct(
        private readonly AllBranchesView $view,
        private readonly FormIsNotSubmittedTwice $forms,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->view->lets($request) || ! $this->view->on($request->user())) {
            return $next($request);
        }

        if (! app()->runningUnitTests()) {
            Log::notice('A write was refused while the all-branches view was on', [
                'user_id' => $request->user()?->getAuthIdentifier(), 'route' => (string) $request->route()?->getName(),
            ]);
        }

        $token = $request->attributes->get(FormIsNotSubmittedTwice::FIELD);

        if (is_string($token) && $token !== '') {
            $this->forms->release($token);
        }

        $message = __('core.all_branches.refused');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        // ⓘ বার্তাটা শাখার ছাপের একই জায়গায় দেখায় ([[shell.branch-pin]]) — খোলসে একবার, প্রতিটা পাতায়, পপআপেও
        return back()
            ->withInput(Arr::except($request->except(['_token', FormBranchPin::FIELD, FormBranchPin::CONFIRM, '_method']), FormBranchPin::NEVER_FLASHED))
            ->with(FormBranchPin::OFFER, ['message' => $message]);
    }
}
