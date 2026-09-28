<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Http\Controllers;

use App\Core\Services\DataScope;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Models\LoyaltyEntry;
use App\Modules\Promotion\Services\LoyaltyLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * পয়েন্টের পাতা — কার কাছে কত পয়েন্ট, আর খাতায় কী কী লেখা (স্পেক §৭-ঠ, §১৩)।
 *
 * ── ⚠️ কেন এতদিন ছিল না, আর কী ক্ষতি হচ্ছিল ────────────────────────
 * ⓘ [[LoyaltyLedger]] পয়েন্ট জমাত, খরচ করাত, ফেরাত, ফুরোত — কিন্তু
 * কেউ পড়তে পারতেন না। ⛔ ক্রেতা কাউন্টারে এসে *"আমার কত পয়েন্ট?"*
 * জিজ্ঞেস করলে উত্তর দেওয়ার কোনো পর্দা ছিল না — অথচ পয়েন্ট টাকার দায়।
 *
 * ── ⭐ কেবল পড়া ──────────────────────────────────────────────────────
 * ⓘ এই পাতায় কোনো ফর্ম নেই। ⛔ হাতে পয়েন্ট বসানোর দরজা থাকলে একদিন কেউ
 * ব্যালান্স মেলাতে কারণ ছাড়া পয়েন্ট বানাতেন — [[LoyaltyKind]]-এ
 * *"সংশোধন"* ধরন না রাখার একই যুক্তি।
 *
 * ── ⛔ ব্যালান্স এখানে গোনা হয় না ───────────────────────────────────
 * ⓘ প্রতিটা সংখ্যা [[LoyaltyLedger::balance()]] থেকে। ⚠️ এখানে
 * `SUM(points)` দেখালে রাতের কাজ এক রাত না চললে ফুরোনো পয়েন্টও ব্যালান্সে
 * দেখাত — আর কাউন্টারের লোক সেই সংখ্যা দেখে ক্রেতাকে কথা দিতেন, যা খরচের
 * দরজা ([[LoyaltyLedger::redeem()]]) পরে ফিরিয়ে দিত। মেয়াদের নিয়ম একটাই
 * জায়গায়।
 *
 * ── ⚠️ কার ক্রেতা কে দেখেন ──────────────────────────────────────────
 * ⓘ কোম্পানির দেয়াল [[BelongsToCompany]] নিজে তোলে — অন্য কোম্পানির
 * ক্রেতা ৪০৪। ⓘ কোম্পানির ভিতরে আজ যে দেয়াল আছে তা শাখার
 * ([[DataScope]], `UserDataScope::BRANCH`) — [[CustomerMetrics]]-এর একই
 * নিয়ম, শাখাহীন ক্রেতাও থাকেন।
 * ⛔ মালিকের সিদ্ধান্ত (২৬ সেপ্টেম্বর) *"বিক্রয়কর্মী কেবল নিজের
 * পরিবেশক দেখবেন"* — সেই কর্মী↔পরিবেশক জোড়াটা এখনো তৈরি হয়নি। ⭐ যেদিন
 * হবে, সেদিন [[visibleCustomers()]]-এ একটা লাইন যোগ হবে — দুই পদ্ধতিতেই
 * এই একটাই প্রশ্ন ব্যবহৃত, তাই তালিকা আর একজনের পাতা একসাথে বদলায়।
 *
 * ⭐ নিজের চাবি — `promotion.loyalty`। ⓘ ক্রেতার পয়েন্ট দেখা অফার বানানো
 * (`view`) থেকে আলাদা কাজ: কাউন্টারের লোক পয়েন্ট দেখেন, অফারের কাগজ নয়।
 */
