<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\ProfileRequest;
use App\Modules\Customer\Services\ProfileRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ অফিসের দিক — পোর্টাল থেকে আসা গ্রাহকের অনুরোধ (তথ্য বদল, কাগজ); গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬।
 *
 * ⓘ চাবি গ্রাহকের তথ্য বদলানোরটাই (`customer.update`): অনুরোধ দেখে যিনি রেকর্ডে বসাবেন, তাঁরই কাজ।
 * ⛔ এখান থেকে গ্রাহকের রেকর্ড বদলায় না — "বসানো হয়েছে" কেবল চিহ্ন; বদলটা গ্রাহকের সম্পাদনার পর্দায়, তার নিজের
 * যাচাই আর নিরীক্ষাসহ।
 */
final class ProfileRequestController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ProfileRequestService $requests,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:customer.update')];
    }

    public function index(Request $request): View
    {
        $all = $request->query('show') === 'all';

        return view('customer::profile-requests', [
            'menu' => $this->menu->forUser($request->user()),
            'all' => $all,
            'openCount' => ProfileRequest::query()->open()->count(),
            'rows' => ProfileRequest::query()
                ->with('customer', 'decider')
                ->when(! $all, fn ($q) => $q->open())
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function decide(Request $request, ProfileRequest $profileRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.ProfileRequest::DONE.','.ProfileRequest::REFUSED],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->requests->decide($profileRequest, $request->user(), $data['status'], $data['decision_note'] ?? null);

        return back()->with('status', __('customer::profile_request.decided'));
    }

    public function paper(ProfileRequest $profileRequest): StreamedResponse
    {
        return $this->requests->stream($profileRequest);
    }
}
