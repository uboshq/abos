/*
 * ⭐ বার্তা — টপবারের ব্যাজ, কল আসার পপআপ আর ব্রাউজারের কলের পর্দা (মালিকের ওয়েব নমুনা M1UddRxK v7, ২১ নভেম্বর ২০২৬)।
 *
 * ── কী কী ─────────────────────────────────────────────────────────────
 *  · হালকা জিজ্ঞাসা: প্রতি ~১৫ সেকেন্ডে `messaging.x.unread` → না-পড়ার ব্যাজ, আর কেউ কল দিলে উপরে ডানে পপআপ।
 *    কল বাজার সময় ~৩ সেকেন্ডে; পাতা লুকানো থাকলে থামে (cPanel-এ websocket নেই — চুক্তি §৬)।
 *  · কল শুরু: বার্তার পাতা `messenger:call` পাঠায় ({ person_id, video }) → POST calls.start → LiveKit-এ যুক্ত হওয়া।
 *  · ধরা / কেটে দেওয়া / শেষ — POST answer / decline / end।
 *
 * ⓘ livekit-client বড় — কেবল কল শুরু হলে `import()`, প্রকল্পের নিজের Vite বান্ডেল থেকে (CDN নয়)।
 * ⓘ কোনো Alpine নয়, কোনো ইনলাইন হ্যান্ডলার নয় — CSP-র নিয়মে সব শ্রোতা এখানে বসে।
 * ⛔ খোলস [data-msgr-root] আঁকে কেবল তিন ABOS রূপে ([[MessengerChrome]]); না থাকলে এই ফাইল কিছুই করে না।
 */

/*
 * ⭐ A ring must reach the other PC in 1–2 s (owner, 24 Nov 2026: "1-2 সেকেন্ডের ভিতরে ওখানে পৌঁছার কথা … প্রায় 30 সেকেন্ড
 * লাগে") — the light ask runs every 2 s while the tab is in front (a hidden tab asks nothing, see poll()). ⓘ It was 15 s.
 */
const POLL_IDLE_MS = 2000
const POLL_RINGING_MS = 2000
const RING_GIVE_UP_MS = 50000
const AV_COLOURS = 6

let root = null
let urls = {}
let words = {}

/** শব্দ — `messaging::call`-এর বিন্দু-চাবি, `:name` ধরনের জায়গা ভরে */
export function word (key, swap = {}) {
    let text = key.split('.').reduce((at, part) => (at && typeof at === 'object' ? at[part] : undefined), words)
    if (typeof text !== 'string') return key
    for (const [k, v] of Object.entries(swap)) text = text.split(':' + k).join(String(v))
    return text
}

export function urlFor (name, callId = '') {
    return String(urls[name] || '').replace('__CALL__', encodeURIComponent(callId))
}

function csrf () {
    return root?.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || ''
}

/** JSON-এর দরজা — সব POST-এ X-CSRF-TOKEN আর Accept: application/json */
export async function api (url, { method = 'GET', body = null, query = null } = {}) {
    let target = url
    if (query) {
        // ⓘ string-built, not `new URL(…, origin)` — the address stays exactly the one the server printed
        const qs = new URLSearchParams()
        for (const [k, v] of Object.entries(query)) if (v !== null && v !== undefined && v !== '') qs.set(k, String(v))
        const s = qs.toString()
        if (s) target = url + (String(url).includes('?') ? '&' : '?') + s
    }
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json'
        headers['X-CSRF-TOKEN'] = csrf()
    }
    try {
        const res = await fetch(target, {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            body: body === null ? undefined : JSON.stringify(body),
        })
        let data = null
        if (res.status !== 204) {
            try { data = await res.json() } catch { data = null }
        }
        return { ok: res.ok, status: res.status, data }
    } catch {
        return { ok: false, status: 0, data: null }
    }
}

let toastTimer = null

/** ছোট খবর — নিচে মাঝখানে, কয়েক সেকেন্ড */
export function toast (text) {
    const el = root?.querySelector('[data-msgr-toast]')
    if (!el || !text) return
    el.textContent = text
    el.hidden = false
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { el.hidden = true }, 4500)
}

