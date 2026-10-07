<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Note;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ডেবিট/ক্রেডিট নোটের দুই খাত — কোন পক্ষে কোন খাত চলে (সমন্বয়কের ঠিক করা হিসাব, ৩ অক্টোবর ২০২৬)।
 *
 * ⭐ নিয়ম একটাই: ক্রেডিট নোটে পক্ষের খাত (control) Cr, অন্য পাশ Dr; ডেবিট নোটে উল্টো।
 *
 *   ধরন          পক্ষের খাত                                        অন্য পাশ
 *   গ্রাহক         প্রাপ্য ১১১০                                       CN: বিক্রয় ফেরত ৪১১০ · DN: বিক্রয় ৪১০০
 *   সরবরাহকারী    প্রদেয় ২১১১                                       ক্রয়মূল্যের পার্থক্য ৫১৫০
 *   সেবাদাতা       যে প্রদেয় খাতে তাঁর বকেয়া আছে (২১১১/২১১৬/২১১৭/২১১৮);  ব্যবহারকারী বাছেন — খরচের খাত; আগে আগে
 *                 একাধিক হলে বাছাই, না থাকলে ২১১৮                     যে খরচে বিল হয়েছিল সেটা আগে থেকে বাছা
 *   ব্যক্তি         তাঁর খাতা যে খাতগুলোয় আছে, তার একটা                 আয় বা খরচের খাত, ব্যবহারকারী বাছেন
 *
 * ⛔ তালিকার বাইরের খাতে নোট কখনো বসে না — সার্ভারে যাচাই ([[NoteService::create()]])।
 * ⓘ সরবরাহকারী আর সেবাদাতা খাতায় একই `supplier` পক্ষ; কে কোনটা তা বলে বাছার তালিকার চিহ্ন ([[PartyRegistry::forPicker()]])।
 */
final class NoteAccounts
{
    /** সেবাদাতার বকেয়া যে খাতগুলোয় বসতে পারে */
    private const PROVIDER_PAYABLES = [StandardChart::PAYABLE, StandardChart::TRANSPORT_PAYABLE, StandardChart::LABOUR_PAYABLE, StandardChart::VENDOR_PAYABLE];

    public function __construct(private readonly PartyRegistry $parties) {}

    /**
     * এই ধরনের পক্ষগুলো — বাছার তালিকার জন্য।
     *
     * @return list<array<string, mixed>>
     */
    public function partyOptions(string $kind): array
    {
        $type = Note::KINDS[$kind] ?? null;

        if ($type === null) {
            return [];
        }

        $options = collect($this->parties->forPicker())->firstWhere('type', $type)['options'] ?? [];

        return array_values(match ($kind) {
            Note::KIND_SUPPLIER => array_filter($options, fn (array $o) => ($o['note'] ?? null) === null),
            Note::KIND_SERVICE_PROVIDER => array_filter($options, fn (array $o) => ($o['note'] ?? null) !== null),
            default => $options,
        });
    }

    /** পক্ষটা কি সত্যিই এই ধরনের — অন্য কোম্পানির বা ভুল ধরনের পক্ষ নয় */
    public function kindMatches(string $kind, int $partyId): bool
    {
        return in_array($partyId, array_map(fn (array $o) => (int) $o['id'], $this->partyOptions($kind)), true);
    }

    /**
     * পক্ষের খাত — যে খাতগুলো চলে।
     *
     * @return Collection<int, Account>
     */
    public function controls(string $kind, int $partyId): Collection
    {
        return match ($kind) {
            Note::KIND_CUSTOMER => $this->byCodes([StandardChart::RECEIVABLE]),
            Note::KIND_SUPPLIER => $this->byCodes([StandardChart::PAYABLE]),
            Note::KIND_SERVICE_PROVIDER => $this->providerControls($partyId),
            Note::KIND_PERSON => $this->accountsWithEntries('person', $partyId),
            default => collect(),
        };
    }

    /**
     * অন্য পাশ — যে খাতগুলো চলে।
     *
     * @return Collection<int, Account>
     */
    public function others(string $kind, string $direction): Collection
    {
        return match ($kind) {
            /*
             * ⭐ গ্রাহকের ক্রেডিট নোট — মাল ফেরত হলে বিক্রয় ফেরত (৪১১০), কেবল দামের ছাড় হলে দেওয়া ছাড় (৫৩০০); মালিকের পরিকল্পনা
             * সংস্করণ ২, ৪ অক্টোবর ২০২৬। ⓘ চাওয়া ক্রমেই সাজানো ([[byCodes()]]), তাই ডিফল্ট ([[defaultOther()]]) আগের মতোই ৪১১০ — আজকের আচরণ বদলায় না।
             */
            /*
             * ⭐ ড্যামেজ দাবি (১১৫১) — তৃতীয় পছন্দ, দুই দিকেই: ডিলারের ক্রেডিট নোটে Dr দাবি, কোম্পানির ডেবিট নোটে Cr দাবি
             * (মালিক, ৪ অক্টোবর ২০২৬; [[StandardChart::DAMAGE_CLAIM]])। ⓘ তালিকার শেষে, তাই ডিফল্ট বদলায় না।
             */
            Note::KIND_CUSTOMER => $this->byCodes($direction === Note::CREDIT
                ? [StandardChart::SALES_RETURN, StandardChart::DISCOUNT_GIVEN, StandardChart::DAMAGE_CLAIM]
                : [StandardChart::SALES]),
            Note::KIND_SUPPLIER => $this->byCodes($direction === Note::DEBIT
                ? [StandardChart::PURCHASE_PRICE_VARIANCE, StandardChart::DAMAGE_CLAIM]
                : [StandardChart::PURCHASE_PRICE_VARIANCE]),
            Note::KIND_SERVICE_PROVIDER => Account::query()->postable()->ofType([Account::EXPENSE])->orderBy('code')->get(),
            Note::KIND_PERSON => Account::query()->postable()->ofType([Account::INCOME, Account::EXPENSE])->orderBy('code')->get(),
            default => collect(),
        };
    }

