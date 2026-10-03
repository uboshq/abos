<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Contracts\RepostsAfterRevision;
use App\Core\Contracts\RevisableDocument;
use App\Core\Support\CompanyContext;
use App\Models\DocumentDelivery;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * পোস্ট হওয়া কাগজের সংশোধন — এক লেনদেনে, আগে-পরে দুইটা ছবিসহ (মালিক, ৩ অক্টোবর ২০২৬)।
 *
 * ── ⭐ এক লেনদেন, এই ক্রমে ─────────────────────────────────────────────────
 *   ১ · কারণ লেখা আছে তো — না থাকলে লেনদেন খোলার আগেই থামা
 *   ২ · কাগজের সারিতে তালা, তারপর অবস্থাটা আবার পড়া
 *   ৩ · পোস্ট হয়েছে · এই কোম্পানির · অনুমতি (সুপার অ্যাডমিন, বা ডাকনেওয়ালার নিজের নিয়ম) · মাস খোলা
 *   ৪ · আগের ছবি — মাথা, সারি, খাতার খোলা দাখিলা, মজুদের নিট চলাচল; কাগজটা আগে বেরিয়েছিল কি না
 *   ৫ · কাজ — [[edit()]]-এ: উল্টানো → বদল → আবার বসানো; [[keep()]]-এ ডাকনেওয়ালা নিজে করে
 *   ৬ · নম্বর একই আছে তো · পরের ছবি · কিছু বদলেছে তো
 *   ৭ · সংশোধনের সারি (১, ২, ৩ …), আর কাগজের অডিটে "সংশোধিত"
 * ⛔ যেকোনো ধাপে ব্যতিক্রম মানে পুরোটা ফেরত — সারি নেই, খাতা আগের মতো, কাগজ আগের মতো।
 *
 * ── ⓘ অনুমতির প্রশ্নটা বদলানো যায়, মাসেরটা যায় না ───────────────────────────
 * সমন্বয়কের সিদ্ধান্ত, ৩ অক্টোবর ২০২৬: দুইটা নিয়ম পাশাপাশি — কাউন্টারের কর্মী গেট পাসের আগে
 * নিজের বিক্রি বদলান (২ অক্টোবর), সুপার অ্যাডমিন মাস বন্ধের আগে যেকোনো কাগজ (৩ অক্টোবর)। আর
 * **দুইটাই** সংশোধনের সারি রাখে। তাই `$authorize` দিলে সেটা সুপার অ্যাডমিনের প্রশ্নের **জায়গায়** বসে,
 * কিন্তু পোস্ট-হওয়া, কোম্পানি আর মাসের প্রশ্ন সবসময় এখানেই চলে, তালার ভিতরে।
 *
 * ── ⚠️ এই ফাইল খাতা পড়ে — যাচাইয়ের জন্য, দেখানোর জন্য নয় ──────────────────────
 * ছবিটা একটা কাগজের নিজের দাখিলা, গোটা কোম্পানি ধরে; হেডারে বাছা শাখা এখানে খাটে না — খাটলে
 * অন্য শাখার সারি ছবি থেকে বাদ পড়ত আর "আগে" মিথ্যা বলত।
 */
