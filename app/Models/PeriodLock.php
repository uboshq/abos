<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * বন্ধ করে দেওয়া একটা মাস — খাতা এই মাসে আর নড়ে না।
 *
 * ── কেন সারি, আর "খোলা" সারি কেন নেই ────────────────────────────────
 * সারি না থাকা মানেই মাসটা খোলা। শুরু থেকে প্রতিটা মাসের জন্য "খোলা"
 * সারি বসালে ওগুলো কিছুই বলত না, আর অনুপস্থিতিই এখানে সৎ উত্তর:
 * **কেউ এই মাসটা বন্ধ করেনি।**
 *
 * ── কেন কোম্পানি-ব্যাপী, শাখা ধরে নয় ────────────────────────────────
 * শাখা ধরে তালা দিলে এক শাখায় বন্ধ আর আরেকটায় খোলা মাস হত। তখন
 * আন্তঃশাখা স্থানান্তরের দুই পা আলাদা হয়ে যেত, ট্রায়াল ব্যালেন্স মিলত
 * না, আর একত্র করা রিপোর্ট ছাপার পরেও বদলাতে পারত।
 *
 * শাখার নিজের দায়বদ্ধতা দরকার হলে সেটা আলাদা জিনিস — "আমার মাস শেষ"
 * বলে চিহ্ন দেওয়া, তালা নয়।
 */
class PeriodLock extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'period_locks';

    protected $fillable = [
        'company_id', 'year', 'month', 'reason', 'locked_by', 'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** "আগস্ট ২০২৬" — ব্যবহারকারীর ভাষায়, বার্তায় বসানোর জন্য। */
    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)
            ->locale(app()->getLocale())
            ->isoFormat('MMMM YYYY');
    }
}
