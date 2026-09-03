<?php

declare(strict_types=1);

namespace App\Modules\Hr\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncRecord;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Support\Carbon;

/**
 * মাঠের হাজিরা — নেট ছাড়া বসানো, নেট এলে সার্ভারে।
 *
 * ── কেন এটা push-যোগ্য ──────────────────────────────────────────────
 * স্পেক §৫.১০ অফলাইনে হাজিরা চায়। মাঠকর্মী নেট ছাড়াই নিজের উপস্থিতি
 * তোলেন; সেটা ফোনে বসে থাকে, নেট এলে আসে।
 *
 * ── ⚠️ কার হাজিরা — নিজেরটাই ─────────────────────────────────────────
 * ফোন থেকে একজন **কেবল নিজের** হাজিরা বসায় (`user_id` ধরে তার নিজের
 * কর্মী-রেকর্ড)। অন্যের হাজিরা বসানো অফিসের কাজ, ওয়েব থেকে — নাহলে
 * একজন মাঠকর্মী আরেকজনকে উপস্থিত দেখিয়ে দিতে পারতেন।
 *
 * ── ⚠️ ঘড়ি কার — ফোনের, আর সেটা মেনে নেওয়া হয় চিহ্নসহ ─────────────────
 * অফলাইনে হাজিরার সময়টা **ফোনের ঘড়ির**, সার্ভারের নয় — মাঠে আর কোনো
 * ঘড়ি নেই। কিন্তু ফোনের ঘড়ি বদলানো যায়। সার্ভার নিজে যখন সারিটা পায়
 * (`created_at`) সেটাও বসে, তাই দাবি-করা দিন আর পাওয়ার দিনের ফাঁক থেকে
 * যায়। দেরিতে আসা একটা সৎ সিঙ্ক আর বদলানো একটা ঘড়ি বাইরে থেকে একরকম
 * দেখায়, তাই এটা **শাস্তি নয়, চিহ্ন**: ফাঁকটা বড় হলে একটা মন্তব্য বসে,
 * যা হাজিরার পর্দায় দেখা যায় — কেবল কলামে চাপা থাকে না।
 * (তুলনীয়: [[NobodyAsksTheDatabaseWhatDayItIs]] — কার ঘড়ি সত্যি।)
 */
