<?php

declare(strict_types=1);

namespace App\Core\Security;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * spatie-র অনুমতি-তালিকা, একই উত্তর, সস্তায় পড়া (২৪ নভেম্বর ২০২৬)।
 *
 * ── কেন ─────────────────────────────────────────────────────────────────
 * [[CompanyContext::set()]] প্রতিবার তালিকার ক্যাশ ছাড়ে — **ইচ্ছাকৃত, আর এখানে বদলায়নি**: না ছাড়লে এক কোম্পানির চাবি অন্যটায়
 * বা অন্য প্রসেসে বদলানো রোলের পুরনো উত্তর থেকে যেতে পারে। কিন্তু তারপর নতুন করে পড়া ৩৬২ অনুমতি × ৩০১৩ জোড়ার জন্য ৩০১৩টা
 * Role আর ৩০১৩টা Pivot মডেল বানাত — ডেমোতে প্রতি পাতায় ~৫০০ms।
 * ⭐ এখানে প্রতিটা রোল একবারই তোলা, জোড়াগুলো সাদা সারি। ⛔ কোন তথ্য, কার জন্য, কতক্ষণ — কিছুই বদলায় না; তালিকাটা মূলেরও কোম্পানি
 *   চেনে না (কার কোন রোল, সেটা মানুষের নিজের সম্পর্কে)।
 */
final class CheapPermissionRegistrar extends PermissionRegistrar
{
    private ?Collection $built = null;

    private ?string $builtFrom = null;

    /**
     * ⭐ একই প্রসেসে কোম্পানি বদলালে (মালিকের কেন্দ্র এক পাতায় বহুবার) তালিকার বস্তু আবার বানানো নয়, যদি টেবিল তিনটা হুবহু একই থাকে
     *   (২৫ নভেম্বর ২০২৬; `/owner`-এর সময়ের ৬০% এখানে যেত)। ⛔ প্রতিবারই ডাটাবেসকে জিজ্ঞেস — তিন টেবিলের ছাপ (গোনা, যোগ আর XOR);
     *   একটা চাবি বা রোল বদলালেই ছাপ আলাদা, তালিকা নতুন। ক্যাশ ছাড়ার নিয়ম ([[CompanyContext::set()]]) অটুট।
     */
    private function asTheDatabaseIsNow(): Collection
    {
        $now = $this->fingerprint();

        if ($this->built === null || $now !== $this->builtFrom) {
            $this->built = $this->getPermissionsWithRoles();
            $this->builtFrom = $now;
        }

        return $this->built;
    }

    /** এখনকার রোল-অনুমতির ছাপ — তালিকা তোলা থাকলে তারই, নইলে ডাটাবেসের (বোর্ডের মনে রাখা অঙ্কের চাবি; [[DashboardFigureCache]]) */
    public function mark(): string
    {
        return $this->permissions !== null && $this->permissions === $this->built && $this->builtFrom !== null
            ? $this->builtFrom
            : $this->fingerprint();
    }

    private function fingerprint(): string
    {
        $of = function (\Illuminate\Database\Query\Builder $query, array $columns): string {
            $row = "CONCAT_WS('|', ".implode(', ', array_map(fn (string $c): string => "IFNULL({$c}, '~')", $columns)).')';
            $mark = $query->selectRaw("COUNT(*) as n, COALESCE(SUM(CRC32({$row})), 0) as s, COALESCE(BIT_XOR(CRC32(REVERSE({$row}))), 0) as x")->first();

            return "{$mark->n}:{$mark->s}:{$mark->x}";
        };

        return implode(' ', [
            $of(DB::table('role_has_permissions'), ['permission_id', 'role_id']),
            $of($this->getPermissionClass()::query()->toBase(), ['id', 'name', 'guard_name', 'updated_at']),
            $of($this->getRoleClass()::query()->toBase(), ['id', 'name', 'guard_name', 'company_id', 'updated_at']),
            DB::connection()->getDatabaseName(),
        ]);
    }

    private ?object $indexOf = null;

    /** @var array<string, array<string, object>> গার্ড => নাম => অনুমতি */
    private array $byName = [];

    /**
     * ⭐ নাম ধরে একটা অনুমতি — তালিকা ঘেঁটে নয়, সূচি থেকে (২৫ নভেম্বর ২০২৬)। মেনু প্রতি পাতায় ~৩০০ নাম খোঁজে, প্রতিটায় মূল কোড ৩৬২
     * সারি হাঁটত — ছোট পাতার প্রায় তৃতীয়াংশ সময়।
     *   ⛔ সূচি বাঁধা তালিকার বস্তুর সাথে: ক্যাশ ছাড়লে তালিকা নতুন বস্তু, সূচিও নতুন। অন্য যেকোনো প্রশ্ন (আইডি, একাধিক, সংখ্যার মতো
     *   নাম) মূলের পথে — উত্তর হুবহু এক।
     */
    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        // ⭐ তালিকা সরাসরি ডাটাবেস থেকে, ক্যাশে লিখে আবার পড়ে নয় (২৫ নভেম্বর ২০২৬): [[CompanyContext::set()]] প্রতি অনুরোধে ক্যাশ
        //   ছাড়ে, তাই ক্যাশ কখনো কাজে লাগত না — কেবল ৩০১৩ জোড়া ভাঁজ করা, লেখা আর খোলা, প্রতি পাতায়। ⓘ পুরনো উত্তর থাকার পথ আরও কম।
        if (! $this->permissions) {
            $this->permissions = $this->asTheDatabaseIsNow();
        }

        $name = $params['name'] ?? null;
        $guard = $params['guard_name'] ?? null;

        if (! $onlyOne || count($params) !== 2 || ! is_string($name) || ! is_string($guard) || is_numeric($name)) {
            return parent::getPermissions($params, $onlyOne);
        }

        if ($this->indexOf !== $this->permissions) {
            $this->indexOf = $this->permissions;
            $this->byName = [];

            foreach ($this->permissions as $permission) {
                // ⓘ একই নাম দুইবার হলে প্রথমটা — মূলের `first()`
                $this->byName[(string) $permission->getAttribute('guard_name')][(string) $permission->getAttribute('name')] ??= $permission;
            }
        }

        $one = $this->byName[$guard][$name] ?? null;

        return new Collection($one === null ? [] : [$one]);
    }

    protected function getPermissionsWithRoles(): Collection
    {
        $roles = $this->getRoleClass()::query()->get()->keyBy(fn ($role) => $role->getKey());
        $of = [];

        foreach (DB::table('role_has_permissions')->get([$this->pivotPermission.' as permission', $this->pivotRole.' as role']) as $pair) {
            if ($roles->has($pair->role)) {
                $of[$pair->permission][] = $roles->get($pair->role);
            }
        }

        return $this->getPermissionClass()::select()->get()
            ->each(fn ($permission) => $permission->setRelation('roles', new Collection($of[$permission->getKey()] ?? [])));
    }
}
