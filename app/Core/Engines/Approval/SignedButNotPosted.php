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
        $ids = $this->ids($user);

        return Approval::query()->whereIn('id', $ids === [] ? [0] : $ids);
    }

    public function count(User $user): int
    {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return 0;
        }

        return (int) Cache::remember("abos.signed-unposted.{$companyId}.{$user->id}", 60, fn () => count($this->ids($user)));
    }

    /** @return list<int> */
    private function ids(User $user): array
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
            ->get(['id', 'approvable_type', 'approvable_id']);

        $out = [];

        foreach ($signed->groupBy('approvable_type') as $type => $rows) {
            $class = Relation::getMorphedModel((string) $type) ?? (string) $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                $table = (new $class)->getTable();

                // ⓘ কেবল যে কাগজের "খসড়া" অবস্থা আছে — গ্রাহকের সীমা বা ছকের মতো কাগজ এখানে আসে না
                if (! Schema::hasColumn($table, 'status')) {
                    continue;
                }

                $drafts = DB::table($table)
                    ->whereIn('id', $rows->pluck('approvable_id')->all())
                    ->where('status', DocumentStatus::DRAFT)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->pluck('id')
                    ->all();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($rows as $row) {
                if (in_array($row->approvable_id, $drafts)) {
                    $out[] = (int) $row->id;
                }
            }
        }

        return $out;
    }
}
