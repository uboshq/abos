<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ⭐ "সই হয়েছে, পোস্ট হয়নি" — মালিক, ১১ অক্টোবর ২০২৬: *"tookhon khuje ber korle somoy lage tai ekta icon"* (ক)।
 *
 * ⓘ শেষ সইয়ে কাগজ নিজেই পোস্ট হয় ([[SignedPaperFinisher]]); যা আটকে যায় (বাকির সীমা, মাস বন্ধ, মজুদ…) সেগুলো এখানে — টপবারের
 * আইকনে সংখ্যা, চাপলে তালিকা। ⭐ আলাদা কোনো খাতা নেই: সই APPROVED অথচ কাগজ এখনো খসড়া — এটাই সংজ্ঞা, তাই কোনো সারি হারায়
 * না, আর হাতে পোস্ট করলেই নিজে থেকে তালিকা থেকে নামে।
 *
 * ⓘ কে কী দেখেন: সই দেন বা অনুমোদনের রিপোর্ট দেখেন যিনি — গোটা কোম্পানির; বাকিরা কেবল নিজের পাঠানো কাগজ।
 */
final class SignedButNotPosted
{
    /** কত পুরনো সই পর্যন্ত — তার আগের পড়ে থাকা কাগজ অন্য প্রশ্ন (খসড়ার রিপোর্ট) */
    private const DAYS = 90;

    /** @return Builder<Approval> */
    public function query(User $user): Builder
    {
        $ids = array_keys($this->papers($user));

        return Approval::query()->whereIn('id', $ids === [] ? [0] : $ids);
    }