/** সার্ভারের ফেরানো কারণ, নইলে সাধারণ কথা — 503 calls_off, 403, 409 সবই `message` দেয় */
export function refusal (res, fallbackKey) {
    const message = res?.data?.message
    return typeof message === 'string' && message !== '' ? message : word(fallbackKey)
}

/** নামের প্রথম অক্ষর — গোটা একটা অক্ষর (বাংলার যুক্তবর্ণ/কার ভাঙে না) */
export function initialOf (name) {
    const text = String(name || '').trim()
    if (text === '') return ''
    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
        const first = new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)[Symbol.iterator]().next().value
        return first ? first.segment.toUpperCase() : ''
    }
    return Array.from(text)[0].toUpperCase()
}

/** ছবির গোল ভরা — ছবি থাকলে ছবি, নইলে প্রথম অক্ষর ছয় রঙের একটায় (id ধরে, তাই একই মানুষ সবসময় একই রঙে) */
export function paintAvatar (el, person) {
    if (!el) return
    el.textContent = ''
    for (let i = 1; i <= AV_COLOURS; i++) el.classList.remove('msgr-av--c' + i)
    const id = Number.parseInt(String(person?.id ?? '0'), 10) || 0
    el.classList.add('msgr-av--c' + ((Math.abs(id) % AV_COLOURS) + 1))
    if (person?.photo_url) {
        const img = document.createElement('img')
        img.src = person.photo_url
        img.alt = ''
        img.addEventListener('error', () => { img.remove(); el.textContent = initialOf(person?.name) })
        el.appendChild(img)
    } else {
        el.textContent = initialOf(person?.name)
    }
}

function two (n) {
    return String(n).padStart(2, '0')
}

/** মিনিট:সেকেন্ড, ইংরেজি অঙ্কে — ঘণ্টা পেরোলে ঘণ্টাও */
export function clockOf (seconds) {
    const s = Math.max(0, Math.floor(seconds))
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    return (h > 0 ? h + ':' + two(m) : two(m)) + ':' + two(s % 60)
}

/* ─────────────────────────── টপবারের ব্যাজ আর জিজ্ঞাসা ─────────────────────────── */

let pollTimer = null
let ringingNow = null
const dismissed = new Set()

function setBadge (count) {
    const link = document.querySelector('[data-msgr-topbar]')
    const badge = link?.querySelector('[data-msgr-badge]')
    if (!link || !badge) return
    const n = Math.max(0, Number(count) || 0)
    // ⓘ বার্তার পাতায় থাকলে ব্যাজ নেই — নমুনার মতো
    const show = n > 0 && !link.hasAttribute('data-msgr-here')
    badge.hidden = !show
    badge.textContent = show ? (n > 99 ? '99+' : String(n)) : ''
    const title = word('topbar.title')
    link.setAttribute('aria-label', show ? title + ' (' + badge.textContent + ')' : title)
}

async function poll () {
    clearTimeout(pollTimer)
    pollTimer = null
    if (document.visibilityState === 'hidden') return

    const res = await api(urlFor('unread'))
    if (res.ok && res.data) {
        setBadge(res.data.unread_total)
        showRinging(res.data.ringing || null)
        window.dispatchEvent(new CustomEvent('messenger:unread', { detail: { unread_total: res.data.unread_total, meeting: res.data.meeting || null } }))
    }
    schedule()
}

function schedule () {
    clearTimeout(pollTimer)
    if (document.visibilityState === 'hidden') return
    const busy = ringingNow !== null || call.phase === 'ringing'
    pollTimer = setTimeout(poll, busy ? POLL_RINGING_MS : POLL_IDLE_MS)
}

/* ─────────────────────────── কল আসছে ─────────────────────────── */

function popup () {
    return root.querySelector('[data-msgr-incoming]')
}

