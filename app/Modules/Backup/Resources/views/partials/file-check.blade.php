                                    @if ($file['check'] === null)
                                        <span class="text-(--color-ink-muted)">{{ __('backup::screen.unchecked') }}</span>
                                    @elseif ($file['check']->sawSomething())
                                        <span class="font-semibold text-(--color-badge-success-ink)">{{ __('backup::screen.checked') }}</span>
                                    @else
                                        <span class="font-semibold text-(--color-badge-danger-ink)">{{ __('backup::screen.check_broke') }}</span>
                                    @endif
