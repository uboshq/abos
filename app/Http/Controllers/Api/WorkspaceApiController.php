<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/workspace` — ফোন থেকে কোম্পানি ও শাখা বদলানো (১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী ছিল না ────────────────────────────────────────────────────────
 * মালিক: *"app e company & branch change hoyna"*। ওয়েবের হেডারে সুইচার আছে
 * ([[WorkspaceController::switchCompany()]], `switchBranch()`), ফোনে কোনো পথ ছিল
 * না — দুই কোম্পানির মালিক ফোনে চিরকাল একটাতেই আটকে থাকতেন।
 *
 * ── ⭐ নিয়ম ওয়েবেরই, নতুন করে লেখা নয় ──────────────────────────────────
 * সদস্যপদ, শাখা ঐ কোম্পানির কি না, আর শাখা মানুষটার নাগালে কি না — তিনটাই
 * [[User::switchCompany()]] দেখে। "সব শাখা" ওয়েবের মতোই কেবল দেখার ধরন বদলায়
 * (`view_all_branches`), কাজের শাখা নয়।
 *
 * ── ⛔ ফোন কোম্পানি ঠিক করে না ──────────────────────────────────────────
 * এখানে কেবল **ব্যবহারকারীর সারিতে** লেখা হয়। প্রতিটা পরের অনুরোধ কোম্পানি ও
 * শাখা আবার সেই সারি থেকেই বের করে ([[ResolveCompanyContext]]) — কোনো হেডার
 * বা প্যারামিটার থেকে নয়। তাই ফোন যা-ই পাঠাক, সে কেবল নিজের সদস্যপদ আর নাগালের
 * ভেতরেই নড়তে পারে।
 *
 * ── কোম্পানি বদলালে জলচিহ্ন গোড়ায় ───────────────────────────────────────
 * ফোন নিজের জমানো তথ্য মুছে ফেলে; সার্ভারের জলচিহ্ন থেকে গেলে নতুন কোম্পানির
 * কেবল নতুন বদল নামত ([[SyncService::startOver()]])। ⚠️ কোন যন্ত্র, সেটা টোকেনের
 * নাম থেকে (`sync:<deviceId>`, [[AuthController::issue()]]) — ফোনের পাঠানো কোনো
 * ঘর থেকে নয়, নইলে একজন অন্যের যন্ত্রের জলচিহ্ন মুছতে পারতেন। শাখা বদলে মোছা
 * হয় না — নইলে প্রতি বদলে গোটা তালিকা আবার নামত।
 */
final class WorkspaceApiController extends Controller
{
    /** "সব শাখা" — ওয়েবের `branch.switch`-এর একই শব্দ। */
    private const ALL = 'all';

    public function __construct(private readonly SyncService $sync) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'company' => ['required', 'string', 'max:64'],
            'branch' => ['required', 'string', 'max:64'],
        ]);

        /*
         * ⛔ অজানা আর "আপনার নয়" একই উত্তর — ৪০৩। আলাদা হলে ফোন দিয়ে গুনে দেখা
         * যেত কোন কোন কোম্পানি এই সার্ভারে আছে।
         */
        $company = Company::query()->where('public_id', $data['company'])->first();

        if ($company === null || ! $user->canAccessCompany((int) $company->id)) {
            return response()->json(['message' => __('core.company.no_access')], 403);
        }

        $branchId = null;

        if ($data['branch'] !== self::ALL) {
            $branchId = Branch::acrossAllCompanies()
                ->where('public_id', $data['branch'])
                ->where('company_id', $company->id)
                ->value('id');

            if ($branchId === null) {
                throw ValidationException::withMessages([
                    'branch' => __('core.company.branch_elsewhere'),
                ]);
            }
        }

        $companyChanged = (int) $user->current_company_id !== (int) $company->id;
        $branchBefore = [(int) $user->current_branch_id, (bool) $user->view_all_branches];

        /*
         * ⓘ "সব শাখা" আর একই কোম্পানি — কাজের শাখা যেমন ছিল তেমনই (ওয়েবের মতো)।
         * অন্যথায় [[User::switchCompany()]]: নাগালের বাইরের শাখা সেখানে ৪২২।
         */
        if ($branchId !== null || $companyChanged) {
            try {
                $user->switchCompany((int) $company->id, $branchId === null ? null : (int) $branchId);
            } catch (ValidationException $refused) {
                /* ⓘ ঘরের নাম ফোনের ভাষায় — `branch_id` নয়, `branch` */
                throw ValidationException::withMessages([
                    'branch' => array_merge(...array_values($refused->errors())),
                ]);
            }
        }

        $user->forceFill(['view_all_branches' => $branchId === null])->save();

        /*
         * ⭐ শাখা বদলালেও নতুন করে — মালিক, ২ অক্টোবর ২০২৬: *"APp e sob branch er data ek branch e dekhay"*।
         * ⛔ আগে কেবল কোম্পানি বদলালে ফোন ক্যাশ মুছত; শাখা বদলালে আগের শাখার সারিগুলো ফোনে থেকে যেত, আর
         * সার্ভারের ওয়াটারমার্ক এগিয়ে থাকায় নতুন শাখার পুরনো সারিও আর আসত না।
         */
        $user->refresh();
        $viewChanged = $companyChanged
            || $branchBefore !== [(int) $user->current_branch_id, (bool) $user->view_all_branches];

        if ($viewChanged) {
            $deviceId = $this->deviceOf($request);

            if ($deviceId !== null) {
                $this->sync->startOver($deviceId);
            }
        }

        $user->refresh();
        $branch = $user->current_branch_id === null
            ? null
            : Branch::acrossAllCompanies()->find($user->current_branch_id);

        CompanyContext::set((int) $user->current_company_id, $user->current_branch_id);

        return response()->json([
            'company' => [
                'public_id' => $company->public_id,
                'code' => $company->code,
                'name' => $company->name(),
            ],
            'branch' => $branch === null ? null : [
                'public_id' => $branch->public_id,
                'code' => $branch->code,
                'name' => $branch->name(),
            ],
            'viewAllBranches' => (bool) $user->view_all_branches,
            /*
             * ⚠️ বসানো অ্যাপগুলো (০.৪.৬ পর্যন্ত) এই পতাকা পেলেই ক্যাশ মুছে পুরোটা টানে — শাখা বদলেও ঠিক সেটাই
             * দরকার, তাই নতুন অ্যাপ ছাড়াই ঠিক হয়। আসল প্রশ্ন দুটো আলাদা ঘরে: কোম্পানি বদলাল কি না, আর নতুন করে কি না।
             */
            'companyChanged' => $viewChanged,
            'companyMoved' => $companyChanged,
            'startOver' => $viewChanged,
        ]);
    }

    /** এই টোকেন কোন যন্ত্রের — `sync:<deviceId>` নামে বসানো ([[AuthController::issue()]])। */
    private function deviceOf(Request $request): ?string
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $prefix = AuthController::ACCESS.':';
        $name = (string) $token->name;

        return str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)
            ? substr($name, strlen($prefix))
            : null;
    }
}
