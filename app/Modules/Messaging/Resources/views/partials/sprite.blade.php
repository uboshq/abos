{{--
    The messenger's icons and the chat wallpaper (sample M1UddRxK v7) — one hidden sprite, drawn once.
    ⚠️ width/height 0 and absolutely placed, never display:none — a hidden sprite loses its <pattern> in some browsers.
--}}
<svg class="msgr-sprite" width="0" height="0" aria-hidden="true" focusable="false">
<defs>
<symbol id="mg-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="mg-pencil" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></g></symbol>
<symbol id="mg-phone" viewBox="0 0 24 24"><path d="M5 3.5h3.5l1.5 4-2 1.5a11 11 0 0 0 7 7l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.1 1.5 1.5 0 0 1 5 3.5z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></symbol>
<symbol id="mg-video" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><rect x="2.5" y="6" width="13" height="12" rx="2.5"/><path d="m15.5 10.2 6-3.4v10.4l-6-3.4z"/></g></symbol>
<symbol id="mg-kebab" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.9" fill="currentColor"/><circle cx="12" cy="12" r="1.9" fill="currentColor"/><circle cx="12" cy="19" r="1.9" fill="currentColor"/></symbol>
<symbol id="mg-back" viewBox="0 0 24 24"><path d="M19.5 12h-15M10.5 5.5 4 12l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-clock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/></g></symbol>
<symbol id="mg-belloff" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 6.5 2.5 8 2.5 8h-17S6 15.5 6 9z"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0M3 3l18 18"/></g></symbol>
<symbol id="mg-bell" viewBox="0 0 24 24"><path d="M6 9a6 6 0 0 1 12 0c0 6.5 2.5 8 2.5 8h-17S6 15.5 6 9z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="M10 20.5a2.2 2.2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></symbol>
<symbol id="mg-users" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18.5 14.8c1.6.8 2.6 2.6 3 5.2"/></g></symbol>
<symbol id="mg-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"/></symbol>
<symbol id="mg-smile" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8 14.2a4.6 4.6 0 0 0 8 0"/></g><circle cx="9" cy="9.8" r="1.2" fill="currentColor"/><circle cx="15" cy="9.8" r="1.2" fill="currentColor"/></symbol>
<symbol id="mg-clip" viewBox="0 0 24 24"><path d="m20 11.5-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4L15 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-micf" viewBox="0 0 24 24"><rect x="8.5" y="2" width="7" height="12.5" rx="3.5" fill="currentColor"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="mg-dictate" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2.5" width="6" height="11" rx="3"/><path d="M5.5 10.5a6.5 6.5 0 0 0 13 0M12 17v3.5"/><path d="M3 21h4M10 21h4M17 21h4"/></g></symbol>
<symbol id="mg-send" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"><path d="M3.5 11.2 20.5 4l-6.8 16.5-2.9-6.8z"/><path d="m10.8 13.7 9.7-9.7"/></g></symbol>
<symbol id="mg-image" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"><rect x="3" y="4.5" width="18" height="15" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="m3.5 17 5-5 4 4 3-3 5 5"/></g></symbol>
<symbol id="mg-doc" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5M8.5 13h7M8.5 16.5h5"/></g></symbol>
<symbol id="mg-paper" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5M8.5 12.5h7M8.5 16h4.5"/><path d="M9.5 3v5.5a1.5 1.5 0 0 1-3 0V5" stroke-width="1.6"/></g></symbol>
<symbol id="mg-camera" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.6"/></g></symbol>
<symbol id="mg-deposit" viewBox="0 0 24 24"><path d="M12 1.8v6.4M9.2 5.6 12 8.4l2.8-2.8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="2.5" y="10.5" width="19" height="11" rx="2.4" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M9.5 14.2h5M9.5 17h5M11 14.2c2 0 2 2.8 0 2.8l2.6 2.6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-tk1" viewBox="0 0 18 12"><path d="m3.5 6.5 3 3 6.5-7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-tk2" viewBox="0 0 18 12"><path d="m1 6.5 3 3L10.5 2M8 9.5 14.5 2M6.3 7.8l1.7 1.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-play" viewBox="0 0 24 24"><path d="M7 4.5v15l12.5-7.5z" fill="currentColor"/></symbol>
<symbol id="mg-pause" viewBox="0 0 24 24"><path d="M7 4.5h3.5v15H7zM13.5 4.5H17v15h-3.5z" fill="currentColor"/></symbol>
<symbol id="mg-download" viewBox="0 0 24 24"><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-pin" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.4"/></g></symbol>
<symbol id="mg-copy" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v9A1.5 1.5 0 0 0 5 15.5h3.5"/></g></symbol>
<symbol id="mg-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-reply" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 7 4.5 12 10 17"/><path d="M5 12h9a5.5 5.5 0 0 1 5.5 5.5V19"/></g></symbol>
<symbol id="mg-fwd" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 7l5.5 5-5.5 5"/><path d="M19 12h-9A5.5 5.5 0 0 0 4.5 17.5V19"/></g></symbol>
<symbol id="mg-trash" viewBox="0 0 24 24"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13M10 11v5.5M14 11v5.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-info" viewBox="0 0 24 24"><path d="M12 11v6M12 7.6v.1" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.9"/></symbol>
<symbol id="mg-chev" viewBox="0 0 24 24"><path d="m6.5 9.5 5.5 5.5 5.5-5.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-ban" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/></g></symbol>
<symbol id="mg-leaf" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 19c0-9 5.5-14 15-14 0 9.5-5.5 14-15 14z"/><path d="M5 19 13 11"/></g></symbol>
<symbol id="mg-cup" viewBox="0 0 24 24"><path d="M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5zM16 10.5h1.5a2.5 2.5 0 0 1 0 5H16M8.5 3.5v2.5M12 3.5v2.5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-ball" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5l4 3-1.5 4.5h-5L8 10.5z"/></g></symbol>
<symbol id="mg-car" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16.5V12l2-5h12l2 5v4.5zM4 12h16"/><circle cx="8" cy="16.5" r="1.6"/><circle cx="16" cy="16.5" r="1.6"/></g></symbol>
<symbol id="mg-bulb" viewBox="0 0 24 24"><path d="M9 17.5h6M10 20.5h4M8 14a6 6 0 1 1 8 0c-.8.8-1 1.8-1 3.5H9c0-1.7-.2-2.7-1-3.5z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="mg-heart" viewBox="0 0 24 24"><path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.2 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></symbol>
<symbol id="mg-flag" viewBox="0 0 24 24"><path d="M5.5 21V4M5.5 4.5h12l-2.5 4 2.5 4h-12" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></symbol>
{{-- our own chat wallpaper: receipts, coins, boxes, trucks, calculators, leaves (sample) --}}
<pattern id="mg-doodle" width="180" height="180" patternUnits="userSpaceOnUse">
<g fill="none" class="mg-doodle-ink" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
<path d="M14 12h20v26l-3.3-2.2-3.3 2.2-3.4-2.2-3.3 2.2-3.4-2.2L14 38z"/><path d="M18.5 20h11M18.5 25.5h11M18.5 31h6"/>
<circle cx="68" cy="24" r="10"/><circle cx="68" cy="24" r="6.5"/>
<path d="M108 16l13-6.5 13 6.5v14l-13 6.5-13-6.5z"/><path d="M108 16l13 6.5 13-6.5M121 22.5v14"/>
<path d="m160 16 2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.5 5-.7z"/>
<path d="M12 74h20v13H12zM32 78h7l4.5 4.5V87H32"/><circle cx="18" cy="89.5" r="2.6"/><circle cx="37" cy="89.5" r="2.6"/>
<path d="M66 66h26a4.5 4.5 0 0 1 4.5 4.5v10A4.5 4.5 0 0 1 92 85H77l-7 5.5V85h-4a4.5 4.5 0 0 1-4.5-4.5v-10A4.5 4.5 0 0 1 66 66z"/><path d="M70 74h16M70 79h10"/>
<path d="M120 96c0-15 10.5-24 26-24 0 15-10.5 24-26 24z"/><path d="M120 96l15-13"/>
<rect x="160" y="70" width="16" height="22" rx="3"/><path d="M163.5 74h9v4.5h-9z"/>
<path d="M14 130l16-7-5.5 16-3.5-5.5z"/><path d="M21 133.5 30 123"/>
<circle cx="72" cy="134" r="10"/><path d="M72 128.5v5.5l3.5 2.2"/>
<path d="M124 136c-3.2-4.2-9.5-2.2-9.5 3.2 0 4.4 9.5 9.6 9.5 9.6s9.5-5.2 9.5-9.6c0-5.4-6.3-7.4-9.5-3.2z"/>
<path d="M150 150c4-4 8 4 12 0s8 4 12 0"/>
<path d="M40 160h22v12H40zM45 160v-4h12v4"/>
<path d="M96 158l4 8 8 1.2-6 5.6 1.5 8"/>
</g>
<g class="mg-doodle-dot"><circle cx="48" cy="52" r="2"/><circle cx="148" cy="48" r="2"/><circle cx="98" cy="112" r="2"/><circle cx="170" cy="122" r="2"/><circle cx="6" cy="166" r="2"/></g>
</pattern>
</defs>
</svg>
