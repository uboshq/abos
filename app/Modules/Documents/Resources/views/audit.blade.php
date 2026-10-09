{{--
    ডকুমেন্টের অডিট ট্রেইল (§১৮; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬) — কে · কী · কোন কাগজ · কবে · IP · যন্ত্র।

    ⛔ কোনো মোছার বোতাম নেই, আর থাকবেও না।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Models\AuditTrail;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.audit') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.audit')" :subtitle="__('documents::message.audit_subtitle')" />
    </x-slot:header>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-(--color-border) px-4 py-3">
            <x-ui.field name="from" type="date" :label="__('documents::field.date_from')" :value="$filters['from']" />
            <x-ui.field name="to" type="date" :label="__('documents::field.date_to')" :value="$filters['to']" />
            <x-ui.select name="user_id" :label="__('documents::field.who')" :options="$people" :selected="$filters['user_id']" placeholder="—" />
            <x-ui.field name="action" :label="__('documents::field.what')" :value="$filters['action']" maxlength="24"
                        :hint="__('documents::message.audit_action_hint')" />
            <x-ui.button type="submit" icon="search">{{ __('documents::action.find') }}</x-ui.button>
        </form>

        <div class="overflow-x-auto">
            <table class="ui-list w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::field.when') }}</th>
                        <th class="text-start">{{ __('documents::field.who') }}</th>
                        <th class="text-start">{{ __('documents::field.what') }}</th>
                        <th class="text-start">{{ __('documents::field.document_no') }}</th>
                        <th class="text-start">{{ __('documents::field.name') }}</th>
                        <th class="text-start">{{ __('documents::field.note') }}</th>
                        <th class="text-start">{{ __('documents::field.ip') }}</th>
                        <th class="text-start">{{ __('documents::field.device') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr data-audit="{{ $row->action }}">
                            <td class="whitespace-nowrap">{{ DateFormat::formatWithTime($row->created_at) }}</td>
                            <td>{{ $row->user?->name ?? '—' }}</td>
                            <td>{{ AuditTrail::actionInWords($row->action) }}</td>
                            <td class="num whitespace-nowrap">{{ $row->document_no }}</td>
                            <td class="max-w-[16rem] truncate">{{ $row->label }}</td>
                            <td class="max-w-[14rem] truncate">{{ $row->reason }}</td>
                            <td class="num whitespace-nowrap">{{ $row->ip_address ?? '—' }}</td>
                            <td class="max-w-[14rem] truncate text-2xs text-(--color-ink-muted)" title="{{ $row->user_agent }}">{{ $row->user_agent ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-(--color-ink-muted)">{{ __('core.empty.no_results') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
