<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Concerns\GrandTotals;
use App\Core\Services\MenuBuilder;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * পরিশোধের সময়সূচি — কোন সরবরাহকারীকে কবে কত দিতে হবে (অর্থের মানচিত্র §৬)।
 *
 * ── কেন ক্রয়ে, অর্থে নয় ──────────────────────────────────────────────
 * বিলের শেষ তারিখ (`due_on`) থাকে কেবল ক্রয়ের বিলে, আর অর্থ ক্রয়কে চেনে
 * না (`depends_on`: হিসাব আর মাস্টার ডেটা)। ⓘ মানচিত্রের লাইনটা যেকোনো
 * মডিউলের রুটে যেতে পারে — পাশের "দেনার তালিকা" আর "দেনার বয়স"ও অর্থের নয়।
 *
 * ── কী দেখায় ─────────────────────────────────────────────────────────
 * পাকা বিল, যার এখনো কিছু বাকি — শেষ তারিখ ধরে সাজানো, চার ভাগে:
 * মেয়াদ পেরোনো · ৭ দিনের মধ্যে · ৩০ দিনের মধ্যে · পরে। ⓘ শেষ তারিখ না
 * থাকলে বিলের তারিখটাই — নগদের বিল, সেদিনই দেওয়ার কথা ছিল।
 * প্রতিটা সারিতে "পরিশোধ" — পরিশোধের পর্দা ঐ বিলটা বাছা অবস্থায় খোলে।
 *
 * ── ⚠️ ছাঁকা, সাজানো আর যোগ — তিনটাই ডাটাবেজে ───────────────────────
 * প্রথম লেখায় পুরো টেবিল টেনে এনে PHP-তে ছাঁকা হচ্ছিল, আর
 * [[Tests\Feature\Architecture\EveryListScreenPaginatesTest]] ঠিকই ধরেছে:
 * ছয় মাস পরে পাতাটা খুলতে সময় লাগত, আর মেমরি শেষ হলে ৫০০ আসত।
 * ⓘ উপরের ভাগগুলোর সংখ্যা ও যোগফল আসে **আলাদা একটা সমষ্টি-প্রশ্ন থেকে**,
 * তালিকার সারি গুনে নয় — নাহলে পাতা ভাগ করার সাথে সাথে "মোট" হয়ে যেত
 * এই পাতার মোট, আর লেবেল বলত পুরোটার।
 */
class PaymentScheduleController extends Controller implements HasMiddleware
{
    use GrandTotals;

    /** @var list<string> */
    public const TABS = ['all', 'overdue', 'week', 'month', 'later'];