    /** পক্ষের খাত আগে থেকে — একটাই থাকলে সেটা; বেশি থাকলে কিছু নয় (ব্যবহারকারী বাছেন) */
    public function defaultControl(string $kind, int $partyId): ?int
    {
        $controls = $this->controls($kind, $partyId);

        return $controls->count() === 1 ? (int) $controls->first()->id : null;
    }

    /**
     * অন্য পাশ আগে থেকে — গ্রাহক/সরবরাহকারীতে একটাই খাত; সেবাদাতায় তাঁর শেষ বিল যে খরচের খাতে বসেছিল।
     */
    public function defaultOther(string $kind, string $direction, int $partyId): ?int
    {
        $others = $this->others($kind, $direction);

        if (in_array($kind, [Note::KIND_CUSTOMER, Note::KIND_SUPPLIER], true)) {
            return $others->first()?->id;
        }

        if ($kind !== Note::KIND_SERVICE_PROVIDER || $partyId <= 0) {
            return null;
        }

        $last = DB::table('ledger_entries as p')
            ->join('ledger_entries as e', fn ($j) => $j->on('e.source_type', '=', 'p.source_type')->on('e.source_id', '=', 'p.source_id'))
            ->where('p.company_id', CompanyContext::id())
            ->where('p.party_type', 'supplier')
            ->where('p.party_id', $partyId)
            ->where('p.credit', '>', 0)
            ->where('e.debit', '>', 0)
            ->whereIn('e.account_id', $others->pluck('id'))
            ->orderByDesc('p.id')
            ->value('e.account_id');

        return $last === null ? null : (int) $last;
    }

    /**
     * নোটের দুই খাত — বসানো থাকলে সেগুলো; পুরনো নোটে (ঘর খালি) আগের নিয়মে।
     *
     * @return array{control: Account, other: Account}
     */
    public function of(Note $note): array
    {
        $kind = (string) ($note->party_kind ?: ($note->party_type === 'customer' ? Note::KIND_CUSTOMER : Note::KIND_SUPPLIER));

        $control = $note->control_account_id !== null
            ? Account::query()->findOrFail($note->control_account_id)
            : Account::query()->findOrFail($this->defaultControl($kind, (int) $note->party_id));
        $other = $note->other_account_id !== null
            ? Account::query()->findOrFail($note->other_account_id)
            : Account::query()->findOrFail($this->defaultOther($kind, (string) $note->direction, (int) $note->party_id));

        return ['control' => $control, 'other' => $other];
    }

    /** @return Collection<int, Account> */
    private function providerControls(int $partyId): Collection
    {
        $candidates = $this->byCodes(self::PROVIDER_PAYABLES);
        $used = $partyId <= 0 ? collect() : $candidates->whereIn('id', $this->entryAccounts('supplier', $partyId))->values();

        return $used->isNotEmpty() ? $used : $this->byCodes([StandardChart::VENDOR_PAYABLE]);
    }

    /** @return Collection<int, Account> */
    private function accountsWithEntries(string $partyType, int $partyId): Collection
    {
        $ids = $partyId <= 0 ? [] : $this->entryAccounts($partyType, $partyId);

        return $ids === [] ? collect() : Account::query()->postable()->whereIn('id', $ids)->orderBy('code')->get();
    }

    /** @return list<int> */
    private function entryAccounts(string $partyType, int $partyId): array
    {
        return DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->distinct()
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $codes
     * @return Collection<int, Account>
     */
    private function byCodes(array $codes): Collection
    {
        /*
         * ⓘ যে ক্রমে চাওয়া হয়েছে সেই ক্রমে — প্রথমটাই ডিফল্ট ([[defaultOther()]])। ⛔ কোড ধরে সাজালে ড্যামেজ দাবি (১১৫১)
         * বিক্রয় ফেরতের (৪১১০) আগে বসত, আর প্রতিটা নতুন ক্রেডিট নোট চুপচাপ ড্যামেজ দাবিতে পড়ত (৪ অক্টোবর ২০২৬)।
         */
        return Account::query()->postable()->whereIn('code', $codes)->get()
            ->sortBy(fn (Account $a) => array_search((string) $a->code, $codes, true))
            ->values();
    }
}
