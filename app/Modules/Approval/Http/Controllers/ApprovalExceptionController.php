<?php

declare(strict_types=1);

namespace App\Modules\Approval\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Approval\Services\ApprovalExceptions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * নিয়মের বাইরে যা কিছু — এক পর্দায়।
 *
 * ── ⚠️ কেন `approval.flow.manage`, `decide` নয় ──────────────────────
 * ⓘ এখানকার সারিগুলো **সাজানোর ভুল** — কোন প্রবাহে গন্তব্য নেই, কোন
 * কাজে প্রবাহই নেই। ⛔ যিনি সই দেন তিনি ওগুলো ঠিক করতে পারেন না, আর
 * তালিকাটা দেখে কেবল উদ্বিগ্ন হতেন।
 */
final class ApprovalExceptionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ApprovalExceptions $exceptions,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:approval.flow.manage')];
    }

    public function index(Request $request): View
    {
        return view('approval::exception.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $this->exceptions->all()->groupBy('kind'),
        ]);
    }
}