final class RevisionKeeper
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly PostedEdit $edits,
    ) {}

    /**
     * সাধারণ পথ — কোর উল্টায়, ডাকনেওয়ালা বদলায়, কোর আবার বসায়।
     *
     * @param  callable(Model&RepostsAfterRevision): mixed  $apply  কেবল কাগজের ঘর আর সারি বদলায়
     * @param  (callable(Model&RevisableDocument, User): mixed)|null  $authorize  সুপার অ্যাডমিনের নিয়মের বদলে নিজের নিয়ম — না মানলে ব্যতিক্রম ছুঁড়ুক
     */
    public function edit(
        Model&RepostsAfterRevision $document,
        User $user,
        string $reason,
        callable $apply,
        ?callable $authorize = null,
    ): DocumentRevision {
        return $this->keep($document, $user, $reason, function (Model&RepostsAfterRevision $locked) use ($apply, $reason): void {
            $locked->reverseForRevision((string) __('revision.reversal_narration', [
                'no' => $locked->revisionNumber(),
                'reason' => trim($reason),
            ]));

            $apply($locked);

            /* ⓘ বদলের পরের সত্যিকারের অবস্থা থেকে বসানো — হাতে ধরা পুরনো সম্পর্ক থেকে নয় */
            $locked->refresh();
            $locked->repostAfterRevision();
        }, $authorize);
    }

    /**
     * নিজের উল্টানো আর আবার বসানো যার আছে (যেমন কাউন্টারের সম্পাদনা) — কোর কেবল পাহারা দেয় আর লিখে রাখে।
     *
     * @param  callable(Model&RevisableDocument): mixed  $work  উল্টানো, বদল আর আবার বসানো — পুরোটা
     * @param  (callable(Model&RevisableDocument, User): mixed)|null  $authorize
     */
    public function keep(
        Model&RevisableDocument $document,
        User $user,
        string $reason,
        callable $work,
        ?callable $authorize = null,
    ): DocumentRevision {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => __('revision.reason_required', ['no' => $document->revisionNumber()]),
            ]);
        }

        /*
         * ⓘ ফেরতের পরে হাতের বস্তুটাও আগের মতো — ডাটাবেস ফেরে, কিন্তু PHP-র বস্তু ফেরে না। ⛔ না ফেরালে
         * ডাকনেওয়ালা ব্যর্থ সম্পাদনার পরেও বদলানো অঙ্ক বা নম্বর হাতে ধরে থাকত, আর পরের বার্তায় সেটাই ছাপত।
         */
        $held = $document->getAttributes();

        try {
            return $this->keepLocked($document, $user, $reason, $work, $authorize);
        } catch (\Throwable $e) {
            $document->setRawAttributes($held, true);

            throw $e;
        }
    }

    private function keepLocked(
        Model&RevisableDocument $document,
        User $user,
        string $reason,
        callable $work,
        ?callable $authorize,
    ): DocumentRevision {
        return DB::transaction(function () use ($document, $user, $reason, $work, $authorize) {
            $this->lockFresh($document);

            $this->edits->assertPosted($document);
            $this->edits->assertSameCompany($document);

            if ($authorize === null) {
                $this->edits->assertSuperAdmin($document, $user);
            } else {
                $authorize($document, $user);
            }

            /* ⛔ ঐচ্ছিক নয় — কারো নিজের নিয়মও বন্ধ মাস খোলে না */
            $this->edits->assertPeriodOpen($document);

            $number = $document->revisionNumber();
            $before = $this->snapshot($document);
            $printed = $this->printedBefore($document);

            $work($document);

            $document->refresh();

            if ($document->revisionNumber() !== $number) {
                throw ValidationException::withMessages([
                    'edit' => __('revision.number_changed', ['no' => $number]),
                ]);
            }

            $after = $this->snapshot($document);

            if ($before === $after) {
                throw ValidationException::withMessages([
                    'edit' => __('revision.nothing_changed', ['no' => $number]),
                ]);
            }

            $type = $document->getMorphClass();
            $id = (int) $document->getKey();

            /* ⓘ সংশোধনের ক্রম কাগজপ্রতি — কাগজের সারিতে তালা আছে, তাই দুইজন একই নম্বর পান না */
            $next = 1 + (int) DocumentRevision::query()
                ->where('document_type', $type)
                ->where('document_id', $id)
                ->max('revision_no');

            $revision = DocumentRevision::query()->create([
                'company_id' => (int) $document->getAttribute('company_id'),
                'document_type' => $type,
                'document_id' => $id,
                'document_no' => $number,
                'revision_no' => $next,
                'edited_by' => $user->id,
                'edited_at' => now(),
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
                'printed_before' => $printed,
            ]);

            if (method_exists($document, 'auditAction')) {
                $document->auditAction('revised', $reason);
            }

            return $revision;
        });
    }

    /**
     * কাগজের পুরো ছবি — নিজের অংশ (মডিউল দেয়) আর খাতা-মজুদ (কোর তোলে)।
     *
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>, ledger: list<array<string, mixed>>, stock: list<array<string, mixed>>}
     */
    public function snapshot(Model&RevisableDocument $document): array
    {
        $own = $document->revisionSnapshot();

        $ledger = [];

        foreach ($document->revisionLedgerSources() as [$type, $id]) {
            foreach ($this->openLedgerRows((string) $type, (int) $id) as $row) {
                $ledger[] = $row;
            }
        }

        return [
            'header' => $own['header'] ?? [],
            'lines' => array_values($own['lines'] ?? []),
            'ledger' => $ledger,
            'stock' => $this->netStock($document->revisionStockSources()),
        ];
    }

    /**
     * এই নামের এখনো-না-উল্টানো দাখিলা — [[PostingEngine::reverse()]]-এর একই নিয়মে।
     *
     * ⓘ উল্টো সারি সবসময় যা উল্টায় তার পরে বসে, তাই শেষ উল্টো সারির id-র পরের সারিগুলোই খোলা।
     *
     * @return list<array<string, mixed>>
     */
    public function openLedgerRows(string $sourceType, int $sourceId): array
    {
        $companyId = (int) CompanyContext::id();

        $lastReversal = DB::table('ledger_entries')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType.':reversal')
            ->where('source_id', $sourceId)
            ->max('id');

        $rows = DB::table('ledger_entries as le')
            ->leftJoin('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.company_id', $companyId)
            ->where('le.source_type', $sourceType)
            ->where('le.source_id', $sourceId)
            ->when($lastReversal !== null, fn ($q) => $q->where('le.id', '>', (int) $lastReversal))
            ->orderBy('le.id')
            ->get([
                'le.trx_date', 'le.account_id', 'a.code as account_code', 'a.name_en', 'a.name_bn',
                'le.party_type', 'le.party_id', 'le.branch_id', 'le.cost_center_id',
                'le.debit', 'le.credit', 'le.narration',
            ]);

        $parties = app(PartyRegistry::class)->labelsOf(
            $rows->map(fn ($r) => [(string) $r->party_type, (int) $r->party_id])->all(),
        );

        $bangla = app()->getLocale() === 'bn';

        return $rows->map(fn ($r) => [
            'trx_date' => (string) $r->trx_date,
            'account' => self::label($r->account_code, ($bangla && filled($r->name_bn)) ? $r->name_bn : $r->name_en),
            'party' => $parties[$r->party_type.':'.$r->party_id] ?? null,
            'branch_id' => $r->branch_id === null ? null : (int) $r->branch_id,
            'cost_center_id' => $r->cost_center_id === null ? null : (int) $r->cost_center_id,
            'debit' => (string) $r->debit,
            'credit' => (string) $r->credit,
            'narration' => $r->narration,
        ])->values()->all();
    }

    /**
     * মজুদে কাগজের নিট চলাচল — পণ্য, গুদাম, লট ধরে; শূন্য হয়ে যাওয়া সারি বাদ।
     *
     * ⓘ টেবিলটা নাম ধরে, মডেল ধরে নয় — কোর কোনো মডিউলের নাম জানে না ([[BoundariesTest]])।
     * মজুদের মডিউল না থাকলে খালি।
     *
     * @param  list<array{0: string, 1: int}>  $sources
     * @return list<array<string, mixed>>
     */
    private function netStock(array $sources): array
    {
        if ($sources === [] || ! Schema::hasTable('inv_stock_movements')) {
            return [];
        }

        $sums = ['floor_change', 'reserved_change', 'hold_change', 'free_change', 'free_reserved_change', 'unplaced_change', 'unplaced_free_change'];
        $sums = array_values(array_filter($sums, fn (string $c) => Schema::hasColumn('inv_stock_movements', $c)));

        $query = DB::table('inv_stock_movements as m')
            ->leftJoin('inv_products as p', 'p.id', '=', 'm.product_id')
            ->where('m.company_id', (int) CompanyContext::id())
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$type, $id]) {
                    $q->orWhere(fn ($w) => $w->where('m.source_type', (string) $type)->where('m.source_id', (int) $id));
                }
            })
            ->groupBy('m.product_id', 'm.warehouse_id', 'm.batch_id', 'p.code', 'p.name_en', 'p.name_bn')
            ->orderBy('m.product_id')
            ->orderBy('m.warehouse_id')
            ->orderBy('m.batch_id')
            ->select(['m.product_id', 'm.warehouse_id', 'm.batch_id', 'p.code', 'p.name_en', 'p.name_bn']);

        foreach ($sums as $column) {
            $query->selectRaw("SUM(m.{$column}) as {$column}");
        }

        $bangla = app()->getLocale() === 'bn';
        $out = [];

        foreach ($query->get() as $row) {
            $figures = [];

            foreach ($sums as $column) {
                $value = bcadd((string) ($row->{$column} ?? '0'), '0', 4);

                if (bccomp($value, '0', 4) !== 0) {
                    $figures[$column] = $value;
                }
            }

            if ($figures === []) {
                continue;
            }

            $out[] = [
                'product' => self::label($row->code, ($bangla && filled($row->name_bn)) ? $row->name_bn : $row->name_en),
                'warehouse_id' => (int) $row->warehouse_id,
                'batch_id' => $row->batch_id === null ? null : (int) $row->batch_id,
                ...$figures,
            ];
        }

        return $out;
    }

    /**
     * "১১০১ — নগদ" — কোড বা নাম না থাকলে যা আছে তাই।
     *
     * ⛔ `trim(…, ' —')` নয়: ওটা বাইট ধরে ছাঁটে, আর "—"-এর বাইট বাংলা অক্ষরের শেষ বাইটের সাথে
     * মেলে (ঔ = E0 A6 94) — নামের শেষ অক্ষর ভেঙে যেত।
     */
    private static function label(?string $code, ?string $name): ?string
    {
        $parts = array_values(array_filter([$code, $name], fn ($p) => $p !== null && $p !== ''));

        return $parts === [] ? null : implode(' — ', $parts);
    }

    /**
     * কাগজটা সংশোধনের আগে বেরিয়েছিল কি না — ছাপা, নামানো, পাঠানো বা খোলা ([[PaperTrail]])।
     *
     * ⓘ শেষ ঘটনাটা আর মোট কতবার। ⚠️ PDF-টা নিজে কোথাও জমা থাকে না (ছাপা হয় চাহিদামতো), তাই
     * "আগের রূপ" হলো এই সংশোধনের **আগের ছবি** — আর এই সারিটা প্রমাণ যে ঐ রূপটাই বাইরে গিয়েছিল।
     *
     * @return array<string, mixed>|null
     */
    private function printedBefore(Model&RevisableDocument $document): ?array
    {
        $paper = $document->revisionPaperType();

        if ($paper === null) {
            return null;
        }

        $out = DocumentDelivery::query()
            ->where('document_type', $paper)
            ->where('document_id', (int) $document->getKey());

        $last = (clone $out)->with('user')->orderByDesc('id')->first();

        if ($last === null) {
            return null;
        }

        return [
            'document_type' => $paper,
            'document_id' => (int) $document->getKey(),
            'delivery_id' => (int) $last->id,
            'delivery_public_id' => (string) $last->public_id,
            'how' => (string) $last->how,
            'paper' => (string) $last->paper,
            'variant' => $last->variant,
            'at' => $last->created_at?->format('Y-m-d H:i:s'),
            'by' => $last->created_by === null ? null : (int) $last->created_by,
            'by_name' => $last->user?->name,
            'times' => (clone $out)->count(),
        ];
    }
}
