{{--
    ⭐ তালিকার একসাথে কাজের বার, ধাপ ২ — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"; [[BulkRun]])।

    পাতা দেয়: `id` (সারির টিক-বাক্স `form="<id>"`, [[BulkRun::pickColumn()]]) আর `actions` — যে কাজগুলো এই মানুষের চাবিতে খোলা:
      [['action' => 'submit'|'cancel'|'approve', 'url' => route(...)], …]
    ⓘ "ছাপুন" আর "Excel" নিজে থেকেই, তালিকার রপ্তানির চাবি থাকলে (`system_admin.list.export`) — তালিকার নিজের প্রশ্নে বাছা সারি
      ([[ListExport::picked()]]), তাই অন্য শাখার কাগজ হাতে বানানো id দিয়েও আসে না।
    ⓘ বাতিলে কারণ লাগে — বারেই একটা ঘর; সার্ভার আবার চায়।
    ⛔ কেবল তিন ABOS রূপে ([[BulkRun::showsHere()]]) — SAP আর Dynamic-এ কিছুই আঁকা হয় না।
    ⛔ সবকিছু সার্ভার আবার দেখে — বোতাম থাকা মানে অনুমতি নয়।
--}}
@props(['id', 'actions' => []])
@php
    $shows = \App\Core\Bulk\BulkRun::showsHere();
    $canExport = $shows && (auth()->user()?->can('system_admin.list.export') ?? false);
    $actions = $shows ? array_values(array_filter($actions, fn ($a) => ! empty($a['url']))) : [];
    $result = $shows ? session(\App\Core\Bulk\BulkOutcome::FLASH) : null;
    $tones = [\App\Core\Bulk\BulkRun::SUBMIT => 'primary', \App\Core\Bulk\BulkRun::APPROVE => 'success', \App\Core\Bulk\BulkRun::CANCEL => 'danger'];
    $hasCancel = collect($actions)->contains('action', \App\Core\Bulk\BulkRun::CANCEL);
@endphp

@if (is_array($result))
    <div role="status" data-bulk-result
         class="border-b border-(--color-border) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
        {{ $result['summary'] ?? '' }}
        @if (! empty($result['details']))
            <p class="mt-1 text-(--color-badge-warning-ink)" data-bulk-skipped>{{ $result['details'] }}</p>
        @endif
    </div>
@endif

@if ($actions !== [] || $canExport)
    <form method="POST" id="{{ $id }}" data-bulk-form data-bulk-kind="papers"
          data-count-label="{{ __('core.bulk.count') }}" data-all-label="{{ __('core.bulk.all') }}"
          class="flex flex-wrap items-center gap-2 border-b border-(--color-border) px-3 py-2">
        @csrf
        <input type="hidden" name="back" value="{{ request()->fullUrl() }}">

        @foreach ($actions as $a)
            <x-ui.button type="submit" :tone="$tones[$a['action']] ?? 'secondary'"
                         formaction="{{ $a['url'] }}" data-bulk-do="{{ $a['action'] }}"
                         data-bulk-confirm="{{ __('core.bulk.confirm.'.$a['action']) }}">
                {{ __('core.bulk.action.'.$a['action']) }}
            </x-ui.button>
        @endforeach

        @if ($hasCancel)
            <input type="text" name="reason" maxlength="500" data-bulk-reason
                   placeholder="{{ __('core.bulk.reason') }}" aria-label="{{ __('core.bulk.reason') }}"
                   class="h-(--spacing-field-compact) min-w-48 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
        @endif

        @if ($canExport)
            <x-ui.button type="button" tone="secondary" icon="printer" data-bulk-export="print">{{ __('core.bulk.action.print') }}</x-ui.button>
            <x-ui.button type="button" tone="secondary" data-bulk-export="xlsx">{{ __('core.bulk.action.export') }}</x-ui.button>
        @endif
    </form>
@endif
