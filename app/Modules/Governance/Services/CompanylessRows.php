<?php

declare(strict_types=1);

namespace App\Modules\Governance\Services;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\ErrorEvent;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * কোম্পানিহীন লগ — কে দেখবেন, সেই প্রশ্নের একমাত্র উত্তর।
 *
 * ── ⛔ যা ভাঙা ছিল (অডিট ২৭ সেপ্টেম্বর ২০২৬, §৩) ─────────────────────
 * ভুলের খাতা আর ঢোকার খাতায় `company_id` খালি থাকে যখন ঘটনাটা কোম্পানি
 * বসার **আগে** ঘটে: লগইনের পর্দায় ভাঙন, অচেনা নামে লগইন। ⚠️ দুইটা পর্দা
 * নিজের নিজের হাতে `orWhereNull('company_id')` লিখত, তাই ঐ সারিগুলো
 * **প্রতিটা কোম্পানির** খাতা-দেখার লোক পড়তে পারতেন — ভুলের বার্তায়
 * অন্য কোম্পানির তথ্য, আর অচেনা নামের চেষ্টায় আক্রমণকারীর আইপি।
 *
 * ── ⭐ কেন এক জায়গায় ────────────────────────────────────────────────
 * ছাঁকনিটা আগে ছয়টা কোয়েরিতে হাতে লেখা ছিল (তালিকা, মাথার সংখ্যা,
 * ড্রপডাউন, "দেখেছি")। ⓘ একটা জায়গায় বদলে আরেকটা ভুলে গেলে ফাঁকটা
 * সংখ্যা বা ড্রপডাউন দিয়ে ফিরে আসত। তাই প্রতিটা টেবিলের জন্য একটাই
 * পদ্ধতি, আর পর্দাগুলো কেবল এটাকেই ডাকে।
 *
 * ⭐ পাহারা: [[CompanylessRowsAnswerOnlyHereTest]] — `app/`-এর অন্য কোথাও
 * কোম্পানিহীন সারি তোলা নিষেধ, কারণসহ ছাড় ছাড়া।
 */
final class CompanylessRows
{
    /**
     * দর্শক কি **এই** কোম্পানির সুপার অ্যাডমিন?
     *
     * ── ⚠️ কেন `$user->roles` নয়, সরাসরি কোয়েরি ─────────────────────
     * spatie teams-এ `roles` সম্পর্কটা যে মুহূর্তে লোড হয় সেই কোম্পানির
     * রোল ধরে রাখে। ⓘ একই `User` বস্তু দুই অনুরোধে বা প্রসঙ্গ বদলের পরে
     * আগের কোম্পানির রোল দেখাতে পারে — আর এখানে ভুল উত্তর মানে অন্যের
     * খাতা খোলা। ⭐ তাই প্রশ্নটা প্রতিবার ডাটাবেসে, চলতি কোম্পানি ধরে
     * ([[Ownership::activeOwnersIn()]]-এর একই ছাঁচ)।
     *
     * ⛔ দর্শক বা কোম্পানি না থাকলে উত্তর "না" — দরজা বন্ধ দিকেই ভাঙে।
     */
    public function viewerSeesThem(?User $viewer): bool
    {
        $company = CompanyContext::id();

        if ($viewer === null || $company === null) {
            return false;
        }

        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', User::class)
            ->where('mhr.model_id', $viewer->getKey())
            ->where('mhr.company_id', $company)
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->exists();
    }

    /**
     * ভুলের খাতা — এই কোম্পানির ভুল, আর কোম্পানিহীনগুলো কেবল সুপার অ্যাডমিনকে।
     *
     * ⓘ কোম্পানিহীন ভুলগুলো লুকিয়ে ফেলা হয়নি, কারণ ওগুলোই প্রায়ই সবচেয়ে
     * গুরুতর (লগইন বা প্রসঙ্গ বসানোর ব্যবস্থাটাই ভাঙা)। ⚠️ কিন্তু ওগুলো
     * গোটা ব্যবস্থার, তাই দেখেন কেবল যাঁর হাতে সবচেয়ে উঁচু চাবি।
     *
     * @return Builder<ErrorEvent>
     */
    public function errors(?User $viewer): Builder
    {
        $company = CompanyContext::id();
        $companyless = $this->viewerSeesThem($viewer);

        return ErrorEvent::query()->where(fn (Builder $q) => $q
            ->where('company_id', $company)
            ->when($companyless, fn (Builder $q) => $q->orWhereNull('company_id')));
    }

