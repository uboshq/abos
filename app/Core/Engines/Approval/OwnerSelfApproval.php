<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\Drillable;
use App\Core\Contracts\IsAMasterRecord;
use App\Core\Contracts\ShownInOwnerSelfReport;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Services\PermissionSyncer;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ⭐ মালিক নিজে লিখে নিজে পাকা করলেন — স্থায়ী দাগ আর রিপোর্ট (মালিক, ২০ নভেম্বর ২০২৬, "ok": লেখক ≠ অনুমোদনকারী কর্মীদের
 * জন্য; মালিকের উপরে কেউ নেই, তাই তাঁকে আটকানো নয় — দাগ থাকবে, আর আলাদা তালিকা)।
 *
 * ── কোথা থেকে জানা, যা পরে কেউ বদলাতে পারেন না ──────────────────────────
 *  · সইয়ের পথে: অনুরোধের `requested_by` আর সেই অনুরোধে "হ্যাঁ" দেওয়া সইয়ের `user_id` একই মানুষ, নিজের হাতে (কারো হয়ে নয়),
 *    আর অনুরোধটা শেষে অনুমোদিত। ⓘ সিদ্ধান্তের সারি বদলায় না, মোছে না।
 *  · সই ছাড়া পোস্ট: কাগজের `created_by` আর অডিট-খাতার প্রথম "confirmed" সারির `user_id` একই — অডিটের সারি মডেলেই অপরিবর্তনীয়
 *    ([[AuditTrail]])। ⚠️ কাগজে অনুমোদিত অনুরোধ থাকলে কেবল সইয়ের পথ দেখা হয়: শেষ সইয়ের শ্রোতা কাগজ পাকা করে লেখকের নামে
 *    ([[SignedPaperFinisher]]), তাই তখন "confirmed" সারির মানুষটা লেখকই — অন্য কেউ সই দিলেও।
 *  · মানুষটা মালিক কি না: কাগজের কোম্পানিতে `super_admin` রোল (রোল পরে সরালে দাগও সরে — রিপোর্টে লেখা)।
 *
 * ⓘ দেখানো হয় কাগজের পাতার মাথায় ([[ui.page-header]]) আর সইয়ের ইতিহাসের অবস্থার ঘরে ([[approval::inbox.partials.status]]);
 * রিপোর্ট [[App\Modules\Accounts\Reports\OwnerSelfApprovedReport]]।
 */
final class OwnerSelfApproval
{
    /** @var array<class-string, list<string>> */
    private static array $columns = [];

    /** এই অনুরোধে মালিক নিজের অনুরোধে নিজে "হ্যাঁ" দিয়েছেন, আর অনুরোধটা অনুমোদিত */
    public function ofApproval(Approval $approval): bool
    {
        if ($approval->status !== Approval::APPROVED || $approval->requested_by === null) {
            return false;
        }

        return ApprovalDecision::query()
            ->where('approval_id', $approval->getKey())
            ->where('decision', ApprovalDecision::APPROVED)
            ->where('user_id', (int) $approval->requested_by)
            ->whereNull('on_behalf_of')
            ->whereIn('user_id', $this->ownersQuery((int) $approval->company_id))
            ->exists();
    }

    /** এই কাগজটা মালিক নিজে লিখে নিজে অনুমোদন বা পোস্ট করেছেন কি না */
    public function ofPaper(Model $paper): bool
    {
        if (! $paper->exists || ! $this->isPaper($paper)) {
            return false;
        }

        $company = (int) ($paper->getAttribute('company_id') ?? 0);

        if ($company === 0) {
            return false;
        }

        $approved = Approval::query()->withoutGlobalScopes()
            ->where('company_id', $company)
            ->where('approvable_type', $paper->getMorphClass())
            ->where('approvable_id', $paper->getKey())
            ->where('status', Approval::APPROVED)
            ->get();

        if ($approved->isNotEmpty()) {
            return $approved->contains(fn (Approval $a): bool => $this->ofApproval($a));
        }

        $maker = $paper->getAttribute('created_by');

        if ($maker === null) {
            return false;
        }

        $poster = AuditTrail::query()->withoutGlobalScopes()
            ->where('auditable_type', $paper::class)
            ->where('auditable_id', $paper->getKey())
            ->where('action', AuditTrail::CONFIRMED)
            ->orderBy('id')
            ->value('user_id');

        return $poster !== null
            && (int) $poster === (int) $maker
            && in_array((int) $maker, $this->ownersOf($company), true);
    }

