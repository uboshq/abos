{{-- ⓘ খাতার এক ঘর — ABOS রূপের টেবিলে ([[audit-rows]]); লেখা পুরোনো টেবিলের হুবহু --}}
@php
    $person = $who->get($row->user_id);
    $channel = $row->channel ?? null;
@endphp
@if ($cell === 'when')
<div class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime(\Illuminate\Support\Carbon::parse($row->at)) }}</div>
@elseif ($cell === 'who')
<div class="text-2xs">
                                    @if ($row->user_id === null)
                                        {{ $name }}
                                    @else
                                        <span class="block font-medium">{{ $person?->name ?? '#'.$row->user_id }}</span>
                                        <span class="block text-(--color-ink-muted)">ID {{ $row->user_id }}@if ($person?->email) · {{ $person->email }}@endif</span>
                                    @endif
                                </div>
@elseif ($cell === 'kind')
<div class="text-2xs"><span class="rounded-(--radius-badge) px-1.5 py-0.5 {{ $tone[$row->kind] ?? 'bg-(--color-surface-sunken) text-(--color-ink)' }}">{{ __('assistant::audit.kinds.'.$row->kind) }}</span></div>
@elseif ($cell === 'what')
<div class="max-w-md text-2xs">
                                    @if ($row->kind === 'visit' && str_starts_with((string) $row->what, '/') === false && isset($pages[strtok((string) $row->page, '?')]))
                                        {{ $pages[strtok((string) $row->page, '?')] }}
                                    @else
                                        {{ $row->what ?? __('assistant::audit.text_gone') }}
                                    @endif
                                    @if ($row->reason)<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.reason') }}: {{ $row->reason }}</span>@endif
                                </div>
@elseif ($cell === 'page')
<div class="max-w-xs break-all text-2xs">{{ $row->kind === 'change' ? class_basename((string) $row->page) : ($row->page ?? '—') }}</div>
@elseif ($cell === 'how')
<div class="whitespace-nowrap text-2xs" title="{{ $row->user_agent }}">
                                    {{ \App\Modules\Assistant\Support\Device::name($row->user_agent, $channel) }}
                                    @if ($channel === 'app' || $channel === 'web')<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.channels.'.$channel) }}</span>@endif
                                </div>
@elseif ($cell === 'where')
<div class="whitespace-nowrap text-2xs tabular-nums">
                                    {{ $row->ip_address ?? '—' }}
                                    @if ($row->place)<span class="block text-(--color-ink-muted)">{{ $row->place }}</span>@endif
                                    @if ($row->branch_id && isset($branches[$row->branch_id]))<span class="block text-(--color-ink-muted)">{{ $branches[$row->branch_id] }}</span>@endif
                                </div>
@elseif ($cell === 'outcome')
<div class="text-2xs">
                                    @if ($row->kind === 'search')
                                        {{ __('assistant::audit.hits', ['n' => (int) $row->outcome]) }}
                                    @elseif ($row->kind === 'login')
                                        {{ $row->outcome === 'ok' ? __('assistant::audit.login_ok') : __('assistant::audit.login_failed').' · '.$row->outcome }}
                                    @elseif ($row->kind === 'change')
                                        {{ \Illuminate\Support\Facades\Lang::has('assistant::audit.actions.'.$row->outcome) ? __('assistant::audit.actions.'.$row->outcome) : str_replace('_', ' ', (string) $row->outcome) }}
                                    @else
                                        {{ $row->outcome ?? '—' }}
                                    @endif
                                </div>
@endif
