{{--
    ⭐ পাসওয়ার্ড রিসেট — তালিকা থেকে এক চাপে (মালিক, ২৪ নভেম্বর ২০২৬: "এখানে রিসেটের একটা কলাম করে দিয়ে দেও")।
    ⓘ সম্পাদনার পাতার "নিরাপত্তা (পাসওয়ার্ড)" ট্যাব সরাসরি খোলে ([[UserFormTabs::open()]] `?tab=security`) — নতুন কোনো পথ নয়:
      একই ফর্ম, একই নিয়ম (১২ অক্ষর, অক্ষর আর সংখ্যা), সেভ করলে পুরনো সব লগইন বাতিল আর নিরীক্ষার খাতায় লেখা ([[UserController::update()]])।
--}}
<a href="{{ route('system_admin.user.edit', ['user' => $user, 'tab' => 'security']) }}" data-user-reset
   class="inline-flex items-center gap-1 whitespace-nowrap rounded-full border border-(--color-border) px-2.5 py-0.5 text-xs font-semibold
          text-(--color-link) hover:bg-(--color-surface-hover)">
    <x-ui.icon name="lock" :size="14" /> {{ __('system_admin::field.reset_password') }}
</a>
