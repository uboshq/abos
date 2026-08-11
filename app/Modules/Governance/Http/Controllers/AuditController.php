<?php

declare(strict_types=1);

namespace App\Modules\Governance\Http\Controllers;

use App\Core\Concerns\SortsLists;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * অডিট ট্রেইল — শুধু পড়া।
 *
 * ── ছাঁকনিগুলো কেন এই চারটা ─────────────────────────────────────────
 * মানুষ অডিটে আসে চারটা প্রশ্নের একটা নিয়ে: "কে করেছে" (ব্যবহারকারী),
 * "কবে" (তারিখ), "কী ধরনের কাজ" (বাতিল? সম্পাদনা?), আর "কোন কাগজে"
 * (নম্বর ধরে খোঁজা)। পাঁচ নম্বর কোনো প্রশ্ন এখনো ওঠেনি, তাই পঞ্চম
 * ছাঁকনিও নেই।
 */
class AuditController extends Controller implements HasMiddleware
{
    use SortsLists;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly ModuleRegistry $registry,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:governance.audit.view')];
    }

    public function index(Request $request): View
    {
        $query = AuditTrail::query()
            ->with(['user', 'changes'])
            ->when($request->query('user'), fn (Builder $q, $id) => $q->where('user_id', (int) $id))
            ->when($request->query('action'), fn (Builder $q, $a) => $q->where('action', $a))
            ->when($request->query('from'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '>=', Carbon::parse((string) $d)->toDateString()))
            ->when($request->query('to'),
                fn (Builder $q, $d) => $q->whereDate('created_at', '<=', Carbon::parse((string) $d)->toDateString()))
            ->when($request->query('module'), fn (Builder $q, $code) => $q->where(
                'auditable_type', 'like', 'App\\Modules\\'.str_replace('_', '', ucwords((string) $code, '_')).'\\%'))
            ->when($request->query('q'), function (Builder $q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $term)).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('document_no', 'like', $like)
                    ->orWhere('label', 'like', $like));
            });

        $sort = $this->applySort($query, $request, $this->sorts());

        return view('governance::audit.index', [
            'menu' => $this->menu->forUser($request->user()),
            'trails' => $query->paginate(60)->withQueryString(),
            'sortOptions' => $this->sortLabels(),
            'sort' => $sort,
            'actions' => AuditTrail::ACTIONS,
            'modules' => $this->moduleOptions(),

            /*
             * ছাঁকনির ব্যবহারকারীর তালিকা অডিট থেকেই আসে, সব
             * ব্যবহারকারী থেকে নয়।
             *
             * যিনি কখনো কিছু বদলাননি তাকে তালিকায় রাখলে বেছে নিয়ে
             * খালি ফলাফল পেতে হত — আর মানুষ ভাবত ছাঁকনিটা নষ্ট।
             */
            'users' => User::query()
                ->whereIn('id', AuditTrail::query()->select('user_id')->distinct())
                ->orderBy('name')
                ->get(),
            'filters' => $request->only(['user', 'action', 'from', 'to', 'module', 'q']),
        ]);
    }

    public function show(Request $request, int $trail): View
    {
        $entry = AuditTrail::query()->with(['changes', 'user', 'branch'])->findOrFail($trail);

        return view('governance::audit.show', [
            'menu' => $this->menu->forUser($request->user()),
            'trail' => $entry,

            /*
             * রেকর্ডটা এখনো আছে কি না।
             *
             * মুছে গিয়ে থাকলে null — আর পর্দায় সেটা লেখা থাকে। না
             * লিখলে "রেকর্ডে যান" বোতামটা চাপার পর কিছুই হত না।
             */
            'record' => $entry->auditable(),
        ]);
    }

    /**
     * এই রেকর্ডের পুরো ইতিহাস — নতুন আগে।
     */
    public function record(Request $request, int $trail): View
    {
        $entry = AuditTrail::query()->findOrFail($trail);

        $history = AuditTrail::query()
            ->forRecord($entry->auditable_type, $entry->auditable_id)
            ->with(['changes', 'user'])
            ->orderByDesc('id')
            ->paginate(60);

        return view('governance::audit.record', [
            'menu' => $this->menu->forUser($request->user()),
            'trail' => $entry,
            'history' => $history,
            'record' => $entry->auditable(),
        ]);
    }

    /**
     * ছাঁকনির মডিউল তালিকা — রেজিস্ট্রি থেকে, হাতে লেখা নয়।
     *
     * @return array<string, string>
     */
    /**
     * নতুন আগে — অডিট পড়া হয় "এইমাত্র কে কী করল" জানতে।
     *
     * পুরনো আগে দিয়ে সাজানো লাগে অন্য সময়: কোনো একটা কাগজের পুরো
     * ইতিহাস শুরু থেকে পড়তে হলে।
     *
     * @return array<string, \Closure>
     */
    private function sorts(): array
    {
        return [
            'latest' => fn ($q) => $q->orderByDesc('id'),
            'oldest' => fn ($q) => $q->orderBy('id'),
        ];
    }

    /** @return array<string, string> */
    private function sortLabels(): array
    {
        return [
            'latest' => __('governance::sort.latest'),
            'oldest' => __('governance::sort.oldest'),
        ];
    }

    private function moduleOptions(): array
    {
        $options = [];

        foreach ($this->registry->all() as $module) {
            $options[$module->code] = $module->label();
        }

        return $options;
    }
}