function showRinging (ringing) {
    const el = popup()
    if (!el) return

    // ⓘ চলতি কলের মাঝে আরেকটা বাজলে পপআপ নয় — সার্ভারের ৪৫ সেকেন্ডে সেটা "ধরেননি" হয়
    const usable = ringing && ringing.call_id && !dismissed.has(ringing.call_id) && call.phase === 'idle'
    if (!usable) {
        ringingNow = null
        el.hidden = true
        return
    }
    if (ringingNow && ringingNow.call_id === ringing.call_id && !el.hidden) return

    ringingNow = ringing
    paintAvatar(el.querySelector('[data-msgr-inc-avatar]'), ringing.peer)
    el.querySelector('[data-msgr-inc-name]').textContent = ringing.peer?.name || ''
    el.title = [ringing.peer?.designation, ringing.peer?.branch].filter(Boolean).join(' · ')
    el.querySelector('[data-msgr-inc-icon="voice"]').hidden = !!ringing.video
    el.querySelector('[data-msgr-inc-icon="video"]').hidden = !ringing.video
    el.querySelector('[data-msgr-inc-what]').textContent = word(ringing.video ? 'incoming.video' : 'incoming.voice')
    placeIncoming(el)
    el.hidden = false
}

function placeIncoming (el) {
    const header = document.querySelector('header[data-topbar]')
    const bottom = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0
    el.style.setProperty('--msgr-inc-top', Math.round(bottom + 48) + 'px')
}

async function decline () {
    const ringing = ringingNow
    if (!ringing) return
    dismissed.add(ringing.call_id)
    ringingNow = null
    popup().hidden = true
    await api(urlFor('decline', ringing.call_id), { method: 'POST', body: {} })
    schedule()
}

async function answer () {
    const ringing = ringingNow
    if (!ringing) return
    dismissed.add(ringing.call_id)
    ringingNow = null
    popup().hidden = true

    const res = await api(urlFor('answer', ringing.call_id), { method: 'POST', body: {} })
    if (!res.ok || !res.data?.data) {
        toast(refusal(res, 'toast.failed'))
        schedule()
        return
    }
    await join(res.data.data, { outgoing: false })
}

/* ─────────────────────────── কলের পর্দা ─────────────────────────── */

const call = {
    phase: 'idle', // idle | ringing | active
    id: null,
    video: false,
    peer: null,
    room: null,
    lk: null,
    startedAt: 0,
    clockTimer: null,
    watchTimer: null,
    giveUpTimer: null,
    muted: false,
    cameraOn: false,
    sharing: false,
    remoteVideo: null,
}

function screen () {
    return root.querySelector('[data-msgr-call]')
}

function all (selector) {
    return Array.from(screen().querySelectorAll(selector))
}

/** পর্দাটা কাজের জায়গায় — টপবারের নিচে, মেনুর ডানে, নিচের বারের উপরে (নমুনা: খোলস চারপাশে থাকে) */
function placeScreen () {
    const el = screen()
    if (!el) return
    if (window.innerWidth < 768) {
        for (const p of ['top', 'left', 'bottom', 'radius']) el.style.setProperty('--msgr-call-' + p, '0px')
        return
    }
    const header = document.querySelector('header[data-topbar]')
    const main = document.getElementById('main')
    const footer = document.querySelector('footer[data-footer]')
    const top = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0
    const left = main ? Math.max(0, main.getBoundingClientRect().left) : 0
    const footTop = footer ? footer.getBoundingClientRect().top : window.innerHeight
    const bottom = footTop > top && footTop < window.innerHeight ? window.innerHeight - footTop : 0
    el.style.setProperty('--msgr-call-top', Math.round(top) + 'px')
    el.style.setProperty('--msgr-call-left', Math.round(left) + 'px')
    el.style.setProperty('--msgr-call-bottom', Math.round(bottom) + 'px')
    el.style.setProperty('--msgr-call-radius', left > 0 ? '24px' : '0px')
}

function paintSub () {
    const kind = word(call.video ? 'screen.video' : 'screen.voice')
    for (const el of all('[data-msgr-sub]')) {
        el.textContent = ''
        if (call.phase === 'active' && call.startedAt) {
            el.appendChild(document.createTextNode(kind + ' · '))
            const n = document.createElement('span')
            n.className = 'msgr-n'
            n.textContent = clockOf((Date.now() - call.startedAt) / 1000)
            el.appendChild(n)
        } else {
            el.textContent = kind + ' · ' + word(call.phase === 'ringing' ? 'screen.ringing' : 'screen.connecting')
        }
    }
}

