<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * পণ্যের ব্র্যান্ড — "নেসলে", "প্রাণ", "ইউনিলিভার"।
 *
 * ── কেন এটা এখন একটা সারি, আগে যা ছিল না ────────────────────────────
 * আগে `inv_products.brand` ছিল ১২০ অক্ষরের একটা মুক্ত লেখার ঘর, আর
 * ইমপোর্টেও ওভাবেই আসত। ফলে একই ব্র্যান্ড কয়েক বানানে বসত: "Nestle",
 * "nestle", "Nestlé", "নেসলে"। রোজকার কাজে কেউ টের পায় না — পণ্যের
 * পাতায় লেখাটা ঠিকই দেখায়।
 *
 * টের পাওয়া যেত ঠিক যেদিন কেউ "ব্র্যান্ড ধরে বিক্রয়" খুলত: এক
 * ব্র্যান্ড চার সারিতে ভাগ হয়ে যেত, প্রতিটার অঙ্ক আসলের এক-চতুর্থাংশ,
 * আর কোনো সারিই সত্যি নয়। তার উপর সেই তালিকা দেখেই কেউ ঠিক করত কোন
 * ব্র্যান্ড রাখা হবে আর কোনটা বাদ।
 */
class Brand extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_brands';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'brand';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.list.index', ['kind' => 'brands']];
    }
}
