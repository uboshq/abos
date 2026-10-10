<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Executive\Reports\ExecutiveReports;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * মালিকের কেন্দ্রের রিপোর্টের দরজা — কেন্দ্রীয় রিপোর্টের পাতা, ছাপা আর ফাইল সবই সেখান থেকে।
 *
 * ⚠️ slug-গুলো module.php-র মেনুর সাথে হুবহু মিলতে হবে ([[ALinkThatLooksAliveAndIsNotTest]])।
 */
final class ExecutiveReportController extends Controller implements HasMiddleware
{
    private const SLUGS = [
        'profit-by-customer' => ExecutiveReports::PROFIT_BY_CUSTOMER,
    ];

    public function __construct(
        private readonly ReportEngine $reports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.ExecutiveReports::PERMISSION)];
    }

    public function show(Request $request, string $slug): View
    {
        abort_unless(isset(self::SLUGS[$slug]), 404);

        $definition = $this->reports->get(self::SLUGS[$slug]);

        return view('accounts::report.show', [
            'menu' => $this->menu->forUser($request->user()),
            'slug' => $slug,
            'report' => $definition,
            'result' => $this->reports->run(
                self::SLUGS[$slug],
                $request->only($definition->requestKeys()),
                page: max(1, (int) $request->query('page', 1)),
                byBranch: true,
            ),
            'branches' => collect(),
            'accounts' => collect(),
            'partyTypes' => collect(),
            'notice' => null,
        ]);
    }
}
