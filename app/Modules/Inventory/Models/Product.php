<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Contracts\Drillable;
use App\Models\User;
use App\Modules\MasterData\Models\Tax;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা পণ্য।
 *
 * এখানে কোনো "স্টক" কলাম নেই, আর সেটাই এই মডিউলের সবচেয়ে গুরুত্বপূর্ণ
 * সিদ্ধান্ত। পরিমাণ আসে চলাচলের যোগফল থেকে — StockMovement। একটা কলাম
 * রাখলে সেটা একদিন সারির যোগফলের সাথে মিলত না, আর তখন "খাতায় ৫০, তাকে
 * ৪৭" প্রশ্নের কোনো উত্তর থাকত না।
 */
class Product extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasDocumentStatus;
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $table = 'inv_products';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn', 'barcode',
        'brand', 'category', 'unit_id', 'tax_id',
        'purchase_price', 'sale_price', 'reorder_level',
        'status', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** ব্যবহারকারীর ভাষায় নাম — বাংলা না থাকলে ইংরেজি (সেকশন ১৮.৩)। */
    public function name(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if ($locale === 'bn' && filled($this->name_bn)) {
            return $this->name_bn;
        }

        return $this->name_en;
    }

    /**
     * নাম, কোড বা বারকোড দিয়ে খোঁজা।
     *
     * বারকোডও, কারণ কাউন্টারে দাঁড়ানো মানুষ নামটা নয়, স্ক্যানারের
     * পাঠানো সংখ্যাটাই দেখেন — আর ওটা দিয়ে খুঁজতে না পারলে স্ক্যানার
     * থাকার কোনো মানে নেই।
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('brand', 'like', $like);
        });
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'product';
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
        return ['inventory.product.show', ['product' => $this->id]];
    }
}
