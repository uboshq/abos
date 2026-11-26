<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailAddress;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailProfile;
use App\Modules\Mail\Models\MailSend;
use App\Modules\Mail\Models\MailTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * ⭐ মেইলের খাতা (মালিকের অডিট নির্দেশ, ২৬ নভেম্বর ২০২৬, "ok"):
 *   · ইতিহাস — মেইলবক্স, ঠিকানা, টেমপ্লেট, সই, উৎসবের প্রতিটা বদল: কে, কখন, কী, আগে → পরে; খোঁজা যায়, মোছা যায় না;
 *   · পাঠানো — ABOS থেকে যাওয়া প্রতিটা মেইল: কে, কোন ঠিকানা থেকে, কাকে, বিষয়, সাথে কোন কাগজ।
 * ⛔ মেইলের লেখা বা কে কোন মেইল পড়লেন — এখানে নেই, কোথাও নেই।
 */
final class MailHistoryController extends Controller implements HasMiddleware
{
    public const TYPES = [
        'account' => MailAccount::class,
        'address' => MailAddress::class,
        'template' => MailTemplate::class,
        'signature' => MailProfile::class,
        'festival' => MailFestival::class,
    ];

    public static function middleware(): array
    {
        return [new Middleware('can:mail.manage')];
    }

    public function index(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $q = trim((string) $request->query('q', ''));
        $from = $request->date('from');
        $to = $request->date('to');

        $rows = AuditTrail::query()
            ->whereIn('auditable_type', isset(self::TYPES[$type]) ? [self::TYPES[$type]] : array_values(self::TYPES))
            ->when($from, fn (Builder $w) => $w->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $w) => $w->where('created_at', '<=', $to->endOfDay()))
            ->when($q !== '', fn (Builder $w) => $w->where(fn (Builder $s) => $s
                ->where('label', 'like', '%'.$q.'%')->orWhere('reason', 'like', '%'.$q.'%')->orWhere('action', 'like', '%'.$q.'%')
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.$q.'%'))
                ->orWhereHas('changes', fn (Builder $c) => $c->where('old_value', 'like', '%'.$q.'%')->orWhere('new_value', 'like', '%'.$q.'%'))))
            ->with(['user:id,name', 'changes'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('mail::history.index', [
            'rows' => $rows,
            'type' => $type,
            'q' => $q,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
            'kinds' => array_flip(self::TYPES),
        ]);
    }

    public function sent(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $from = $request->date('from');
        $to = $request->date('to');

        $rows = MailSend::query()
            ->when($from, fn (Builder $w) => $w->where('sent_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $w) => $w->where('sent_at', '<=', $to->endOfDay()))
            ->when($request->query('paper') === 'yes', fn (Builder $w) => $w->whereNotNull('paper_type'))
            ->when($q !== '', fn (Builder $w) => $w->where(fn (Builder $s) => $s
                ->where('to', 'like', '%'.$q.'%')->orWhere('subject', 'like', '%'.$q.'%')->orWhere('from_email', 'like', '%'.$q.'%')
                ->orWhere('paper_no', 'like', '%'.$q.'%')->orWhereHas('sender', fn (Builder $u) => $u->where('name', 'like', '%'.$q.'%'))))
            ->with('sender:id,name')
            ->latest('sent_at')
            ->paginate(50)
            ->withQueryString();

        return view('mail::history.sent', ['rows' => $rows, 'q' => $q, 'from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d'),
            'paper' => $request->query('paper') === 'yes']);
    }
}
