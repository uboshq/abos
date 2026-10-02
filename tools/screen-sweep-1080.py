# -*- coding: utf-8 -*-
"""ABOS — ১০৮০p পর্দা-ঝাড়ু: প্রতিটা মেনুর পাতা ১৯২০×১০৮০-এ ঠিক আছে কি না।

মালিকের বাধ্যতামূলক নিয়ম, ২ অক্টোবর ২০২৬: *"sob porda 1080p korbe mendetory"*।
প্রতিটা পাতায় পাঁচটা জিনিস মাপা হয়:

  ক) পাশে স্ক্রল নেই        — document.scrollWidth <= জানালার চওড়া
  খ) পুরো চওড়া ব্যবহার      — মাঝখানে সরু কলাম নয় (max-w-* রেখে দুই পাশে ফাঁকা)
  গ) টুলবার এক লাইনে        — টুলবারের কোনো সারি ভেঙে নিচে নামেনি
  ঘ) ছক ধরে যায়            — নাহলে নিজের বাক্সের ভিতরে স্ক্রল করে, পাতা ঠেলে না
  ঙ) ডানে কিছু কাটা নেই      — কোনো দৃশ্যমান জিনিস ডান কিনারার বাইরে নয়

চালানো (পাতা জমা দেওয়ার আগে যেকোনো সেশন):

    py -m pip install playwright && py -m playwright install chromium
    $env:ABOS_SWEEP_PW='password'                 (PowerShell)
    $env:ABOS_SWEEP_TOTP='<base32 secret>'        (কেবল দুই ধাপ চালু থাকলে)
    py tools/screen-sweep-1080.py --base http://127.0.0.1:8791 --email owner@abos.test
    py tools/screen-sweep-1080.py ... --only /sales/        (কেবল যে ঠিকানায় এই টুকরো আছে)
    ⚠️ Git Bash-এ `/sales/` একটা Windows পথ হয়ে যায় — আগে MSYS_NO_PATHCONV=1 দিন।

    ফল: <out>/report.html (বাংলা), <out>/report.json, <out>/shots/*.png
    কোনো পাতা ভাঙা হলে exit code 1 — তাই জমার আগে চালিয়ে দেখা যায়।

⛔ কেবল GET আর দেখা — ক্লিক নয়, জমা নয়। ঠিকানা ধরে যাওয়া হয় (E:\\ABOS\\e2e\\abos_walk.py-র
   পথ), আর যে ঠিকানা কিছু বদলাতে পারে (delete, approve, post …) সেখানে যাওয়াই হয় না।
⛔ লাইভে (erp.adi.com.bd) চালানো নিষেধ — মালিকের আসল খাতা। স্ক্রিপ্ট নিজেই থামায়।
⚠️ পাসওয়ার্ড কেবল ABOS_SWEEP_PW থেকে, TOTP-র গোপন চাবি কেবল ABOS_SWEEP_TOTP থেকে —
   কমান্ডে নেওয়ার পথ নেই (শেলের ইতিহাসে থেকে যেত), আর রিপোর্টে কোনোটাই যায় না।
"""
from __future__ import annotations

import argparse
import base64
import datetime
import hashlib
import hmac
import json
import os
import struct
import sys
import time
from pathlib import Path
from urllib.parse import urljoin, urlparse, urlunparse

try:
    from playwright.sync_api import sync_playwright
except ImportError:  # pragma: no cover
    sys.exit('playwright নেই:  py -m pip install playwright && py -m playwright install chromium')

# ⛔ Windows-এর কনসোল cp1252 — বাংলা ছাপতে গেলেই ঝাড়ু মাঝপথে থামত
for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding='utf-8', errors='replace')
    except Exception:  # noqa: BLE001
        pass


VIEWPORT = {'width': 1920, 'height': 1080}

# ⛔ মালিকের আসল খাতা — এখানে ঝাড়ু চলে না
FORBIDDEN_HOSTS = ['erp.adi.com.bd']

# abos_walk.py-র একই তালিকা — "কেবল GET" মানেই "নিরাপদ" নয়
NEVER_VISIT = [
    'delete', 'destroy', 'remove', 'cancel', 'void', 'reject', 'approve',
    'reset', 'restore', 'signout', 'logout', 'confirm',
    'post-', '/post', 'clear', 'purge', 'truncate', 'rollback',
]
NOT_A_PAGE = ['export', 'download', '.csv', '.xlsx', '.pdf', '/print']

# রায়ের সীমা
WIDTH_RATIO_MIN = 0.90      # খ) মূল কলাম অন্তত ৯০% চওড়া
TOLERANCE_PX = 2            # উপ-পিক্সেলের গোলমাল
# গ) টুলবার কত লাইনে বসতে পারে — মালিকের ১০৮০p নিয়মে এক লাইন। (--toolbar-lines দিয়ে বদলায়)
TOOLBAR_MAX_LINES = 1
TRY_CSS = ''


