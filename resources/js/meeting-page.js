/*
 * ⭐ মিটিং আর দলের কল — ওয়েবের পাতা (নমুনা VxwPoxm77whS6ToNqsyZPU ট্যাব ৩; ২৪ নভেম্বর ২০২৬)।
 *
 *  · পাতা সার্ভারের অবস্থা থেকে আঁকে (৩ সেকেন্ডে একবার — সেটাই "আছি"র খবরও); কোনো ক্ষমতা পাতা নিজে ঠিক করে না।
 *  · ছবি-শব্দ LiveKit-এ; ছয়টা টাইল পর্যন্ত ছবি, বাকিদের নাম; পর্দা শেয়ার স্লাইডের মাপে, শব্দসহ।
 *  · টোকেন ছোট — অর্ধেক মেয়াদে নতুনটা চাওয়া হয়, আবার যুক্ত হতে সেটাই লাগে।
 * ⛔ দুজনের কলের কোড ([[messenger-call.js]]) এখানে ডাকা হয় না; সেখানে কেউ বেরোলেই কল শেষ, এখানে নয়।
 */

const POLL_MS = 3000
export const TILES = 6

const ICON = {
    mic: '<path d="M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3zM5 11a7 7 0 0 0 14 0M12 18v3"/>',
    camera: '<rect x="3" y="6" width="12" height="12" rx="2"/><path d="m15 10 6-3v10l-6-3"/>',
    share: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M12 14V8m-3 3 3-3 3 3M8 21h8"/>',
    hand: '<path d="M8 12V5a1.5 1.5 0 0 1 3 0v6-7a1.5 1.5 0 0 1 3 0v7-5a1.5 1.5 0 0 1 3 0v8a6 6 0 0 1-6 6c-4 0-5-3-7-6l2-1z"/>',
    people: '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0M16 5a3 3 0 0 1 0 6M18 20a6 6 0 0 0-3-5"/>',
    chat: '<path d="M4 5h16v11H9l-5 4z"/>',
    invite: '<circle cx="10" cy="8" r="3"/><path d="M4 20a6 6 0 0 1 12 0M19 8v6M16 11h6"/>',
    end_all: '<path d="M5 5l14 14M19 5 5 19"/>',
    leave: '<path d="M3 14c5-5 13-5 18 0l-3 3-4-2v-3c-2-.6-4-.6-6 0v3l-4 2z"/>',
}

/** কাদের টাইল আগে — যিনি বলছেন, হাত তুলেছেন, ছবি আছে, তারপর আগে থেকে যাঁরা; নিজের টাইল সবসময় থাকে */
export function pickTiles (people, cap = TILES) {
    const score = (p) => (p.me ? 8 : 0) + (p.speaking ? 4 : 0) + (p.hand ? 2 : 0) + (p.picture ? 1 : 0)
    return people.map((p, i) => [p, i]).sort((a, b) => score(b[0]) - score(a[0]) || a[1] - b[1]).slice(0, cap)
        .sort((a, b) => a[1] - b[1]).map(([p]) => p)
}

export function clock (seconds) {
    const s = Math.max(0, Math.floor(seconds))
    return [Math.floor(s / 3600), Math.floor(s / 60) % 60, s % 60].map((n) => String(n).padStart(2, '0')).join(':')
}

/** পর্দা শেয়ারের মাপ — স্লাইড: পরিষ্কার লেখা, কম আপলোড; ব্রাউজার শব্দ আর ট্যাব বদল দিলে সেটাও */
export function shareOptions () {
    return [
        { audio: true, systemAudio: 'include', surfaceSwitching: 'include', selfBrowserSurface: 'exclude', contentHint: 'detail', resolution: { width: 1280, height: 720, frameRate: 5 } },
        { screenShareEncoding: { maxBitrate: 800000, maxFramerate: 5 } },
    ]
}

function parse (json) {
    try { return JSON.parse(json || '{}') } catch { return {} }
}