function paintControls () {
    const el = screen()
    el.dataset.mode = call.video ? 'video' : 'voice'
    el.dataset.phase = call.phase

    for (const b of all('[data-msgr-mute]')) {
        b.setAttribute('aria-pressed', call.muted ? 'true' : 'false')
        b.setAttribute('aria-label', word(call.muted ? 'screen.unmute' : 'screen.mute'))
        b.title = b.getAttribute('aria-label')
    }
    for (const l of all('[data-msgr-mute-label]')) l.textContent = word(call.muted ? 'screen.unmute' : 'screen.mute')

    for (const b of all('[data-msgr-camera]')) {
        b.setAttribute('aria-pressed', call.cameraOn ? 'false' : 'true')
        b.setAttribute('aria-label', word(call.cameraOn ? 'screen.camera_off' : 'screen.camera_on'))
        b.title = b.getAttribute('aria-label')
    }
    for (const b of all('[data-msgr-screen]')) {
        b.setAttribute('aria-pressed', call.sharing ? 'true' : 'false')
        b.setAttribute('aria-label', word(call.sharing ? 'screen.screen_stop' : 'screen.screen_share'))
        b.title = b.getAttribute('aria-label')
    }
    paintSub()
}

function openScreen (payload) {
    call.peer = payload.peer || null
    call.video = !!payload.video
    for (const el of all('[data-msgr-peer-avatar]')) paintAvatar(el, call.peer)
    for (const el of all('[data-msgr-peer-name]')) el.textContent = call.peer?.name || ''
    screen().setAttribute('aria-label', (call.peer?.name || '') + ' — ' + word(call.video ? 'screen.video' : 'screen.voice'))
    placeScreen()
    paintControls()
    screen().hidden = false
    screen().querySelector('[data-msgr-end]:not([hidden])')?.focus({ preventScroll: true })
}

function startClock () {
    if (call.startedAt) return
    call.startedAt = Date.now()
    call.phase = 'active'
    clearTimeout(call.giveUpTimer)
    clearInterval(call.watchTimer)
    call.watchTimer = null
    paintControls()
    call.clockTimer = setInterval(paintSub, 1000)
}

/** কল শেষ — সার্ভারকে বলা (বললে), ঘর ছাড়া, পর্দা বন্ধ */
async function hangUp ({ tell = true, note = null } = {}) {
    if (call.phase === 'idle') return
    const id = call.id
    const room = call.room

    call.phase = 'idle'
    clearInterval(call.clockTimer)
    clearInterval(call.watchTimer)
    clearTimeout(call.giveUpTimer)
    call.clockTimer = call.watchTimer = call.giveUpTimer = null
    call.room = null
    call.startedAt = 0
    call.remoteVideo = null

    const el = screen()
    el.hidden = true
    closeDevices()
    for (const v of el.querySelectorAll('video')) { v.srcObject = null; v.hidden = true }
    el.querySelector('[data-msgr-remote-ph]').removeAttribute('hidden')
    el.querySelector('[data-msgr-audio]').textContent = ''

    try { await room?.disconnect() } catch { /* ⓘ ঘর আগেই বন্ধ */ }
    if (tell && id) await api(urlFor('end', id), { method: 'POST', body: {} })
    call.id = null
    if (note) toast(word(note))
    schedule()
}

/* ── সামনের মানুষের শব্দ আর ছবি ── */

function remoteVideoEl () {
    return screen().querySelector('[data-msgr-remote-video]')
}

function showRemoteVideo (track) {
    const v = remoteVideoEl()
    if (!track) {
        v.hidden = true
        v.srcObject = null
        screen().querySelector('[data-msgr-remote-ph]').removeAttribute('hidden')
        return
    }
    track.attach(v)
    v.hidden = false
    screen().querySelector('[data-msgr-remote-ph]').setAttribute('hidden', '')
    // ⓘ সামনের মানুষ ক্যামেরা চালালে ভয়েসের পর্দা ভিডিওতে বদলায়
    if (!call.video) {
        call.video = true
        paintControls()
    }
}

