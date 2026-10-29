<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\Drillable;
use App\Models\Approval;
use App\Models\NotificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনের কাগজের চিপের দুই কথা — "সইয়ের অপেক্ষায় · ২-এর ১" আর "সই শেষ, পোস্ট হয়নি — কেন" (a6, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ ফোনের তালিকা (ভাউচার, খরচের দাবি) আগে কেবল বলত "সইয়ের অপেক্ষায়" — কোন ধাপে, কয় ধাপের মধ্যে, তা নয়; আর সই শেষ হয়েও
 * পোস্ট আটকালে কাগজটা চুপচাপ "খসড়া" দেখাত, কারণ ছাড়া।
 *
 * ⭐ দুটোই অন্যের হিসাব পড়ে, নিজে কিছু গোনে না:
 *  - ধাপ — অনুমোদনের ইঞ্জিন ([[ApprovalEngine::standingOf()]], [[ApprovalEngine::stepsFor()]]) — ওয়েবের যাত্রাপথের একই স্তর।
 *  - আটকে থাকা — "সই হয়েছে, পোস্ট হয়নি" তালিকার সংজ্ঞা ([[SignedButNotPosted::among()]]); কারণ — শেষকারী যে খবরটা
 *    লেখক আর সইকারীকে পাঠায় ([[SignedPaperFinisher::stuck()]], ঘণ্টার "কারণ: …। কারণটা মিটিয়ে … পোস্ট চাপুন।") — হুবহু সেই লেখা।
 *
 * ⛔ সারি ধরে নয়, পাতা ধরে — প্রতিটা প্রশ্ন এক ডাকে (`whereIn`), ছকগুলো ইঞ্জিনে একবার ([[N+1]])।
 * ⓘ কেবল পড়া; দেয়াল ডাকার দরজার — এখানে যে id আসে সেগুলো দরজা আগেই নিজের দেয়ালে বেছেছে।
 */
final class PaperSigningFacts
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly SignedButNotPosted $signed,
    ) {}

    /**
     * অপেক্ষমাণ সইয়ের ধাপ — কাগজ → `['level' => ১-থেকে ক্রমিক, 'levels' => ছকের স্তর-সংখ্যা]`।
     *
     * ⓘ একই কাগজে একাধিক অপেক্ষমাণ অনুরোধ থাকলে সবচেয়ে নতুনটা (পরে বসা অনুরোধই এখনকার)। ছক না পেলে কাগজটা ফলেই নেই।
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $ids
     * @return array<int, array{level: int, levels: int}>
     */
    public function standing(string $class, array $ids, ?string $module = null): array
    {
        if ($ids === []) {
            return [];
        }

        $pending = Approval::query()
            ->where('approvable_type', (new $class)->getMorphClass())
            ->whereIn('approvable_id', array_values(array_unique($ids)))
            ->when($module !== null, fn ($q) => $q->where('module', $module))
            ->pending()
            ->orderBy('id')
            ->get(['id', 'company_id', 'approvable_type', 'approvable_id', 'module', 'action', 'status', 'current_level']);

        $out = [];

        foreach ($pending as $approval) {
            $standing = $this->engine->standingOf($approval);

            // ⓘ নতুনটা জেতে — পুরনোর ধাপ রেখে দিলে "২-এর ২" দেখাত যখন নতুন অনুরোধ আসলে ধাপ ১-এ
            if ($standing === null) {
                unset($out[(int) $approval->approvable_id]);
            } else {
                $out[(int) $approval->approvable_id] = $standing;
            }
        }

        return $out;
    }

    /**
     * সই শেষ অথচ পোস্ট হয়নি — কাগজ → ওয়েবের ঘণ্টার একই বাংলা কারণ।
     *
     * ⓘ কারণটা কেবল সেই খবর থেকে যা এই সইয়ের পরে লেখা ([[SignedPaperFinisher::NOTICE]], `created_at` ≥ সইয়ের `decided_at`)
     * — ⛔ আগের কোনো সইয়ের পুরনো কারণ আজকের কারণ নয়। খবর না পেলে (শেষকারীর আগের সই, বা খবর বন্ধ) সাধারণ কথা
     * "সই হয়েছে, পোস্ট হয়নি" — ⛔ চুপ নয়, কারণ কাগজটা সত্যিই আটকে আছে।
     *
     * @param  class-string<Model>  $class
     * @param  list<int>  $draftIds  খসড়া কাগজের id — দরজা জানে কোনটা খসড়া
     * @return array<int, string>
     */
    public function postProblems(string $class, array $draftIds): array
    {
        $stuck = $this->signed->among($class, $draftIds);

        if ($stuck === []) {
            return [];
        }

        $events = is_subclass_of($class, Drillable::class)
            ? NotificationEvent::query()
                ->where('type', SignedPaperFinisher::NOTICE)
                ->where('subject_type', $class::drillSourceType())
                ->whereIn('subject_id', array_keys($stuck))
                ->orderBy('id')
                ->get(['id', 'subject_id', 'body', 'created_at'])
                ->groupBy('subject_id')
            : collect();

        $out = [];

        foreach ($stuck as $paperId => $signature) {
            $since = $signature->decided_at === null ? null : Carbon::parse((string) $signature->decided_at);

            $body = collect($events->get($paperId, []))
                ->filter(fn (NotificationEvent $e) => $since === null || ($e->created_at !== null && $e->created_at->gte($since)))
                ->last()?->body;

            $out[(int) $paperId] = is_string($body) && trim($body) !== ''
                ? $body
                : (string) __('approval::menu.signed_unposted');
        }

        return $out;
    }
}
