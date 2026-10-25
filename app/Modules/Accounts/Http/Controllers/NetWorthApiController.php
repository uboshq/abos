<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Accounts\Services\NetWorthStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ⭐ ফোনের নিট সম্পদ বিবরণী — `/api/v1/accounts/net-worth` (মালিক, fe ২৫ অক্টোবর ২০২৬; a4-এর অ্যাপ এটা পড়ে; [[NetWorthStatement]])।
 *
 * ⛔ কেবল এই কোম্পানির মালিক। অন্য কারো কাছে ৪০৪ — ৪০৩ নয়, ঢাকা অঙ্কও নয়: কার্ডটা যে আছে, সেটাও জানা যায় না।
 * ⓘ এক কোম্পানি — হেডারের/টোকেনের কোম্পানি; সব কোম্পানি একসাথে কেবল ওয়েবের Executive-এ (fe)।
 * ⓘ অঙ্ক কাঁচা দশমিক (৪ ঘর) — লাখের কমা আর ইংরেজি অঙ্ক ফোন নিজে বসায়, ওয়েবের একই নিয়মে।
 */
final class NetWorthApiController extends Controller
{
    public function __construct(private readonly NetWorthStatement $statement) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->authorizeOwner($request);

        $company = Company::query()->findOrFail($companyId);

        return response()->json([
            'company' => ['code' => (string) $company->code, 'name' => $company->name()],
            ...$this->statement->forCompany($companyId),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * ⭐ হোমের ছোট কার্ড — "নগদ · MFS · ব্যাংক"-এর ঠিক নিচে (মালিক, fe ২৫ অক্টোবর ২০২৬): কেবল নিট অঙ্ক, এ বছরের লাভ আর সময়।
     * ⓘ একই হিসাব, একই পাহারা — পুরো বিবরণীর ঘর থেকেই, আলাদা গোনা নয়।
     */
    public function summary(Request $request): JsonResponse
    {
        $statement = $this->statement->forCompany($this->authorizeOwner($request));

        return response()->json([
            'net' => $statement['figures']['net'],
            'this_year' => $statement['figures']['this_year'],
            'as_of' => $statement['as_of'],
            'agrees' => $statement['agrees'],
        ])->header('Cache-Control', 'no-store');
    }

    /** ⛔ এই কোম্পানির মালিক, নইলে ৪০৪ — চাবির নাম নেই, ভূমিকাটাই চাবি ([[NetWorthStatement::mayView()]]) */
    private function authorizeOwner(Request $request): int
    {
        $companyId = (int) CompanyContext::id();

        if ($companyId === 0 || ! $this->statement->mayView($request->user(), $companyId)) {
            throw new NotFoundHttpException;
        }

        return $companyId;
    }
}
