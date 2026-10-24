<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\DataScope;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\ListSummary;
use App\Core\Support\MoneyMethod;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ ভাউচারের তালিকার ছাঁকনি — তারিখ, পক্ষ, খাত, পদ্ধতি, অবস্থা, টাকার পরিসর, কে বানালেন, শাখা (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬:
 * রসিদের তালিকার "+ Filter" কী করে — কেবল তারিখ খুলত; fe: international standard-এর ভাউচার তালিকা)।
 *
 * ⓘ পাঁচ ধরনের তালিকা একই ছাঁকনি নেয় ([[VoucherController::index()]] ধরন রুট থেকে পায়)। ⓘ সব ঠিকানায় — চিপ, share-লিংক আর
 * ছাপা একই তালিকা দেখায়; মোট ([[GrandTotals]]) একই ছাঁকা কোয়েরি থেকে, তাই মোটও ছাঁকনি মানে।
 *
 *   from, to      — তারিখ
 *   party         — "type:id" ([[x-ui.party-search]]-এর দলের মান), ভাউচারের মাথার পক্ষ
 *   account       — খাতের id, ভাউচারের যেকোনো সারিতে (রসিদে "যে খাতে জমা", পরিশোধে "যে খাত থেকে", জাবেদায় যেকোনো খাত)
 *   method        — পদ্ধতি ([[MoneyMethod::METHODS]])
 *   state         — তালিকার মোটের দল ([[ListSummary::GROUPS]]): পাকা, অপেক্ষায়, খসড়া, ফেরত, বাতিল — মোটের একই সই-সেবায়
 *   min, max      — টাকার পরিসর (লাখ-কমা ছেঁটে)
 *   by            — কে বানালেন (`created_by`)
 *   branch_id     — শাখা; হেডারে এক শাখা বাছা থাকলে সেটাই শুরুর মান (শাখার দেয়াল তো আছেই, [[ScopedToUserBranch]])
 *
 * ⛔ ভুল বা অ্যারে মান (`?party[]=x`, `?min=abc`) নীরবে বাদ — ৫০০ নয়, আর "সব" দেখানোর ভুলও নয়: অচেনা অবস্থা বা পদ্ধতি বাদ যায়,
 * কারণ সেগুলো কারো হাতে-লেখা ঠিকানা থেকেই আসে।
 */
final class VoucherListFilters
{
    public const KEYS = ['from', 'to', 'party', 'account', 'method', 'state', 'min', 'max', 'by', 'branch_id'];

    public function __construct(
        private readonly PartyRegistry $parties,
        private readonly DataScope $scope,
    ) {}

    /**
     * ঠিকানা থেকে পড়া, পরিষ্কার — কেবল চেনা আর ঠিক আকারের মান।
     *
     * @return array<string, string>
     */
    public function read(Request $request): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            $value = $request->query($key);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            $ok = match ($key) {
                'from', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                'party' => preg_match('/^[a-z_]+:\d+$/', $value) === 1 && $this->parties->knows(explode(':', $value)[0]),
                'account', 'by', 'branch_id' => ctype_digit($value),
                'method' => in_array($value, [...MoneyMethod::METHODS, ...MoneyMethod::LEGACY], true),
                'state' => in_array($value, ListSummary::GROUPS, true),
                'min', 'max' => is_numeric($value = str_replace(',', '', $value)),
                default => false,
            };