    public function count(User $user): int
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return 0;
        }

        return (int) Cache::remember("abos.signed-unposted.{$companyId}.{$user->id}", 60, fn () => count($this->papers($user)));
    }

    /**
     * সই → কাগজের নম্বর (তালিকায় কাগজটা চেনার জন্য)।
     *
     * @return array<int, string>
     */
    public function papers(User $user): array
    {
        $companyId = CompanyContext::id();

        if ($companyId === null || ! Schema::hasTable('approvals')) {
            return [];
        }

        $signed = DB::table('approvals')
            ->where('company_id', $companyId)
            ->where('status', Approval::APPROVED)
            ->where('decided_at', '>=', now()->subDays(self::DAYS))
            ->when(! ($user->can('approval.decide') || $user->can('approval.report')),
                fn ($q) => $q->where('requested_by', $user->id))
            ->get(['id', 'approvable_type', 'approvable_id', 'action', 'state_hash', 'payload', 'requested_by']);

        /*
         * ⛔ শাখার নাগাল — কাগজ খোলার একই নিয়মে ([[OpensInReach::opensFor()]]; লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬, fe-র প্রশ্নে ধরা)।
         * ⓘ আগে কেবল কোম্পানি দেখা হত: শাখা-সীমিত সইদাতার সংখ্যায় অন্য শাখার আটকে-থাকা কাগজও গোনা হত — বারের ব্যাজ বাড়ত, আর
         * তালিকায় এমন কাগজ আসত যা তিনি খুলতেই পারেন না। ⭐ এখন গোনা হয় যদি কাগজের শাখা তাঁর নাগালে (বা শাখাহীন), নয়তো তিনি নিজে
         * সেটায় সই দিয়েছেন বা নিজেই চেয়েছিলেন — খোলার নিয়মের সইকারীর ছাড়ের মতো।
         */
        $scope = app(\App\Core\Services\DataScope::class);
        // ⓘ সইয়ের সারিতে কোম্পানির ঘর নেই — কোম্পানি আসে উপরের অনুমোদনগুলো থেকে (সেগুলো কোম্পানি ধরে ছাঁকা), তাই মডেলে, কাঁচা টেবিলে নয়
        $decided = $signed->isEmpty() ? [] : array_flip(\App\Models\ApprovalDecision::query()
            ->where('user_id', $user->id)
            ->whereIn('approval_id', $signed->pluck('id')->all())
            ->pluck('approval_id')
            ->map(fn ($id) => (int) $id)
            ->all());
        $inReach = function (object $row, ?int $branch) use ($scope, $user, $decided): bool {
            return $branch === null
                || $scope->allows($user, \App\Models\UserDataScope::BRANCH, $branch)
                || (int) $row->requested_by === (int) $user->id
                || isset($decided[(int) $row->id]);
        };

        $out = [];

        foreach ($signed->groupBy('approvable_type') as $type => $rows) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            // ⓘ সইয়ের পরে যার পোস্ট নেই (দলিল) সে এখানে নয় — নাহলে চিরকাল তালিকায় ([[NothingToPostAfterSigning]], ১৩ অক্টোবর ২০২৬)
            if (is_subclass_of($class, \App\Core\Contracts\NothingToPostAfterSigning::class)) {
                continue;
            }

            try {
                $table = (new $class)->getTable();

                // ⓘ কেবল যে কাগজের "খসড়া" অবস্থা আর কোম্পানি আছে — গ্রাহকের সীমা বা ছকের মতো কাগজ এখানে আসে না
                if (! Schema::hasColumn($table, 'status') || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }

                // ⛔ কোম্পানির ছাঁকনি হাতে — DB::table() গ্লোবাল স্কোপ মানে না ([[EveryRawQueryNamesItsCompanyTest]])
                $label = Schema::hasColumn($table, 'document_no') ? 'document_no' : 'id';
                $hasBranch = Schema::hasColumn($table, 'branch_id');
                $found = DB::table($table)
                    ->where('company_id', CompanyContext::id())
                    ->whereIn('id', $rows->pluck('approvable_id')->all())
                    ->where('status', DocumentStatus::DRAFT)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->get(array_values(array_unique(array_filter(['id', $label, $hasBranch ? 'branch_id' : null]))))
                    ->keyBy(fn (object $d) => (int) $d->id);
                $drafts = $found->map(fn (object $d) => $d->{$label})->all();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $rows = $rows->filter(fn (object $row) => array_key_exists((int) $row->approvable_id, $drafts)
                && $inReach($row, isset($found[(int) $row->approvable_id]->branch_id) ? (int) $found[(int) $row->approvable_id]->branch_id : null));

            foreach ($this->stillSigned($class, (string) $type, $rows) as $row) {
                $out[(int) $row->id] = (string) $drafts[(int) $row->approvable_id];
            }
        }

        return $out;
    }

    /**
     * ⭐ এই খসড়াগুলোর কোনটা "সই হয়েছে, পোস্ট হয়নি" — কাগজ → তার সই (a6, ২০ অক্টোবর ২০২৬; ফোনের কাগজের চিপ)।
     *
     * ⓘ [[papers()]]-এর অবিকল একই সংজ্ঞা — ৯০ দিনের APPROVED সই, কাগজ খসড়া, আর সইটা এখনো কাগজটাকে ঢাকে ([[stillSigned()]])
     * — কেবল এক ধরনের কয়েকটা কাগজে, পাতা ধরে এক ডাকে (সারি ধরে নয়)। ⓘ কোনটা খসড়া তা ডাকার দরজা জানে (কাগজ তার হাতেই),
     * তাই এখানে কেবল খসড়ার id আসে — আবার তোলা হয় না।
     * ⛔ মানুষ ধরে ছাঁকা নেই: কে কোন কাগজ দেখেন, তা ডাকার দরজার নিজের দেয়াল ঠিক করে ([[VoucherApiController::visible()]])।
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $draftIds  খসড়া কাগজের id
     * @return array<int, object>  কাগজের id → সইয়ের সারি (`id`, `decided_at`, …)
     */
    public function among(string $class, array $draftIds): array
    {
        $companyId = CompanyContext::id();

        if ($draftIds === [] || $companyId === null || is_subclass_of($class, \App\Core\Contracts\NothingToPostAfterSigning::class)) {
            return [];
        }

        $type = (new $class)->getMorphClass();

        // ⛔ কোম্পানির ছাঁকনি হাতে — DB::table() গ্লোবাল স্কোপ মানে না ([[EveryRawQueryNamesItsCompanyTest]])
        $signed = DB::table('approvals')
            ->where('company_id', $companyId)
            ->where('status', Approval::APPROVED)
            ->where('decided_at', '>=', now()->subDays(self::DAYS))
            ->where('approvable_type', $type)
            ->whereIn('approvable_id', array_values(array_unique($draftIds)))
            ->orderBy('id')
            ->get(['id', 'approvable_type', 'approvable_id', 'action', 'state_hash', 'payload', 'decided_at']);

        $out = [];

        foreach ($this->stillSigned($class, $type, $signed) as $row) {
            $out[(int) $row->approvable_id] = $row;
        }

        return $out;
    }

    /**
     * ⭐ কেবল যে সই এখনো কাগজটাকে ঢাকে — হিসাবের নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
     *
     * ⛔ আগে সই APPROVED আর কাগজ খসড়া হলেই তালিকায়। কিন্তু সইয়ের পরে লেখক কাগজটা বদলালে (খাত, তারিখ, পক্ষ, সারি) পোস্ট
     * নতুন সই চায় ([[DocumentApproval::stopping()]], [[Approval::stillCovers()]]) — অথচ তালিকা বলত "সই হয়েছে, কেবল
     * পোস্ট বাকি", আর নতুন অনুরোধ বসার পরেও পুরনো সইয়ের জোরে কাগজটা এখানে থাকত।
     *
     * ⓘ বাদ যায়: (১) একই কাগজ-কাজে পরে আরেকটা অনুরোধ বসেছে — পুরনো সইয়ের জায়গা সে নিয়েছে; (২) সইয়ের ছাপ আর কাগজের
     * এখনকার ছাপ মেলে না। ⓘ ছাপ ছাড়া পুরনো সই আগের মতোই থাকে — [[Approval::stillCovers()]]-এর একই নিয়ম।
     * ⓘ বদলে যাওয়া কাগজ নিরীক্ষার পর্দায় আলাদা দেখা যায় ([[ApprovalExceptions::changedAfterSigning()]])।
     *
     * @param  class-string<Model>  $class
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function stillSigned(string $class, string $type, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        // ⛔ কোম্পানির ছাঁকনি হাতে — DB::table() গ্লোবাল স্কোপ মানে না ([[EveryRawQueryNamesItsCompanyTest]])
        $latest = DB::table('approvals')
            ->where('company_id', CompanyContext::id())
            ->where('approvable_type', $type)
            ->whereIn('approvable_id', $rows->pluck('approvable_id')->unique()->values()->all())
            ->groupBy('approvable_id', 'action')
            ->selectRaw('approvable_id, action, MAX(id) AS last_id')
            ->get()
            ->mapWithKeys(fn (object $r) => [$r->approvable_id.'|'.$r->action => (int) $r->last_id]);

        return $rows->filter(function (object $row) use ($class, $latest): bool {
            if (($latest[$row->approvable_id.'|'.$row->action] ?? (int) $row->id) !== (int) $row->id) {
                return false;
            }

            if ($row->state_hash === null) {
                return true;
            }

            try {
                $paper = $class::query()->withoutGlobalScope('user-branch')->find((int) $row->approvable_id);

                // ⓘ সারিটা নিজেই বলে কোন ছাপে সই বসেছিল ([[DocumentFingerprint::forApproval()]]) — ছাড়ের সই গোটা বিলের ছাপ নেয় না
                $signed = (new Approval)->forceFill(['payload' => json_decode((string) ($row->payload ?? 'null'), true)]);

                return $paper !== null
                    && hash_equals((string) $row->state_hash, app(DocumentFingerprint::class)->forApproval($signed, $paper));
            } catch (Throwable $e) {
                report($e);

                // ⓘ ছাপ নেওয়া না গেলে আগের মতো তালিকায় — চুপচাপ লুকানোর চেয়ে দেখানো ভালো
                return true;
            }
        });
    }
}
