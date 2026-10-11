<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentGrants;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ডকুমেন্টের অডিট ট্রেইল — কে, কী, কবে, কোন IP, কোন যন্ত্র (§১৮; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ খাতা ABOS-এর একটাই ([[AuditTrail]]); এখানে কেবল ডকুমেন্টের সারি, আর কেবল যে কাগজ দেখছেন তিনি দেখতে পান।
 * ⛔ মোছার কোনো দরজা নেই — পরিকল্পনা §১৮: *"সাধারণ user audit log মুছতে পারবেন না"*; খাতাটা নিজেও বদল
 * আর মোছা থামায়।
 */
final class DocumentAuditController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DocumentGrants $people,
    ) {}

    public function trail(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->authorize('documents.audit');

        $from = $this->date($request->query('from')) ?? Carbon::today()->subDays(30)->toDateString();
        $to = $this->date($request->query('to')) ?? Carbon::today()->toDateString();
        $who = (int) $request->query('user_id');
        $action = (string) $request->query('action', '');

        $rows = AuditTrail::query()
            ->where('auditable_type', Document::class)
            ->whereIn('auditable_id', Document::query()->withTrashed()->inViewedBranch()->visibleTo($user)->select('dms_documents.id'))
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($who > 0, fn ($q) => $q->where('user_id', $who))
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->with('user')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('documents::audit', [
            'menu' => $this->menu->forUser($user),
            'rows' => $rows,
            'people' => $this->people->people(),
            'filters' => ['from' => $from, 'to' => $to, 'user_id' => $who ?: null, 'action' => $action],
        ]);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
