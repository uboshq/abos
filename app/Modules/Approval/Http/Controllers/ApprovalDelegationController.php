<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Engines\Approval\DelegationService;
use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * সই দেওয়ার ভার — কে কার হয়ে, কোন সময় পর্যন্ত।
 *
 * ── ⚠️ কার অনুমতি লাগে, আর কেন সেটা `decide` নয় ─────────────────────
 * ⓘ ভার দেওয়া মানে **নিজের** ক্ষমতা অন্যকে ধার দেওয়া — তাই যিনি সই
 * দিতে পারেন তিনিই দিতে পারবেন। ⛔ আলাদা একটা চাবি বানালে প্রশাসককে
 * প্রতিটা ছুটির আগে ডাকতে হত, আর তখন কেউ ভারই দিত না।
 *
 * ⚠️ তবে **অন্যের হয়ে** ভার দেওয়া আলাদা কথা: ওটা প্রশাসকের কাজ, আর
 * সেটা `approval.manage` চাবিতে।
 */
final class ApprovalDelegationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DelegationService $delegations,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:approval.decide')];
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('approval::delegation.index', [
            'menu' => $this->menu->forUser($user),

            /*
             * ⭐ দুইটা তালিকা, আর দুইটাই দরকার।
             *
             * ⓘ *"আমি কাকে দিয়েছি"* — ফিরে এসে বন্ধ করার জন্য।
             * ⓘ *"আমার কাছে কার ভার আছে"* — কারণ ইনবক্সে হঠাৎ অন্যের
             * কাগজ দেখলে মানুষ ভাবেন কিছু একটা ভুল হয়েছে।
             */
            'given' => ApprovalDelegation::query()
                ->where('from_user_id', $user->id)
                ->with('to')
                ->orderByDesc('starts_on')
                ->paginate(20)
                ->withQueryString(),

            'held' => ApprovalDelegation::query()
                ->active()
                ->where('to_user_id', $user->id)
                ->with('from')
                ->get(),

            /*
             * ⛔ কেবল এই কোম্পানির মানুষ — সুবিধা নয়, শর্ত।
             *
             * ── ⚠️ যা ফাঁস হচ্ছিল, ২৪ সেপ্টেম্বর ২০২৬ ────────────────
             * ⓘ `User`-এ কোনো global scope নেই — একজন মানুষ একাধিক
             * কোম্পানিতে থাকতে পারেন, তাই সম্পর্কটা pivot-এ।
             *
             * ⛔ ফল: সরল `User::query()` লিখলে এই ড্রপডাউনে **অন্য
             * কোম্পানির মানুষের নাম** আসত — আর ক্ষতিটা নাম দেখার
             * চেয়ে বড়: তাঁদের হাতে **এই কোম্পানির সইয়ের ভার** দিয়েও
             * দেওয়া যেত।
             *
             * ⓘ ছাঁচটা [[ApprovalFlowController::companyUsers()]]-এর — নতুন
             * কিছু বানানো হয়নি, কারণ দুই রকম করলে তৃতীয় জায়গায়
             * তৃতীয় রকম হয়।
             */
            'people' => User::query()
                ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['id', 'name']),

            'modules' => $this->modules(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],

            /*
             * ⛔ শুরুর তারিখ পিছনে যেতে পারে না।
             *
             * ⚠️ পিছনের তারিখে ভার দিলে **ইতিমধ্যে হয়ে যাওয়া** সিদ্ধান্ত
             * বৈধ দেখাত — অর্থাৎ কেউ অনুমতি ছাড়া সই দিয়ে পরে ভারটা
             * পিছিয়ে বসিয়ে দিতে পারতেন।
             */
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'modules' => ['array'],
            'modules.*' => ['string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->delegations->grant(
            from: $request->user(),
            to: User::findOrFail($data['to_user_id']),
            startsOn: (string) $data['starts_on'],
            endsOn: (string) $data['ends_on'],
            modules: array_values($data['modules'] ?? []),
            reason: $data['reason'] ?? null,
        );

        return redirect()
            ->route('approval.delegation.index')
            ->with('saved', __('approval::message.delegation_saved'));
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): RedirectResponse
    {
        /*
         * ⛔ কেবল নিজের দেওয়া ভার বন্ধ করা যায়।
         *
         * ⚠️ রুটে `can:approval.decide` আছে, কিন্তু ওটা বলে *"ইনি সই
         * দিতে পারেন"* — *"এই সারিটা ইনার"* নয়। ⓘ না দেখলে যেকোনো
         * সইকারী অন্যের ভার বন্ধ করে দিতে পারতেন।
         */
        abort_unless((int) $delegation->from_user_id === (int) $request->user()->id, 403);

        $this->delegations->revoke($delegation);

        return redirect()
            ->route('approval.delegation.index')
            ->with('saved', __('approval::message.delegation_revoked'));
    }

    /**
     * যে মডিউলগুলো সত্যিই অনুমোদন চায়।
     *
     * ⭐ তালিকাটা নিজে বানানো হয় না — [[ApprovalFlowService::choices()]]
     * ওই একই প্রশ্নের উত্তর আগে থেকেই দেয়, আর প্রবাহের
     * ফর্ম সেটাই ব্যবহার করে।
     *
     * ⛔ দুই জায়গায় দুইবার লেখা মানে একদিন দুইটা আলাদা
     * হওয়া — নতুন একটা মডিউল অনুমোদন চাইলে প্রবাহে আসত,
     * ভারে আসত না, আর কেউ টের পেত না।
     *
     * @return array<string, string>
     */
    private function modules(): array
    {
        $out = [];

        foreach (app(ApprovalFlowService::class)->choices() as $code => $choice) {
            $out[$code] = (string) $choice['label'];
        }

        ksort($out);

        return $out;
    }
}
