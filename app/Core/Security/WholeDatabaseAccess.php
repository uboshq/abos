<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Services\PermissionSyncer;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * গোটা ডাটাবেস কে হাতে পেতে পারেন — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১)।
 *
 * ── ⛔ কী খোলা ছিল ───────────────────────────────────────────────────────
 * প্রতিটা কোম্পানির super_admin নিজে থেকেই সব চাবি পান ([[PermissionSyncer]]), `backup.download` সহ। অথচ ব্যাকআপ
 * ফাইলে **সব কোম্পানির** সব তথ্য থাকে। ⚠️ ABOS অনেক ব্যবসার কাছে বিক্রি হয় — এক কোম্পানির মালিক অন্য সবার খাতা
 * নিয়ে যেতে পারতেন, আর চাবির তালিকায় কিছুই ভুল দেখাত না।
 *
 * ── ⭐ নিয়ম: যিনি সব চালু কোম্পানিতেই super_admin ─────────────────────────────
 * ⓘ চাবি নয় (ওটা কোম্পানি ধরে), ভূমিকার বিস্তার: গোটা ডাটাবেস তাঁরই, যিনি গোটা ব্যবস্থার মালিক। ⭐ মালিকের শর্ত
 * (৩০ সেপ্টেম্বর: *"EI BUSINESS ER MALIK EKA AMI TAI AMR SURIMPOWER NISCIT KORBE"*): মালিক প্রতিটা কোম্পানিতে
 * super_admin, তাই তাঁর ক্ষমতা পুরো থাকে; আলাদা হোস্টিংয়ে একটা কোম্পানি হলে তিনি সেখানেও একমাত্র super_admin।
 *
 * ⚠️ নতুন ক্লাস, পুরনো [[Ownership]]-এ নতুন পদ্ধতি নয় — চলমান টেস্ট-রান পুরনো ক্লাস ধরে রাখে (৩০ সেপ্টেম্বরের শিক্ষা)।
 */
final class WholeDatabaseAccess
{
    public function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        /*
         * ⛔ বন্ধ কোম্পানিও গোনা — পুরো ERP অডিট, ৬ অক্টোবর ২০২৬ (SystemAdmin ⛔১)। আগে কেবল চালুগুলো গোনা হত: কেউ
         * নতুন কোম্পানি খুলে (সেখানে নিজে super_admin) বাকিগুলো বন্ধ করলে "সব চালু কোম্পানির super_admin" হয়ে যেতেন —
         * আর ব্যাকআপে বন্ধ কোম্পানির তথ্যও থাকে। ⭐ ডাটাবেসে যত কোম্পানি, সবগুলোতেই super_admin হতে হবে।
         */
        $active = Company::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($active === []) {
            return false;
        }

        $ownedIn = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.model_type', User::class)
            ->where('mhr.model_id', $user->id)
            ->where('r.name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->pluck('mhr.company_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_diff($active, $ownedIn) === [];
    }
}
