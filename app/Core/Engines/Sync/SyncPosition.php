<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * ⭐ একটা হ্যান্ডলারের তালিকায় "এই পর্যন্ত পাঠানো হয়েছে" — সময় আর id, হ্যান্ডলার যে দুটো ধরে সাজায়
 * (Inventory অডিট গ১৮, ৪ অক্টোবর ২০২৬; [[SyncService::pull()]])।
 *
 * ── ⛔ কী ভাঙা ছিল ────────────────────────────────────────────────────
 * পরের পাতা চাওয়ার কোনো উপায় ছিল না: ফোন যতবার ডাকত, সার্ভার জলচিহ্নের পরের **একই প্রথম ১,০০০** সারি দিত আর
 * বলত "আরও আছে"। জলচিহ্ন এগোয় কেবল পুরোটা পেলে ([[SyncService::recordSuccessfulPull()]]) — তাই ১,০০০-এর বেশি পণ্য বা
 * গ্রাহকের দোকানে বাকিগুলো ফোনে কোনোদিন আসত না, আর `sync_states`-এ কোনোদিন কিছু লেখা হত না।
 *
 * ── কেন সময় + id, শুধু সময় নয় ────────────────────────────────────────
 * একসাথে আমদানি করা ৩,০০০ পণ্যের `updated_at` একই সেকেন্ড। কেবল সময় ধরে "এর পরে" চাইলে বাকি ২,০০০ বাদ পড়ত, আর
 * "এর পরে বা সমান" চাইলে একই ১,০০০ চিরকাল ফিরত। ⭐ (সময়, id) জোড়া প্রতিটা সারিকে আলাদা জায়গা দেয়।
 * ⓘ সময় খালি (NULL) হতে পারে — যেমন খাতায় কিছু নেই এমন গ্রাহকের "বকেয়া শেষ কবে নড়ল"; MySQL-এ NULL সবার আগে বসে, আর
 * এখানেও তাই ধরা হয়।
 */
final class SyncPosition
{
    public function __construct(
        public readonly ?string $at,
        public readonly int $id,
    ) {}

    public static function of(mixed $at, int|string $id): self
    {
        $at = match (true) {
            $at === null || $at === '' => null,
            $at instanceof CarbonInterface => $at->format('Y-m-d H:i:s'),
            default => \Illuminate\Support\Carbon::parse((string) $at)->format('Y-m-d H:i:s'),
        };

        return new self($at, (int) $id);
    }

    /** @return array{at: ?string, id: int} */
    public function toArray(): array
    {
        return ['at' => $this->at, 'id' => $this->id];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw) || ! is_numeric($raw['id'] ?? null)) {
            return null;
        }

        $at = $raw['at'] ?? null;

        return new self(is_string($at) && $at !== '' ? $at : null, (int) $raw['id']);
    }

    /**
     * এই অবস্থানের **পরের** সারিগুলো — `(সময়, id) > (at, id)`, NULL সবার আগে।
     *
     * @param  string  $atSql  সময়ের কলাম বা উপ-প্রশ্ন (যা দিয়ে সাজানো হয়)
     * @param  list<mixed>  $atBindings  উপ-প্রশ্নের বাঁধন
     */
    public function after(Builder|QueryBuilder $query, string $atSql, string $idColumn, array $atBindings = []): void
    {
        $query->where(function ($w) use ($atSql, $idColumn, $atBindings): void {
            if ($this->at === null) {
                $w->whereRaw("({$atSql}) IS NOT NULL", $atBindings)
                    ->orWhere(fn ($x) => $x->whereRaw("({$atSql}) IS NULL", $atBindings)->where($idColumn, '>', $this->id));

                return;
            }

            $w->whereRaw("({$atSql}) > ?", [...$atBindings, $this->at])
                ->orWhere(fn ($x) => $x->whereRaw("({$atSql}) = ?", [...$atBindings, $this->at])->where($idColumn, '>', $this->id));
        });
    }
}
