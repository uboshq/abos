<?php

declare(strict_types=1);

namespace App\Modules\Hr\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Concerns\SharedAcrossCompaniesWhenAsked;
use App\Core\Contracts\Drillable;
use App\Core\Security\FieldSecurity;
use App\Models\Attachment;
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
    use SharedAcrossCompaniesWhenAsked;
    use SoftDeletes;

    protected $table = 'hr_employees';

    /**
     * ⭐ পরিচয়ের চাবির (`hr.identity.view`) পেছনের ঘর — এক তালিকা: মডিউলের `sensitive_fields`, লেখার দুই পাহারা
     * ([[EmployeeController]]) আর পর্দা এখান থেকেই পড়ে।
     * ⛔ আগে কেবল NID আর ব্যাংক/MFS — জন্মতারিখ, বাবা-মায়ের নাম, রক্তের গ্রুপ, ঠিকানা আর জরুরি যোগাযোগ `hr.employee.view`
     * থাকলেই সবাই দেখতেন (ঘাটতির তালিকা #১৯; GDPR ন্যূনতম তথ্য, ISO 27001 A.5.34; a6, ২০ অক্টোবর ২০২৬)।
     *
     * @var list<string>
     */
    public const GUARDED = [
        'national_id', 'bank_account_no', 'bank_routing_no', 'mfs_number',
        'father_name', 'mother_name', 'date_of_birth', 'blood_group', 'present_address', 'permanent_address', 'emergency_name', 'emergency_relation', 'emergency_mobile',
        // ⭐ লিঙ্গ — মাতৃত্বকালীন ছুটির যোগ্যতা (শ্রম আইন অধ্যায় ৪; a6, ২১ অক্টোবর ২০২৬)
        'gender',
        // ⭐ জীবিত সন্তান — মাতৃত্বকালীন সুবিধা (ধারা ৪৬(৩); fe, ২১ অক্টোবর ২০২৬)
        'surviving_children',
        // ⭐ e-TIN — করদাতার নম্বর; NID-এর মতোই পরিচয় (আয়কর আইন ২০২৩; a6, ২১ অক্টোবর ২০২৬, [[SalaryTax]])
        'e_tin',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_bn',
        'father_name', 'mobile', 'email', 'national_id', 'e_tin',
        // ⭐ প্রোফাইলের মানুষটা ও রিপোর্টিং লাইন — মালিকের অনুমোদিত নকশা, ২ অক্টোবর ২০২৬
        'date_of_birth', 'mother_name', 'blood_group', 'present_address', 'permanent_address',
        'emergency_name', 'emergency_relation', 'emergency_mobile', 'reports_to_employee_id', 'gender', 'surviving_children',
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
            'date_of_birth' => 'date',
            'leaving_date' => 'date',
            'is_active' => 'boolean',
            'surviving_children' => 'integer',

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
            // ⭐ e-TIN — NID-এর মতোই ডাটাবেজেও ঢাকা (a6, ২১ অক্টোবর ২০২৬)
            'e_tin' => 'encrypted',
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

    /** ⓘ রক্তের আটটা গ্রুপ — বাছাই থেকে, হাতে লেখা নয়, যাতে "B +ve" আর "B+" দুই রকম না হয় */
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /** ⭐ লিঙ্গ — বাছাই থেকে; মাতৃত্বকালীন ছুটি কেবল `female` ([[LeaveService::assertEligible()]]; a6, ২১ অক্টোবর ২০২৬) */
    public const FEMALE = 'female';

    /** @var list<string> */
    public const GENDERS = [self::FEMALE, 'male', 'other'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * ⭐ যাঁর অধীনে কাজ করেন — প্রোফাইলের রিপোর্টিং লাইন।
     * ⓘ কোম্পানির দেয়াল এখানে নয়, কন্ট্রোলারের যাচাইয়ে আর [[BelongsToCompany]]-র স্কোপে।
     *
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_employee_id');
    }

    /**
     * ⭐ প্রোফাইলের ছবি — [[EmployeePhotoService]] বসায়; দেখানো কেবল `attachment.download` দিয়ে।
     * ⓘ `$fillable`-এ নেই, ইচ্ছা করে: ফর্মের সাধারণ ঘর দিয়ে অন্য কারও সংযুক্তির id বসানো যেত।
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'photo_attachment_id');
    }

    /** @return HasMany<Employee, $this> যাঁরা এঁর অধীনে */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_employee_id');
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
                ->orWhere('mobile', 'like', $like);

            /*
             * ⛔ পরিচয়পত্রে খোঁজা কেবল যিনি সেটা দেখতে পান — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
             * ([[TheIdCardNumberLayInTheOpenTest]])। ⓘ আগে কর্মীর তালিকার চাবি থাকলেই পুরো নম্বর লিখে মিলিয়ে নেওয়া যেত "এই
             * NID অমুকের" — পরিচয়ের চাবি (`hr.identity.view`) ছাড়াই।
             */
            if (! FieldSecurity::visible(self::class, 'national_id')) {
                return;
            }

            $q
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
