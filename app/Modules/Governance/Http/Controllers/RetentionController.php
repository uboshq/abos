<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Services\WhatIsKeptHowLong;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * কাগজ সংরক্ষণ নীতি — ফিন্যান্স মানচিত্রের §২৮।
 *
 * ⓘ কেবল পড়া। পর্দাটা নিয়ম বসায় না, **যা সত্যিই ঘটে তাই দেখায়**
 * ([[WhatIsKeptHowLong]]) — কারণ লেখা নীতি আর আসল আচরণ আলাদা হলে নীতিটাই
 * সবচেয়ে বিপজ্জনক কাগজ।
 *
 * ⓘ চাবি `governance.audit.view`: যিনি অডিট ট্রেইল দেখতে পারেন, "কী কতদিন
 * থাকে" প্রশ্নটা তাঁরই — আর এখানে কোনো ব্যবসার তথ্য নেই, কেবল সংখ্যা।
 */
class RetentionController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:governance.audit.view')];
    }

    public function index(Request $request, WhatIsKeptHowLong $kept): View
    {
        return view('governance::retention.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $kept->all(),
            'backupDays' => $kept->backupDays(),
            'lawYears' => WhatIsKeptHowLong::LAW_YEARS,
        ]);
    }
}