    /**
     * এখনো বাকি — বিলের মোট বাদ পাকা পরিশোধ।
     *
     * ⓘ শর্তগুলো [[PurchaseBill::scopeWithPaid()]]-এর হুবহু নকল, আর সেটা
     * ইচ্ছাকৃত ঝুঁকি: একটা বদলালে অন্যটাও বদলাতে হবে। ⚠️ বিকল্পটা ছিল
     * অ্যালিয়াসের উপর `HAVING`, আর `ONLY_FULL_GROUP_BY` চালু থাকলে সেটা
     * ভেঙে পড়ত।
     *
     * ⛔ আর অবস্থার তালিকাটা এখানে **হাতে লেখা** ছিল — ২১ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ `'confirmed', 'closed'` — অর্থাৎ *"কোন কাগজ হিসাবে গোনা হবে"*
     * প্রশ্নের উত্তরটা [[DocumentStatus::POSTED]]-এর বাইরে আরেকবার লেখা,
     * আর এবার SQL-এর ভিতরে, যেখানে কোনো পাহারা তাকাত না। ⚠️ কেউ
     * তালিকাটা বদলালে এই পর্দাটা নীরবে পুরনো নিয়মে চলত।
     *
     * ⭐ এখন ধ্রুবকটা থেকেই বোনা হয়, তাই দুই জায়গায় দুই উত্তর অসম্ভব।
     */
    private static function due(): string
    {
        $posted = implode(', ', array_map(
            fn (string $status) => "'".$status."'",
            DocumentStatus::POSTED,
        ));

        /*
         * ⭐ ভাউচারে দেওয়া টাকা আর পাকা ফেরতও বাদ — [[PurchaseBill::dueAmount()]]-এর হুবহু (৭ অক্টোবর ২০২৬)। ⛔ আগে কেবল
         * পরিশোধের কাগজ বাদ যেত: কাউন্টারে ভাউচারে পুরো শোধ বা পুরো ফেরত হওয়া বিলও "বাকি" তালিকায় থাকত — আর পরিশোধের
         * প্রস্তাবে ওঠার ঝুঁকি।
         */
        $voucherPosted = "'".DocumentStatus::CONFIRMED."'";

        return "(pur_bills.total - (
            select COALESCE(SUM(pl.amount), 0) from pur_payment_lines pl
            join pur_payments p on p.id = pl.payment_id
            where pl.purchase_bill_id = pur_bills.id and p.status in ({$posted})
        ) - (
            select COALESCE(SUM(v.amount), 0) from vouchers v
            where v.type = 'payment' and v.against_type = 'purchase_bill' and v.against_id = pur_bills.id and v.status = {$voucherPosted}
        ) - (
            select COALESCE(SUM(r.total), 0) from pur_returns r
            where r.purchase_bill_id = pur_bills.id and r.status in ({$posted})
        ))";
    }

    /** শেষ তারিখ — না থাকলে বিলের তারিখ */
    private const WHEN = 'COALESCE(pur_bills.due_on, pur_bills.trx_date)';

    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:purchase.payment.view')];
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'all';
        $today = Carbon::today();

        $list = $this->outstanding($tab, $today)
            ->with('supplier')
            ->withPaid()
            ->orderByRaw(self::WHEN)
            ->orderBy('pur_bills.id');

        // ⭐ সর্বমোট — ট্যাবের সব পাতা মিলে, বিলের তালিকার হুবহু হিসাবে ([[GrandTotals]])
        $grand = $this->grandTotals($list, ['total' => 't.total', 'amount' => 'GREATEST(t.total - COALESCE(t.paid_total, 0) - COALESCE(t.voucher_paid_total, 0) - COALESCE(t.returned_total, 0), 0)']);
        $rows = $list->paginate(50)->withQueryString();

        return view('purchase::payment-schedule.index', [
            'menu' => $this->menu->forUser($request->user()),
            'tab' => $tab,
            'today' => $today,
            'buckets' => $this->buckets($today),
            'rows' => $rows,
            'grand' => $grand,
            // ⭐ পরিশোধের প্রস্তাব — কেবল যাঁর পরিশোধ লেখার চাবি আছে (টাকা আসা-যাওয়ার পরিকল্পনা, ধাপ খ ১১, ৭ অক্টোবর ২০২৬)
            'accounts' => $request->user()?->can('purchase.payment.create') ? PaymentController::moneyAccounts() : collect(),
        ]);
    }

    /**
     * ⭐ পরিশোধের প্রস্তাব — বাছা বিল আর অঙ্ক থেকে সরবরাহকারী প্রতি একটা খসড়া পরিশোধ ([[PaymentProposalService]])।
     * ⓘ খাতায় কিছু নয়; অনুমোদন আর টাকা দেওয়া আজকের পথে, পরিশোধের তালিকা থেকে।
     */
    public function propose(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()?->can('purchase.payment.create'), 403);

        $data = $request->validate([
            'picks' => ['required', 'array'],
            'picks.*' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['required', 'integer', \Illuminate\Validation\Rule::in(PaymentController::moneyAccounts()->pluck('id')->all())],
            'trx_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $made = app(\App\Modules\Purchase\Services\PaymentProposalService::class)
            ->propose((array) $data['picks'], (int) $data['account_id'], $data['trx_date'] ?? null);

        return redirect()->route('purchase.payment_schedule.proposal', ['no' => (string) $made[0]->proposal_no])
            ->with('saved', __('purchase::schedule.proposed', [
                'count' => count($made),
                'numbers' => collect($made)->pluck('document_no')->implode(', '),
            ]));
    }

    /**
     * ⭐ প্রস্তাবের পাতা — কোন কোন খসড়া, কার অনুমোদন বাকি, কোনগুলো পরিশোধ হয়েছে (ধাপ খ ১১, ৭ অক্টোবর ২০২৬)।
     * ⓘ প্রস্তাব আলাদা টেবিলে নয় — তার পরিশোধগুলোই প্রস্তাব (`proposal_no`); না থাকলে ৪০৪।
     */
    public function proposal(Request $request, string $no): View
    {
        $payments = \App\Modules\Purchase\Models\Payment::query()->where('proposal_no', $no)
            ->with(['supplier', 'creator', 'lines.bill'])->orderBy('id')->get();

        abort_if($payments->isEmpty(), 404);

        $approvals = app(\App\Core\Engines\Approval\ApprovalEngine::class);

        return view('purchase::payment-schedule.proposal', [
            'menu' => $this->menu->forUser($request->user()),
            'no' => $no,
            'payments' => $payments,
            'approvalOf' => $payments->mapWithKeys(fn ($p) => [$p->id => $approvals->latestFor($p, 'payment')]),
        ]);
    }

    /**
     * পাকা, এখনো বাকি থাকা বিল — চাইলে একটা ভাগের।
     *
     * @return Builder<PurchaseBill>
     */
    private function outstanding(string $tab, Carbon $today): Builder
    {
        $query = PurchaseBill::query()
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereRaw(self::due().' > 0');

        $when = self::WHEN;

        return match ($tab) {
            'overdue' => $query->whereRaw("{$when} < ?", [$today->toDateString()]),
            'week' => $query->whereRaw("{$when} between ? and ?",
                [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]),
            'month' => $query->whereRaw("{$when} between ? and ?",
                [$today->copy()->addDays(8)->toDateString(), $today->copy()->addDays(30)->toDateString()]),
            'later' => $query->whereRaw("{$when} > ?", [$today->copy()->addDays(30)->toDateString()]),
            default => $query,
        };
    }

    /**
     * ভাগগুলোর সংখ্যা ও টাকা — একটাই প্রশ্নে, পুরো তালিকার উপর।
     *
     * @return array<string, array{count: int, amount: string}>
     */
    private function buckets(Carbon $today): array
    {
        $when = self::WHEN;
        $due = self::due();

        $row = $this->outstanding('all', $today)
            ->toBase()
            ->selectRaw("
                COUNT(*) as all_n, COALESCE(SUM({$due}), 0) as all_t,
                SUM({$when} < ?) as overdue_n, COALESCE(SUM(case when {$when} < ? then {$due} else 0 end), 0) as overdue_t,
                SUM({$when} between ? and ?) as week_n,
                COALESCE(SUM(case when {$when} between ? and ? then {$due} else 0 end), 0) as week_t,
                SUM({$when} between ? and ?) as month_n,
                COALESCE(SUM(case when {$when} between ? and ? then {$due} else 0 end), 0) as month_t,
                SUM({$when} > ?) as later_n, COALESCE(SUM(case when {$when} > ? then {$due} else 0 end), 0) as later_t
            ", $this->bounds($today))
            ->first();

        return collect(self::TABS)
            ->mapWithKeys(fn (string $tab) => [$tab => [
                'count' => (int) ($row?->{$tab.'_n'} ?? 0),
                'amount' => (string) ($row?->{$tab.'_t'} ?? '0'),
            ]])
            ->all();
    }

    /**
     * উপরের প্রশ্নের তারিখগুলো, ঠিক যে ক্রমে `?` বসেছে।
     *
     * @return list<string>
     */
    private function bounds(Carbon $today): array
    {
        $now = $today->toDateString();
        $week = $today->copy()->addDays(7)->toDateString();
        $weekEnd = $today->copy()->addDays(8)->toDateString();
        $month = $today->copy()->addDays(30)->toDateString();

        return [$now, $now, $now, $week, $now, $week, $weekEnd, $month, $weekEnd, $month, $month, $month];
    }
}
