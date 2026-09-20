<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use Illuminate\Support\Collection;

/**
 * ছকের ব্যাংক/MFS খাতগুলো কোন প্রতিষ্ঠানের — নাম দেখে প্রস্তাব।
 *
 * ── ⚠️ কেবল প্রস্তাব, নিজে কিছু বসায় না ─────────────────────────────
 * নাম দেখে চেনা মানুষের আন্দাজের মতোই ভুল করতে পারে। তাই এটা একটা
 * তালিকা ফেরায়; জোড়া বসে কেবল `--apply`-এ, আর লাইভে সেটা চালান
 * সমন্বয়কারী, তালিকাটা দেখে ([[App\Console\Commands\ProposeInstitutionLinks]])।
 *
 * ── কী মেলে ──────────────────────────────────────────────────────────
 *   ১. প্রতিষ্ঠানের পুরো নাম খাতের নামে আছে — "Islami Bank Bangladesh"
 *   ২. প্রতিষ্ঠানের সংক্ষেপ খাতের নামে আলাদা শব্দ হিসেবে আছে — "IBBL CD"
 *   ৩. চেনা সংক্ষেপ ([[ALIASES]]) খাতের নামে, আর তার পুরো রূপ প্রতিষ্ঠানের নামে
 *
 * ⛔ দুইটা প্রতিষ্ঠান মিললে প্রস্তাব নয় — "দ্ব্যর্থক" হিসেবে জানায়।
 * ভুল ব্যাংকে জোড়া বসার চেয়ে না বসা ভালো: প্রথমটা কেউ খোঁজে না।
 */
final class InstitutionLinkProposer
{
    /**
     * বাংলাদেশের চেনা সংক্ষেপ → পুরো নামের যে অংশটা প্রতিষ্ঠানের নামে থাকবেই।
     *
     * ⓘ কেবল নিশ্চিত জোড়া; সন্দেহের কিছু এখানে নয়।
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'ibbl' => 'islami bank bangladesh',
        'dbbl' => 'dutch bangla',
        'ebl' => 'eastern bank',
        'ucb' => 'united commercial',
        'ucbl' => 'united commercial',
        'sjibl' => 'shahjalal islami',
        'fsibl' => 'first security islami',
        'sibl' => 'social islami',
        'aibl' => 'al arafah islami',
        'exim' => 'export import',
        'mtb' => 'mutual trust',
        'ncc' => 'national credit',
        'pbl' => 'prime bank',
        'bkash' => 'bkash',
        'nagad' => 'nagad',
    ];

    /**
     * @return Collection<int, array{account: Account, institution: ?Institution, candidates: list<Institution>, why: string}>
     */
    public function propose(): Collection
    {
        $linked = InstitutionAccount::query()->pluck('account_id')->all();

        $institutions = Institution::query()->orderBy('name_en')->get();

        return Account::query()
            ->whereIn('money_kind', [Account::BANK, Account::MFS])
            ->where('is_group', false)
            ->whereNotIn('id', $linked)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($institutions) {
                $hits = [];

                foreach ($institutions as $institution) {
                    if (! $this->kindsFit($account, $institution)) {
                        continue;
                    }

                    $why = $this->why($account, $institution);

                    if ($why !== null) {
                        $hits[] = [$institution, $why];
                    }
                }

                return [
                    'account' => $account,
                    'institution' => count($hits) === 1 ? $hits[0][0] : null,
                    'candidates' => array_map(fn ($h) => $h[0], $hits),
                    'why' => count($hits) === 1 ? $hits[0][1] : (count($hits) === 0 ? 'none' : 'ambiguous'),
                ];
            });
    }

    /** @return int কয়টা জোড়া বসল */
    public function apply(Collection $proposals, ?int $by = null): int
    {
        $made = 0;

        foreach ($proposals as $p) {
            if ($p['institution'] === null) {
                continue;
            }

            InstitutionAccount::query()->firstOrCreate(
                ['account_id' => $p['account']->id],
                [
                    'company_id' => $p['account']->company_id,
                    'institution_id' => $p['institution']->id,
                    'created_by' => $by,
                ],
            );

            $made++;
        }

        return $made;
    }

    /** কেন মেলে — না মিললে null */
    private function why(Account $account, Institution $institution): ?string
    {
        $name = $this->words($account->name_en.' '.$account->name_bn);
        $inst = $this->words($institution->name_en.' '.$institution->name_bn);

        $full = $this->words((string) $institution->name_en);

        if ($full !== '' && str_contains($name, $full)) {
            return 'name';
        }

        $code = $this->words((string) $institution->short_code);

        if ($code !== '' && $this->hasWord($name, $code)) {
            return 'short_code';
        }

        foreach (self::ALIASES as $alias => $longForm) {
            if ($this->hasWord($name, $alias) && str_contains($inst, $this->words($longForm))) {
                return 'alias:'.$alias;
            }
        }

        return null;
    }

    /**
     * MFS খাত কেবল মোবাইল ব্যাংকিংয়ের; ব্যাংকের খাত ব্যাংক বা আর্থিক প্রতিষ্ঠানের।
     *
     * ⓘ রকেট ডাচ্-বাংলার, কিন্তু রকেটের খাত "Dutch-Bangla Bank"-এর চলতি
     * হিসাবের সাথে এক তালিকায় বসলে ব্যাংকের জের আর MFS-এর জের মিশত —
     * ঠিক যেটা ১১০২/১১০৫ ভাগ করে আলাদা করা হয়েছিল।
     */
    private function kindsFit(Account $account, Institution $institution): bool
    {
        return $account->money_kind === Account::MFS
            ? $institution->kind === Institution::MFS
            : in_array($institution->kind, [Institution::BANK, Institution::NBFI], true);
    }

    /** [[Institution::keyFor]]-এর মতো, আর `-` ফাঁকা — "Dutch-Bangla" আর "Dutch Bangla" এক */
    private function words(string $text): string
    {
        return Institution::keyFor(str_replace('-', ' ', $text));
    }

    private function hasWord(string $haystack, string $word): bool
    {
        return preg_match('/(^|[\s\/()])'.preg_quote($word, '/').'($|[\s\/()])/u', $haystack) === 1;
    }
}
