<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * পোস্ট হওয়া একটা কাগজের একটা সংশোধন — কী ছিল, কী হলো, কে, কখন, কেন (মালিক, ৩ অক্টোবর ২০২৬)।
 *
 * *"প্রত্যেকটা এডিটের আগের আর পরের রূপ থাকবে, যাতে কেউ চ্যালেঞ্জ করলে দেখানো যায়।"*
 *
 * ── ⛔ সারিটা প্রমাণ — বসে, কখনো বদলায় না, মোছেও না ────────────────────────
 * বদলানো গেলে "আগে" ঘরটা যে কেউ নতুন অঙ্কে মিলিয়ে দিতে পারতেন, আর প্রশ্ন উঠলে দেখানোর মতো কিছু
 * থাকত না। ⓘ তাই মডেলেই `updating` আর `deleting` থামানো ([[ApprovalDecision]]-এর একই ছাঁচ)।
 * ⚠️ এটা Eloquent-এর পথ পাহারা দেয়; কাঁচা `DB::table()->update()` এই টেবিলে কোথাও লেখা নেই, আর
 * থাকা চলবে না।
 *
 * ⓘ `IsAudited` সারিটা **বসার** দাগ রাখে — কে বসালেন, কোন খাতায়। আগে-পরের বড় JSON অডিটে যায় না
 * (তৈরির অডিট ঘর ধরে লেখে না)।
 */
class DocumentRevision extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'document_revisions';

    protected $fillable = [
        'company_id', 'document_type', 'document_id', 'document_no', 'revision_no',
        'edited_by', 'edited_at', 'reason', 'before', 'after', 'printed_before',
    ];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'revision_no' => 'integer',
            'edited_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'printed_before' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision) {
            throw new \RuntimeException(
                '⛔ সংশোধনের সারি বদলানো যায় না — '.$revision->document_no.' #'.$revision->revision_no
                .'। এটা প্রমাণ; ভুল হলে কাগজটা আবার সংশোধন করুন, নতুন সারি বসবে।'
            );
        });

        static::deleting(function (self $revision) {
            throw new \RuntimeException(
                '⛔ সংশোধনের সারি মোছা যায় না — '.$revision->document_no.' #'.$revision->revision_no.'।'
            );
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeForDocument(Builder $query, Model $document): Builder
    {
        return $query
            ->where('document_type', $document->getMorphClass())
            ->where('document_id', (int) $document->getKey());
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
