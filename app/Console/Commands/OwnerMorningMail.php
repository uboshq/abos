<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\PermissionSyncer;
use App\Models\User;
use App\Modules\Executive\Notifications\MorningSummary;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\Snapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * ⭐ সকালের মেইল — মালিকের উত্তর ৪, ১০ অক্টোবর ২০২৬: "যাবে alaminsuv@gmail.com এ"।
 *
 * ⓘ ঠিকানা কোডে নয়, `.env`-এ (`ABOS_EXECUTIVE_MAIL_TO`, কমা দিয়ে একাধিক) — ABOS অনেক ব্যবসায় বিক্রি হয়।
 * ফাঁকা থাকলে কিছুই যায় না, কোনো ভুলও নয়।
 *
 * ⛔ সংখ্যাগুলো কার চোখে: যিনি সব কোম্পানির সুপার অ্যাডমিন (মালিক) — পর্দা তাঁকে যা দেখায়, মেইলেও ঠিক তাই
 * ([[CompanyLens]], [[Snapshots::on()]])। ⓘ কেবল গোটা কোম্পানির সারি; শাখা ধরে বিস্তারিত পর্দায়।
 */
final class OwnerMorningMail extends Command
{
    protected $signature = 'abos:owner-morning-mail {--date= : কোন দিনের হিসাব (Y-m-d); না দিলে গতকাল}';

    protected $description = 'মালিকের কেন্দ্রের আগের দিনের সারাংশ মালিকের ইমেইলে পাঠায়';

    public function handle(Snapshots $snapshots, CompanyLens $lens): int
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string) config('abos.executive.mail_to')))));

        if ($to === []) {
            $this->info('ABOS_EXECUTIVE_MAIL_TO বসানো নেই — কিছু পাঠানো হলো না।');

            return self::SUCCESS;
        }

        $owner = $this->owner();

        if ($owner === null) {
            $this->warn('কোনো সক্রিয় সুপার অ্যাডমিন নেই — সারাংশ বানানো গেল না।');

            return self::SUCCESS;
        }

        $date = (string) ($this->option('date') ?: Carbon::yesterday(config('app.timezone'))->toDateString());

        $rows = array_values(array_filter(
            $snapshots->on($owner, $lens->companies($owner), $date),
            fn (array $row): bool => $row['branch_id'] === null,
        ));

        foreach ($to as $address) {
            Notification::route('mail', $address)->notify(new MorningSummary($date, $rows));
        }

        $this->info(count($to).'টি ঠিকানায় '.count($rows).'টি কোম্পানির সারাংশ পাঠানো হলো।');

        return self::SUCCESS;
    }

    /**
     * মালিক — সবচেয়ে বেশি কোম্পানিতে সুপার অ্যাডমিন, সমান হলে আগের id। ⓘ `.env`-এ `ABOS_EXECUTIVE_MAIL_AS` দিলে সেই মানুষ।
     */
    private function owner(): ?User
    {
        $chosen = config('abos.executive.mail_as');

        if ($chosen !== null && $chosen !== '') {
            return User::query()->where('is_active', true)->find((int) $chosen);
        }

        /*
         * ⚠️ সরাসরি `model_has_roles` — teams চালু, তাই `roles()` সম্পর্ক চলতি কোম্পানির সারিই দেখে, আর কনসোলে
         * চলতি কোম্পানি নেই।
         */
        $id = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->where('u.is_active', true)
            ->whereNull('u.deleted_at')
            ->groupBy('mr.model_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('mr.model_id')
            ->value('mr.model_id');

        return $id === null ? null : User::query()->find((int) $id);
    }
}
