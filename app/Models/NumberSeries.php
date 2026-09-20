<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Services\NumberSeriesProvisioner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** একটা ডকুমেন্ট টাইপের নম্বর সিরিজ। NumberSeriesEngine ছাড়া কেউ next_number বদলায় না। */
class NumberSeries extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $table = 'number_series';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'module', 'doc_type',
        'prefix', 'suffix', 'format', 'padding', 'next_number', 'start_number',
        'reset_yearly', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_number' => 'integer',
            'start_number' => 'integer',
            'reset_yearly' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * ⛔ বছর ছাড়া ছকে বছর-রিসেট বসতে পারে না — ২০ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ঘটছিল, লাইভে ────────────────────────────────────────────────
     * ছক `{PREFIX}-{SEQ}`, আর `reset_yearly = 1` — ১৬০টা সারির
     * **১৬০টাতেই**। ⓘ বছর বন্ধ হলে গুনতি ১-এ ফেরে, অথচ নম্বরে বছর নেই,
     * তাই নতুন বছরের `INV-0001` পুরনো বছরের `INV-0001`-এর হুবহু সমান।
     * ⚠️ `issued_numbers`-এর unique সেটা আটকায় → লেনদেন rollback → আর
     * rollback-এ `next_number`-এর বাড়াটাও মুছে যায়। ⛔ ফলে সিরিজটা
     * সংঘর্ষটা **কখনো পেরোয় না**: নতুন বছরের প্রথম কাগজটাই কাটা যায় না,
     * যতবারই চেষ্টা হোক।
     *
     * ── ⭐ কেন নিয়মটা এখানে, লেখকদের কাছে নয় ──────────────────────────
     * নিয়মটা কোডে ছিলই — কিন্তু **এক জায়গায়**: নম্বর সিরিজের পর্দা।
     * হাতে বানালে মানা হত, নিজে থেকে বসলে হত না, আর বছর বদলের সময়
     * পুরনো ভুল মানটা হুবহু বয়ে নেওয়া হত। ⓘ তিনজন লেখক, একটা নিয়ম —
     * আর নিয়মটা তিন জায়গায় লিখলে চতুর্থ লেখকের দিন থেকে আবার ভাঙত।
     *
     * ⚠️ এটা নীরব শোধরানো, তাই যেখানে মানুষ ঘরটা টিক দেন সেখানে
     * (`NumberSeriesController`) কারণটা বার্তা দিয়ে বলা হয় — নাহলে
     * ব্যবহারকারী টিক দিয়ে সেভ করে ফাঁকা ঘর দেখতেন আর বুঝতেন না।
     */
    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            if ($series->reset_yearly && ! NumberSeriesProvisioner::resetsWith((string) $series->format)) {
                $series->reset_yearly = false;
            }
        });
    }

    /**
     * পরের নম্বরটা অডিটে যায় না।
     *
     * প্রতিটা ডকুমেন্ট তৈরিতে এটা এক বাড়ে। লগ করলে অডিট তালিকার অর্ধেক
     * সারি হত "next_number: ৬ → ৭", আর তার ফাঁকে কে দর বদলাল সেটা খুঁজে
     * পাওয়া যেত না। নম্বরটা কোথায় গেল তা এমনিতেই জানা — যে ডকুমেন্ট
     * সেটা নিল, সেটা নিজেই অডিটে আছে।
     *
     * উপসর্গ, বিন্যাস বা সিরিজ বন্ধ করা — এগুলো মানুষের সিদ্ধান্ত, আর
     * সেগুলো আগের মতোই লগ হয়।
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return ['next_number'];
    }
}
