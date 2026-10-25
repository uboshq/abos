<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Services\NetWorthStatement;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ⭐ নিট সম্পদ বিবরণী (Net Worth Statement) — ওয়েবের পাতা (মালিক, ২৫ অক্টোবর ২০২৬; নমুনা "নিট-সম্পদ-বিবরণী-নমুনা-২৫-অক্টোবর.pdf")।
 *
 * ⛔ কেবল মালিক: যে কোম্পানির মালিক নন, সেটা দেখেন না; কোনো কোম্পানির মালিক না হলে ৪০৪ — ৪০৩ নয়, পাতাটা যে আছে সেটাও জানা যায় না
 * ([[NetWorthApiController]]-এর একই নিয়ম)।
 * ⓘ উপরে কোম্পানির চিপ: "সব কোম্পানি" (মালিক যেসবের মালিক, যোগ করে) বা একটা; যেখানে মালিক নন সেটা নিচে নাম ধরে লেখা, চুপচাপ বাদ নয়।
 */
final class NetWorthController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly NetWorthStatement $statement,
    ) {}

    public function show(Request $request): View
    {
        $owner = $this->authorizeOwner($request);

        $picked = strtoupper((string) $request->query('company', ''));
        $company = collect($owner['companies'])->first(fn (array $c) => strtoupper($c['code']) === $picked);

        return view('accounts::report.net-worth', [
            'menu' => $this->menu->forUser($request->user()),
            'companies' => $owner['companies'],
            'missing' => $owner['missing'],
            'picked' => $company['code'] ?? null,
            'figures' => $company['figures'] ?? $owner['all'],
            'accounts' => $company['accounts'] ?? [],
            'agrees' => $company['agrees'] ?? collect($owner['companies'])->every(fn (array $c) => $c['agrees']),
            'asOf' => $owner['companies'][0]['as_of'],
        ]);
    }

    /**
     * ⛔ কোনো কোম্পানির মালিক না হলে ৪০৪ — চাবির নাম নেই, ভূমিকাটাই চাবি ([[NetWorthStatement::mayView()]])
     *
     * @return array{companies: list<array<string, mixed>>, all: array<string, string>|null, missing: list<array{code: string, name: string}>}
     */
    private function authorizeOwner(Request $request): array
    {
        $owner = $this->statement->forOwner($request->user());

        if ($owner['companies'] === []) {
            throw new NotFoundHttpException;
        }

        return $owner;
    }
}