final class AttendanceSync implements SyncsToDevices
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public static function module(): string
    {
        return 'hr';
    }

    public static function entityType(): string
    {
        return 'Attendance';
    }

    /**
     * *নিজের* হাজিরা দেওয়ার চাবি — "সবার হাজিরা দেখা" নয়।
     *
     * ⭐ দুইটা আলাদা কর্তৃত্ব: `hr.attendance.view` গোটা দলের হাজিরা দেখায়
     * (কে দেরি করেছে, কে ছুটিতে), আর সেটা মাঠকর্মীকে দিলে সুবিধার জন্য
     * গোপনীয়তা বিক্রি করা হত। `hr.attendance.self` কেবল নিজেরটা — প্রতিটা
     * ক্রেতার মাঠকর্মীর ঠিক এটাই দরকার, তাই এটা আলাদা চাবি।
     */
    public static function requiredPermission(): ?string
    {
        return 'hr.attendance.self';
    }

    /**
     * ফোনে নিজের হাজিরাগুলো ফিরে আসে — কোন দিনগুলো পৌঁছেছে তা দেখা যায়।
     *
     * @return list<SyncRecord>
     */
    public function pull(User $user, ?Carbon $since, int $limit): array
    {
        $employee = $this->ownEmployee($user);

        if ($employee === null) {
            return [];
        }

        $query = Attendance::query()
            ->where('employee_id', $employee->id)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query->get()->map(fn (Attendance $attendance) => new SyncRecord(
            entityType: self::entityType(),
            entityId: (string) $attendance->public_id,
            payload: [
                'id' => (string) $attendance->public_id,
                'workDate' => $attendance->work_date?->toDateString(),
                'status' => $attendance->status,
                'inTime' => $attendance->in_time,
                'outTime' => $attendance->out_time,
                'isLate' => (bool) $attendance->is_late,
                'remarks' => $attendance->remarks,
            ],
            updatedAt: $attendance->updated_at ?? $attendance->created_at ?? now(),
        ))->all();
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    /**
     * ফোনের তোলা এক দিনের হাজিরা — নিজের, আর কেবল একবার।
     *
     * ── কেন কেবল CREATE ─────────────────────────────────────────────
     * অফিস কোনো দিনের হাজিরা শুধরে থাকলে (যেমন অনুমোদিত ছুটি বসানো) একটা
     * পুরনো অফলাইন সারি সেটা নীরবে চাপা দিত। তাই ওই দিনের সারি থাকলে এটা
     * প্রত্যাখ্যান — সংশোধন নেটওয়ার্কে।
     */
    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('hr::sync.attendance_edit_needs_network'));
        }

        $payload = $change->payload();

        $employee = $this->ownEmployee($user);

        if ($employee === null) {
            throw new SyncRejection(__('hr::sync.not_an_employee'));
        }

        /*
         * ⛔ কেবল নিজেরটা — চাবিটা (`hr.attendance.self`) ঠিক এটাই বলে।
         *
         * ফোন যদি অন্য কারো employee পাঠায়, চুপচাপ নিজেরটা বসিয়ে দেওয়া
         * হয় না — প্রত্যাখ্যান। নীরব সংশোধন পরে "আমার হাজিরা কে দিল"
         * প্রশ্নের জন্ম দিত। কিছু না পাঠালে নিজেরটাই ধরা হয়।
         */
        $claimed = trim((string) ($payload['employeeId'] ?? ''));

        if ($claimed !== '' && $claimed !== (string) $employee->public_id) {
            throw new SyncRejection(__('hr::sync.only_own_attendance'));
        }

        $date = trim((string) ($payload['workDate'] ?? ''));

        if ($date === '') {
            throw new SyncRejection(__('hr::sync.attendance_needs_date'));
        }

        // ওই দিনের সারি আগে থেকে থাকলে সংশোধন নেটওয়ার্কে (mark() নিজে
        // upsert করত, তাই আগে দেখে নেওয়া — নাহলে অফিসের সংশোধন চাপা পড়ত)
        $already = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->exists();

        if ($already) {
            throw SyncRejection::conflict(__('hr::sync.attendance_already_marked'));
        }

        $status = (string) ($payload['status'] ?? Attendance::PRESENT);

        $attendance = $this->attendance->mark($employee, $date, $status, [
            'in_time' => $payload['inTime'] ?? null,
            'out_time' => $payload['outTime'] ?? null,
            'is_late' => (bool) ($payload['isLate'] ?? false),
            'remarks' => $this->clockStampedRemark($payload['remarks'] ?? null, $date),
        ]);

        return (string) $attendance->public_id;
    }

    /** নিজের কর্মী-রেকর্ড — চলতি কোম্পানির scope-এ, `user_id` ধরে। */
    private function ownEmployee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    /**
     * দাবি-করা দিন আর আজকের দিনের ফাঁক বড় হলে একটা মন্তব্য জুড়ে দেওয়া,
     * যাতে দেরিতে সিঙ্ক হওয়া হাজিরাটা পর্দায় চোখে পড়ে (কলামে চাপা না থাকে)।
     * কর্মীর নিজের মন্তব্য মুছি না — জুড়ি।
     */
    private function clockStampedRemark(?string $own, string $workDate): ?string
    {
        $daysLate = Carbon::parse($workDate)->startOfDay()->diffInDays(now()->startOfDay(), false);

        $note = $daysLate >= 1
            ? __('hr::sync.synced_days_late', ['days' => $daysLate])
            : null;

        $combined = trim(implode(' · ', array_filter([$own, $note])));

        return $combined !== '' ? $combined : null;
    }
}
