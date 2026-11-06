{{--
    ⭐ জরুরি সেটিংয়ের বদল — কী অপেক্ষায় গেল, কী আটকাল, আর কোনগুলো অনুমোদনের অপেক্ষায় (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭;
    [[SignedSettings]])। ⓘ যিনি চাইলেন তিনি নিজের সারিতে বোতাম পান না — কেবল "অন্য কেউ অনুমোদন দেবেন"।
--}}
@php
    $signed = app(\App\Core\Services\SignedSettings::class);
    $waitingChanges = ($listChanges ?? true) ? $signed->pending() : collect();
    $me = auth()->user();
@endphp

@foreach ((array) session('setting_waits', []) as $line)
    <div role="status" data-setting-waits
         class="mb-3 rounded-(--radius-field) border-s-4 border-(--color-board-wait) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
        {{ $line }}
    </div>
@endforeach

@if ($waitingChanges->isNotEmpty())
    <section id="setting-changes" data-boxed data-setting-changes
             class="mb-4 rounded-(--radius-card) border border-(--color-border) border-t-4 border-t-(--color-board-wait) bg-(--color-surface-card) p-4">
        <h2 class="font-semibold">{{ __('system_admin::settings.changes_title') }} <span class="num text-(--color-ink-muted)">· {{ $waitingChanges->count() }}</span></h2>
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.changes_note') }}</p>
        <ul class="divide-y divide-(--color-border)">
            @foreach ($waitingChanges as $change)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" data-setting-change="{{ $change->key }}">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $signed->summaryOf($change) }}</p>
                        <p class="text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.change_asked_by', ['name' => $change->requester?->name ?? '—', 'when' => \App\Core\Support\DateFormat::spokenDate($change->created_at)]) }}</p>
                    </div>
                    @if ($me !== null && $signed->canSign($change, $me))
                        <div class="flex items-center gap-2">
                            <form method="POST" action="{{ route('system_admin.setting-change.sign', $change->public_id) }}">
                                @csrf
                                <x-ui.button type="submit" tone="primary" data-setting-change-sign>{{ __('system_admin::settings.change_sign') }}</x-ui.button>
                            </form>
                            <form method="POST" action="{{ route('system_admin.setting-change.reject', $change->public_id) }}">
                                @csrf
                                <x-ui.button type="submit" tone="secondary" data-setting-change-reject>{{ __('system_admin::settings.change_reject') }}</x-ui.button>
                            </form>
                        </div>
                    @else
                        <span class="text-xs text-(--color-ink-muted)" data-setting-change-own>{{ __('system_admin::settings.change_own') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
