<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\RevisionKeeper;
use App\Core\Support\DocumentStatus;
use App\Models\DocumentRevision;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * [[App\Core\Contracts\RevisableDocument]]-এর সাধারণ অংশ — "মাথা + সারি + উৎস ধরে খাতা" (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ মডিউলকে লিখতে হয় কেবল [[revisionLedgerSources()]] (খাতায় কোন নামে বসে) — আর কোর নিজে উল্টাবে-বসাবে
 * ([[App\Core\Contracts\RepostsAfterRevision]]) তো [[repostAfterRevision()]]-ও (কীভাবে আবার বসে, নিজের
 * পোস্টিংয়ের যাচাইসহ)। বাকি সব এখানে, আর যার কাগজ আলাদা সে মেথডটা নিজে লিখে দেয়:
 *
 *   সারির সম্পর্ক     `$revisionLines` (ডিফল্ট `lines`; সারি না থাকলে `null`)
 *   তারিখের ঘর        `$revisionDateColumn` (ডিফল্ট `trx_date`)
 *   বাদ দেওয়া ঘর      [[revisionIgnores()]] · [[revisionLineIgnores()]]
 *   সারির বাড়তি নাম   [[revisionLineExtras()]] (যেমন খাতের নাম, কেবল id নয়)
 *   মজুদ              [[revisionStockSources()]] আর [[reverseForRevision()]] — মজুদ ছুঁলে নিজে লিখুন
 *
 * ⚠️ ছবিতে কাঁচা ডাটাবেসের মান বসে (`getAttributes()`), cast করা মান নয় — তারিখ `2026-10-03`,
 * টাকা `500.0000`। ⛔ cast করা মান বসালে তারিখ ISO-লেখা হত আর একই মানের দুই ছবি "বদলেছে" দেখাত।
 */
trait KeepsRevisions
{
    /**
     * @return list<array{0: string, 1: int}>
     */
    abstract public function revisionLedgerSources(): array;

    /** @return array{header: array<string, scalar|null>, lines: list<array<string, scalar|null>>} */
    public function revisionSnapshot(): array
    {
        $skip = array_merge(
            ['id', 'public_id', 'company_id', 'created_at', 'updated_at', 'deleted_at'],
            $this->getHidden(),
            $this->revisionIgnores(),
        );

        $lines = [];
        $relationName = property_exists($this, 'revisionLines') ? $this->revisionLines : 'lines';

        if ($relationName !== null && method_exists($this, $relationName)) {
            $relation = $this->{$relationName}();

            $lineSkip = array_merge(
                ['id', 'public_id', 'company_id', 'created_at', 'updated_at', 'deleted_at'],
                $relation instanceof HasMany ? [$relation->getForeignKeyName()] : [],
                $this->revisionLineIgnores(),
            );

            /* ⚠️ নতুন কোয়েরি, তোলা সম্পর্ক নয় — তালার পরে আর বদলের পরে সত্যিকারের অবস্থা */
            foreach ($relation->getQuery()->with($this->revisionLineWith())->get() as $line) {
                $lines[] = array_merge(
                    self::plainRevisionValues($line->getAttributes(), array_merge($lineSkip, $line->getHidden())),
                    $this->revisionLineExtras($line),
                );
            }
        }

        return [
            'header' => self::plainRevisionValues($this->getAttributes(), $skip),
            'lines' => $lines,
        ];
    }

    /** @return list<array{0: string, 1: int}> */
    public function revisionStockSources(): array
    {
        return [];
    }

    public function revisionDate(): ?CarbonInterface
    {
        $column = property_exists($this, 'revisionDateColumn') ? $this->revisionDateColumn : 'trx_date';
        $value = $this->getAttribute($column);

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value : Carbon::parse((string) $value);
    }

    public function revisionNumber(): string
    {
        return (string) $this->getAttribute('document_no');
    }

    public function isPostedForRevision(): bool
    {
        return in_array($this->getAttribute('status'), DocumentStatus::POSTED, true);
    }

    public function revisionPaperType(): ?string
    {
        return null;
    }

    /**
     * খাতা উল্টানো — কাগজের **নিজের তারিখে**, আজকের তারিখে নয়।
     *
     * ⭐ কেন নিজের তারিখে: সংশোধনটা ঐ মাসেরই হিসাব ঠিক করে। ⛔ আজকের তারিখে উল্টালে বন্ধ মাস
     * খুলে করা সংশোধনেও পুরনো মাসের অঙ্ক বদলাত না — উল্টো সারি বসত চলতি মাসে, আর মাস খোলা-বন্ধের
     * পুরো নিয়মটাই অর্থহীন হত।
     *
     * ⓘ কেবল যে নামের খোলা দাখিলা আছে সেটাই উল্টায় — শূন্য টাকার কাগজ বা যার খাতা কখনো বসেনি,
     * তাকে উল্টাতে গেলে ইঞ্জিন থামত।
     */
    public function reverseForRevision(string $reason): void
    {
        $keeper = app(RevisionKeeper::class);
        $posting = app(PostingEngine::class);

        foreach ($this->revisionLedgerSources() as [$type, $id]) {
            if ($keeper->openLedgerRows((string) $type, (int) $id) === []) {
                continue;
            }

            $posting->reverse((string) $type, (int) $id, $this->revisionDate() ?? Carbon::today(), $reason);
        }
    }

    /**
     * এই কাগজের সংশোধনগুলো, পুরনো থেকে নতুন।
     *
     * @return \Illuminate\Database\Eloquent\Builder<DocumentRevision>
     */
    public function revisions()
    {
        return DocumentRevision::query()->forDocument($this)->orderBy('revision_no');
    }

    /** @return list<string> মাথার যে ঘর ছবিতে বসবে না */
    protected function revisionIgnores(): array
    {
        return [];
    }

    /** @return list<string> সারির যে ঘর ছবিতে বসবে না */
    protected function revisionLineIgnores(): array
    {
        return [];
    }

    /**
     * সারির সাথে একবারে যা তোলা হবে — [[revisionLineExtras()]] যা পড়ে।
     *
     * ⚠️ সারিপ্রতি আলাদা কোয়েরি নয়: স্থানীয়ভাবে `preventLazyLoading` চালু, আর পঞ্চাশ সারির বিলে
     * পঞ্চাশটা কোয়েরি হত।
     *
     * @return list<string>
     */
    protected function revisionLineWith(): array
    {
        return [];
    }

    /** @return array<string, scalar|null> সারির পাশে মানুষের পড়ার মতো নাম — খাত, পণ্য */
    protected function revisionLineExtras(Model $line): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $skip
     * @return array<string, scalar|null>
     */
    private static function plainRevisionValues(array $attributes, array $skip): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $out[$key] = match (true) {
                $value === null, is_scalar($value) => $value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                default => json_encode($value, JSON_UNESCAPED_UNICODE),
            };
        }

        ksort($out);

        return $out;
    }
}
