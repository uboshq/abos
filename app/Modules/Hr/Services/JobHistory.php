<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Models\AuditTrail;
use App\Models\Branch;
use App\Modules\Hr\Models\Employee;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\Designation;
use App\Modules\MasterData\Models\EmploymentType;
use Illuminate\Support\Carbon;

/**
 * চাকরির ইতিহাস — প্রোফাইলের সময়রেখা, মালিকের অনুমোদিত নকশা (৪ নম্বর), ২ অক্টোবর ২০২৬।
 *
 * ── কোথা থেকে ─────────────────────────────────────────────────────────
 * নতুন কোনো খাতা নয়: কর্মীর সারি [[IsAudited]], তাই পদবি, বিভাগ, শাখা, নিয়োগের ধরন বা
 * "যাঁর অধীনে" বদলালেই নিরীক্ষার খাতায় আগের ও পরের মান বসে যায়। এখানে শুধু সেগুলো পড়ে
 * মানুষের ভাষায় সাজানো। ⓘ আলাদা খাতা রাখলে দুইটা খাতা একদিন দুই কথা বলত।
 *
 * ── কেন যোগদান খাতা থেকে নয় ───────────────────────────────────────────
 * পুরনো কর্মীরা আমদানি হয়ে এসেছেন — তাঁদের "তৈরি" সারির তারিখটা আমদানির দিন, যোগদানের নয়।
 * ⓘ তাই প্রথম ঘটনাটা সবসময় সারির `joining_date`, আর শেষটা `leaving_date` (থাকলে)।
 */
final class JobHistory
{
    /** যে ঘরগুলো বদলালে "চাকরির ঘটনা" — বাকি বদল (মোবাইল, ঠিকানা) ইতিহাস নয় */
    private const FIELDS = [
        'designation_id' => Designation::class,
        'department_id' => Department::class,
        'branch_id' => Branch::class,
        'employment_type_id' => EmploymentType::class,
        'reports_to_employee_id' => Employee::class,
    ];

    /**
     * নতুন থেকে পুরনো।
     *
     * @return list<array{date: Carbon, field: string, from: ?string, to: ?string, by: ?string}>
     */
    public function of(Employee $employee): array
    {
        $events = [];

        if ($employee->leaving_date) {
            $events[] = ['date' => $employee->leaving_date, 'field' => 'left', 'from' => null, 'to' => null, 'by' => null];
        }

        $trails = AuditTrail::query()
            ->forRecord(Employee::class, (int) $employee->getKey())
            ->where('action', AuditTrail::UPDATED)
            ->whereHas('changes', fn ($q) => $q->whereIn('field', array_keys(self::FIELDS)))
            ->with(['changes' => fn ($q) => $q->whereIn('field', array_keys(self::FIELDS)), 'user'])
            ->orderByDesc('id')
            ->get();

        $names = $this->names($trails);

        foreach ($trails as $trail) {
            foreach ($trail->changes as $change) {
                $events[] = [
                    'date' => $trail->created_at,
                    'field' => $change->field,
                    'from' => $names[$change->field][(int) $change->old_value] ?? null,
                    'to' => $names[$change->field][(int) $change->new_value] ?? null,
                    'by' => $trail->user?->name,
                ];
            }
        }

        if ($employee->joining_date) {
            $events[] = ['date' => $employee->joining_date, 'field' => 'joined', 'from' => null, 'to' => $employee->designation?->name(), 'by' => null];
        }

        return $events;
    }

    /**
     * এক ঘরের সব আইডির নাম একবারে — সারিপ্রতি একটা কোয়েরি নয়।
     * ⓘ মুছে-ফেলা পদবি/বিভাগের নামও চাই, কারণ ইতিহাস তখনকার কথা বলে।
     *
     * @param  \Illuminate\Support\Collection<int, AuditTrail>  $trails
     * @return array<string, array<int, string>>
     */
    private function names($trails): array
    {
        $ids = [];

        foreach ($trails as $trail) {
            foreach ($trail->changes as $change) {
                foreach ([$change->old_value, $change->new_value] as $value) {
                    if ((int) $value > 0) {
                        $ids[$change->field][(int) $value] = true;
                    }
                }
            }
        }

        $names = [];

        foreach ($ids as $field => $set) {
            $model = self::FIELDS[$field];
            $query = $model::query()->whereKey(array_keys($set));

            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }

            foreach ($query->get() as $row) {
                $names[$field][(int) $row->getKey()] = (string) $row->name();
            }
        }

        return $names;
    }
}