            if ($ok) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * ছাঁকনিগুলো কোয়েরিতে — তালিকা আর মোট দুজনেই এই কোয়েরি পড়ে।
     *
     * @param  array<string, string>  $f  [[read()]]-এর ফল
     */
    public function apply(Builder $query, array $f): void
    {
        $query
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('trx_date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('trx_date', '<=', $d))
            ->when($f['party'] ?? null, function ($q, string $party) {
                [$type, $id] = explode(':', $party);
                $q->where('party_type', $type)->where('party_id', (int) $id);
            })
            ->when($f['account'] ?? null, fn ($q, $id) => $q->whereHas('lines', fn ($l) => $l->where('account_id', (int) $id)))
            ->when($f['method'] ?? null, fn ($q, $m) => $q->where('instrument', $m))
            ->when(isset($f['min']), fn ($q) => $q->where('amount', '>=', $f['min']))
            ->when(isset($f['max']), fn ($q) => $q->where('amount', '<=', $f['max']))
            ->when($f['by'] ?? null, fn ($q, $id) => $q->where('created_by', (int) $id))
            ->when($f['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', (int) $id));

        // ⓘ অবস্থা শেষে — দলের ভাগ বাকি ছাঁকা সারিগুলোর উপরেই ([[ListSummary::onlyGroup()]])
        if (isset($f['state'])) {
            ListSummary::onlyGroup($query, Voucher::listSummary(), $f['state']);
        }
    }

    /**
     * হেডারে এক শাখা বাছা থাকলে সেটাই শাখার শুরুর মান — ঠিকানায় শাখা না থাকলে।
     *
     * @param  array<string, string>  $f
     * @return array<string, string>
     */
    public function withHeaderBranch(array $f, ?User $user): array
    {
        if (! isset($f['branch_id']) && $user !== null && $this->scope->viewsOneBranch($user)) {
            $one = $this->scope->viewBranchIds($user)[0] ?? null;

            if ($one !== null) {
                $f['branch_id'] = (string) $one;
            }
        }

        return $f;
    }

    /**
     * পর্দার ঘরগুলোর বাছাই — পক্ষের দল, খাত, পদ্ধতি, অবস্থা, যিনি বানালেন, শাখা।
     *
     * @return array{parties: list<array<string, mixed>>, accounts: array<string, string>, methods: array<string, string>, states: array<string, string>, makers: array<string, string>, branches: array<string, string>}
     */
    public function options(string $type, ?User $user): array
    {
        // ⓘ রসিদ-পরিশোধ-খরচ-স্থানান্তরে টাকার খাত (নকশার "যে খাতে জমা" / "যে খাত থেকে"); জাবেদায় যেকোনো খাত
        $accounts = Account::query()->postable()->active()
            ->when($type !== Voucher::JOURNAL, fn ($q) => $q->money())
            ->orderBy('code')->get()
            ->mapWithKeys(fn (Account $a) => [(string) $a->id => $a->label()])->all();

        $makers = User::query()
            ->whereIn('id', Voucher::query()->ofType($type)->whereNotNull('created_by')->distinct()->select('created_by'))
            ->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn (User $u) => [(string) $u->id => (string) $u->name])->all();

        $reach = $user !== null ? $this->scope->viewBranchIds($user) : null;
        $branches = Branch::query()->where('company_id', CompanyContext::id())
            ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
            ->orderBy('sort_order')->orderBy('name_en')->get()
            ->mapWithKeys(fn (Branch $b) => [(string) $b->id => $b->name()])->all();

        return [
            'parties' => $this->parties->forPicker(evenInactiveWithABalance: true),
            'accounts' => $accounts,
            'methods' => collect(MoneyMethod::METHODS)->mapWithKeys(fn (string $m) => [$m => __('money.method.'.$m)])->all(),
            'states' => collect(ListSummary::GROUPS)->mapWithKeys(fn (string $g) => [$g => __('accounts::voucher.filter.state_'.$g)])->all(),
            'makers' => $makers,
            'branches' => $branches,
        ];
    }

    /**
     * চিপের নাম আর মানের লেখা — টুলবারের `filterLabels` আর `filterValues` ([[x-ui.toolbar]])।
     *
     * @param  array<string, mixed>  $options  [[options()]]-এর ফল
     * @param  array<string, string>  $f
     * @return array{labels: array<string, string>, values: array<string, array<string, string>>}
     */
    public function chips(string $type, array $options, array $f): array
    {
        $labels = [
            // ⛔ পরিশোধে "কাকে দেওয়া হল" নয় — ফর্মে ঐ লেখা খাতের ঘরের, পক্ষের নয়; এক লেখার দুই মানে হত
            'party' => __($type === Voucher::RECEIPT ? 'accounts::field.received_from' : 'accounts::field.party'),
            'account' => __(match ($type) {
                Voucher::RECEIPT => 'accounts::field.paid_into',
                Voucher::JOURNAL => 'accounts::voucher.filter.account',
                default => 'money.screen.from_account',
            }),
            'method' => __('money.method.legend'),
            'state' => __('accounts::voucher.filter.state'),
            'min' => __('accounts::voucher.filter.min'),
            'max' => __('accounts::voucher.filter.max'),
            'by' => __('accounts::voucher.filter.by'),
            'branch_id' => __('accounts::voucher.filter.branch'),
        ];

        $partyName = null;
        if (isset($f['party'])) {
            [$pt, $pid] = explode(':', $f['party']);
            $partyName = $this->parties->labelsOf(collect([[$pt, (int) $pid]]))[$pt.':'.$pid] ?? null;
        }

        return [
            'labels' => $labels,
            'values' => [
                'party' => isset($f['party']) && $partyName !== null ? [$f['party'] => (string) $partyName] : [],
                'account' => $options['accounts'],
                'method' => $options['methods'],
                'state' => $options['states'],
                'by' => $options['makers'],
                'branch_id' => $options['branches'],
            ],
        ];
    }
}
