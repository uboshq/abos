{{--
    নির্ধারিত রিপোর্টের সারির কাজ — বদলান আর চালু/বন্ধ। দুই টেবিলেই (ABOS রূপের ভাগ করা টেবিল আর বাকি রূপের পুরনো টেবিল) এই একই অংশ।
    ⛔ ফর্মে `@csrf` — `csrf_field()` নয়: এই প্রকল্পের `@csrf` সঙ্গে দ্বৈত-জমার `_once` আর শাখার ছাপ `_branch`-ও বসায়
       ([[AppServiceProvider]], [[EveryFormCarriesItsOwnTokenTest]]; Mac run srt3 of 865f48b8a ধরেছিল, ২৮ নভেম্বর ২০২৬)।
--}}
<a href="{{ route('system_admin.reports.schedule.edit', $s) }}"
   class="text-(--color-brand-600) hover:underline">{{ __('core.action.edit') }}</a>
<form method="POST" action="{{ route('system_admin.reports.schedule.toggle', $s) }}"
      class="inline">
    @csrf
    <button type="submit"
            class="ms-3 text-2xs text-(--color-ink-muted) underline hover:text-(--color-ink)">
        {{ __($s->is_active ? 'system_admin::schedule.deactivate' : 'system_admin::schedule.activate') }}
    </button>
</form>
