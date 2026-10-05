<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\MasterData\Models\Person;

/**
 * ⭐ মালিকের নামে শুরুর মূলধন — মালিকের আদেশ, ৫ অক্টোবর ২০২৬: *"মূলধন ও বিনিয়োগে মালিকের নামই নেই, কোথায় বসালে তাহলে"*।
 *
 * ⛔ খোলা জের (মজুদ, গ্রাহক, সরবরাহকারী, খাত) আর শুরুর জাবেদা মালিকের মূলধনে (৩১০০) বসত
 * ([[OpeningBalanceService::openingEquity()]]), অথচ মূলধনের রেজিস্টার কারো নামে কিছু দেখাত না — আভা ট্রেডের
 * ৳৬,০৮,৬৯৩.৫২ খাতায় ছিল, রেজিস্টারে শূন্য।
 *
 * ⓘ নিয়ম একটাই, আর সেটাই দুবার গোনা ঠেকায়: **প্রতিটা শাখায় রেজিস্টারের মোট = ৩১০০-এর জের**। ফাঁক থাকলে সেই শাখায়
 * মালিকের নামে একটা `opening` সারি ফাঁকটা ভরে ([[CapitalEntry::OPENING]]) — নতুন দাখিলা ছাড়া, কারণ টাকা খাতায় আগেই
 * বসেছে। আবার চালালে ফাঁক শূন্য, তাই কিছুই হয় না। খোলা জের বসার পরে নিজেই চলে ([[ReconcileOpeningCapital]]), আর
 * পুরনো কোম্পানির জন্য কমান্ড (`finance:opening-capital`)।
 */
final class OwnerCapital
{
    public const SETTING = 'finance.owner_person_id';

    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * কন্ট্রোল প্যানেলের বাছাই — এই কোম্পানির চালু ব্যক্তিরা ([[SettingOptions]] এটা ডাকে)।
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return ['' => '—'] + Person::query()->where('is_active', true)->orderBy('name_en')->get()
            ->mapWithKeys(fn (Person $p) => [(string) $p->id => $p->name()])
            ->all();
    }

    /** কোম্পানির মালিক — বাছা না থাকলে, বা বাছা মানুষ এই কোম্পানির না হলে `null` */
    public function owner(): ?Person
    {
        $id = (int) app(SettingsService::class)->get(self::SETTING, 0);

        return $id > 0 ? Person::query()->find($id) : null;
    }

    public function choose(Person $person): void
    {
        app(SettingsService::class)->set(self::SETTING, (int) $person->id);
    }

    /**
     * শাখা ধরে ৩১০০-এর জের − রেজিস্টারের মোট। শাখাহীন দাখিলা `''`-এ।
     *
     * @return array<string, string> শাখার id (বা '') → ফাঁক (ধনাত্মক = রেজিস্টারে কম)
     */
    public function gaps(): array
    {
        $capital = StandardChart::find(StandardChart::OWNER_CAPITAL);

        if ($capital === null) {
            return [];
        }

        $accounts = $capital->selfAndDescendants()->pluck('id')->all();

        /*
         * ⛔ গোটা কোম্পানি, দেখার শাখা ছাড়া — হেডারে একটা শাখা বাছা থাকলে অন্য শাখার ফাঁক অদৃশ্য হত, আর সেই শাখার মূলধন কখনো
         * রেজিস্টারে আসত না। ⓘ শাখা আলাদা হয় নিচের `groupBy`-এ।
         */
        $books = LedgerEntry::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $accounts)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n')
            ->pluck('n', 'branch_id');

        // ⓘ রেজিস্টারের নিয়ম [[CapitalService::positions()]]-এর হুবহু — পাকা, আর যে রসিদে এসেছিল সেটা বাতিল নয়
        $register = CapitalEntry::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->posted()
            ->where(fn ($q) => $q->whereNull('voucher_id')
                ->orWhereHas('voucher', fn ($v) => $v->where('status', '!=', DocumentStatus::CANCELLED)))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(amount), 0) as n')
            ->pluck('n', 'branch_id');

        $gaps = [];

        foreach ($books->keys()->merge($register->keys())->unique() as $branch) {
            $gap = bcsub((string) ($books[$branch] ?? '0'), (string) ($register[$branch] ?? '0'), 4);

            if (bccomp($gap, '0', 4) !== 0) {
                $gaps[(string) $branch] = $gap;
            }
        }

        return $gaps;
    }

    /**
     * ফাঁক ভরানো — প্রতিটা শাখায় মালিকের নামে একটা `opening` সারি। মালিক বাছা না থাকলে কিছুই নয় (পাতা আগে বাছতে বলে)।
     *
     * @return list<CapitalEntry> যে সারিগুলো বসল
     */
    public function reconcile(): array
    {
        $owner = $this->owner();

        if ($owner === null) {
            return [];
        }

        $made = [];

        foreach ($this->gaps() as $branch => $gap) {
            $made[] = CapitalEntry::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $branch === '' ? null : (int) $branch,
                'document_no' => $this->numbers->next('CAP'),
                'person_id' => $owner->id,
                'contributor_type' => CapitalEntry::OWNER,
                'entry_type' => CapitalEntry::CONTRIBUTION,
                'in_kind' => CapitalEntry::OPENING,
                'trx_date' => now()->toDateString(),
                'amount' => $gap,
                'narration' => __('finance::message.opening_capital_narration'),
                'status' => CapitalEntry::POSTED,
                'posted_at' => now(),
                'created_by' => auth()->id(),
            ]);
        }

        return $made;
    }
}