/** বড় পর্দায় কী — স্ক্রিন শেয়ার থাকলে সেটা, নইলে ক্যামেরা */
function pickRemoteVideo (participant) {
    const { Track } = call.lk
    const pubs = Array.from(participant?.trackPublications?.values?.() || [])
    const live = (source) => pubs.find((p) => p.source === source && p.track && !p.isMuted)?.track || null
    showRemoteVideo(live(Track.Source.ScreenShare) || live(Track.Source.Camera))
}

function paintRemoteMic (participant) {
    const { Track } = call.lk
    const pub = participant?.getTrackPublication?.(Track.Source.Microphone)
    const off = !!pub && pub.isMuted
    screen().querySelector('[data-msgr-remote-mic="on"]').hidden = off
    screen().querySelector('[data-msgr-remote-mic="off"]').hidden = !off
}

function remoteOf (room) {
    return Array.from(room?.remoteParticipants?.values?.() || [])[0] || null
}

function selfVideo () {
    const { Track } = call.lk
    const v = screen().querySelector('[data-msgr-self-video]')
    const pub = call.room?.localParticipant?.getTrackPublication?.(Track.Source.Camera)
    if (pub?.track && call.cameraOn) {
        pub.track.attach(v)
        v.hidden = false
    } else {
        v.hidden = true
        v.srcObject = null
    }
}

/** LiveKit-এ যুক্ত হওয়া — দুই দিকেই একই (চুক্তি §২: { call_id, url, token, video, peer, room }) */
async function join (payload, { outgoing }) {
    call.id = payload.call_id
    call.phase = outgoing ? 'ringing' : 'active'
    call.muted = false
    call.sharing = false
    call.cameraOn = !!payload.video
    call.startedAt = 0
    openScreen(payload)
    if (!outgoing) {
        call.phase = 'active'
        paintControls()
    }
    schedule()

    let lk
    try {
        lk = await import('livekit-client')
    } catch {
        await hangUp({ note: 'toast.failed' })
        return
    }
    if (call.id !== payload.call_id) return
    call.lk = lk

    /*
     * ⛔ `singlePeerConnection: false` — the call server's older `/rtc` path (see the fe/rc1311 note); ⭐ HD video (owner, 24 Nov
     * 2026: "ভিডিওর রেজুলেশন একদম খারাপ মানের … বাফারিং করে"): 720p camera, sent as 720p with 180p/360p layers beside it.
     */
    const P = lk.VideoPresets
    const room = new lk.Room({
        adaptiveStream: true,
        dynacast: true,
        singlePeerConnection: false,
        videoCaptureDefaults: { resolution: P.h720.resolution },
        publishDefaults: { simulcast: true, videoEncoding: P.h720.encoding, videoSimulcastLayers: [P.h180, P.h360] },
    })
    call.room = room
    const { RoomEvent, Track } = lk
    const audioBox = screen().querySelector('[data-msgr-audio]')

    room
        .on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
            if (track.kind === Track.Kind.Audio) {
                const a = track.attach()
                audioBox.appendChild(a)
            } else {
                pickRemoteVideo(participant)
            }
        })
        .on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
            track.detach()
            if (track.kind !== Track.Kind.Audio) pickRemoteVideo(participant)
        })
        .on(RoomEvent.TrackMuted, (publication, participant) => {
            if (participant !== room.localParticipant) { pickRemoteVideo(participant); paintRemoteMic(participant) }
        })
        .on(RoomEvent.TrackUnmuted, (publication, participant) => {
            if (participant !== room.localParticipant) { pickRemoteVideo(participant); paintRemoteMic(participant) }
        })
        .on(RoomEvent.ParticipantConnected, (participant) => {
            startClock()
            paintRemoteMic(participant)
        })
        // ⓘ সামনের মানুষ চলে গেলেন — কল শেষ
        .on(RoomEvent.ParticipantDisconnected, () => {
            if (call.room === room) hangUp({ note: 'toast.ended' })
        })
        .on(RoomEvent.Disconnected, () => {
            if (call.room === room) hangUp({ note: 'toast.ended' })
        })
        .on(RoomEvent.LocalTrackUnpublished, (publication) => {
            if (publication.source === Track.Source.ScreenShare && call.sharing) {
                call.sharing = false
                paintControls()
            }
        })

    try {
        await room.connect(payload.url, payload.token)
    } catch {
        if (call.room === room) await hangUp({ note: 'toast.failed' })
        return
    }
    if (call.room !== room) { room.disconnect(); return }

    try { await room.startAudio() } catch { /* ⓘ ব্রাউজার নিজে চালাবে প্রথম চাপে */ }

    try {
        await room.localParticipant.setMicrophoneEnabled(true)
        if (call.cameraOn) await room.localParticipant.setCameraEnabled(true)
    } catch {
        await hangUp({ note: 'toast.no_media' })
        return
    }
    selfVideo()

    const there = remoteOf(room)
    if (there) {
        startClock()
        pickRemoteVideo(there)
        paintRemoteMic(there)
    } else if (outgoing) {
        watchOutgoing()
    }
    paintControls()
    loadDevices()
}

