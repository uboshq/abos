<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** কোন কাজে কয় স্তরের অনুমোদন লাগবে — Control Panel থেকে সাজানো। */
class ApprovalFlow extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'module', 'action', 'document_type', 'threshold_amount', 'is_active',
    ];

    protected function casts(): array
    {
        return ['threshold_amount' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalFlowStep::class)->orderBy('level');
    }

    /**
     * এই পরিমাণে অনুমোদন লাগবে কি না।
     *
     * সীমা না থাকলে সবসময় লাগে। সীমা থাকলে তার নিচে লাগে না — কারণ
     * "৫০ টাকার ডিসকাউন্টে মালিকের অনুমোদন" বাস্তবে কেউ মানে না, আর
     * একবার না-মানা শুরু হলে পুরো ব্যবস্থাটাই অকেজো হয়ে যায়।
     */
    public function appliesTo(?string $amount): bool
    {
        if ($this->threshold_amount === null) {
            return true;
        }

        if ($amount === null) {
            return true;
        }

        return bccomp((string) $amount, (string) $this->threshold_amount, 4) >= 0;
    }
}
