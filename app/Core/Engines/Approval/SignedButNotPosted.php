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
            ->get(['id', 'approvable_type', 'approvable_id', 'action', 'state_hash', 'payload']);

        $out = [];

        foreach ($signed->groupBy('approvable_type') as $type => $rows) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                $table = (new $class)->getTable();

                // ⓘ কেবল যে কাগজের "খসড়া" অবস্থা আর কোম্পানি আছে — গ্রাহকের সীমা বা ছকের মতো কাগজ এখানে আসে না
                if (! Schema::hasColumn($table, 'status') || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }

                // ⛔ কোম্পানির ছাঁকনি হাতে — DB::table() গ্লোবাল স্কোপ মানে না ([[EveryRawQueryNamesItsCompanyTest]])
                $drafts = DB::table($table)
                    ->where('company_id', CompanyContext::id())
                    ->whereIn('id', $rows->pluck('approvable_id')->all())
                    ->where('status', DocumentStatus::DRAFT)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->pluck(Schema::hasColumn($table, 'document_no') ? 'document_no' : 'id', 'id')
                    ->all();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $rows = $rows->filter(fn (object $row) => array_key_exists((int) $row->approvable_id, $drafts));

            foreach ($this->stillSigned($class, (string) $type, $rows) as $row) {
                $out[(int) $row->id] = (string) $drafts[(int) $row->approvable_id];
            }
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