/** বাজছে — সামনের মানুষ কেটে দিলেন / ধরলেন না কি না, কলের খাতা থেকে (৩ সেকেন্ডে) */
function watchOutgoing () {
    const id = call.id
    clearInterval(call.watchTimer)
    call.watchTimer = setInterval(async () => {
        if (call.id !== id || call.phase !== 'ringing') return
        const res = await api(urlFor('calls'))
        const row = (res.data?.data || []).find((r) => r.call_id === id)
        if (!row || call.id !== id || call.phase !== 'ringing') return
        if (row.status === 'declined') hangUp({ tell: false, note: 'toast.declined' })
        else if (row.status === 'missed') hangUp({ tell: false, note: 'toast.missed' })
        else if (row.status === 'ended') hangUp({ tell: false, note: 'toast.ended' })
    }, POLL_RINGING_MS)
    clearTimeout(call.giveUpTimer)
    call.giveUpTimer = setTimeout(() => {
        if (call.id === id && call.phase === 'ringing') hangUp({ note: 'toast.missed' })
    }, RING_GIVE_UP_MS)
}

/** কল শুরু — বার্তার পাতার `messenger:call` থেকে */
export async function startCall (personId, video) {
    if (call.phase !== 'idle') {
        toast(word('toast.busy'))
        return
    }
    if (!personId) return
    call.phase = 'ringing' // ⓘ দুইবার চাপলে দুইটা কল নয়
    const res = await api(urlFor('start'), { method: 'POST', body: { person: String(personId), video: !!video } })
    call.phase = 'idle'
    if (!res.ok || !res.data?.data) {
        toast(refusal(res, 'toast.failed'))
        return
    }
    await join(res.data.data, { outgoing: true })
}

/* ── বোতাম ── */

async function toggleMute () {
    if (!call.room) return
    call.muted = !call.muted
    try { await call.room.localParticipant.setMicrophoneEnabled(!call.muted) } catch { call.muted = !call.muted }
    paintControls()
}

async function toggleCamera (on = !call.cameraOn) {
    if (!call.room) return
    try {
        await call.room.localParticipant.setCameraEnabled(on)
        call.cameraOn = on
    } catch {
        toast(word('toast.no_media'))
    }
    if (on && !call.video) call.video = true
    selfVideo()
    paintControls()
}

async function toggleScreen () {
    if (!call.room) return
    const on = !call.sharing
    try {
        await call.room.localParticipant.setScreenShareEnabled(on)
        call.sharing = on
    } catch {
        call.sharing = false
    }
    paintControls()
}

/* ── স্পিকার ▾ — কোন যন্ত্রে শব্দ যাবে ── */

let devices = []
let deviceNow = ''

async function loadDevices () {
    if (!call.lk) return
    try {
        devices = await call.lk.Room.getLocalDevices('audiooutput', false)
    } catch {
        devices = []
    }
    deviceNow = call.room?.getActiveDevice?.('audiooutput') || deviceNow || devices[0]?.deviceId || ''
    paintSpeaker()
}

function deviceName (d) {
    return d?.label && d.deviceId !== 'default' ? d.label : word('screen.speaker_default')
}

function paintSpeaker () {
    const now = devices.find((d) => d.deviceId === deviceNow)
    const label = word('screen.speaker_is', { name: deviceName(now) })
    for (const el of all('[data-msgr-speaker-label]')) el.textContent = label
}

