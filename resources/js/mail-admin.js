/*
 * ⭐ মেইলের বাকি পাতা — প্রশাসন (ঠিকানা), সই, টেমপ্লেট, খাতা (নমুনা v4 পর্দা ৪–১২)।
 * ⓘ ফর্মগুলো সাধারণ POST (সার্ভার পাতা ফেরায়); এখানে কেবল জানালা খোলা, ⋯ মেনু, ট্যাব, সারির তথ্য ফর্মে বসানো।
 * ⛔ CSP: inline স্ক্রিপ্ট নয়, সব এখানে; একটা handler-এ একটাই কাজ।
 */
import '../css/mail.css'

const ZERO = '00000000-0000-0000-0000-000000000000'
const page = document.querySelector('[data-mail-admin], [data-mail-sign], [data-mail-tpl], [data-mail-history]')

if (page) {
    const $ = (s, el = page) => el.querySelector(s)
    const $$ = (s, el = page) => Array.from(el.querySelectorAll(s))
    const routes = parse(page.dataset.routes, {})
    let current = null

    const open = (id) => { const d = document.getElementById(id); if (d && ! d.open) d.showModal() }
    const close = (el) => { const d = el.closest('dialog'); if (d) d.close() }

    page.addEventListener('click', (ev) => {
        const t = ev.target.closest('button, a, [data-row-act]')
        if (! ev.target.closest('[data-row-menu]') && ! ev.target.closest('[data-row-act="menu"]')) hideMenu()
        if (! t) return
        if (t.dataset.open) { ev.preventDefault(); open(t.dataset.open); return }
        if (t.hasAttribute('data-close')) { ev.preventDefault(); close(t); return }
        if (t.dataset.rowAct === 'menu') { current = rowOf(t); showMenu(t); return }
        if (t.dataset.rowAct === 'pause') { current = rowOf(t); prepare('pause'); return }
        if (t.dataset.menuAct) { hideMenu(); prepare(t.dataset.menuAct); return }
        if (t.dataset.tab) { tab(t.dataset.tab); return }
        if (t.dataset.removeMember !== undefined) { t.closest('tr, [data-member]').remove(); return }
        if (t.dataset.insert) { insertField(t.dataset.insert, t); return }
        if (t.dataset.pickStyle) { pickStyle(t); return }
    })

    page.addEventListener('change', (ev) => {
        const t = ev.target
        if (t.matches('[data-kind]')) kindSwitch(t.closest('form'), t.value)
        if (t.matches('[data-staff]')) {
            const form = t.closest('form')
            const opt = t.selectedOptions[0]
            if (opt && opt.dataset.name && ! form.display_name.value) form.display_name.value = opt.dataset.name
            if (opt && opt.dataset.name && ! form.local.value) form.local.value = slug(opt.dataset.name)
        }
        if (t.matches('[data-add-member]') && t.value) {
            addMember(t.closest('form'), Number(t.value), t.selectedOptions[0].textContent.trim(), true)
            t.value = ''
        }
        if (t.matches('[data-auto-submit]')) t.form.requestSubmit()
    })

    page.addEventListener('input', (ev) => {
        if (ev.target.matches('[data-typed]') && current) {
            $('[data-delete-btn]').disabled = ev.target.value.trim().toLowerCase() !== current.email.toLowerCase()
        }
    })

    $$('dialog[data-open-now]').forEach(d => d.showModal())

    // ── প্রশাসন: সারির তথ্য ফর্মে ─────────────────────────────────────
    function rowOf (el) { return parse(el.closest('[data-row]').dataset.row, null) }

    function showMenu (anchor) {
        const menu = $('[data-row-menu]')
        if (! menu || ! current) return
        const st = current.status
        $$('[data-menu-act]', menu).forEach(b => {
            const a = b.dataset.menuAct
            b.hidden = (a === 'pause' && st !== 'active') || (a === 'resume' && st !== 'paused') || (a === 'delete' && st !== 'paused')
                || (a === 'restore' && st !== 'deleting') || (a === 'people' && current.kind !== 'shared')
        })
        const r = anchor.getBoundingClientRect()
        const host = page.getBoundingClientRect()
        menu.hidden = false
        menu.style.top = (r.bottom - host.top + 4) + 'px'
        menu.style.left = Math.max(8, r.right - host.left - 240) + 'px'
    }
    function hideMenu () { const m = $('[data-row-menu]'); if (m) m.hidden = true }

    function action (key) { return (routes[key] || '').replace(ZERO, current.id) }

    function prepare (what) {
        if (! current) return
        if (what === 'history') { location.href = routes.history + '&q=' + encodeURIComponent(current.email); return }
        if (what === 'resume' || what === 'restore') {
            const form = what === 'restore' ? $('[data-form="restore"]') : document.createElement('form')
            if (what === 'resume') { form.method = 'POST'; form.innerHTML = $('[data-form="restore"]').innerHTML; page.appendChild(form) }
            form.action = action(what)
            form.submit()
            return
        }
        if (what === 'pause') {
            const f = $('[data-form="pause"]')
            f.action = action('pause')
            f.reset()
            title(f)
            open('dlg-pause')
            return
        }
        if (what === 'delete') {
            const f = $('[data-form="delete"]')
            f.action = action('delete')
            f.reset()
            title(f)
            $('[data-delete-btn]').disabled = true
            open('dlg-delete')
            return
        }
        // edit / people / forward / security
        const d = document.getElementById('dlg-edit')
        $('[data-edit-title]', d).textContent = '✏️ ' + current.email
        $$('[data-form="update"]', d).forEach(f => {
            f.action = action('update')
            f.display_name.value = current.display_name || ''
            f.quota_mb.value = current.quota_mb
            f.forward_to.value = current.forward_to || ''
            if (f.sender_with_person && f.sender_with_person.length) Array.from(f.sender_with_person).forEach(r => { r.checked = (r.value === '1') === !! current.sender_with_person })
            else if (f.sender_with_person) f.sender_with_person.value = current.sender_with_person ? '1' : '0'
        })
        $('[data-form="members"]', d).action = action('members')
        const ar = $('[data-form="auto_reply"]', d)
        if (ar) {
            const r = current.auto_reply || {}
            ar.action = action('auto_reply')
            ar.on.checked = !! r.on
            ar.subject.value = r.subject || ''
            ar.body.value = r.body || ''
            ar.start.value = r.start || ''
            ar.stop.value = r.stop || ''
        }
        $('[data-form="password"]', d).action = action('password')
        const list = $('[data-members]', d)
        list.innerHTML = ''
        ;(current.members || []).forEach(m => addMember($('[data-form="members"]', d), m.user_id, m.name, m.can_send))
        $('[data-log-link]', d).href = routes.history + '&q=' + encodeURIComponent(current.email)
        $$('[data-only-shared]', d).forEach(el => { el.hidden = current.kind !== 'shared' })
        tab({ edit: 'general', people: 'people', forward: 'forward', auto: 'auto', security: 'security' }[what] || 'general')
        open('dlg-edit')
    }

    function title (form) {
        const span = $('[data-title-tpl]', form)
        if (span) span.textContent = span.dataset.titleTpl.replace(':email', current.email)
    }

    function tab (name) {
        const d = document.getElementById('dlg-edit')
        if (! d) return
        $$('[data-tab]', d).forEach(b => b.classList.toggle('on', b.dataset.tab === name))
        $$('[data-pane]', d).forEach(p => { p.hidden = p.dataset.pane !== name })
    }

    function addMember (form, id, name, canSend) {
        const list = $('[data-members]', form) || $('[data-member-list]', form)
        if (! list || list.querySelector('[data-uid="' + id + '"]')) return
        const i = list.children.length + Date.now() % 1000
        const isTable = list.tagName === 'TBODY'
        const row = document.createElement(isTable ? 'tr' : 'div')
        row.dataset.uid = id
        if (! isTable) row.dataset.member = '1'
        row.innerHTML = (isTable ? '<td>' : '<span style="display:inline-flex;gap:10px;align-items:center;margin:0 10px 6px 0" class="mx-chip">')
            + esc(name) + '<input type="hidden" name="members[' + i + '][user_id]" value="' + id + '">'
            + (isTable ? '</td><td><span class="mx-sw" aria-hidden="true"></span></td><td>' : ' · ')
            + '<label style="display:inline-flex;gap:4px;align-items:center"><input type="checkbox" name="members[' + i + '][can_send]" value="1"' + (canSend ? ' checked' : '') + '>'
            + (isTable ? '' : ' ✉') + '</label>' + (isTable ? '</td><td>' : ' ')
            + '<button type="button" class="mx-chip" data-remove-member style="color:#c40d16;border:0">✕</button>' + (isTable ? '</td>' : '</span>')
        list.appendChild(row)
    }

    function kindSwitch (form, kind) {
        $$('[data-for]', form).forEach(el => { el.hidden = el.dataset.for !== kind })
        if (form.user_id) form.user_id.required = kind === 'person'
    }

    // ── টেমপ্লেট: ঘর বসানো ───────────────────────────────────────────
    function insertField (token, btn) {
        const form = btn.closest('form')
        const area = form ? form.querySelector('[data-tpl-body]') : null
        if (! area) return
        const s = area.selectionStart ?? area.value.length
        area.value = area.value.slice(0, s) + token + area.value.slice(area.selectionEnd ?? s)
        area.focus()
        area.selectionStart = area.selectionEnd = s + token.length
    }

    // ── সই: নকশা বাছা ───────────────────────────────────────────────
    function pickStyle (btn) {
        const form = $('[data-sign-form]')
        if (! form) return
        const field = btn.dataset.pickFor === 'reply' ? form.style_reply : form.style_new
        if (field.disabled) return
        field.value = btn.dataset.pickStyle
        form.requestSubmit()
    }

    const create = $('#dlg-create form') || document.querySelector('#dlg-create form')
    if (create) kindSwitch(create, 'person')
}

function parse (s, f) { try { return JSON.parse(s || '') } catch { return f } }
function esc (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])) }
function slug (name) { return String(name || '').toLowerCase().split(/\s+/)[0].replace(/[^a-z0-9._-]/g, '') }