# ── TOTP (RFC 6238 — SHA1, ৬ অঙ্ক, ৩০ সেকেন্ড) ───────────────────────────
def totp(secret: str, at: float | None = None) -> str:
    key = secret.strip().replace(' ', '').upper()
    key += '=' * (-len(key) % 8)
    raw = base64.b32decode(key)
    step = int((at if at is not None else time.time()) // 30)
    digest = hmac.new(raw, struct.pack('>Q', step), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = (struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1_000_000

    return '%06d' % code


def norm(url: str) -> str:
    p = urlparse(url)
    return urlunparse((p.scheme, p.netloc, p.path.rstrip('/') or '/', '', p.query, ''))


def skip_reason(url: str, base: str) -> str | None:
    p = urlparse(url)
    if p.scheme not in ('http', 'https'):
        return 'পাতা নয় (%s)' % (p.scheme or 'খালি')
    if p.netloc != urlparse(base).netloc:
        return 'অন্য সাইট'
    low = (p.path + '?' + (p.query or '')).lower()
    for bad in NEVER_VISIT:
        if bad in low:
            return 'বদলে দিতে পারে এমন ঠিকানা (%s)' % bad
    for bad in NOT_A_PAGE:
        # ⓘ '/print' কেবল পুরো অংশ হলে (…/print, …/print/…) — `/sales/print-queue` একটা তালিকার পাতা
        if bad == '/print':
            if any(seg == 'print' for seg in p.path.lower().split('/')):
                return 'ফাইল নামায় (%s)' % bad
            continue
        if bad in low:
            return 'ফাইল নামায় (%s)' % bad
    return None


# ── লগইন ──────────────────────────────────────────────────────────────────
def login(page, base: str, email: str, password: str, secret: str) -> tuple[bool, str]:
    page.goto(urljoin(base, '/signin'), wait_until='domcontentloaded')
    if page.locator('#identifier').count() == 0:
        return False, 'লগইনের ঘর (#identifier) পাতায় নেই'

    page.fill('#identifier', email)
    page.fill('#password', password)
    with page.expect_navigation(wait_until='domcontentloaded'):
        page.press('#password', 'Enter')

    here = urlparse(page.url).path.rstrip('/')

    # ⓘ দুই ধাপ চালু: /signin আবার আসে, সাথে #code ঘর। পাসওয়ার্ড আবার দিতে হয়
    # (সার্ভার কেবল identifier আর remember ফেরত দেয়), সাথে `code`।
    if (here.endswith('/signin') or here.endswith('/login')) and page.locator('#code').count():
        if not secret:
            return False, 'দুই ধাপ চালু — ABOS_SWEEP_TOTP বসানো নেই'
        # ⚠️ ৩০ সেকেন্ডের সীমার একদম কাছে হলে পরের ধাপের জন্য অপেক্ষা
        if 30 - (time.time() % 30) < 3:
            time.sleep(3.5)
        page.fill('#password', password)
        page.fill('#code', totp(secret))
        with page.expect_navigation(wait_until='domcontentloaded'):
            page.press('#code', 'Enter')
        here = urlparse(page.url).path.rstrip('/')

    if here.endswith('/two-step'):
        return False, 'দুই ধাপ বসানো বাকি — প্রতিটা পাতা বসানোর পর্দায় পাঠায়'
    if here.endswith('/signin') or here.endswith('/login'):
        note = ''
        for sel in ['[role="alert"]', 'form']:
            if page.locator(sel).count():
                note = page.locator(sel).first.inner_text()[:200].replace('\n', ' ')
                break
        return False, 'লগইন হয়নি — %s' % (note or 'কারণ পর্দায় লেখা নেই')

    return True, here or '/'


def menu_links(page) -> list[str]:
    """সাইডবার, রেলের ভাসমান তালিকা, উপরের/মডিউলের ট্যাব, নিচের নেভ — hover ছাড়া।"""
    hrefs = page.eval_on_selector_all(
        '[data-site-map] a[href], .rail-flyout a[href], [data-module-bar] a[href], '
        '[aria-label] nav a[href], a.bottom-nav-item[href]',
        '(els) => els.map(e => e.href)',
    )
    seen, out = set(), []
    for h in hrefs:
        n = norm(h)
        if n in seen:
            continue
        seen.add(n)
        out.append(h)
    return out


# ── মাপার JavaScript — পাতার ভিতরে চলে, কিছু বদলায় না ────────────────────
MEASURE_JS = r"""
() => {
  const TOL = %(tol)d
  const doc = document.scrollingElement || document.documentElement
  const vw = doc.clientWidth            // স্ক্রলবার বাদে দেখা যায় যতটা
  const iw = window.innerWidth

  const sel = (el) => {
    if (!el || el === document.body) return 'body'
    let s = el.tagName.toLowerCase()
    if (el.id) return s + '#' + el.id
    for (const a of el.getAttributeNames()) {
      if (a.startsWith('data-') && a.length < 30 && !a.startsWith('data-label')) { s += '[' + a + ']'; break }
    }
    const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(c => c && c.length < 40).slice(0, 3)
    if (cls.length) s += '.' + cls.join('.')
    const p = el.parentElement
    const ps = p && p !== document.body ? (p.id ? p.tagName.toLowerCase() + '#' + p.id
      : p.tagName.toLowerCase() + ((p.getAttribute('class') || '').trim() ? '.' + (p.getAttribute('class') || '').trim().split(/\s+/)[0] : '')) : ''
    return (ps ? ps + ' > ' : '') + s
  }
  const visible = (el, r) => {
    if (r.width < 2 || r.height < 2) return false
    const cs = getComputedStyle(el)
    if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) === 0) return false
    if (cs.position === 'fixed' && r.left >= vw) return false    // পর্দার বাইরে রাখা ড্রয়ার
    if (cs.clip && cs.clip !== 'auto' && cs.position === 'absolute') return false  // sr-only
    return true
  }
  // ⓘ চলমান পট্টি (স্ট্যাটাস বারের ব্যাকআপ-টিকার) ইচ্ছে করেই বাক্সের বাইরে দিয়ে ঘোরে — ওটা "কাটা" নয়
  const animated = (el) => {
    for (let a = el, i = 0; a && i < 4; a = a.parentElement, i++) {
      if (getComputedStyle(a).animationName !== 'none') return true
    }
    return false
  }
  // কাছের যে পূর্বপুরুষ আড়াআড়ি কিছু করে — স্ক্রল (auto/scroll) নাকি কাটা (hidden/clip)
  const xBox = (el) => {
    for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
      const ox = getComputedStyle(a).overflowX
      if (ox === 'auto' || ox === 'scroll') return { kind: 'scroll', el: a }
      if (ox === 'hidden' || ox === 'clip') return { kind: 'clip', el: a }
    }
    return null
  }
  const inScroller = (el) => {
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      const ox = getComputedStyle(a).overflowX
      if (ox === 'auto' || ox === 'scroll') return a
    }
    return null
  }

  const out = { vw, iw, scrollWidth: doc.scrollWidth, pageOverflow: Math.max(0, doc.scrollWidth - vw) }

  // ক + ঙ) ডানে উপচে পড়া / কাটা
  const blame = [], clipped = []
  const all = document.querySelectorAll('body *')
  for (const el of all) {
    const r = el.getBoundingClientRect()
    if (r.right <= vw + TOL) continue
    if (!visible(el, r)) continue
    if (el.closest('svg') && el.tagName.toLowerCase() !== 'svg') continue
    const box = xBox(el)
    if (box && box.kind === 'scroll') continue                 // নিজের বাক্সে স্ক্রল করে — ঠিক
    // মূল অপরাধী: নিজে উপচায়, কিন্তু বাবা উপচায় না (নাহলে বাবাই নাম পায়)
    const p = el.parentElement
    const pr = p ? p.getBoundingClientRect() : null
    const parentOver = pr && pr.right > vw + TOL && p !== document.body
    if (box && box.kind === 'clip') {
      if (animated(el)) continue
      if (r.left < vw && !parentOver) clipped.push({ sel: sel(el), px: Math.round(r.right - vw), by: sel(box.el), text: (el.innerText || '').trim().slice(0, 40) })
    } else if (!parentOver) {
      blame.push({ sel: sel(el), px: Math.round(r.right - vw), w: Math.round(r.width), text: (el.innerText || '').trim().slice(0, 40) })
    }
  }
  // ঙ) এমন জিনিস যা পর্দার ভিতরেই, কিন্তু overflow:hidden বাক্স তার ডান দিক কেটে দিয়েছে
  for (const el of document.querySelectorAll('main button, main a, main input, main select, main th, main td, main label, main h1, main h2')) {
    const r = el.getBoundingClientRect()
    if (!visible(el, r) || r.right > vw + TOL) continue
    const box = xBox(el)
    if (!box || box.kind !== 'clip') continue
    if (box.el === el || animated(el)) continue
    const cs = getComputedStyle(box.el)
    if (cs.position === 'absolute' && cs.clip !== 'auto') continue
    const br = box.el.getBoundingClientRect()
    if (r.right > br.right + TOL && r.left < br.right) {
      // ⓘ ইচ্ছাকৃত কাটা (truncate) — নিজের লেখা, নিজের বাক্স; এখানে আসে না কারণ box.el !== el
      clipped.push({ sel: sel(el), px: Math.round(r.right - br.right), by: sel(box.el), text: (el.innerText || el.value || '').trim().slice(0, 40) })
    }
    if (clipped.length > 40) break
  }
  blame.sort((a, b) => b.px - a.px)
  out.blame = blame.slice(0, 8)
  clipped.sort((a, b) => b.px - a.px)
  out.clipped = clipped.slice(0, 8)
  out.clippedCount = clipped.length

  // খ) মূল কলামের চওড়া
  const main = document.querySelector('main#main') || document.querySelector('main')
  const aside = document.querySelector('aside')
  let sideW = 0
  if (aside) { const ar = aside.getBoundingClientRect(); if (visible(aside, ar) && ar.left < 5) sideW = ar.right }
  const avail = vw - sideW
  out.sidebar = Math.round(sideW)
  out.available = Math.round(avail)
  if (main) {
    const mr = main.getBoundingClientRect()
    const mcs = getComputedStyle(main)
    let inner = mr.width - parseFloat(mcs.paddingLeft) - parseFloat(mcs.paddingRight)
    // ⓘ খোলসের কলাম (main-এর প্রথম বাক্স) — ছোট পাতাতেও মাপা হয়, উচ্চতা যা-ই হোক
    for (const c of main.children) {
      const r = c.getBoundingClientRect()
      const cs = getComputedStyle(c)
      if (!visible(c, r) || cs.position === 'absolute' || cs.position === 'fixed') continue
      if (r.width < inner - TOL) {
        inner = r.width
        if (cs.maxWidth !== 'none') out.shellColumn = { sel: sel(c), width: Math.round(r.width), maxWidth: cs.maxWidth }
      }
      break
    }
    out.mainWidth = Math.round(mr.width)
    // সরু কলাম: কেন্দ্রে বসা (দুই পাশে সমান ফাঁক), max-width বসানো, আর উঁচু
    const narrow = []
    const mainH = Math.max(1, mr.height)
    const walk = (el, depth) => {
      if (depth > 6) return
      for (const c of el.children) {
        const r = c.getBoundingClientRect()
        if (!visible(c, r)) continue
        const cs = getComputedStyle(c)
        if (cs.position === 'absolute' || cs.position === 'fixed') continue
        const pr = c.parentElement.getBoundingClientRect()
        const pcs = getComputedStyle(c.parentElement)
        const pin = pr.width - parseFloat(pcs.paddingLeft) - parseFloat(pcs.paddingRight)
        const gapL = r.left - (pr.left + parseFloat(pcs.paddingLeft))
        const gapR = (pr.right - parseFloat(pcs.paddingRight)) - r.right
        const capped = cs.maxWidth !== 'none'
        if (capped && r.width < pin * %(ratio)s && r.height > Math.min(240, mainH * 0.3) && gapR > 40) {
          narrow.push({ sel: sel(c), width: Math.round(r.width), of: Math.round(pin), maxWidth: cs.maxWidth,
                        centred: Math.abs(gapL - gapR) < 8 })
          continue
        }
        if (r.width > pin * 0.5) walk(c, depth + 1)
      }
    }
    walk(main, 0)
    out.narrow = narrow.slice(0, 5)
    const used = narrow.length ? Math.min(...narrow.map(n => n.width)) : inner
    out.contentWidth = Math.round(used)
    const room = avail - (parseFloat(mcs.paddingLeft) + parseFloat(mcs.paddingRight))
    out.widthRatio = room > 0 ? Math.round((used / room) * 1000) / 1000 : 1
  } else {
    out.mainWidth = null
    out.widthRatio = null
    out.narrow = []
  }

  // গ) টুলবার — কোনো flex-wrap সারি ভেঙেছে কি না, আর মোট দৃশ্যমান সারি
  const rowsOf = (items) => {
    const rows = []
    for (const r of items) {
      const hit = rows.find(x => r.top < x.bottom - 4 && r.bottom > x.top + 4)
      if (hit) { hit.top = Math.min(hit.top, r.top); hit.bottom = Math.max(hit.bottom, r.bottom) }
      else rows.push({ top: r.top, bottom: r.bottom })
    }
    return rows.length
  }
  const laidOut = (el) => {         // display:contents ভেদ করে সত্যিকারের বাক্সগুলো
    const res = []
    for (const c of el.children) {
      const cs = getComputedStyle(c)
      if (cs.display === 'contents') { res.push(...laidOut(c)); continue }
      if (cs.position === 'absolute' || cs.position === 'fixed') continue
      const r = c.getBoundingClientRect()
      if (!visible(c, r)) continue
      res.push(c)
    }
    return res
  }
  out.toolbars = []
  for (const tb of document.querySelectorAll('.toolbar-view')) {
    const tr = tb.getBoundingClientRect()
    if (!visible(tb, tr)) continue
    // ⓘ ছাঁকনির প্যানেল (#toolbar-filters) টুলবারের লাইন নয় — খুললে নিচে নামার কথাই
    const direct = laidOut(tb).filter(c => c.id !== 'toolbar-filters').map(c => c.getBoundingClientRect())
    const wrapped = []
    const conts = [tb, ...tb.querySelectorAll('*')].filter(e => {
      const cs = getComputedStyle(e)
      return (cs.display === 'flex' || cs.display === 'inline-flex') && cs.flexWrap === 'wrap' && e.id !== 'toolbar-filters' && !e.closest('#toolbar-filters')
    })
    for (const c of conts) {
      const cr = c.getBoundingClientRect()
      if (!visible(c, cr)) continue
      const kids = laidOut(c).map(k => k.getBoundingClientRect())
      if (kids.length < 2) continue
      const n = rowsOf(kids)
      if (n > 1 && getComputedStyle(c).flexDirection.startsWith('row')) wrapped.push({ sel: sel(c), rows: n, items: kids.length })
    }
    // মোট লাইন: পাতার বোতাম/ঘরগুলো কয়টা আলাদা উচ্চতায় বসেছে (ছাঁকনির প্যানেল বাদে)
    const leaves = [...tb.querySelectorAll('h1, button, a, input, select, label, [data-record-count]')]
      .filter(e => !e.closest('#toolbar-filters') && !e.closest('[x-cloak]'))
      .map(e => [e, e.getBoundingClientRect()]).filter(([e, r]) => visible(e, r) && r.height > 8)
      .map(([e, r]) => r)
    out.toolbars.push({ sel: sel(tb), directRows: rowsOf(direct), lines: rowsOf(leaves), wrapped: wrapped.slice(0, 4),
                        right: Math.round(tr.right) })
  }

  // ঘ) ছক
  out.tables = []
  for (const t of document.querySelectorAll('table')) {
    const r = t.getBoundingClientRect()
    if (!visible(t, r)) continue
    const host = t.parentElement
    const hr = host.getBoundingClientRect()
    const sc = inScroller(t)
    const wider = t.scrollWidth > host.clientWidth + TOL || r.right > hr.right + TOL || r.right > vw + TOL
    if (!wider) continue
    out.tables.push({ sel: sel(t), width: Math.round(Math.max(r.width, t.scrollWidth)), room: Math.round(host.clientWidth),
                      scrolls: !!sc, scroller: sc ? sel(sc) : null })
  }
  return out
}
""" % {'tol': TOLERANCE_PX, 'ratio': WIDTH_RATIO_MIN}


def judge(row: dict) -> list[str]:
    """ভাঙার কারণগুলো — বাংলায়, মাপসহ। খালি তালিকা মানে ঠিক।"""
    why: list[str] = []
    if row.get('open_failed') or row.get('status') is None:
        return ['খোলেনি: %s' % row.get('open_failed', '—')]
    if row['status'] >= 400:
        why.append('HTTP %d' % row['status'])
    m = row.get('m') or {}
    if not m:
        return why + ['মাপা যায়নি']
    if m.get('pageOverflow', 0) > TOLERANCE_PX:
        why.append('পাশে স্ক্রল %dpx' % m['pageOverflow'])
    if m.get('widthRatio') is not None and m['widthRatio'] < WIDTH_RATIO_MIN:
        why.append('সরু কলাম — চওড়ার %d%% ব্যবহার' % round(m['widthRatio'] * 100))
    tall = [t for t in m.get('toolbars', []) if t.get('directRows', 1) > TOOLBAR_MAX_LINES]
    if tall:
        why.append('টুলবার %d লাইনে (সীমা %d)' % (max(t['directRows'] for t in tall), TOOLBAR_MAX_LINES))
    wraps = [t for t in m.get('toolbars', []) if t.get('wrapped')]
    if wraps:
        why.append('টুলবার এক লাইনে ধরেনি (%d সারি)' % max(w['rows'] for t in wraps for w in t['wrapped']))
    bad_tables = [t for t in m.get('tables', []) if not t['scrolls']]
    if bad_tables:
        why.append('ছক বাক্সের বাইরে (%dpx চওড়া, জায়গা %dpx)' % (bad_tables[0]['width'], bad_tables[0]['room']))
    if m.get('clippedCount'):
        why.append('ডানে কাটা %d টা জিনিস' % m['clippedCount'])
    return why


CAUSE_KEYS = [
    ('HTTP', 'http'), ('খোলেনি', 'open'), ('পাশে স্ক্রল', 'overflow'), ('সরু কলাম', 'narrow'),
    ('টুলবার এক লাইনে ধরেনি', 'toolbar_wrap'), ('টুলবার', 'toolbar'), ('ছক', 'table'), ('ডানে কাটা', 'clipped'),
]


def cause_keys(reasons: list[str]) -> list[str]:
    # ⓘ প্রতিটা কারণের প্রথম মিলটাই — "টুলবার এক লাইনে ধরেনি" আর "টুলবার ২ লাইনে" আলাদা
    return [next(k for (prefix, k) in CAUSE_KEYS if r.startswith(prefix))
            for r in reasons if any(r.startswith(prefix) for (prefix, _) in CAUSE_KEYS)]


def visit(page, url: str, shot: Path | None) -> dict:
    row: dict = {'url': url, 'path': urlparse(url).path + (('?' + urlparse(url).query) if urlparse(url).query else '')}
    try:
        resp = page.goto(url, wait_until='domcontentloaded', timeout=60_000)
        row['status'] = resp.status if resp else None
    except Exception as e:  # noqa: BLE001
        row['status'] = None
        row['open_failed'] = str(e)[:300]
        return row
    try:
        page.wait_for_load_state('load', timeout=15_000)
    except Exception:  # noqa: BLE001
        pass
    page.wait_for_timeout(400)          # Alpine আর ফন্ট বসুক
    row['final_url'] = norm(page.url)
    row['redirected'] = norm(page.url) != norm(url)
    try:
        if TRY_CSS:
            # ⓘ "এই CSS বসালে কী হত" — কেবল এই ব্রাউজারের পাতায়, সার্ভারে কিছু বদলায় না
            page.add_style_tag(content=TRY_CSS)
            page.wait_for_timeout(150)
        row['m'] = page.evaluate(MEASURE_JS)
    except Exception as e:  # noqa: BLE001
        row['m'] = {}
        row['measure_failed'] = str(e)[:300]
    if shot is not None:
        try:
            page.screenshot(path=str(shot), full_page=True)
            row['shot'] = shot.name
        except Exception as e:  # noqa: BLE001
            row['shot_failed'] = str(e)[:200]
    return row


def sweep(args, password: str, secret: str, out: Path) -> dict:
    out.mkdir(parents=True, exist_ok=True)
    (out / 'shots').mkdir(exist_ok=True)
    report: dict = {'base': args.base, 'email': args.email, 'viewport': VIEWPORT, 'sidebar': args.sidebar,
                    'toolbar_max_lines': TOOLBAR_MAX_LINES, 'try_css': TRY_CSS,
                    'started': datetime.datetime.now().isoformat(timespec='seconds'),
                    'pages': [], 'skipped': []}
    with sync_playwright() as pw:
        # ⚠️ headless Chromium ডিফল্টে স্ক্রলবার লুকায় — তাহলে পাতার চওড়া ১৯২০ ধরা হত, অথচ
        # Windows-এর আসল Chrome-এ লম্বা পাতায় ~১৭px স্ক্রলবার জায়গা খায়। তাই স্ক্রলবার রাখা হয়।
        opts = {'headless': not args.headed, 'ignore_default_args': ['--hide-scrollbars']}
        try:
            browser = pw.chromium.launch(**opts)
        except Exception:  # noqa: BLE001 — নিজের chromium নেই, ইনস্টল করা Chrome
            browser = pw.chromium.launch(channel='chrome', **opts)
        # ⓘ --try-css হলে কেবল তখনই CSP পাশ কাটানো হয় — ABOS-এর CSP ইনলাইন <style> আটকায়, আর
        # তাতে পরীক্ষাটা চুপচাপ ব্যর্থ হত। ⚠️ সাধারণ মাপে CSP অটুট, কারণ আসল পাতা ওটা নিয়েই চলে।
        ctx = browser.new_context(viewport=VIEWPORT, device_scale_factor=1, locale='en-GB',
                                  bypass_csp=bool(TRY_CSS))
        # ⓘ সাইডবার খোলা/গুটানো — ব্রাউজারের নিজের পছন্দ (localStorage 'abos.sidebar'), সার্ভারে কিছু লেখে না
        ctx.add_init_script("try { localStorage.setItem('abos.sidebar', %s) } catch (e) {}"
                            % json.dumps('collapsed' if args.sidebar == 'collapsed' else 'open'))
        page = ctx.new_page()

        ok, note = login(page, args.base, args.email, password, secret)
        report['login'] = 'ঠিক' if ok else note
        if not ok:
            print('লগইন: %s' % note, flush=True)
            browser.close()
            return report

        links = menu_links(page)
        report['menu_count'] = len(links)
        print('মেনুতে %d টা ঠিকানা' % len(links), flush=True)

        n = 0
        for url in links:
            why = skip_reason(url, args.base)
            if why:
                report['skipped'].append({'url': url, 'why': why})
                continue
            if args.only and args.only not in url:
                continue
            n += 1
            name = '%03d-%s.png' % (n, (urlparse(url).path.strip('/').replace('/', '_') or 'home')[:80])
            row = visit(page, url, (out / 'shots' / name) if not args.no_shots else None)
            row['reasons'] = judge(row)
            row['causes'] = cause_keys(row['reasons'])
            row['verdict'] = 'ভাঙা' if row['reasons'] else 'ঠিক'
            report['pages'].append(row)
            print('  %-5s %-50s %s' % (row['verdict'], row['path'][:50], '; '.join(row['reasons'])), flush=True)
            if args.max and n >= args.max:
                report['capped'] = True
                break
        browser.close()
    report['finished'] = datetime.datetime.now().isoformat(timespec='seconds')
    return report


def esc(s: object) -> str:
    return str(s).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


CAUSE_BN = {'http': 'HTTP ত্রুটি', 'open': 'খোলেনি', 'overflow': 'পাশে স্ক্রল', 'narrow': 'সরু কলাম',
            'toolbar': 'টুলবার এক লাইনের বেশি', 'toolbar_wrap': 'টুলবারের সারি ভেঙেছে', 'table': 'ছক বাক্সের বাইরে', 'clipped': 'ডানে কাটা'}


def html_report(report: dict, out: Path) -> Path:
    pages = report['pages']
    bad = [p for p in pages if p['verdict'] != 'ঠিক']
    counts: dict[str, int] = {}
    for p in bad:
        for k in set(p['causes']):
            counts[k] = counts.get(k, 0) + 1

    b = ['<h1>১০৮০p পর্দা-ঝাড়ু</h1>',
         '<p class="m">%s · %s · ১৯২০×১০৮০ · %s থেকে %s</p>' % (
             esc(report['base']), esc(report.get('email', '')), esc(report['started']), esc(report.get('finished', '—'))),
         '<p class="m">লগইন: %s · সাইডবার: %s</p>' % (esc(report.get('login', '—')), 'গুটানো' if report.get('sidebar') == 'collapsed' else 'খোলা'),
         '<div class="cards"><div class="card"><b>%d</b><span>পাতা</span></div>'
         '<div class="card ok"><b>%d</b><span>ঠিক</span></div>'
         '<div class="card bad"><b>%d</b><span>ভাঙা</span></div>' % (len(pages), len(pages) - len(bad), len(bad))]
    for k, v in sorted(counts.items(), key=lambda kv: -kv[1]):
        b.append('<div class="card bad"><b>%d</b><span>%s</span></div>' % (v, esc(CAUSE_BN[k])))
    b.append('</div>')
    b.append('<p class="m">নিয়ম: ক) পাশে স্ক্রল নেই · খ) মূল কলাম অন্তত ৯০%% চওড়া · '
             'গ) টুলবার %d লাইনে, আর কোনো সারি ভেঙে নিচে নামেনি · '
             'ঘ) চওড়া ছক নিজের বাক্সে স্ক্রল করে · ঙ) ডানে কিছু কাটা নেই।</p>' % report.get('toolbar_max_lines', 1))
    if report.get('try_css'):
        b.append('<p class="m">⚠️ পরীক্ষামূলক CSS বসিয়ে মাপা (সার্ভারে নেই): <code>%s</code></p>' % esc(report['try_css']))
    b.append('<table><tr><th>রায়</th><th>ঠিকানা</th><th>কোড</th><th>পাশে স্ক্রল</th><th>চওড়া</th>'
             '<th>টুলবার লাইন</th><th>কারণ ও প্রমাণ</th><th>ছবি</th></tr>')
    for p in sorted(pages, key=lambda r: (r['verdict'] == 'ঠিক', r['path'])):
        m = p.get('m') or {}
        ev = []
        for x in m.get('blame', [])[:3]:
            ev.append('উপচায়: <code>%s</code> +%dpx' % (esc(x['sel']), x['px']))
        if m.get('shellColumn') and m.get('widthRatio') is not None and m['widthRatio'] < WIDTH_RATIO_MIN:
            x = m['shellColumn']
            ev.append('খোলসের কলাম: <code>%s</code> %dpx (max-width %s)' % (esc(x['sel']), x['width'], esc(x['maxWidth'])))
        for x in m.get('narrow', [])[:2]:
            ev.append('সরু: <code>%s</code> %d/%dpx (max-width %s)' % (esc(x['sel']), x['width'], x['of'], esc(x['maxWidth'])))
        for t in m.get('toolbars', []):
            for w in t.get('wrapped', [])[:2]:
                ev.append('টুলবার ভেঙেছে: <code>%s</code> %d সারিতে %d টা জিনিস' % (esc(w['sel']), w['rows'], w['items']))
        for t in m.get('tables', [])[:2]:
            ev.append('ছক <code>%s</code> %dpx / জায়গা %dpx — %s' % (
                esc(t['sel']), t['width'], t['room'],
                'নিজের বাক্সে স্ক্রল করে (ঠিক)' if t['scrolls'] else 'বাক্স নেই, পাতা ঠেলে'))
        for x in m.get('clipped', [])[:3]:
            ev.append('কাটা: <code>%s</code> %dpx, কেটেছে <code>%s</code>' % (esc(x['sel']), x['px'], esc(x['by'])))
        if p.get('redirected'):
            ev.append('পাঠিয়ে দিয়েছে → %s' % esc(p.get('final_url', '')))
        tl = ', '.join(str(t.get('directRows', t['lines'])) for t in m.get('toolbars', [])) or '—'
        shot = '<a href="shots/%s">ছবি</a>' % esc(p['shot']) if p.get('shot') else '—'
        b.append('<tr class="%s"><td>%s</td><td class="u">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
                 '<td><b>%s</b>%s</td><td>%s</td></tr>' % (
                     'bad' if p['verdict'] != 'ঠিক' else '', esc(p['verdict']), esc(p['path']),
                     esc(p.get('status', '—')), esc(m.get('pageOverflow', '—')),
                     ('%d%%' % round(m['widthRatio'] * 100)) if m.get('widthRatio') is not None else '—', esc(tl),
                     esc('; '.join(p['reasons'])), ('<br>' + '<br>'.join(ev)) if ev else '', shot))
    b.append('</table>')
    if report.get('skipped'):
        b.append('<h2>বাদ দেওয়া ঠিকানা</h2><p class="m">⛔ কিছু বদলাতে পারে বা ফাইল নামায় — দেখা হয়নি, ঠিক বলে ধরা হয়নি।</p><table>')
        for s in report['skipped']:
            b.append('<tr><td class="u">%s</td><td>%s</td></tr>' % (esc(urlparse(s['url']).path), esc(s['why'])))
        b.append('</table>')
    css = """
    :root{--ink:#111;--muted:#666;--line:#ddd;--bad:#b00020;--ok:#0a7d32;--bg:#fff;--th:#f6f6f6}
    @media (prefers-color-scheme: dark){:root{--ink:#eee;--muted:#aaa;--line:#444;--bad:#ff6b81;--ok:#4cd07d;--bg:#161616;--th:#222}}
    body{font:14px/1.5 system-ui,'Noto Sans Bengali',sans-serif;color:var(--ink);background:var(--bg);margin:0;padding:20px 16px 64px}
    h1{font-size:22px;margin:0 0 4px} h2{font-size:17px;margin:28px 0 8px} .m{color:var(--muted);margin:0 0 12px}
    table{border-collapse:collapse;width:100%;font-size:13px} th,td{border:1px solid var(--line);padding:5px 7px;text-align:start;vertical-align:top}
    th{background:var(--th)} tr.bad td:first-child{color:var(--bad);font-weight:600} td.u{font-family:ui-monospace,monospace;word-break:break-all}
    code{font-size:11px;word-break:break-all} .cards{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
    .card{border:1px solid var(--line);border-radius:8px;padding:8px 12px;min-width:92px} .card b{display:block;font-size:20px}
    .card span{color:var(--muted);font-size:12px} .card.bad b{color:var(--bad)} .card.ok b{color:var(--ok)}
    """
    path = out / 'report.html'
    path.write_text('<!doctype html><html lang="bn"><head><meta charset="utf-8">'
                    '<meta name="viewport" content="width=device-width, initial-scale=1">'
                    '<title>১০৮০p পর্দা-ঝাড়ু</title><style>%s</style></head><body>%s</body></html>'
                    % (css, '\n'.join(b)), encoding='utf-8')
    return path


def main() -> int:
    global TOOLBAR_MAX_LINES, TRY_CSS
    ap = argparse.ArgumentParser(description='ABOS 1080p screen sweep (GET only)')
    ap.add_argument('--base', required=True, help='যেমন http://127.0.0.1:8791 — ⛔ লাইভ নয়')
    ap.add_argument('--email', default='owner@abos.test')
    ap.add_argument('--out', default='')
    ap.add_argument('--max', type=int, default=0, help='সর্বোচ্চ কত পাতা (০ = সব)')
    ap.add_argument('--only', default='', help='কেবল যে ঠিকানায় এই টুকরো আছে')
    ap.add_argument('--no-shots', action='store_true')
    ap.add_argument('--headed', action='store_true')
    ap.add_argument('--sidebar', choices=['open', 'collapsed'], default='open',
                    help='সাইডবার খোলা না গুটানো অবস্থায় মাপা হবে')
    ap.add_argument('--try-css', default='',
                    help='মাপার আগে পাতায় এই CSS বসানো — একটা সাধারণ সমাধান কাজ করবে কি না, build ছাড়াই দেখা')
    ap.add_argument('--toolbar-lines', type=int, default=TOOLBAR_MAX_LINES,
                    help='টুলবার সর্বোচ্চ কত লাইনে বসতে পারে (ডিফল্ট ১)')
    args = ap.parse_args()
    args.base = args.base.rstrip('/')
    TOOLBAR_MAX_LINES = args.toolbar_lines
    TRY_CSS = args.try_css

    host = (urlparse(args.base).hostname or '').lower()
    if any(host == h or host.endswith('.' + h) for h in FORBIDDEN_HOSTS):
        print('⛔ %s মালিকের আসল খাতা — এখানে ঝাড়ু চলে না।' % host)
        return 3

    password = os.environ.get('ABOS_SWEEP_PW', '')
    if not password:
        print('ABOS_SWEEP_PW বসানো নেই। পাসওয়ার্ড কেবল পরিবেশ-চলক থেকে নেওয়া হয়।')
        return 2
    secret = os.environ.get('ABOS_SWEEP_TOTP', '')

    out = Path(args.out or ('out/sweep-1080-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S')))
    report = sweep(args, password, secret, out)
    (out / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
    page = html_report(report, out)

    if report.get('login') != 'ঠিক':
        print('রিপোর্ট: %s' % page.resolve())
        return 2
    bad = sum(1 for p in report['pages'] if p['verdict'] != 'ঠিক')
    print('\n%d টা পাতা দেখা হলো, %d টা ভাঙা' % (len(report['pages']), bad))
    print('রিপোর্ট: %s' % page.resolve())
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
