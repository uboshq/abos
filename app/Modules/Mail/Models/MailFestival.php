<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ উৎসব ও দিবস (নমুনা v4, পর্দা ৬): ঈদ, পূজা, নববর্ষ… আর জাতীয় দিবস। কোম্পানি নিজে যোগ করে বা লুকায়; চাঁদের তারিখ প্রতি বছর হাতে বসে।
 * ⛔ `quiet` দিবসে (একুশে, স্বাধীনতা, বিজয়, মে দিবস) কেবল শ্রদ্ধা — অফার বা বিক্রির কথা নয়।
 */
class MailFestival extends Model
{
    use BelongsToCompany;
    use IsAudited;

    protected $table = 'mail_festivals';

    protected $fillable = ['key', 'name', 'greeting', 'line', 'when', 'on_date', 'colours', 'mark', 'quiet', 'active'];

    protected function casts(): array
    {
        return ['on_date' => 'date', 'colours' => 'array', 'quiet' => 'boolean', 'active' => 'boolean'];
    }

    /** ⓘ আজ এই উৎসবের শুভেচ্ছার দিন কিনা — তারিখের দিন আর তার আগের দিন */
    public function isNear(?\DateTimeInterface $day = null): bool
    {
        if ($this->on_date === null || ! $this->active) {
            return false;
        }

        $today = \Illuminate\Support\Carbon::instance($day ?? now())->startOfDay();

        return $today->between($this->on_date->copy()->subDay(), $this->on_date->copy());
    }
}