export function bootMeeting (root) {
    if (!root) return null
    const urls = parse(root.dataset.urls)
    const words = parse(root.dataset.words)
    const me = String(root.dataset.me)
    const $ = (s) => root.querySelector(s)
    const w = (key, swap = {}) => {
        let t = key.split('.').reduce((at, k) => at?.[k], words)
        if (typeof t !== 'string') return key
        for (const [k, v] of Object.entries(swap)) t = t.split(':' + k).join(String(v))
        return t
    }
    const S = { state: null, room: null, lk: null, joined: false, waiting: false, panel: null, ticket: null, poll: null, renew: null, clock: null, people: [], messages: [], chatTimer: null, shareTracks: [] }

    const el = (tag, cls, text) => {
        const e = document.createElement(tag)
        if (cls) e.className = cls
        if (text !== undefined) e.textContent = text
        return e
    }
    const button = (cls, text, act, data = {}) => {
        const b = el('button', cls, text)
        b.type = 'button'
        b.dataset.mmAct = act
        for (const [k, v] of Object.entries(data)) b.dataset[k] = v
        return b
    }

    async function ask (url, body = null) {
        try {
            const res = await fetch(url, {
                method: body === null ? 'GET' : 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body === null ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf }) },
                credentials: 'same-origin',
                cache: 'no-store',
                body: body === null ? undefined : JSON.stringify(body),
            })
            const data = res.status === 204 ? null : await res.json().catch(() => null)
            return { ok: res.ok, status: res.status, data }
        } catch {
            return { ok: false, status: 0, data: null }
        }
    }

    let toastTimer = null
    function toast (text) {
        const t = $('[data-mm-toast]')
        t.textContent = text
        t.hidden = false
        clearTimeout(toastTimer)
        toastTimer = setTimeout(() => { t.hidden = true }, 4500)
    }

    /** একটা কাজ চাওয়া — না হলে সার্ভারের কথাটাই দেখানো; হলে অবস্থা আবার পড়া */
    async function act (door, body = {}) {
        const res = await ask(urls[door], body)
        if (!res.ok) toast(res.data?.message || w('failed'))
        await refresh()
        return res.ok
    }

    // ── ঢোকা ──────────────────────────────────────────────

    function door (text, canJoin, label = 'join') {
        $('[data-mm-door]').hidden = false
        $('[data-mm-stage]').hidden = true
        $('[data-mm-say]').textContent = text
        const go = $('[data-mm-act="join"]')
        go.hidden = !canJoin
        go.textContent = w(label)
    }

    async function join () {
        door(w('connecting'), false)
        const res = await ask(urls.join, {})
        if (!res.ok || !res.data?.data) {
            S.waiting = false
            door(res.data?.message || w('failed'), res.status !== 409 || res.data?.error === 'meeting_full')
            return
        }
        S.state = res.data.data
        if (S.state.state === 'waiting') {
            S.waiting = true
            door(w('waiting'), false)
            schedule()
            return
        }
        S.waiting = false
        S.ticket = S.state
        await connect()
    }

    async function connect () {
        try {
            S.lk = S.lk || await import('livekit-client')
        } catch {
            door(w('failed'), true)
            return
        }
        const { Room, RoomEvent, Track, VideoPresets: P } = S.lk
        // ⛔ singlePeerConnection: false — কল-সার্ভারের পুরনো /rtc পথ (দুজনের কলের একই কারণ)
        const room = new Room({
            adaptiveStream: true, dynacast: true, singlePeerConnection: false,
            videoCaptureDefaults: { resolution: P.h720.resolution },
            publishDefaults: { simulcast: true, videoEncoding: P.h720.encoding, videoSimulcastLayers: [P.h180, P.h360] },
        })
        S.room = room
        const draw = () => { if (S.room === room) paint() }
        room.on(RoomEvent.TrackSubscribed, (track) => {
            if (track.kind === Track.Kind.Audio) $('[data-mm-audio]').appendChild(track.attach())
            draw()
        })
        room.on(RoomEvent.TrackUnsubscribed, (track) => { track.detach().forEach((e) => { if (e.tagName === 'AUDIO') e.remove() }); draw() })
        for (const e of ['ParticipantConnected', 'ParticipantDisconnected', 'TrackMuted', 'TrackUnmuted', 'LocalTrackPublished', 'LocalTrackUnpublished', 'ActiveSpeakersChanged', 'ParticipantAttributesChanged', 'ParticipantPermissionsChanged']) {
            room.on(RoomEvent[e], draw)
        }
        // ⓘ কারো বেরোনোয় মিটিং শেষ নয়; নিজের ঘর বন্ধ হলে সার্ভারকে জিজ্ঞাসা — শেষ, বের করা, না কি নেট গেছে
        room.on(RoomEvent.Disconnected, () => { if (S.room === room) dropped() })

        try {
            await room.connect(S.ticket.url, S.ticket.token)
        } catch {
            if (S.room === room) { S.room = null; door(w('failed'), true, 'again') }
            return
        }
        if (S.room !== room) { room.disconnect(); return }
        S.joined = true
        $('[data-mm-door]').hidden = true
        $('[data-mm-stage]').hidden = false
        try { await room.startAudio() } catch { /* ⓘ প্রথম চাপে ব্রাউজার নিজে চালায় */ }
        try {
            await room.localParticipant.setMicrophoneEnabled(true)
            if (root.dataset.video === '1') await room.localParticipant.setCameraEnabled(true)
        } catch {
            // ⓘ মাইক না থাকলেও শোনা যায় — মিটিং থেকে বের করে দেওয়া নয়
            toast(w('no_media'))
        }
        keepTicket()
        clearInterval(S.clock)
        S.clock = setInterval(paintClock, 1000)
        paint()
        schedule()
    }

    function keepTicket () {
        clearTimeout(S.renew)
        S.renew = setTimeout(async () => {
            const res = await ask(urls.renew, {})
            if (res.ok && res.data?.data) S.ticket = res.data.data
            if (S.joined) keepTicket()
        }, (S.ticket?.renew_in || 300) * 1000)
    }

    async function dropped () {
        S.room = null
        S.joined = false
        close()
        const res = await ask(urls.state)
        S.state = res.data?.data || S.state
        if (S.state?.over) door(w('over'), false)
        else if (S.state?.state === 'removed') door(w('removed'), false)
        else if (S.state?.state === 'in') {
            // ⓘ নেট গিয়েছিল — নতুন টোকেনে একবার নিজে চেষ্টা
            const again = await ask(urls.renew, {})
            if (again.ok && again.data?.data) { S.ticket = again.data.data; door(w('connecting'), false); await connect() } else door(w('failed'), true, 'again')
        } else door(w('left'), true, 'again')
    }

    function close () {
        for (const t of [S.renew, S.poll, S.chatTimer]) clearTimeout(t)
        clearInterval(S.clock)
        $('[data-mm-audio]').textContent = ''
        S.shareTracks = []
    }

    async function leave () {
        const room = S.room
        S.room = null
        S.joined = false
        close()
        await ask(urls.leave, {})
        try { await room?.disconnect() } catch { /* ⓘ আগেই বন্ধ */ }
        door(w('left'), true, 'again')
    }

    // ── অবস্থা ─────────────────────────────────────────────

    function schedule () {
        clearTimeout(S.poll)
        if (S.joined || S.waiting) S.poll = setTimeout(refresh, POLL_MS)
    }

    async function refresh () {
        clearTimeout(S.poll)
        const res = await ask(urls.state)
        if (res.ok && res.data?.data) {
            S.state = res.data.data
            if (S.state.over) {
                const room = S.room
                S.room = null
                S.joined = S.waiting = false
                close()
                try { await room?.disconnect() } catch { /* ⓘ */ }
                door(w('over'), false)
                return
            }
            // ⓘ অপেক্ষা থেকে ঢুকতে দেওয়া হলো
            if (S.waiting && S.state.state === 'invited') { await join(); return }
            if (S.waiting && S.state.state !== 'waiting') { S.waiting = false; door(w('left'), true, 'again'); return }
            if (S.joined) paint()
        }
        schedule()
    }

    // ── আঁকা ──────────────────────────────────────────────

    const nameOf = (id) => S.state?.people?.find((p) => p.id === id)?.name || S.room?.remoteParticipants?.get(id)?.name || ''
    const track = (p, source) => {
        const pub = p?.getTrackPublication?.(source)
        return pub && pub.track && !pub.isMuted ? pub.track : null
    }

    function everyone () {
        const { Track } = S.lk
        const local = S.room.localParticipant
        return [local, ...S.room.remoteParticipants.values()].map((p) => ({
            id: p.identity,
            me: p === local,
            name: p === local ? w('you') : nameOf(p.identity),
            speaking: p.isSpeaking,
            hand: p.attributes?.hand === '1',
            muted: !track(p, Track.Source.Microphone),
            picture: track(p, Track.Source.Camera),
            screen: track(p, Track.Source.ScreenShare),
            sound: track(p, Track.Source.ScreenShareAudio),
        }))
    }

    function paint () {
        if (!S.room || !S.lk) return
        const all = everyone()
        $('[data-mm-count]').textContent = w('count', { n: all.length })
        paintTiles(all)
        paintSlide(all)
        paintFoot(all)
        const lock = $('[data-mm-lock]')
        lock.hidden = !S.state?.runs
        lock.textContent = w(S.state?.locked ? 'unlock' : 'lock')
        if (S.panel === 'people') paintPeople(all)
    }

    function paintClock () {
        const at = S.state?.started_at ? Date.parse(S.state.started_at) : Date.now()
        $('[data-mm-clock]').textContent = clock((Date.now() - at) / 1000)
    }

    /** ⓘ ভিডিওর ঘর রেখে দেওয়া — বার বার বানালে ছবি কাঁপত */
    function paintTiles (all) {
        const box = $('[data-mm-tiles]')
        const shown = pickTiles(all)
        const want = new Set(shown.map((p) => p.id))
        for (const old of Array.from(box.children)) if (!want.has(old.dataset.id)) old.remove()
        shown.forEach((p, i) => {
            let tile = Array.from(box.children).find((t) => t.dataset.id === p.id)
            if (!tile) {
                tile = el('div', 'mmeet-tile')
                tile.dataset.id = p.id
                const v = el('video')
                v.autoplay = v.playsInline = v.muted = true
                tile.append(el('span', 'mmeet-av'), v, el('small'))
            }
            if (box.children[i] !== tile) box.insertBefore(tile, box.children[i] || null)
            tile.classList.toggle('is-talking', !!p.speaking)
            const [av, v, label] = tile.children
            av.textContent = Array.from(p.name || '?')[0]
            v.hidden = !p.picture
            av.hidden = !!p.picture
            if (p.picture && tile.trackSid !== p.picture.sid) { p.picture.attach(v); tile.trackSid = p.picture.sid }
            if (!p.picture) { v.srcObject = null; tile.trackSid = null }
            label.textContent = [p.name, p.speaking ? w('speaking') : '', p.muted ? '🔇' : '', p.hand ? '✋ ' + w('hand_up') : ''].filter(Boolean).join(' · ')
        })
    }

    function paintSlide (all) {
        const who = all.find((p) => p.screen)
        const box = $('[data-mm-slide]')
        box.hidden = !who
        $('[data-mm-body]').classList.toggle('has-slide', !!who)
        if (!who) { box.dataset.sid = ''; return }
        const v = $('[data-mm-share-video]')
        if (box.dataset.sid !== String(who.screen.sid)) { who.screen.attach(v); box.dataset.sid = String(who.screen.sid) }
        box.dataset.person = who.id
        $('[data-mm-share-who]').textContent = w('sharing', { name: who.name }) + (who.sound ? ' · ' + w('with_sound') : '')
        $('[data-mm-window]').hidden = !who.me
        $('[data-mm-stop]').hidden = who.me || !S.state?.runs
    }

    function paintFoot (all) {
        const mine = all.find((p) => p.me)
        const list = [['mic', !mine.muted], ['camera', !!mine.picture], ...(S.state?.may_share ? [['share', !!mine.screen]] : []), ['hand', mine.hand], ['people', S.panel === 'people'],
            ...(S.state?.thread ? [['chat', S.panel === 'chat']] : []), ...(S.state?.runs ? [['invite', S.panel === 'invite'], ['end_all', false]] : []), ['leave', false]]
        const sign = JSON.stringify(list)
        const foot = $('[data-mm-foot]')
        if (foot.dataset.sign === sign) return
        foot.dataset.sign = sign
        const focus = foot.contains(document.activeElement) ? document.activeElement.dataset.mmAct : null
        foot.textContent = ''
        for (const [key, on] of list) {
            const b = button('mmeet-cc' + (on ? ' is-on' : '') + (key === 'end_all' || key === 'leave' ? ' is-end' : ''), undefined, key)
            if (!['leave', 'end_all'].includes(key)) b.setAttribute('aria-pressed', on ? 'true' : 'false')
            const i = el('i')
            i.setAttribute('aria-hidden', 'true')
            i.innerHTML = '<svg viewBox="0 0 24 24">' + ICON[key] + '</svg>'
            b.append(i, el('span', '', w(key)))
            foot.appendChild(b)
        }
        if (focus) foot.querySelector('[data-mm-act="' + focus + '"]')?.focus()
    }

    // ── পাশের পাতা ──────────────────────────────────────────

    function openPanel (name) {
        S.panel = S.panel === name ? null : name
        clearTimeout(S.chatTimer)
        $('[data-mm-side]').hidden = S.panel === null
        $('[data-mm-body]').classList.toggle('has-side', S.panel !== null)
        $('[data-mm-side-body]').textContent = ''
        if (S.panel) $('[data-mm-side-title]').textContent = w(S.panel)
        if (S.panel === 'chat') openChat()
        if (S.panel === 'invite') openInvite()
        paint()
    }

    function row (text, ...buttons) {
        const p = el('p')
        const right = el('span')
        right.append(...buttons)
        p.append(el('span', '', text), right)
        return p
    }

    function paintPeople (all) {
        const box = $('[data-mm-side-body]')
        const open = box.querySelector('details[open]')?.dataset.id
        const live = new Map(all.map((p) => [p.id, p]))
        const of = (state) => (S.state?.people || []).filter((p) => p.state === state)
        const out = []
        const waiting = of('waiting')
        if (S.state?.runs && waiting.length) {
            out.push(el('h3', '', w('in_lobby') + ' (' + waiting.length + ')'))
            for (const p of waiting) out.push(row(p.name, button('mmeet-mini', w('admit'), 'lobby', { person: p.id, yes: '1' }), button('mmeet-mini', w('refuse'), 'lobby', { person: p.id, yes: '0' })))
        }
        out.push(el('h3', '', w('inside')))
        for (const p of of('in')) {
            const now = live.get(p.id)
            const tags = [now?.muted ? '🔇' : '', now?.hand ? '✋' : '', p.role !== 'member' ? w('role_' + p.role) : ''].filter(Boolean).join(' ')
            const b = []
            if (S.state?.runs && p.id !== me) {
                if (now && !now.muted) b.push(button('mmeet-mini', w('mute'), 'mute', { person: p.id }))
                if (p.role !== 'host') {
                    const more = el('details', 'mmeet-more')
                    more.dataset.id = p.id
                    more.open = open === p.id
                    const s = el('summary', 'mmeet-mini', '⋯')
                    s.setAttribute('aria-label', w('more') + ': ' + p.name)
                    more.append(s, button('mmeet-mini', w('remove'), 'remove', { person: p.id }))
                    if (S.state.role === 'host') more.append(button('mmeet-mini', w(p.role === 'cohost' ? 'make_member' : 'make_cohost'), 'role', { person: p.id, role: p.role === 'cohost' ? 'member' : 'cohost' }))
                    b.push(more)
                }
            }
            out.push(row([p.id === me ? w('you') : p.name, tags].filter(Boolean).join(' '), ...b))
        }
        const called = of('invited')
        if (called.length) {
            out.push(el('h3', '', w('called')))
            for (const p of called) out.push(row(p.name))
        }
        if (S.state?.runs) {
            out.push(button('mmeet-mini mmeet-all', w('mute_all'), 'mute-all'))
            for (const [key, on] of [['lobby_on', S.state.lobby], ['share_everyone', S.state.allow_share === 'everyone']]) {
                const l = el('label', 'mmeet-tick')
                const c = el('input')
                c.type = 'checkbox'
                c.checked = on
                c.dataset.mmSet = key
                l.append(c, el('span', '', w(key)))
                out.push(l)
            }
        }
        const focus = box.contains(document.activeElement) ? document.activeElement : null
        const back = focus && [focus.dataset.mmAct, focus.dataset.person, focus.dataset.mmSet]
        box.replaceChildren(...out)
        if (back) Array.from(box.querySelectorAll('button, input')).find((x) => x.dataset.mmAct === back[0] && x.dataset.person === back[1] && x.dataset.mmSet === back[2])?.focus()
    }

    function openInvite () {
        const box = $('[data-mm-side-body]')
        const q = el('input', 'mmeet-q')
        q.type = 'search'
        q.placeholder = w('search')
        q.setAttribute('aria-label', w('search'))
        const list = el('div')
        box.replaceChildren(q, list)
        let timer = null
        const load = async () => {
            const res = await ask(urls.people + '?q=' + encodeURIComponent(q.value.trim()))
            if (S.panel !== 'invite') return
            const inside = new Set((S.state?.people || []).filter((p) => p.state === 'in').map((p) => p.id))
            const rows = (res.data?.data || []).filter((p) => !inside.has(String(p.id))).slice(0, 60)
                .map((p) => row(p.name, button('mmeet-mini', w('call'), 'call', { person: String(p.id) })))
            list.replaceChildren(...(rows.length ? rows : [el('p', '', w('nobody'))]))
        }
        q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250) })
        load()
    }

    function openChat () {
        const box = $('[data-mm-side-body]')
        const log = el('div', 'mmeet-log')
        log.setAttribute('role', 'log')
        const form = el('form', 'mmeet-write')
        const input = el('input', 'mmeet-q')
        input.placeholder = w('write')
        input.maxLength = 4000
        input.setAttribute('aria-label', w('write'))
        const send = el('button', 'mmeet-mini', w('send'))
        send.type = 'submit'
        form.append(input, send)
        box.replaceChildren(log, form)
        const thread = encodeURIComponent(S.state.thread)
        const load = async () => {
            clearTimeout(S.chatTimer)
            if (S.panel !== 'chat') return
            const res = await ask(urls.messages.replace('__T__', thread))
            const rows = (res.data?.data || []).filter((m) => m.text)
            log.replaceChildren(...rows.map((m) => el('p', '', [m.sender?.name || m.from?.name || '', m.text].filter(Boolean).join(': '))))
            log.scrollTop = log.scrollHeight
            S.chatTimer = setTimeout(load, POLL_MS)
        }
        form.addEventListener('submit', async (e) => {
            e.preventDefault()
            const text = input.value.trim()
            if (!text) return
            const res = await ask(urls.send.replace('__T__', thread), { kind: 'text', text, client_id: crypto.randomUUID() })
            if (res.ok) input.value = ''
            else toast(res.data?.message || w('failed'))
            load()
        })
        load()
        input.focus()
    }

    // ── নিজের কাজ ───────────────────────────────────────────

    async function toggle (key) {
        const lp = S.room?.localParticipant
        if (!lp) return
        const { Track } = S.lk
        try {
            if (key === 'mic') await lp.setMicrophoneEnabled(!track(lp, Track.Source.Microphone))
            if (key === 'camera') await lp.setCameraEnabled(!track(lp, Track.Source.Camera))
            if (key === 'hand') await lp.setAttributes({ hand: lp.attributes?.hand === '1' ? '' : '1' })
        } catch { toast(w('no_media')) }
        paint()
    }

    async function toggleShare () {
        const lp = S.room?.localParticipant
        if (!lp) return
        const all = everyone()
        const mine = all.find((p) => p.me)
        const other = all.find((p) => p.screen && !p.me)
        if (!mine.screen && other) { toast(w('someone_shares', { name: other.name })); return }
        try {
            await lp.setScreenShareEnabled(!mine.screen, ...shareOptions())
        } catch { /* ⓘ জানালা না বেছে ফেরা */ }
        paint()
    }

    /** অন্য জানালা — নতুনটা হাতে এলে তবেই পুরনোটা ছাড়া, তাই মাঝে কালো পর্দা নেই */
    async function otherWindow () {
        const lp = S.room?.localParticipant
        const { Track } = S.lk
        const now = lp?.getTrackPublication(Track.Source.ScreenShare)?.track
        if (!now) return
        try {
            const fresh = await lp.createScreenTracks(shareOptions()[0])
            for (const t of fresh) {
                const old = lp.getTrackPublication(t.source)?.track
                if (old) await old.replaceTrack(t.mediaStreamTrack, true)
                else await lp.publishTrack(t)
            }
            const kept = new Set(fresh.map((t) => t.source))
            const sound = lp.getTrackPublication(Track.Source.ScreenShareAudio)?.track
            if (sound && !kept.has(Track.Source.ScreenShareAudio)) await lp.unpublishTrack(sound)
            for (const t of fresh) {
                if (t.source !== Track.Source.ScreenShare) continue
                t.mediaStreamTrack.addEventListener('ended', () => { lp.setScreenShareEnabled(false).catch(() => {}) })
            }
        } catch { /* ⓘ জানালা না বেছে ফেরা — আগেরটাই চলে */ }
        paint()
    }

    async function onAct (b) {
        const d = b.dataset
        switch (d.mmAct) {
            case 'join': return join()
            case 'mic': case 'camera': case 'hand': return toggle(d.mmAct)
            case 'share': return toggleShare()
            case 'window': return otherWindow()
            case 'people': case 'chat': case 'invite': return openPanel(d.mmAct)
            case 'leave': return leave()
            case 'end_all': return window.confirm(w('confirm_end')) ? act('end') : null
            case 'link':
                try { await navigator.clipboard.writeText(urls.page); toast(w('link_copied')) } catch { toast(urls.page) }
                return null
            case 'lock': return act('set', { locked: !S.state?.locked })
            case 'stop-share': return act('stop_share', { person: Number($('[data-mm-slide]').dataset.person) })
            case 'mute': return act('mute', { person: Number(d.person) })
            case 'mute-all': return act('mute', {})
            case 'lobby': return act('lobby', { person: Number(d.person), yes: d.yes === '1' })
            case 'remove': return act('remove', { person: Number(d.person) })
            case 'role': return act('role', { person: Number(d.person), role: d.role })
            case 'call':
                b.disabled = true
                return act('invite', { people: [Number(d.person)] })
            default: return null
        }
    }

    root.addEventListener('click', (e) => {
        const b = e.target instanceof Element ? e.target.closest('[data-mm-act]') : null
        if (b && root.contains(b)) { e.preventDefault(); onAct(b) }
    })
    root.addEventListener('change', (e) => {
        const c = e.target
        if (c?.dataset?.mmSet === 'lobby_on') act('set', { lobby: c.checked })
        if (c?.dataset?.mmSet === 'share_everyone') act('set', { allow_share: c.checked ? 'everyone' : 'hosts' })
    })
    // ⛔ পাতা বন্ধ — সার্ভারকে জানানো; না পৌঁছালে LiveKit-এর খবর, তাও না হলে হৃৎস্পন্দন থামা ধরে
    window.addEventListener('pagehide', () => {
        if (!S.joined && !S.waiting) return
        const form = new FormData()
        form.append('_token', root.dataset.csrf)
        try { navigator.sendBeacon(urls.leave, form) } catch { /* ⓘ */ }
    })

    return { S, join, paint }
}

if (typeof document !== 'undefined') bootMeeting(document.querySelector('[data-meeting]'))
