{{-- নোটিশের তালিকার টেবিল — বার থাকা আর না-থাকা দুই রূপেই একই সারি --}}
<div class="table-responsive">
    <table class="ui-list table-cards w-full border-collapse">
        <thead>
            <tr class="border-b border-(--color-border)">
                <th class="text-start">{{ __('system_admin::notice.field_title') }}</th>
                {{-- অবস্থা আর অগ্রাধিকার — ২৪ সেপ্টেম্বর ২০২৬।

                     ⓘ আগে তালিকায় কেবল শিরোনাম আর সময় ছিল। ⛔ খসড়া,
                     অনুমোদনের অপেক্ষা আর প্রকাশিত নোটিশ তাতে একই রকম
                     দেখাত, আর কেউ বলতে পারত না কোনটা সত্যিই বেরিয়েছে। --}}
                <th class="text-start">{{ __('core.table.status') }}</th>
                <th class="text-start">{{ __('core.notice.priority_label') }}</th>
                <th class="text-start">{{ __('system_admin::notice.roles') }}</th>
                <th class="text-start">{{ __('system_admin::notice.period') }}</th>
                <th class="text-end"><span class="sr-only">{{ __('core.action.view') }}</span></th>
            </tr>
        </thead>

        <tbody>
            @foreach ($rows as $row)
                <tr class="hover:bg-(--color-surface-hover)">
                    <td data-label="{{ __('system_admin::notice.field_title') }}">
                        <a href="{{ route('system_admin.notice.show', $row->id) }}"
                           class="font-medium text-(--color-link) hover:underline">
                            {{ $row->title }}
                        </a>

                        {{-- ⭐ না-পড়া নোটিশে একটা দাগ — নাহলে তালিকায়
                             নতুনটা পুরনোগুলোর মতোই দেখাত। --}}
                        @if (in_array($row->id, $unread, true))
                            <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-info-bg)
                                         px-2 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                {{ __('system_admin::notice.unread_badge') }}
                            </span>
                        @endif

                        @if (! $row->is_active)
                            <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg)
                                         px-2 py-0.5 text-2xs text-(--color-badge-draft-ink)">
                                {{ __('system_admin::notice.off') }}
                            </span>
                        @endif
                    </td>

                    <td data-label="{{ __('core.table.status') }}">
                        @if ($row->status)
                            <span class="rounded-(--radius-badge) px-2 py-0.5 text-2xs
                                         {{ $row->status->isLive()
                                             ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                                             : 'bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)' }}">
                                {{ $row->status->label() }}
                            </span>
                        @endif
                    </td>

                    <td class="text-(--color-ink-muted)"
                        data-label="{{ __('core.notice.priority_label') }}">
                        {{ $row->priority?->label() }}
                    </td>

                    <td class="text-(--color-ink-muted)"
                        data-label="{{ __('system_admin::notice.roles') }}">
                        @php $who = $row->audience->pluck('role')->all(); @endphp
                        {{ $who === [] ? __('system_admin::notice.everyone') : implode(' · ', array_map(\App\Core\Support\RoleLabel::for(...), $who)) }}
                    </td>

                    <td class="text-(--color-ink-muted)"
                        data-label="{{ __('system_admin::notice.period') }}">
                        @if ($row->starts_on === null && $row->ends_on === null)
                            {{ __('system_admin::notice.always') }}
                        @else
                            {{ ($row->starts_on ? \App\Core\Support\DateFormat::spokenDate($row->starts_on) : '—') }}
                            →
                            {{ ($row->ends_on ? \App\Core\Support\DateFormat::spokenDate($row->ends_on) : '—') }}
                        @endif
                    </td>

                    <td class="text-end">
                        <a href="{{ route('system_admin.notice.show', $row->id) }}"
                           class="text-(--color-link) hover:underline">{{ __('core.action.view') }}</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
