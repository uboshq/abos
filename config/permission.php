<?php

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [

    'models' => [

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your permissions. Of course, it
         * is often just the "Permission" model but you may use whatever you like.
         *
         * The model you want to use as a Permission model needs to implement the
         * `Spatie\Permission\Contracts\Permission` contract.
         */

        'permission' => Permission::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your roles. Of course, it
         * is often just the "Role" model but you may use whatever you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Spatie\Permission\Contracts\Role` contract.
         */

        'role' => Role::class,

        /*
         * When using the "Teams" feature from this package, we need to know which
         * Eloquent model should be used to retrieve your teams. Of course, it
         * is often just the "Team" model but you may use whatever you like.
         */
        /*
         * ⭐ আমাদের "টিম" মানে কোম্পানি — ৭ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ ঘরটা `null` রেখে দিয়েছিলাম, ভেবেছিলাম কেবল কলামের নামটাই
         * দরকার। ⛔ কিন্তু teams চালু হলে spatie `User`-এ একটা `teams()`
         * সম্পর্ক যোগ করে, আর সে জিজ্ঞেস করে **কোন মডেলটা টিম**।
         *
         * ⓘ ধরা পড়েছে `ARelationPointedAtAClassThatWasNotThere` পাহারায়:
         * *"User::teams() — No team model configured."*
         *
         * ⭐ পাহারাটা এমন একটা সম্পর্ক ধরেছে **যেটা আমি যোগ করেছি বলেও
         * জানতাম না** — কনফিগের একটা লাইন বদলে দেওয়ায় মডেলে একটা নতুন
         * সম্পর্ক এসে গিয়েছিল।
         */
        'team' => App\Models\Company::class,

        /*
         * When using the "HasModels" trait and passing raw IDs to syncModels,
         * attachModels, or detachModels, this model class will be used to
         * resolve those IDs. If null, defaults to the guard's model.
         */
        'default_model' => null,
    ],

    'table_names' => [

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'roles' => 'roles',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your permissions. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'permissions' => 'permissions',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your models permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'model_has_permissions' => 'model_has_permissions',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your models roles. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'model_has_roles' => 'model_has_roles',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        /*
         * Change this if you want to name the related pivots other than defaults
         */
        'role_pivot_key' => null, // default 'role_id',
        'permission_pivot_key' => null, // default 'permission_id',

        /*
         * Change this if you want to name the related model primary key other than
         * `model_id`.
         *
         * For example, this would be nice if your primary keys are all UUIDs. In
         * that case, name this `model_uuid`.
         */

        'model_morph_key' => 'model_id',

        /*
         * Change this if you want to use the teams feature and your related model's
         * foreign key is other than `team_id`.
         */

        /*
         * ⭐ কোম্পানিই এখানে "টিম" — ৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ spatie-র শব্দটা `team`, আমাদের শব্দটা `company`। কলামের নাম
         * `company_id` রাখা হলো যাতে বাকি ৯০টা টেবিলের সাথে মেলে —
         * `team_id` লিখলে প্রতিটা join-এ কেউ থমকে ভাবত এটা আবার কী।
         */
        'team_foreign_key' => 'company_id',
    ],

    /*
     * When set to true, the method for checking permissions will be registered on the gate.
     * Set this to false if you want to implement custom logic for checking permissions.
     */

    'register_permission_check_method' => true,

    /*
     * When set to true, Laravel\Octane\Events\OperationTerminated event listener will be registered
     * this will refresh permissions on every TickTerminated, TaskTerminated and RequestTerminated
     * NOTE: This should not be needed in most cases, but an Octane/Vapor combination benefited from it.
     */
    'register_octane_reset_listener' => false,

    /*
     * Events will fire when a role or permission is assigned/unassigned:
     * \Spatie\Permission\Events\RoleAttachedEvent
     * \Spatie\Permission\Events\RoleDetachedEvent
     * \Spatie\Permission\Events\PermissionAttachedEvent
     * \Spatie\Permission\Events\PermissionDetachedEvent
     *
     * To enable, set to true, and then create listeners to watch these events.
     */
    'events_enabled' => false,

    /*
     * Teams Feature.
     * When set to true the package implements teams using the 'team_foreign_key'.
     * If you want the migrations to register the 'team_foreign_key', you must
     * set this to true before doing the migration.
     * If you already did the migration then you must make a new migration to also
     * add 'team_foreign_key' to 'roles', 'model_has_roles', and 'model_has_permissions'
     * (view the latest version of this package's migration file)
     */

    /*
     * ── ⛔ কেন এটা চালু করতেই হলো, ৭ সেপ্টেম্বর ২০২৬ ────────────────────
     * রোল বিশ্বজনীন ছিল, অর্থাৎ **এক ক্রেতার মালিক আর আরেক ক্রেতার মালিক
     * একই `owner` রোল** ভাগ করে নিতেন। ফল দুইটা, দুইটাই খারাপ:
     *
     *   ⛔ ব্যাকআপ নামানো — একজন সবার ডাটাবেস নামাতে পারতেন
     *   ⛔ রোল সম্পাদনা  — একজন "বিক্রয়কর্মী"-র ক্ষমতা বদলালে **সব
     *                      কোম্পানির** বিক্রয়কর্মীর ক্ষমতা বদলে যেত
     *
     * ⚠️ পারমিশন (১৮৪টা) বিশ্বজনীনই থাকে — ওটা পণ্যের শব্দভাণ্ডার, সবার
     * এক। ⓘ বদলায় কেবল **রোল**: "বিক্রয়কর্মী কী কী পারে" প্রতিটা
     * ব্যবসার নিজের সিদ্ধান্ত, আর মালিকের নিয়মও তাই — *"যে তালিকা
     * গ্রাহকভেদে বদলায়, সেটা সেটিংসের সারি"*।
     *
     * ── ⚠️ এর সবচেয়ে বড় বিপদ ──────────────────────────────────────────
     * teams চালু থাকলে **প্রতিটা পথে টিম-প্রসঙ্গ বসাতে হয়**। একটা পথে
     * ভুলে গেলে পারমিশন **নীরবে ভুল উত্তর দেয়** — হয় সবাই তালাবন্ধ, নয়
     * সবাই খোলা — আর **কোনো ত্রুটিবার্তা আসে না**।
     *
     * ⭐ তাই ভুলে যাওয়ার পথটাই বন্ধ করা হয়েছে: [[CompanyContext::set()]]
     * নিজেই টিমটা বসায়। কেউ আলাদা করে মনে রাখে না।
     */
    'teams' => true,

    /*
     * The class to use to resolve the permissions team id
     */
    'team_resolver' => DefaultTeamResolver::class,

    /*
     * Passport Client Credentials Grant
     * When set to true the package will use Passports Client to check permissions
     */

    'use_passport_client_credentials' => false,

    /*
     * When set to true, the required permission names are added to exception messages.
     * This could be considered an information leak in some contexts, so the default
     * setting is false here for optimum safety.
     */

    'display_permission_in_exception' => false,

    /*
     * When set to true, the required role names are added to exception messages.
     * This could be considered an information leak in some contexts, so the default
     * setting is false here for optimum safety.
     */

    'display_role_in_exception' => false,

    /*
     * By default wildcard permission lookups are disabled.
     * See documentation to understand supported syntax.
     */

    'enable_wildcard_permission' => false,

    /*
     * The class to use for interpreting wildcard permissions.
     * If you need to modify delimiters, override the class and specify its name here.
     */
    // 'wildcard_permission' => Spatie\Permission\WildcardPermission::class,

    /* Cache-specific settings */

    'cache' => [

        /*
         * By default all permissions are cached for 24 hours to speed up performance.
         * When permissions or roles are updated the cache is flushed automatically.
         */

        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        /*
         * The cache key used to store all permissions.
         */

        'key' => 'spatie.permission.cache',

        /*
         * You may optionally indicate a specific cache driver to use for permission and
         * role caching using any of the `store` drivers listed in the cache.php config
         * file. Using 'default' here means to use the `default` set in cache.php.
         */

        'store' => 'default',
    ],
];
