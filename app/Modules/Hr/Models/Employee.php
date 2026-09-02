<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একজন কর্মী।
 *
 * ── কর্মী আর ব্যবহারকারী এক নয় ───────────────────────────────────────
 * গুদামের শ্রমিকের সিস্টেমে ঢোকার দরকার নেই, অথচ তার বেতন হয়। মালিকের
 * অ্যাকাউন্ট আছে, বেতন নেই। দুইটাকে এক টেবিলে মেলালে হয় শ্রমিকদের ভুয়া
 * লগইন বানাতে হত, নয় তাদের বেতন সিস্টেমের বাইরে থাকত।
 */
class Employee extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'hr_employees';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_bn',
        'father_name', 'mobile', 'email', 'national_id',
        'user_id', 'department_id', 'designation_id', 'employment_type_id',
        'joining_date', 'leaving_date',
        'payment_method', 'bank_name', 'bank_branch',
        'bank_account_name', 'bank_account_no', 'bank_routing_no', 'mfs_number',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'leaving_date' => 'date',
            'is_active' => 'boolean',

            /*
             * পরিচয় ও টাকা পাঠানোর তথ্য — ডাটাবেজেও ঢাকা।
             *
             * ── কেন পর্দার তালাটা যথেষ্ট নয় ─────────────────────────
             * [[FieldSecurity]] ঠিক করে কে পর্দায় দেখবে। কিন্তু একটা
             * ব্যাকআপ ফাইল, একটা `mysql` প্রম্পট বা phpMyAdmin — তিনটার
             * যেকোনোটাই ওই তালাটার পাশ দিয়ে যায়। **ডাম্পটা কারও হাতে
             * পড়লে প্রতিটা কর্মীর পরিচয়পত্র পড়া যেত।**
             *
             * ── দাম ────────────────────────────────────────────────
             * এনক্রিপ্টেড ঘরে `LIKE` চলে না, তাই খোঁজার জন্য আলাদা
             * `national_id_hash` ([[scopeSearch]])। আর APP_KEY হারালে
             * মানগুলোও হারায় — **চাবিটা এখন ব্যাকআপের অংশ**।
             */
            'national_id' => 'encrypted',
            'bank_account_no' => 'encrypted',
            'bank_routing_no' => 'encrypted',
            'mfs_number' => 'encrypted',
        ];
    }

    /**
     * বেতন কীভাবে যায়।
     *
     * ধ্রুবক, সারি নয়: টাকা পৌঁছানোর পথ তিনটাই, আর প্রতিটার সাথে আলাদা
     * ঘর জড়িত (ব্যাংকে হিসাব নম্বর, MFS-এ মোবাইল নম্বর)। নতুন পথ এলে
     * শুধু একটা নাম নয়, নতুন ঘর ও নতুন ফাইলের গড়নও লাগবে।
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = ['cash', 'bank', 'mfs'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<EmploymentType, $this> */
    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    /** @return HasMany<SalaryStructure, $this> */
    public function structures(): HasMany
    {
        return $this->hasMany(SalaryStructure::class)->orderByDesc('effective_from');
    }

    /**
     * এই তারিখে কর্মীটা বেতনের তালিকায় আছে কি না।
     *
     * যোগ দেওয়ার আগের মাসে বেতন হয় না, আর ছেড়ে যাওয়ার পরের মাসেও নয় —
     * কিন্তু ছাড়ার মাসেই হয়, কারণ ওই মাসের কিছু দিন সে কাজ করেছে।
     */
    public function wasEmployedOn(Carbon $date): bool
    {
        if ($this->joining_date !== null && $date->lt($this->joining_date->startOfDay())) {
            return false;
        }

        return $this->leaving_date === null || $date->lte($this->leaving_date->endOfDay());
    }

    /**
     * বেতনের তালিকায় যারা আসবে — ওই মাসে যারা কর্মরত ছিলেন।
     *
     * ── কেন এখানে active() নেই ──────────────────────────────────────
     * চাকরির অবসানে কর্মী নিষ্ক্রিয় হন। active() ধরলে যে মাসে কেউ
     * ছেড়ে গেছেন সেই মাসেই তিনি তালিকা থেকে পড়ে যেতেন — অথচ ওই মাসের
     * দশ দিন তিনি কাজ করেছেন, আর ওই বেতনটা তার পাওনা।
     *
     * তারিখই এখানে একমাত্র কর্তৃপক্ষ: যোগদানের পর, আর ছাড়ার আগে।
     */
    public function scopeOnPayrollFor(Builder $query, Carbon $monthEnd): Builder
    {
        return $query
            ->whereDate('joining_date', '<=', $monthEnd->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('leaving_date')
                ->orWhereDate('leaving_date', '>=', $monthEnd->copy()->startOfMonth()->toDateString()));
    }

    /**
     * পরিচয়পত্রের ছাপটা মডেলেই বসে, সেবায় নয়।
     *
     * ── কেন এখানে ───────────────────────────────────────────────────
     * সেবায় বসালে সিডার, ইমপোর্ট বা টিঙ্কার থেকে বসানো একটা কর্মীর
     * ছাপ থাকত না, আর তাঁকে NID দিয়ে **কোনোদিন খুঁজে পাওয়া যেত না** —
     * কোনো ভুল ছাড়াই, কেবল ফলাফলে অনুপস্থিত। ঠিক এই কারণেই এই রিপোতে
     * অডিটও মডেলের ঘটনা থেকে লেখা হয়।
     */
    protected static function booted(): void
    {
        static::saving(function (self $employee): void {
            if (! $employee->isDirty('national_id')) {
                return;
            }

            $value = (string) ($employee->national_id ?? '');

            $employee->national_id_hash = $value === '' ? null : self::blindIndex($value);
        });
    }

    /**
     * খোঁজার জন্য নির্ধারিত ছাপ — মেলানোর জন্য, খোলার জন্য নয়।
     *
     * ফাঁকা ও ড্যাশ ফেলে দেওয়া হয়: কেউ "১২৩৪ ৫৬৭৮" লিখলে আর
     * "১২৩৪৫৬৭৮" লিখলে একই কর্মী পাওয়া উচিত।
     */
    public static function blindIndex(string $value): string
    {
        $clean = preg_replace('/[\s\-]+/u', '', trim($value)) ?? '';

        return hash_hmac('sha256', $clean, (string) config('app.key'));
    }

    /** নাম, কোড, মোবাইল বা পরিচয়পত্র — যেটা মনে আছে সেটা দিয়েই। */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like, $term) {
            $q->where('code', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like)
                ->orWhere('mobile', 'like', $like)
                /*
                 * পরিচয়পত্র মেলে হুবহু, `LIKE`-এ নয়।
                 *
                 * ── কেন বদলাতে হলো ─────────────────────────────────
                 * ঘরটা এখন এনক্রিপ্টেড, আর একই সংখ্যা প্রতিবার আলাদা
                 * ciphertext হয় (প্রতিবার নতুন IV) — তাই `LIKE` মেলানোর
                 * মতো কিছুই থাকে না। ওটা রেখে দিলে খোঁজাটা **চুপচাপ
                 * কখনো কিছু পেত না**, আর কেউ বলত "কর্মীটা নেই"।
                 *
                 * ── কী হারাল ───────────────────────────────────────
                 * আংশিক খোঁজা — শেষ চার সংখ্যা লিখে আর পাওয়া যাবে না।
                 * পুরো নম্বরে পাওয়া যাবে, আর বাস্তবে কার্ড দেখে বা কপি
                 * করে এভাবেই খোঁজা হয়। এটাই এনক্রিপশনের ঘোষিত দাম।
                 */
                ->orWhere('national_id_hash', self::blindIndex($term));
        });
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'employee';
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
        return ['hr.employee.show', ['employee' => $this->id]];
    }
}