function closeDevices () {
    const menu = screen()?.querySelector('[data-msgr-devices]')
    if (menu) menu.hidden = true
    for (const b of all('[data-msgr-speaker]')) b.setAttribute('aria-expanded', 'false')
}

async function openDevices (button) {
    const menu = screen().querySelector('[data-msgr-devices]')
    if (!menu.hidden) { closeDevices(); return }
    await loadDevices()
    menu.textContent = ''
    const list = devices.length ? devices : [{ deviceId: '', label: '' }]
    for (const d of list) {
        const item = document.createElement('button')
        item.type = 'button'
        item.setAttribute('role', 'menuitemradio')
        item.setAttribute('aria-checked', d.deviceId === deviceNow ? 'true' : 'false')
        item.textContent = deviceName(d)
        item.addEventListener('click', async () => {
            closeDevices()
            if (!d.deviceId || !call.room) return
            try {
                await call.room.switchActiveDevice('audiooutput', d.deviceId)
                deviceNow = d.deviceId
            } catch { /* ⓘ এই ব্রাউজার যন্ত্র বদলাতে দেয় না */ }
            paintSpeaker()
        })
        menu.appendChild(item)
    }
    menu.hidden = false
    button.setAttribute('aria-expanded', 'true')
    menu.querySelector('button')?.focus()
}

/* ─────────────────────────── জাগানো ─────────────────────────── */

function wire () {
    root.addEventListener('click', (event) => {
        const t = event.target instanceof Element ? event.target : null
        if (!t) return
        if (t.closest('[data-msgr-answer]')) { event.preventDefault(); answer() }
        else if (t.closest('[data-msgr-decline]')) { event.preventDefault(); decline() }
        else if (t.closest('[data-msgr-end]')) { event.preventDefault(); hangUp({ note: 'toast.ended' }) }
        else if (t.closest('[data-msgr-mute]')) { event.preventDefault(); toggleMute() }
        else if (t.closest('[data-msgr-camera]')) { event.preventDefault(); toggleCamera() }
        else if (t.closest('[data-msgr-screen]')) { event.preventDefault(); toggleScreen() }
        else if (t.closest('[data-msgr-to-video]')) { event.preventDefault(); toggleCamera(true) }
        else if (t.closest('[data-msgr-speaker]')) { event.preventDefault(); openDevices(t.closest('[data-msgr-speaker]')) }
        else if (!t.closest('[data-msgr-devices]')) closeDevices()
    })

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeDevices()
    })

    // ⓘ capture-এ, window-এ — বার্তার পাতা যে উপাদানেই পাঠাক (bubbles ছাড়াও) এখানে পৌঁছায়
    window.addEventListener('messenger:call', (event) => {
        const d = event.detail || {}
        startCall(d.person_id ?? d.personId ?? d.person, !!d.video)
    }, true)

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll()
        else clearTimeout(pollTimer)
    })

    window.addEventListener('resize', () => {
        if (!screen().hidden) placeScreen()
        if (!popup().hidden) placeIncoming(popup())
    })

    // ⛔ পাতা বদলালে ব্রাউজারের কল কাটে — সার্ভারকেও জানানো, যাতে সামনের মানুষ ঝুলে না থাকেন
    window.addEventListener('pagehide', () => {
        if (call.phase === 'idle' || !call.id) return
        const form = new FormData()
        form.append('_token', csrf())
        try { navigator.sendBeacon(urlFor('end', call.id), form) } catch { /* ⓘ সার্ভারের ঝাড়ু ৪৫ সেকেন্ডে ধরে */ }
    })
    window.addEventListener('beforeunload', (event) => {
        if (call.phase === 'idle') return
        event.preventDefault()
        event.returnValue = ''
    })
}

function parse (json) {
    try { return JSON.parse(json || '{}') } catch { return {} }
}

/** খোলস এঁকে থাকলে জাগে; বার বার ডাকলে একবারই */
export function bootMessenger (doc = document) {
    const found = doc.querySelector('[data-msgr-root]')
    if (!found || found.dataset.msgrBooted === '1') return found ? root : null
    found.dataset.msgrBooted = '1'
    root = found
    urls = parse(root.dataset.urls)
    words = parse(root.dataset.words)
    wire()
    poll()
    return root
}
