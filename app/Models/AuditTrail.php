<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Module\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * একটা অডিট ঘটনা — কে, কখন, কোন রেকর্ডে, কী কাজ।
 *
 * ── কেন এটা বদলানো বা মোছা যায় না ───────────────────────────────────
 * অডিট বদলানো গেলে অডিট বলে কিছু থাকে না। যে ব্যবহারকারী একটা বিল
 * বদলে ফেলেছেন তিনিই যদি তার চিহ্নটাও মুছতে পারেন, তবে পুরো ব্যবস্থাটা
 * কেবল সৎ মানুষের বিরুদ্ধেই কাজ করে।
 *
 * অনুমতি দিয়ে আটকানো হয়নি, মডেলেই আটকানো — অনুমতি কেউ বদলাতে পারে,
 * আর কোডে লেখা নিষেধটা প্রতিটা পথেই খাটে (কনসোল, টিঙ্কার, সিডার)।
 */
class AuditTrail extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $table = 'audit_trails';

    /** সারি তৈরির পর আর ছোঁয়া হয় না, তাই updated_at-এর মানে নেই। */
    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const ACTIONS = [
        self::CREATED, self::UPDATED, self::DELETED, self::RESTORED,
        self::CONFIRMED, self::CANCELLED, self::APPROVED, self::REJECTED,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'action',
        'auditable_type', 'auditable_id', 'document_no', 'label',
        'ip_address', 'user_agent', 'reason',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        /*
         * তৈরির পর যেকোনো সংরক্ষণ আটকে যায়।
         *
         * updating নয়, saving — কারণ কিছুই না বদলালে Eloquent updating
         * ডাকেই না। তখন "একই মান দিয়ে সেভ" চুপচাপ পার পেয়ে যেত, আর
         * নিষেধটা কেবল সেই চেষ্টাগুলোতেই খাটত যেগুলো এমনিতেই ধরা পড়ত।
         */
        static::saving(function (self $trail) {
            if (! $trail->exists) {
                return;
            }

            throw new RuntimeException(
                'An audit trail row cannot be changed. Its whole value is that nobody can edit it.'
            );
        });

        static::deleting(function (self $trail) {
            throw new RuntimeException(
                'An audit trail row cannot be deleted. A document may be cancelled; its history stays.'
            );
        });
    }

    /** @return HasMany<AuditFieldChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(AuditFieldChange::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * যে রেকর্ডটার ঘটনা — মুছে গিয়ে থাকলে null।
     *
     * সেই কারণেই document_no ও label কপি হয়ে বসে: রেকর্ড না থাকলেও
     * তালিকায় সারিটা পড়া যায়।
     */
    public function auditable(): ?Model
    {
        if (! class_exists($this->auditable_type)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->auditable_type;

        $query = $class::query();

        // মুছে যাওয়া রেকর্ডও দেখাতে হয় — অডিটের পুরো কাজটাই তো সেটা
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find($this->auditable_id);
    }

    /** পড়ার মতো নাম — নম্বর থাকলে নম্বর, নাহলে নাম। */
    public function title(): string
    {
        return $this->document_no ?: ($this->label ?: '#'.$this->auditable_id);
    }

    /** মডিউলের নাম মডেলের namespace থেকে — App\Modules\Sales\... → sales. */
    public function moduleCode(): ?string
    {
        if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', (string) $this->auditable_type, $m) !== 1) {
            return null;
        }

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $m[1]) ?? '');
    }

    /**
     * মডিউলের পড়ার মতো নাম — রেজিস্ট্রি থেকে।
     *
     * কোডটা (sales, hr) ভেতরের কথা; পর্দায় মডিউলের ঘোষিত নামটাই যায়,
     * আর সেটা ব্যবহারকারীর ভাষায়। মডিউলটা পরে সরে গেলে null — তখন
     * পর্দা শুধু কিছু দেখায় না, ভাঙে না।
     */
    public function moduleLabel(): ?string
    {
        $code = $this->moduleCode();

        if ($code === null) {
            return null;
        }

        $module = app(ModuleRegistry::class)->get($code);

        return $module?->label();
    }

    public function scopeForRecord(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }
}
