{{--
    সারির কাজের ঘর।

    মালিকের রোলে সম্পাদনার লিংক নেই — ওটা সংজ্ঞা অনুযায়ীই সব পারে, আর
    প্রতিটা ডিপ্লয়ে `abos:sync-permissions` নতুন অনুমতি ওখানে বসিয়ে
    দেয়। লিংকটা রাখলে সেটা একটা মিথ্যা প্রতিশ্রুতি দিত।
--}}
@if ($role->name === $ownerRole)
    <span class="text-(--color-ink-muted)">{{ __('system_admin::message.owner_role_fixed') }}</span>
@else
    <a href="{{ route('system_admin.role.edit', $role) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">
        {{ __('core.action.edit') }}
    </a>
@endif
