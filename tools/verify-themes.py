# -*- coding: utf-8 -*-
"""আটটা রূপ ব্রাউজারে খুলে নমুনার মানের সাথে মেলায় — চোখে নয়, computed value ধরে।

সারি থাকা পর্দায় দেখা হয় (`/suppliers`), খালি পাতায় নয়: খালি তালিকা
কেবল খালি পথটাই প্রমাণ করে।
"""
import subprocess, sys, pathlib
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8081"
OUT = pathlib.Path(sys.argv[1]); OUT.mkdir(parents=True, exist_ok=True)
# রূপ **আর তার সুপারিশ করা রং** — চেহারার পাতায় টিক দিলে ঠিক এটাই ঘটে।
# শুধু রূপ বসালে বোতামের কালি আগের রঙেরই থাকত, আর নকলটা অর্ধেক হত।
B = chr(92)
TPL = ("App" + B + "Models" + B + "User::where('email','owner@abos.test')"
       "->update(['ui'=>'%s','accent'=>" + B + "App" + B + "Core" + B + "Support"
       + B + "Ui::accent('%s')]);")

# নমুনার মান — artifact 4b0e0e40 (আটটা রূপ) ও fed36f24 (ক্লাসিক)
SPEC = {
 "classic": {"--color-section-head":"#efebe2","--color-brand-ink":"rgb(26 26 26 / 1)","--color-surface-app":"#edeae3","--color-topbar":"#23303c","--color-topnav":"#33424f",
             "--color-topnav-selected":"#e08c1a","--color-table-head":"#5e6b78","--color-row-alt":"#fbfaf6",
             "--color-surface-hover":"#fdf3df","--color-link":"#1d4e89","--row-height":"26px",
             "--font-size-table":"12.5px","--radius-field":"2px"},
 "tiles":   {"--color-section-head":"transparent","--color-brand-ink":"rgb(255 255 255 / 1)","--color-surface-app":"#f5f6f7","--color-topbar":"#354a5f","--color-ink":"#32363a",
             "--color-ink-muted":"#6a6d70","--color-link":"#0a6ed1","--color-surface-hover":"#eaf2fb",
             "--color-border":"#e5e5e5","--row-height":"32px","--font-size-table":"13px",
             "--radius-field":"4px"},
 "suite":   {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-surface-app":"#f0f0f0","--color-topbar":"#2e4b6b","--color-topnav":"#3c5f84",
             "--color-table-head":"#d9e1e9","--color-row-alt":"#f5f7f9","--color-surface-hover":"#fff8dc",
             "--color-link":"#1b62a5","--color-border":"#c3ccd5","--row-height":"24px",
             "--font-size-table":"11px","--radius-field":"2px"},
 "apps":    {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-topbar":"#714b67","--color-ink":"#1f1b24","--color-ink-muted":"#8a7f90",
             "--color-border":"#e5dee3","--color-link":"#5b3c54","--color-surface-hover":"#fbf9fb",
             "--row-height":"44px","--font-size-table":"13px","--radius-field":"6px",
             "--radius-badge":"999px"},
 "dynamic": {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-topbar":"#0b2a4a","--color-ink":"#161a1f","--color-ink-muted":"#616161",
             "--color-border":"#e1e1e1","--color-link":"#0f6cbd","--color-surface-hover":"#f3f9fd",
             "--color-sidebar-panel":"#f5f5f5","--row-height":"44px","--font-size-table":"12.5px",
             "--radius-field":"2px"},
 "redwood": {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-surface-app":"#f5f4f2","--color-surface-card":"#fffdfb","--color-topbar":"#fffdfb",
             "--color-ink":"#161513","--color-ink-muted":"#8a8681","--color-link":"#0b5a9e",
             "--color-border":"#e6e2dc","--color-sidebar-active":"#c74634","--row-height":"52px",
             "--font-size-table":"13px","--radius-card":"16px","--radius-field":"999px"},
 "rose":    {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-sidebar":"#f6f8fb","--color-ink":"#1b2430","--color-ink-muted":"#5b6575",
             "--color-border":"#d9e1ec","--color-link":"#9d1249","--color-sidebar-active":"#c2185b",
             "--color-surface-selected":"#fff1f6","--row-height":"44px","--font-size-table":"13px",
             "--radius-field":"8px","--radius-card":"12px"},
 "navy":    {"--color-brand-ink":"rgb(255 255 255 / 1)","--color-section-head":"transparent","--color-surface-app":"#f2f6fc","--color-sidebar":"#08132b","--color-sidebar-active":"#f5b800",
             "--color-ink":"#0f172a","--color-ink-muted":"#64748b","--color-link":"#1565c0",
             "--color-border":"#e5e7eb","--color-table-head":"#f8fafc","--row-height":"44px",
             "--font-size-table":"12.5px","--radius-field":"6px","--radius-card":"8px"},
}
NAV = {"classic":"top","tiles":"top","suite":"top","apps":"top",
       "dynamic":"rail","redwood":"rail","rose":"rail","navy":"rail"}


def norm(v):
    v = v.strip().lower()
    if v.startswith('#') and len(v) == 4:          # #fff -> #ffffff
        v = '#' + ''.join(c * 2 for c in v[1:])
    return v


bad_total = 0
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1440, "height": 900})
    pg.goto(BASE + "/login", wait_until="domcontentloaded")
    pg.fill("input[name=identifier]", "owner@abos.test")
    pg.fill("input[name=password]", "password")
    pg.click("button[type=submit]")
    pg.wait_for_load_state("networkidle")

    for look, spec in SPEC.items():
        subprocess.run(["php", "artisan", "tinker", "--execute", TPL % (look, look)],
                       cwd=r"E:\ABOS\abos", capture_output=True, timeout=180)
        pg.goto(BASE + "/suppliers", wait_until="networkidle")

        got = pg.evaluate(
            "ns => Object.fromEntries(ns.map(n => "
            "[n, getComputedStyle(document.documentElement).getPropertyValue(n).trim()]))",
            list(spec))
        shell = pg.evaluate(
            "() => ({ui: document.documentElement.dataset.ui,"
            " top: !!document.querySelector('nav.topnav'),"
            " rail: !!document.querySelector('aside'),"
            " rows: document.querySelectorAll('.ui-list tbody tr').length,"
            " td: (t => t ? getComputedStyle(t).height : '-')"
            "(document.querySelector('.ui-list tbody td'))})")

        bad = [(k, v, norm(got.get(k, ''))) for k, v in spec.items()
               if norm(got.get(k, '')) != norm(v)]
        navok = (shell['top'] if NAV[look] == 'top' else shell['rail'])
        if not navok:
            bad.append(("nav", NAV[look], "top=%s rail=%s" % (shell['top'], shell['rail'])))
        bad_total += len(bad)

        print("%-8s ui=%-8s %2d/%d  rows=%d td=%s  %s"
              % (look, shell['ui'], len(spec) - len(bad), len(spec),
                 shell['rows'], shell['td'], "OK" if not bad else "MISMATCH"))
        for k, want, g in bad:
            print("           %-26s want=%-9s got=%s" % (k, want, g))

        pg.screenshot(path=str(OUT / (look + ".png")))

    subprocess.run(["php", "artisan", "tinker", "--execute", TPL % ("navy", "navy")],
                   cwd=r"E:\ABOS\abos", capture_output=True, timeout=180)
    b.close()

print("\ntotal mismatches:", bad_total)
