<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Services\DealerScope;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\DealerBinding;
use App\Modules\Customer\Models\StaffSupervisor;
use App\Modules\Customer\Services\DealerBindingService;
use App\Modules\MasterData\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ কোন কর্মী কোন ডিলারের — বাঁধার পর্দা, হাতবদল, উপরওয়ালা আর আগাম দেখা
 * (⛔১৬, ২ অক্টোবর ২০২৬)।
 *
 * ⛔ সুইচ ডিফল্ট চালু (মালিক, ২৬ সেপ্টেম্বর ২০২৬) — বাঁধন না বসালে বিক্রয়কর্মী শূন্য দেখেন।
 * তাই এই পর্দা আর এর আগাম দেখা চালুর আগে যায়।
 * ⓘ একটাই চাবি, `customer.binding.manage` — দেখা আর বসানো একই দায়িত্ব: যিনি দেখেন কে কার
 * ডিলার, তিনিই সেটা ঠিক করেন। ⚠️ দেয়ালের ভিতরের মানুষ চাবিটা পেলেও কিছু বদলাতে পারেন না
 * ([[DealerScope::mayDo()]])।
 */
final class DealerBindingController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DealerBindingService $bindings,
        private readonly DealerScope $scope,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:customer.binding.manage')];
    }

    public function index(Request $request): View
    {
        $userId = $request->integer('user_id') ?: null;

        $rows = DealerBinding::query()
            ->with(['user', 'customer'])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when(! $request->boolean('ended'), fn ($q) => $q->open())
            ->orderBy('user_id')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('customer::binding.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'preview' => $this->bindings->preview(),
            'staff' => $this->bindings->staff(),
            // ⓘ বাঁধার ঘর — দেয়াল ছাড়া (অন্য কর্মীর ডিলারও বাঁধা যায়), হেডারের শাখায়
            'customers' => Customer::acrossDealers()->inViewedBranch()->active()->orderBy('code')
                ->get(['id', 'code', 'name_en', 'name_bn']),
            'areas' => Location::query()->orderBy('name_en')->get(['id', 'name_en', 'name_bn', 'level', 'parent_id']),
            'switchOn' => $this->scope->switchOn(),
            'filterUser' => $userId,
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    /** বাঁধা — ডিলার বেছে, অথবা একটা এলাকার আজকের ডিলার একসাথে। */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer'],
            'location_id' => ['nullable', 'integer'],
            'starts_on' => ['required', 'date'],
        ]);

        $made = ! empty($data['location_id'])
            ? $this->bindings->bindArea((int) $data['user_id'], (int) $data['location_id'], (string) $data['starts_on'])
            : $this->bindings->bind((int) $data['user_id'], (array) ($data['customer_ids'] ?? []), (string) $data['starts_on']);

        return back()->with('saved', __('customer::binding.bound', ['count' => $made]));
    }

    public function end(Request $request, DealerBinding $binding): RedirectResponse
    {
        $data = $request->validate(['ends_on' => ['required', 'date']]);

        $this->bindings->end($binding, (string) $data['ends_on']);

        return back()->with('saved', __('customer::binding.ended'));
    }

    public function handover(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_user_id' => ['required', 'integer'],
            'to_user_id' => ['required', 'integer'],
            'on_date' => ['required', 'date'],
        ]);

        $moved = $this->bindings->handover((int) $data['from_user_id'], (int) $data['to_user_id'], null, (string) $data['on_date']);

        return back()->with('saved', __('customer::binding.handed_over', ['count' => $moved]));
    }

    public function supervisor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'supervisor_id' => ['nullable', 'integer'],
        ]);

        $this->bindings->setSupervisor((int) $data['user_id'], empty($data['supervisor_id']) ? null : (int) $data['supervisor_id']);

        return back()->with('saved', __('customer::binding.supervisor_saved'));
    }

    /** ⭐ গাছ — কে কার উপরে, আর প্রত্যেকে আজ কয়জন ডিলার দেখেন। */
    public function tree(Request $request): View
    {
        $preview = $this->bindings->preview()->keyBy(fn (array $row) => $row['user']->id);
        $links = StaffSupervisor::query()->pluck('supervisor_id', 'user_id')->map(fn ($id) => (int) $id)->all();

        $children = [];

        foreach ($links as $user => $supervisor) {
            $children[$supervisor][] = (int) $user;
        }

        // ⓘ মাথা — যাঁর উপরওয়ালা নেই কিন্তু নিচে কেউ আছে, অথবা দেয়ালের ভিতরের একা মানুষ
        $roots = $preview->keys()
            ->filter(fn ($id) => ! isset($links[$id]) && (isset($children[$id]) || $preview[$id]['walled']))
            ->values()
            ->all();

        return view('customer::binding.tree', [
            'menu' => $this->menu->forUser($request->user()),
            'preview' => $preview,
            'children' => $children,
            'roots' => $roots,
        ]);
    }
}