    /**
     * ঢোকার খাতা — এই কোম্পানির সারি, এই কোম্পানির মানুষের নামে কোম্পানিহীন
     * চেষ্টা, আর অচেনা নামের চেষ্টা কেবল সুপার অ্যাডমিনকে।
     *
     * ── তিনটা ভাগ, আর প্রতিটার কারণ ───────────────────────────────────
     * ⓵ `company_id` = এই কোম্পানি — নিজের খাতা, সবাই (চাবিসহ) দেখেন।
     * ⓶ কোম্পানিহীন, কিন্তু নামটা **এই কোম্পানির কারো** — তালা পড়া
     *    চেষ্টা ([[CredentialCheck]] তখন ব্যবহারকারী দেয় না) বা চলতি
     *    কোম্পানি না-বাছা মানুষ। ⭐ কেউ আপনার লোকের নামে বারবার চেষ্টা
     *    করলে সেটা আপনারই জানার কথা (২১ সেপ্টেম্বরের নকশা, অক্ষত)।
     * ⓷ কোম্পানিহীন, আর নামটা **কারোই নয়** — আক্রমণকারীর বানানো নাম,
     *    আর আইপিটাও তার। ⛔ আগে এটা সবাই দেখতেন; ⭐ এখন কেবল সুপার
     *    অ্যাডমিন (অডিট ২৭ সেপ্টেম্বর, §৩)।
     *
     * ⚠️ অন্য কোম্পানির মানুষের নামে কোম্পানিহীন চেষ্টা কেউই দেখেন না —
     * সুপার অ্যাডমিনও নন; ওটা ঐ কোম্পানির কথা।
     *
     * @return Builder<LoginAttempt>
     */
    public function logins(?User $viewer): Builder
    {
        $company = CompanyContext::id();
        $companyless = $this->viewerSeesThem($viewer);

        return LoginAttempt::query()->where(fn (Builder $q) => $q
            ->where('company_id', $company)
            ->orWhere(fn (Builder $w) => $w->whereNull('company_id')
                ->where(fn (Builder $who) => $who
                    ->whereIn('identifier', $this->identifiersHere())
                    ->when($companyless, fn (Builder $who) => $who
                        ->orWhereNotIn('identifier', $this->identifiersAnywhere())))));
    }

    /**
     * এই কোম্পানির মানুষগুলো লগইনে যা যা লেখেন।
     *
     * ⓘ [[LoginHistoryController]] থেকে সরানো, বদলানো নয় — কেন লাগে তার
     * পুরো ইতিহাস সেখানে ছিল (২১ সেপ্টেম্বর ২০২৬): লগইনের সারিতে
     * `company_id` খালি থাকে ঠিক তখনই যখন ইমেইলটা চেনা যায়নি বা
     * ব্যবহারকারী দেওয়া হয়নি, আর সারিটায় ইমেইল ও আইপি দুইটাই বসে।
     *
     * @return list<string>
     */
    private function identifiersHere(): array
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->get(['email', 'login_id'])
            ->flatMap(fn (User $u) => [$u->email, $u->login_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * গোটা ইনস্টলেশনের সব নাম — কেবল ছাঁকার জন্য, দেখানোর জন্য নয়।
     *
     * ⓘ তালিকাটা কেবল `whereNotIn`-এ যায় — একটা নামও পর্দায় ওঠে না।
     * ⚠️ "নামটা অন্য কোম্পানির কারো" আর "নামটা কারোই নয়" আলাদা করতে এটা
     * লাগে; প্রথমটা লুকানো ঠিক, দ্বিতীয়টা সুপার অ্যাডমিনের।
     *
     * @return list<string>
     */
    private function identifiersAnywhere(): array
    {
        return User::query()
            ->withoutGlobalScopes()
            ->get(['email', 'login_id'])
            ->flatMap(fn (User $u) => [$u->email, $u->login_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
