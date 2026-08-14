<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\Money;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * টাকা ও হেফাজত — কোন টাকা কার কাছে আছে।
 *
 * ── কেন এই পর্দাটা লাগে ─────────────────────────────────────────────
 * ব্যবস্থায় টাকার সংখ্যা অনেক জায়গায় ছড়ানো: নগদ কাউন্টারের তালিকায়
 * এক রকম, হিসাবের ছকে আরেক রকম, ড্যাশবোর্ডে যোগফল। কিন্তু মালিকের
 * প্রশ্নটা এই তিনটার কোনোটাই নয় — প্রশ্নটা **"কার কাছে কত"**।
 *
 * ওই প্রশ্নের উত্তর আজ পর্যন্ত কোথাও এক পর্দায় ছিল না। কেউ চলে গেলে,
 * কাউকে ছাঁটাই করতে হলে, বা রাতে সিন্দুক মেলাতে গেলে হিসাবরক্ষককে
 * তিন-চার জায়গা ঘুরে কাগজে যোগ করতে হত।
 *
 * ── ছকটা Ava-র /money থেকে ─────────────────────────────────────────
 * একই মালিকের আরেক পণ্যে এই পর্দাটা আছে, আর তার কলামগুলো পরীক্ষিত:
 * কোড · নাম · ধরন · হেফাজতকারী · ব্যালেন্স · পথে · শিফট। উপরে
 * "আপনার গ্রহণের অপেক্ষায়" — কারণ হাতে হাতে দেওয়া টাকা কেউ না জানলে
 * সপ্তাহখানেক অগৃহীত পড়ে থাকে, আর তখন দুই ধাপের হস্তান্তর নিছক একটা
 * বাড়তি বোতাম।
 *
 * ── ABOS-এ যেটা আলাদা ───────────────────────────────────────────────
 * "পথে" এখানে একটা সারি, কেবল কলাম নয়। ABOS-এ পথের টাকার নিজের খাত
 * আছে (১১০৩), আর সেটাই একমাত্র টাকা যেটা **কারও হাতে নেই** — তাই সে
 * তালিকার শেষে নিজের সারিতে বসে, কারও নামের পাশে নয়।
 */
class MoneyCustodyController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        /*
         * নগদ কাউন্টার দেখার যে চাবি, এটারও সেই চাবি।
         *
         * এই পর্দা নতুন কোনো তথ্য দেয় না — যা যা আলাদা আলাদা পর্দায়
         * আছে সেগুলোই এক জায়গায় সাজায়। আলাদা অনুমতি রাখলে কেউ
         * টিলের তালিকা দেখতে পেতেন অথচ যোগফলটা নয়, যেটা অর্থহীন।
         */
        return [new Middleware('can:accounts.till.view')];
    }

    public function __invoke(Request $request): View
    {
        $tills = CashTill::query()
            ->with(['account', 'holder'])
            ->orderByDesc('is_primary')
            ->orderBy('code')
            ->get();

        $banks = Account::query()
            ->where('is_bank', true)
            ->postable()
            ->orderBy('code')
            ->get();

        /*
         * পথে কার কাছে কত — দলিল থেকে, খাত থেকে নয়।
         *
         * খাতের ব্যালেন্স বলে মোট কত পথে আছে, কিন্তু "কে পাঠিয়েছেন,
         * কার কাছে যাচ্ছে" প্রশ্নের উত্তর কেবল দলিলেই আছে। আর ওই
         * প্রশ্নটাই এই পর্দার কারণ।
         */
        $onTheRoad = MoneyTransfer::query()
            ->with(['fromTill', 'toTill', 'toAccount', 'giver'])
            ->pending()
            ->orderBy('trx_date')
            ->get();

        return view('accounts::custody.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $this->rows($tills, $banks),
            'onTheRoad' => $onTheRoad,
            'transit' => Money::format(
                StandardChart::find(StandardChart::CASH_IN_TRANSIT)?->balanceOn() ?? '0'
            ),

            /*
             * আমার গ্রহণের অপেক্ষায় — ইনবক্স।
             *
             * টিলের মালিক ধরে ছাঁকা: যে হস্তান্তরগুলো আমার টিলে আসছে
             * সেগুলোই আমার কাজ। ওপরে না দেখালে হাতে হাতে দেওয়া টাকা
             * দিনের পর দিন অগৃহীত পড়ে থাকে।
             */
            'waitingForMe' => $onTheRoad->filter(
                fn (MoneyTransfer $t) => $t->toTill?->holder_id === $request->user()?->id
            )->values(),
        ]);
    }

    /**
     * প্রতিটা টাকার জায়গা, এক ছকে।
     *
     * টিল ও ব্যাংক দুইটা আলাদা মডেল, কিন্তু পর্দায় প্রশ্নটা এক — তাই
     * এখানে একই আকারে আনা হয়। ভিউ-তে দুইটা লুপ লিখলে একটায় কলাম যোগ
     * করে অন্যটায় ভুলে যাওয়া নিশ্চিত।
     *
     * @param  Collection<int, CashTill>  $tills
     * @param  Collection<int, Account>  $banks
     * @return list<array<string, mixed>>
     */
    private function rows($tills, $banks): array
    {
        $sentFrom = MoneyTransfer::query()
            ->pending()
            ->selectRaw('from_till_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('from_till_id')
            ->pluck('total', 'from_till_id');

        $rows = [];

        foreach ($tills as $till) {
            $rows[] = [
                'code' => $till->code,
                'name' => $till->name(),
                'kind' => __('accounts::custody.kind_till'),
                'holder' => $till->holder?->name,
                'balance' => Money::format($till->balance()),
                'sent' => Money::format((string) ($sentFrom[$till->id] ?? '0')),
                'url' => route('accounts.till.index'),
                'active' => $till->is_active,
                'primary' => $till->is_primary,
            ];
        }

        foreach ($banks as $bank) {
            $rows[] = [
                'code' => $bank->code,
                'name' => $bank->label(),
                'kind' => __('accounts::custody.kind_bank'),

                /*
                 * ব্যাংকের কোনো হেফাজতকারী নেই, আর সেটা ফাঁক নয়।
                 *
                 * ব্যাংকের টাকা কারও ড্রয়ারে থাকে না; ওটা ব্যাংকের
                 * কাছে। খালি ঘরটা তাই সত্যি কথাই বলে — নিচের সতর্কতাটা
                 * কেবল নগদ কাউন্টারের জন্য।
                 */
                'holder' => null,
                'balance' => Money::format($bank->balanceOn()),
                'sent' => Money::format('0'),
                'url' => route('accounts.coa.index'),
                'active' => $bank->is_active,
                'primary' => false,
            ];
        }

        return $rows;
    }
}
