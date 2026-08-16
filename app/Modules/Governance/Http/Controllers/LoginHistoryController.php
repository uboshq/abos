<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ঢোকার খাতা — শুধু পড়া।
 *
 * ── কেন ব্যর্থ চেষ্টাগুলো আগে দেখানো যায় ────────────────────────────
 * সফল ঢোকা রোজকার ঘটনা; একশো সারির মধ্যে নিরানব্বইটা। যেটা দেখা দরকার
 * সেটা হলো একই নামে পঁচিশটা ব্যর্থ চেষ্টা এক ঘণ্টায়, আর সেটা সফলগুলোর
 * ভিড়ে হারিয়ে যায়। তাই "কেবল ব্যর্থ" একটা ছাঁকনি, আর উপরে গোনাটাও।
 */
class LoginHistoryController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:governance.audit.view')];
    }

    public function index(Request $request): View
    {
        $rows = LoginAttempt::query()
            ->with('user')
            ->when($request->query('user'), fn (Builder $q, $id) => $q->where('user_id', (int) $id))
            ->when($request->query('only') === 'failed', fn (Builder $q) => $q->failed())
            ->when($request->query('from'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '>=', Carbon::parse((string) $d)->toDateString()))
            ->when($request->query('to'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '<=', Carbon::parse((string) $d)->toDateString()))
            ->latestFirst()
            ->paginate(50)
            ->withQueryString();

        return view('governance::login.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,

            /*
             * গত চব্বিশ ঘণ্টার ব্যর্থ চেষ্টা — পাতার মাথায়।
             *
             * ── কেন একটা সংখ্যা ─────────────────────────────────────
             * কেউ এই পর্দায় রোজ আসে না। যেদিন আসে, প্রথম প্রশ্নটা
             * "কিছু অস্বাভাবিক ঘটছে কি না" — আর সেই উত্তরটা তালিকা
             * পড়ে বের করতে হলে বেশিরভাগ দিন কেউ বের করত না।
             */
            'failedToday' => LoginAttempt::query()
                ->failed()
                ->where('created_at', '>=', now()->subDay())
                ->count(),

            'users' => User::query()
                ->whereIn('id', LoginAttempt::query()->distinct()->pluck('user_id')->filter())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