    /**
     * এই কোম্পানির মালিকেরা — `super_admin` রোলধারী মানুষ।
     *
     * @return list<int>
     */
    public function ownersOf(int $companyId): array
    {
        return $this->ownersQuery($companyId)->pluck('mr.model_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * ⭐ রিপোর্টের প্রশ্ন — প্রতিটা ধরনের কাগজের দুই পথ (সই, সই ছাড়া পোস্ট) একসাথে, তারপর শাখার দেয়াল।
     *
     * ⓘ কাগজ বিশটার বেশি মডিউলে, আর প্রতিটার টেবিল আলাদা — তাই যে ধরনগুলোতে মালিকের অনুরোধ বা পোস্ট আছে কেবল সেগুলো, প্রতিটা
     * নিজের টেবিলে জোড় লাগিয়ে (নম্বর, তারিখ, শাখা, পক্ষ, অঙ্ক কাগজের নিজের ঘর থেকে)। ⛔ কোনো ঘর না থাকলে খালি — অনুমান নয়।
     *
     * @param  array<string, mixed>  $f  ইঞ্জিনের ছাঁকনি: company_id, from, to, শাখা
     */
    public function reportQuery(array $f): Builder
    {
        $company = (int) $f['company_id'];
        $from = $f['from'].' 00:00:00';
        $to = $f['to'].' 23:59:59';
        $owners = $this->ownersOf($company);
        $parts = [];

        if ($owners !== []) {
            $viaApproval = DB::table('approvals')->where('company_id', $company)->where('status', Approval::APPROVED)
                ->whereIn('requested_by', $owners)->distinct()->pluck('approvable_type')->all();
            $viaPosting = DB::table('audit_trails')->where('company_id', $company)->where('action', AuditTrail::CONFIRMED)
                ->whereIn('user_id', $owners)->whereBetween('created_at', [$from, $to])->distinct()->pluck('auditable_type')->all();

            foreach (array_unique(array_merge($viaApproval, $viaPosting)) as $class) {
                $columns = $this->columnsOf((string) $class);

                if ($columns === null) {
                    continue;
                }

                if (in_array($class, $viaApproval, true)) {
                    $parts[] = $this->approvalPart((string) $class, $columns, $company, $owners, $from, $to);
                }

                if (in_array($class, $viaPosting, true) && in_array('created_by', $columns, true)) {
                    $parts[] = $this->postingPart((string) $class, $columns, $company, $owners, $from, $to);
                }
            }
        }

        $union = array_shift($parts) ?? $this->nothing($company);

        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        /*
         * ⓘ তারিখের পরিসর বাইরের প্রশ্নেও — প্রতিটা ভাগ নিজেও একই ঘরে (সই/পোস্টের মুহূর্ত) একই সীমায় ছাঁকে (সূচি কাজে লাগে), তাই ফল
         *   একই; কিন্তু কোনো ভাগ না থাকলেও (খালি খাতা) পরিসরটা প্রশ্নেই লেখা থাকে, PHP-তে লুকিয়ে নয় ([[ADrawnFilterBoxThatChangesNothingTest]]:
         *   Mac run of e88be43a3 — date_range "মরা" দেখাচ্ছিল, কারণ খালি ডেটাবেসে প্রশ্নটা কেবল খালি UNION)।
         */
        return DB::query()
            ->fromSub($union, 'o')
            ->whereBetween('o.approved_at', [$from, $to])
            ->tap(ReportEngine::branchWall($f, 'o.branch_id'))
            ->orderByDesc('o.approved_at')
            ->orderByDesc('o.source_id')
            ->select('o.*');
    }

    /**
     * সইয়ের পথ — নিজের অনুরোধে নিজের "হ্যাঁ" (একাধিক স্তরে দিলে শেষটা একবার)।
     *
     * @param  list<string>  $columns
     * @param  list<int>  $owners
     */
    private function approvalPart(string $class, array $columns, int $company, array $owners, string $from, string $to): Builder
    {
        $table = (new $class)->getTable();
        $approved = DB::getPdo()->quote(ApprovalDecision::APPROVED);

        return DB::table('approvals as a')
            ->join('approval_decisions as d', fn ($j) => $j->on('d.approval_id', '=', 'a.id')->on('d.user_id', '=', 'a.requested_by'))
            ->join($table.' as p', 'p.id', '=', 'a.approvable_id')
            ->leftJoin('users as wu', 'wu.id', '=', 'a.requested_by')
            ->leftJoin('users as au', 'au.id', '=', 'd.user_id')
            ->where('a.company_id', $company)
            ->where('a.approvable_type', $class)
            ->where('a.status', Approval::APPROVED)
            ->whereIn('a.requested_by', $owners)
            ->where('d.decision', ApprovalDecision::APPROVED)
            ->whereNull('d.on_behalf_of')
            ->whereBetween('d.decided_at', [$from, $to])
            ->whereRaw('d.id = (SELECT MAX(d2.id) FROM approval_decisions d2 WHERE d2.approval_id = a.id AND d2.user_id = a.requested_by'
                .' AND d2.decision = '.$approved.' AND d2.on_behalf_of IS NULL)')
            ->select($this->select($class, $columns, 'd.decided_at', $this->approvedAmountOf($class, $columns), 'approved', $this->typeOfRequest($class, $columns, $company)));
    }

    /**
     * সই ছাড়া পোস্ট — লেখক নিজেই প্রথম "confirmed" (অনুমোদিত অনুরোধ থাকলে এ পথে নয়; উপরের কারণ)।
     *
     * @param  list<string>  $columns
     * @param  list<int>  $owners
     */
    private function postingPart(string $class, array $columns, int $company, array $owners, string $from, string $to): Builder
    {
        $table = (new $class)->getTable();
        $pdo = DB::getPdo();

        return DB::table('audit_trails as t')
            ->join($table.' as p', 'p.id', '=', 't.auditable_id')
            ->leftJoin('users as wu', 'wu.id', '=', 'p.created_by')
            ->leftJoin('users as au', 'au.id', '=', 't.user_id')
            ->where('t.company_id', $company)
            ->where('t.auditable_type', $class)
            ->where('t.action', AuditTrail::CONFIRMED)
            ->whereIn('t.user_id', $owners)
            ->whereColumn('p.created_by', 't.user_id')
            ->whereBetween('t.created_at', [$from, $to])
            ->whereRaw('t.id = (SELECT MIN(t2.id) FROM audit_trails t2 WHERE t2.auditable_type = t.auditable_type'
                .' AND t2.auditable_id = t.auditable_id AND t2.action = '.$pdo->quote(AuditTrail::CONFIRMED).')')
            ->whereNotExists(fn ($q) => $q->from('approvals as a2')
                ->whereColumn('a2.approvable_id', 'p.id')
                ->where('a2.approvable_type', $class)
                ->where('a2.status', Approval::APPROVED))
            ->select($this->select($class, $columns, 't.created_at', $this->amountOf($columns), 'posted'));
    }

    /**
     * দুই পথের একই ঘর, একই ক্রমে — UNION-এর শর্ত।
     *
     * @param  list<string>  $columns
     * @return list<\Illuminate\Contracts\Database\Query\Expression>
     */
    private function select(string $class, array $columns, string $at, string $amount, string $how, ?string $type = null): array
    {
        $pdo = DB::getPdo();
        $date = $this->first($columns, ['trx_date', 'date', 'voucher_date', 'claimed_on', 'deliver_on']);

        return [
            DB::raw(($date !== null ? 'p.'.$date : 'DATE('.$at.')').' as paper_date'),
            DB::raw(($type ?? $pdo->quote($this->typeLabel($class))).' as paper_type'),
            DB::raw((in_array('document_no', $columns, true) ? "COALESCE(p.document_no, CONCAT('#', p.id))" : "CONCAT('#', p.id)").' as document_no'),
            DB::raw($this->partyKeyOf($columns).' as party_key'),
            DB::raw($amount.' as amount'),
            DB::raw('wu.name as writer'),
            DB::raw('au.name as approver'),
            DB::raw($at.' as approved_at'),
            DB::raw($pdo->quote(__('core.owner_self.how_'.$how)).' as how'),
            DB::raw($pdo->quote($this->sourceOf($class)).' as source_type'),
            DB::raw($this->sourceIdOf($class).' as source_id'),
            DB::raw((in_array('branch_id', $columns, true) ? 'p.branch_id' : 'NULL').' as branch_id'),
        ];
    }

    /**
     * কিছু না পেলে — একই ঘরের খালি প্রশ্ন, যাতে ইঞ্জিনের যোগ, খোঁজা আর পাতা আগের মতো চলে।
     * ⓘ কোনো সারি ফেরে না, তবু কোম্পানির ছাঁকনি বসানো — প্রতিটা কাঁচা কোয়েরি নিজের কোম্পানি বলে ([[EveryRawQueryNamesItsCompanyTest]])।
     */
    private function nothing(int $company): Builder
    {
        return DB::table('approvals')->where('company_id', $company)->whereRaw('1 = 0')->selectRaw(
            'NULL as paper_date, NULL as paper_type, NULL as document_no, NULL as party_key, 0 as amount, NULL as writer, NULL as approver,'
            .' NULL as approved_at, NULL as how, NULL as source_type, NULL as source_id, NULL as branch_id'
        );
    }

    /** @param list<string> $columns */
    private function amountOf(array $columns): string
    {
        $col = $this->first($columns, ['amount', 'grand_total', 'total', 'total_amount', 'net_amount', 'net_pay']);

        return $col === null ? 'NULL' : 'p.'.$col;
    }

    /**
     * পক্ষের চাবি "ধরন:id" — ইঞ্জিন নাম বসায় ([[ReportColumn::$partyKey]]); কাগজের নিজের ঘর থেকে, [[PaperParties::keyOf()]]-এর একই ক্রমে।
     *
     * @param  list<string>  $columns
     */
    private function partyKeyOf(array $columns): string
    {
        if (in_array('party_type', $columns, true) && in_array('party_id', $columns, true)) {
            return "CONCAT(p.party_type, ':', p.party_id)";
        }

        foreach (app(PartyRegistry::class)->types() as $type) {
            if (in_array($type.'_id', $columns, true)) {
                return 'CONCAT('.DB::getPdo()->quote($type.':').', p.'.$type.'_id)';
            }
        }

        return 'NULL';
    }

    /** কাগজের ধরনের নাম — খাতার উৎসের নামেই ("ভাউচার", "বিক্রয় চালান"); না থাকলে ক্লাসের নাম */
    private function typeLabel(string $class): string
    {
        $key = 'core.source.'.$this->sourceOf($class);
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : class_basename($class);
    }

    private function sourceOf(string $class): string
    {
        if (is_subclass_of($class, ShownInOwnerSelfReport::class)) {
            return $class::ownerSelfSource()[0];
        }

        return is_subclass_of($class, Drillable::class) ? $class::drillSourceType() : '';
    }

    private function sourceIdOf(string $class): string
    {
        return 'p.'.(is_subclass_of($class, ShownInOwnerSelfReport::class) ? $class::ownerSelfSource()[1] : 'id');
    }

    /**
     * অনুমোদনের অঙ্ক — খালি হলে কাগজের নিজের ঘর থেকে।
     * ⛔ যার অঙ্ক চাবির পেছনে ([[ShownInOwnerSelfReport]]) তার কোনোটাই নয়।
     *
     * @param  list<string>  $columns
     */
    private function approvedAmountOf(string $class, array $columns): string
    {
        return is_subclass_of($class, ShownInOwnerSelfReport::class) ? 'NULL' : 'COALESCE(a.amount, '.$this->amountOf($columns).')';
    }

    /**
     * নিজের নম্বর নেই এমন সারিতে (কর্মীর কাঠামো বদল, ব্যাংকের বদল) ধরনের নাম অনুরোধের কাজ থেকে — "কর্মী" লেখা থাকলে কী অনুমোদন
     * হলো বোঝা যেত না।
     *
     * @param  list<string>  $columns
     */
    private function typeOfRequest(string $class, array $columns, int $company): ?string
    {
        if (in_array('document_no', $columns, true)) {
            return null;
        }

        $pdo = DB::getPdo();
        $cases = DB::table('approvals')->where('company_id', $company)->where('approvable_type', $class)
            ->distinct()->get(['module', 'action'])
            ->map(fn ($r) => 'WHEN a.module = '.$pdo->quote((string) $r->module).' AND a.action = '.$pdo->quote((string) $r->action)
                .' THEN '.$pdo->quote(app(ApprovalLabel::class)->of((string) $r->module, (string) $r->action)))->all();

        return $cases === [] ? null : 'CASE '.implode(' ', $cases).' ELSE '.$pdo->quote($this->typeLabel($class)).' END';
    }

    /**
     * কাগজের টেবিলের ঘরগুলো — কাগজ না হলে (মাস্টার, অচেনা ক্লাস, টেবিল নেই) null।
     *
     * @return list<string>|null
     */
    private function columnsOf(string $class): ?array
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)
            || ! (is_subclass_of($class, Drillable::class) || is_subclass_of($class, ShownInOwnerSelfReport::class))
            || is_subclass_of($class, IsAMasterRecord::class)) {
            return null;
        }

        if (! array_key_exists($class, self::$columns)) {
            try {
                self::$columns[$class] = Schema::getColumnListing((new $class)->getTable());
            } catch (Throwable) {
                self::$columns[$class] = [];
            }
        }

        $columns = self::$columns[$class];

        return in_array('id', $columns, true) ? $columns : null;
    }

    private function isPaper(Model $paper): bool
    {
        return ! $paper instanceof IsAMasterRecord;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $choices
     */
    private function first(array $columns, array $choices): ?string
    {
        foreach ($choices as $choice) {
            if (in_array($choice, $columns, true)) {
                return $choice;
            }
        }

        return null;
    }

    /** `super_admin` রোলধারীদের id-র প্রশ্ন — রোল কোম্পানির (spatie teams), বা সব-কোম্পানির রোল এই কোম্পানিতে দেওয়া */
    private function ownersQuery(int $companyId): Builder
    {
        return DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where(fn ($q) => $q->where('r.company_id', $companyId)
                ->orWhere(fn ($q) => $q->whereNull('r.company_id')->where('mr.company_id', $companyId)))
            ->select('mr.model_id');
    }
}
