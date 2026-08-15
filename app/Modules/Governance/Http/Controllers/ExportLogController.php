<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\ExportLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * রপ্তানির খাতা — শুধু পড়া।
 *
 * ── কেন এই পর্দাটা লাগে ─────────────────────────────────────────────
 * খাতা লেখা হলেই কাজ শেষ নয় — যে খাতা কেউ পড়তে পারে না, সেটা থাকা আর
 * না থাকা সমান। প্রশ্নটা ওঠে নির্দিষ্ট একটা মুহূর্তে: কেউ চাকরি ছাড়ল,
 * বা প্রতিযোগীর কাছে দর ফাঁস হলো — তখন জানতে হয় গত তিন মাসে কে কী
 * নামিয়েছে।
 *
 * ── ছাঁকনি তিনটাই কেন ───────────────────────────────────────────────
 * "কে" (ওই কর্মী), "কবে" (কোন সময়ে), আর "কোন পর্দা" — বাস্তব প্রশ্ন
 * এই তিনটাই। চতুর্থ কোনো প্রশ্ন এখনো ওঠেনি, তাই চতুর্থ ছাঁকনিও নেই।
 */
class ExportLogController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        /*
         * অডিটের একই চাবি।
         *
         * দুইটাই একই প্রশ্নের দুই দিক — "কে কী করেছে"। আলাদা অনুমতি
         * রাখলে কেউ পরিবর্তনের খাতা দেখতে পেতেন অথচ কী নামানো হয়েছে
         * তা নয়, আর দ্বিতীয়টা প্রায়ই বেশি জরুরি।
         */
        return [new Middleware('can:governance.audit.view')];
    }

    public function index(Request $request): View
    {
        $rows = ExportLog::query()
            ->with(['user', 'branch'])
            ->when($request->query('user'), fn (Builder $q, $id) => $q->where('user_id', (int) $id))
            ->when($request->query('route'), fn (Builder $q, $r) => $q->where('route', $r))
            ->when($request->query('from'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '>=', Carbon::parse((string) $d)->toDateString()))
            ->when($request->query('to'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '<=', Carbon::parse((string) $d)->toDateString()))
            ->latestFirst()
            ->paginate(50)
            ->withQueryString();

        return view('governance::export.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,

            /*
             * ছাঁকনির তালিকাগুলো যা সত্যিই ঘটেছে তা থেকেই।
             *
             * সব ব্যবহারকারী বা সব রুট দেখালে তালিকাটা এমন নামে ভরে
             * যেত যাঁরা কোনোদিন কিছু নামাননি — আর যাঁকে খোঁজা হচ্ছে
             * তিনি ওখানেই হারিয়ে যেতেন।
             */
            'users' => User::query()
                ->whereIn('id', ExportLog::query()->distinct()->pluck('user_id')->filter())
                ->orderBy('name')
                ->get(['id', 'name']),

            'routes' => ExportLog::query()->distinct()->orderBy('route')->pluck('route'),
        ]);
    }
}