final class PromotionLoyaltyController extends Controller implements HasMiddleware
{
    private const SCALE = 4;

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly LoyaltyLedger $ledger,
        private readonly DataScope $scope,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:promotion.loyalty')];
    }

    /**
     * ⭐ যাঁদের খাতায় পয়েন্ট আছে — নাম বা কোড দিয়ে খোঁজা যায়।
     *
     * ── ⚠️ ছাঁকনিটা SQL-এ, আর কেন সেটা "প্রায়" ─────────────────────
     * ⓘ ব্যালান্স খাতা পড়ে গোনা হয়, SQL-এ নয় — তাই SQL দিয়ে ঠিক
     * "অশূন্য ব্যালান্স" ছাঁকা যায় না। ⛔ পাতায় ছাঁকলে পঞ্চাশের পাতায়
     * তিনটা সারি দেখাত আর বাকি পাতা খালি ([[PromotionGiftController]]-এর
     * একই শিক্ষা)।
     *
     * ⭐ তাই SQL ছাঁকে `SUM(points) <> 0`: ⓘ ব্যালান্স = যোগফল − (না-লেখা
     * মেয়াদ-শেষ), আর দ্বিতীয়টা কখনো ঋণাত্মক নয় — তাই ধনাত্মক ব্যালান্সের
     * প্রত্যেকে এই ছাঁকনিতে থাকেন। ⚠️ উল্টোদিকে, রাতের কাজ না চললে
     * সব-ফুরোনো ক্রেতাও থাকেন — তাঁর ঘরে ব্যালান্স ০ দেখায়, মিথ্যা সংখ্যা নয়।
     */
    public function index(Request $request): View
    {
        $holders = LoyaltyEntry::query()
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('SUM(points) <> 0');

        $rows = $this->visibleCustomers($request->user())
            ->search($request->query('q'))
            ->whereIn('id', $holders)
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        /* ⓘ পাতার প্রতিটা ক্রেতার ব্যালান্স খাতা থেকে — পঞ্চাশটার বেশি কখনো নয় */
        $balances = [];

        foreach ($rows as $customer) {
            $balances[$customer->id] = $this->plain($this->ledger->balance((int) $customer->id));
        }

        return view('promotion::loyalty.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
            'balances' => $balances,
        ]);
    }

    /**
     * ⭐ একজন ক্রেতার খাতা — নতুন আগে, প্রতিটা সারির পাশে সেই মুহূর্তের ব্যালান্স।
     *
     * ── ⚠️ সারির পাশের ব্যালান্স ─────────────────────────────────────
     * ⓘ [[LoyaltyLedger::balance()]]-কে সারির `occurred_at` দিয়ে জিজ্ঞেস
     * করা — অর্থাৎ *"সেই মুহূর্তে খরচ করা যেত কত"*, সেদিনের মেয়াদ ধরে।
     * ⛔ এখানে চলতি যোগফল টানলে মেয়াদের নিয়মটা দ্বিতীয় জায়গায় লিখতে হত।
     * ⚠️ একই মুহূর্তে লেখা দুইটা সারি (বিল বাতিলে অর্জন আর খরচ একসাথে
     * ফেরে) একই ব্যালান্স দেখায় — দুইটা মিলে পরের অবস্থা।
     *
     * ⛔ অন্য কোম্পানির ক্রেতা রুটের বাঁধনেই ৪০৪; অন্য শাখার ক্রেতাও ৪০৪ —
     * ৪০৩ নয়, যাতে *"এই নম্বরে কেউ আছেন"* কথাটাও ফাঁস না হয়।
     */
    public function show(Request $request, Customer $customer): View
    {
        $visible = $this->visibleCustomers($request->user())->whereKey($customer->id)->exists();
        abort_unless($visible, 404);

        $entries = LoyaltyEntry::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $running = [];
        $points = [];

        foreach ($entries as $entry) {
            $running[$entry->id] = $this->plain($this->ledger->balance((int) $customer->id, $entry->occurred_at));
            $points[$entry->id] = $this->plain((string) $entry->points);
        }

        $balance = $this->ledger->balance((int) $customer->id);

        return view('promotion::loyalty.show', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            'entries' => $entries,
            'running' => $running,
            'points' => $points,
            'balance' => $this->plain($balance),
            'worth' => $this->plain($this->ledger->worthOf($balance)),
        ]);
    }

    /**
     * ⭐ এই ব্যবহারকারী কোন ক্রেতাদের দেখতে পান — দুই পদ্ধতির একটাই উত্তর।
     *
     * ⚠️ তালিকা আর একজনের পাতা আলাদা প্রশ্ন লিখলে একদিন একটায় দেয়াল উঠত,
     * অন্যটায় না — আর তালিকায় লুকানো ক্রেতার পাতা নম্বর বদলে খোলা যেত।
     *
     * @return Builder<Customer>
     */
    private function visibleCustomers(User $user): Builder
    {
        return Customer::query()
            ->when($this->scope->idsFor($user, UserDataScope::BRANCH), fn (Builder $q, array $ids) => $q
                ->where(fn (Builder $w) => $w->whereIn('customers.branch_id', $ids)
                    ->orWhereNull('customers.branch_id')));
    }

    /**
     * ⓘ `100.0000` → `100`, `12.5000` → `12.5` — পর্দায় চার ঘরের শূন্য নয়।
     *
     * ⚠️ `rtrim` সরাসরি নয়: `0.0000` তখন খালি লেখা হয়ে যেত, আর খালি ঘর
     * *"জানা নেই"* পড়ায়, *"শূন্য"* নয়।
     */
    private function plain(string $value): string
    {
        $value = bcadd($value, '0', self::SCALE);

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return in_array($value, ['', '-0'], true) ? '0' : $value;
    }
}
