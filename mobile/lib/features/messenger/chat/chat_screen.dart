import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:just_audio/just_audio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/api_client/api_client.dart';
import '../../../core/auth/auth_state.dart';
import '../../../core/auth/me_features.dart';
import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/messenger/messenger_push.dart';
import '../../../core/navigation/recent_papers.dart';
import '../../../core/permissions/camera_shot.dart';
import '../../../core/printing/documents_api.dart';
import '../../../core/printing/paper_files.dart';
import '../../../core/theme/app_look.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/voice/copilot_voice.dart';
import '../../../core/widgets/day_part.dart' show monthNames;
import '../../printing/pdf_view_page.dart';
import '../call/calls.dart';
import 'chat_bubbles.dart';
import 'chat_composer.dart';
import 'chat_header.dart';
import 'chat_pages.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';
import 'wa_wallpaper.dart';

/// ⭐ চ্যাটের পাতা (WhatsApp-ধাঁচ, তিন রূপেই একই) — নমুনা 3nuwRUdn v7 "খ"/"গ"।
///
/// <p>নিজের মাথা (অ্যাপের বার নয়), নিজের নকশার পট, বুদবুদ, লেখার জায়গা। খোলা থাকলে ৩ সেকেন্ডে একবার টানে (`after` শেষ নম্বর),
/// ৩০ সেকেন্ড চুপ থাকলে ৫ সেকেন্ডে; push এলে সাথে সাথে; পটভূমিতে গেলে থামে। পাঠানো বার্তা সাথে সাথে দেখায় (ফোনের নিজের
/// চিহ্ন দিয়ে), সার্ভারের উত্তর এলে বদলায়; না গেলে লাল চিহ্ন, চাপলে আবার যায়। ওপরে টানলে পুরনো বার্তা আসে।
/// ⛔ দরজা নেই/অনুমতি নেই হলে শান্ত কথা — ভাঙে না।
class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({
    super.key,
    required this.thread,
    this.me,
    this.now,
    this.openPaper,
    this.recentPapers,
    this.voice,
    this.loadBytes,
    this.pollFast = const Duration(seconds: 3),
    this.pollSlow = const Duration(seconds: 5),
    this.callsEnabled = true,
  });

  final ChatThread thread;

  /// আমি — আমার ভয়েস বার্তার অবতার; না দিলে সাইন-ইন থেকে
  final ChatPerson? me;

  /// ঘড়ি ("আজ"/"গতকাল") — পরীক্ষায় স্থির
  final DateTime Function()? now;

  /// কাগজ খোলা — না দিলে কাগজের দরজা (`/documents/{type}/{id}`) থেকে PDF এনে অ্যাপেই দেখায়
  final Future<void> Function(BuildContext context, PaperRef paper)? openPaper;

  /// "ERP কাগজ" বাছাইয়ের তালিকা — না দিলে "সাম্প্রতিক"-এর কাগজ ([RecentPapers])
  final Future<List<PaperChoice>> Function()? recentPapers;

  /// বলে লেখা — না দিলে ফোনের নিজের ([PhoneCopilotVoice])
  final CopilotVoice? voice;

  /// সংযুক্তির বাইট — না দিলে অ্যাপের [ApiClient] দিয়ে (টোকেন, pinning একই)
  final Future<Uint8List> Function(String url)? loadBytes;

  /// ⭐ কল/ভিডিও বোতাম দেখাবে কি — খোলার জায়গা `/me`-র features.calls দেয় ([openChat]-এর `calls`); পুরনো সার্ভারে false
  final bool callsEnabled;

  final Duration pollFast;
  final Duration pollSlow;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> with WidgetsBindingObserver {
  late final MessengerApi _api = ref.read(messengerApiProvider);
  ChatThread get _thread => widget.thread;

  final List<ChatMessage> _msgs = [];
  bool _loading = true;
  Object? _error;
  bool _hasOlder = true;
  bool _loadingOlder = false;
  List<ChatPerson> _typing = const [];
  bool? _peerOnline;
  late bool _muted = _thread.muted;
  bool _trayOpen = false;
  String? _selected;
  ChatMessage? _replyTo;
  String? _lastMarked;

  // খোঁজা
  bool _searching = false;
  bool _searched = false;
  final _searchCtrl = TextEditingController();
  List<String> _matches = const [];
  int _matchAt = 0;
  String _query = '';

  final _scroll = ScrollController();
  final _composerKey = GlobalKey<ChatComposerState>();
  final Map<String, GlobalKey> _keys = {};
  final Map<String, ChatUpload> _uploads = {};
  final Map<String, String> _replyIds = {};
  final Map<String, Future<Uint8List?>> _images = {};
  final Map<String, String> _files = {};
  final _voiceNow = ValueNotifier<VoiceNow>(const VoiceNow());
  final _downloading = ValueNotifier<Set<String>>(const {});

  Timer? _timer;
  bool _active = true;
  DateTime _lastActivity = DateTime.now();
  DateTime _lastTypingSent = DateTime.fromMillisecondsSinceEpoch(0);
  StreamSubscription<MessengerEvent>? _push;
  AudioPlayer? _player;
  final List<StreamSubscription<Object?>> _playerSubs = [];

  String? _toast;
  bool _toastClock = false;
  bool _toastFading = false;
  Timer? _toastTimer;

  DateTime _now() => (widget.now ?? DateTime.now)();

  /// পাতার ভেতরের প্রসঙ্গ ([_sameInEveryLook]-এর থিমের নিচে) — শিট/ডায়ালগ এখান থেকে খোলে
  BuildContext? _inner;
  BuildContext get _sheetContext {
    final c = _inner;
    return c != null && c.mounted ? c : context;
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _scroll.addListener(_onScroll);
    _push = MessengerPush.instance.events.listen((e) {
      if (e.type == 'chat' && (e.threadId == null || e.threadId == _thread.id)) unawaited(_pull());
    });
    // ⓘ খোলা চ্যাটের নতুন বার্তায় নোটিফিকেশন নয় — push_service এটা দেখে চুপ থাকে
    MessengerPush.instance.visibleThread.value = _thread.id;
    unawaited(_load());
  }

  void _hideFromPush() {
    final v = MessengerPush.instance.visibleThread;
    if (v.value == _thread.id) v.value = null;
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _hideFromPush();
    _timer?.cancel();
    _toastTimer?.cancel();
    _push?.cancel();
    for (final s in _playerSubs) {
      s.cancel();
    }
    unawaited(_player?.dispose());
    _scroll.dispose();
    _searchCtrl.dispose();
    _voiceNow.dispose();
    _downloading.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _active = true;
      MessengerPush.instance.visibleThread.value = _thread.id;
      unawaited(_pull().then((_) => _schedule()));
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      _active = false;
      _hideFromPush();
      _timer?.cancel();
    }
  }

  ChatPerson? get _me {
    if (widget.me != null) return widget.me;
    try {
      final u = ref.read(authStateProvider).user;
      if (u == null) return null;
      return ChatPerson(id: u.id, name: u.name, photoUrl: u.avatarUrl);
    } catch (_) {
      return null;
    }
  }

  // ── আনা আর টানা ───────────────────────────────────────────────
  static bool _isLocal(String id) => id.startsWith('local-');

  static int _order(ChatMessage a, ChatMessage b) {
    final la = _isLocal(a.id), lb = _isLocal(b.id);
    if (la != lb) return la ? 1 : -1;
    if (la) return a.at.compareTo(b.at);
    final na = int.tryParse(a.id), nb = int.tryParse(b.id);
    if (na != null && nb != null) return na.compareTo(nb);
    return a.at.compareTo(b.at);
  }

  String? get _lastServerId {
    for (final m in _msgs.reversed) {
      if (!_isLocal(m.id)) return m.id;
    }
    return null;
  }

  String? get _firstServerId {
    for (final m in _msgs) {
      if (!_isLocal(m.id)) return m.id;
    }
    return null;
  }

  void _upsert(ChatMessage m) {
    var i = m.clientId == null ? -1 : _msgs.indexWhere((x) => x.clientId != null && x.clientId == m.clientId);
    if (i < 0) i = _msgs.indexWhere((x) => x.id == m.id);
    if (i >= 0) {
      _msgs[i] = m;
    } else {
      _msgs.add(m);
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final list = await _api.messages(_thread.id);
      if (!mounted) return;
      setState(() {
        _msgs
          ..clear()
          ..addAll(list)
          ..sort(_order);
        _loading = false;
      });
      _markRead();
      _schedule();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e;
      });
    }
  }

  void _schedule() {
    _timer?.cancel();
    if (!_active || !mounted || _error != null) return;
    final quiet = DateTime.now().difference(_lastActivity) > const Duration(seconds: 30);
    _timer = Timer(quiet ? widget.pollSlow : widget.pollFast, () async {
      await _pull();
      _schedule();
    });
  }

  bool _pulling = false;

  Future<void> _pull() async {
    if (_pulling || _loading || !mounted) return;
    _pulling = true;
    try {
      final p = await _api.poll(_thread.id, after: _lastServerId ?? '0');
      if (!mounted) return;
      var fresh = false;
      setState(() {
        for (final m in p.messages) {
          if (!_msgs.any((x) => x.id == m.id) && !m.mine) fresh = true;
          _upsert(m);
        }
        for (final s in p.states) {
          final i = _msgs.indexWhere((x) => x.id == s.id);
          if (i < 0) continue;
          final old = _msgs[i];
          _msgs[i] = s.deleted ? old.copyWith(deleted: true) : old.copyWith(state: s.state, readCount: s.readCount);
        }
        _msgs.sort(_order);
        _typing = p.typing;
        if (p.peerOnline != null) _peerOnline = p.peerOnline;
      });
      if (p.messages.isNotEmpty) _lastActivity = DateTime.now();
      if (fresh) _markRead();
    } catch (_) {
      // ⓘ টান না হলে পরের বার — পাতা যেমন আছে থাকে
    } finally {
      _pulling = false;
    }
  }

  void _markRead() {
    final id = _lastServerId;
    if (id == null || id == _lastMarked) return;
    _lastMarked = id;
    unawaited(_api.markRead(_thread.id, id).catchError((_) {}));
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final p = _scroll.position;
    if (p.pixels >= p.maxScrollExtent - 200) unawaited(_loadOlder());
  }

  Future<bool> _loadOlder() async {
    final first = _firstServerId;
    if (!_hasOlder || _loadingOlder || first == null) return false;
    _loadingOlder = true;
    try {
      final older = await _api.messages(_thread.id, before: first);
      if (!mounted) return false;
      setState(() {
        if (older.isEmpty) _hasOlder = false;
        for (final m in older) {
          if (!_msgs.any((x) => x.id == m.id)) _msgs.add(m);
        }
        _msgs.sort(_order);
      });
      return older.isNotEmpty;
    } catch (_) {
      return false;
    } finally {
      _loadingOlder = false;
    }
  }

  // ── পাঠানো ───────────────────────────────────────────────────
  static String _clientId() {
    final r = math.Random.secure();
    final b = List<int>.generate(16, (_) => r.nextInt(256));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
    return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
  }

  ChatMessage _local(MessageKind kind, {String text = '', ChatAttachment? attachment, double? lat, double? lng}) {
    final cid = _clientId();
    final r = _replyTo;
    if (r != null) _replyIds[cid] = r.id;
    return ChatMessage(
      id: 'local-$cid',
      threadId: _thread.id,
      clientId: cid,
      kind: kind,
      at: _now(),
      mine: true,
      text: text,
      state: MessageState.sending,
      attachment: attachment,
      lat: lat,
      lng: lng,
      replyTo: r == null ? null : ReplyRef(id: r.id, kind: r.kind, text: r.text, sender: r.sender, mine: r.mine),
    );
  }

  void _post(ChatMessage m) {
    setState(() {
      _msgs.add(m);
      _replyTo = null;
      _trayOpen = false;
    });
    _lastActivity = DateTime.now();
    if (_scroll.hasClients) unawaited(_scroll.animateTo(0, duration: const Duration(milliseconds: 200), curve: Curves.easeOut));
    unawaited(_deliver(m));
  }

  Future<void> _deliver(ChatMessage m) async {
    final cid = m.clientId!;
    final reply = _replyIds[cid];
    try {
      final ChatMessage got;
      switch (m.kind) {
        case MessageKind.image:
        case MessageKind.file:
        case MessageKind.voice:
          got = await _api.sendFile(_thread.id, _uploads[cid]!, clientId: cid, replyToId: reply);
        case MessageKind.location:
          got = await _api.sendLocation(_thread.id, m.lat ?? 0, m.lng ?? 0, clientId: cid, replyToId: reply);
        default:
          got = await _api.sendText(_thread.id, m.text, clientId: cid, replyToId: reply);
      }
      if (!mounted) return;
      var fixed = got.state == MessageState.sending || got.state == MessageState.failed ? got.copyWith(state: MessageState.sent) : got;
      if (fixed.clientId == null) fixed = _withClient(fixed, cid);
      final upload = _uploads[cid];
      // ⓘ পাঠানো ছবির বাইট ফোনেই আছে — নতুন নম্বরেও সেটাই দেখায়, আবার নামায় না
      if (upload != null) _images[fixed.id] = Future.value(upload.bytes);
      setState(() {
        final i = _msgs.indexWhere((x) => x.clientId == cid);
        if (i >= 0) {
          _msgs[i] = fixed;
        } else {
          _upsert(fixed);
        }
        _msgs.removeWhere((x) => x.id == fixed.id && x.clientId != cid);
        _msgs.sort(_order);
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        final i = _msgs.indexWhere((x) => x.clientId == cid);
        if (i >= 0) _msgs[i] = _msgs[i].copyWith(state: MessageState.failed);
      });
      if (e is MessengerNotAllowed && e.message != null) _say(e.message!);
    }
  }

  static ChatMessage _withClient(ChatMessage m, String cid) => ChatMessage(
        id: m.id,
        threadId: m.threadId,
        clientId: cid,
        kind: m.kind,
        at: m.at,
        mine: m.mine,
        sender: m.sender,
        text: m.text,
        state: m.state,
        readCount: m.readCount,
        attachment: m.attachment,
        paper: m.paper,
        lat: m.lat,
        lng: m.lng,
        replyTo: m.replyTo,
        forwarded: m.forwarded,
      );

  void _retry(ChatMessage m) {
    if (m.clientId == null) return;
    setState(() {
      final i = _msgs.indexWhere((x) => x.clientId == m.clientId);
      if (i >= 0) _msgs[i] = _msgs[i].copyWith(state: MessageState.sending);
    });
    unawaited(_deliver(_msgs.firstWhere((x) => x.clientId == m.clientId)));
  }

  void _sendText(String t) => _post(_local(MessageKind.text, text: t));

  void _sendUpload(ChatUpload u) {
    final m = _local(u.kind,
        attachment: ChatAttachment(url: '', name: u.name, mime: u.mime, size: u.bytes.length, durationMs: u.durationMs, waveform: u.waveform));
    _uploads[m.clientId!] = u;
    _images[m.id] = Future.value(u.bytes);
    _post(m);
  }

  void _onTyping() {
    final now = DateTime.now();
    if (now.difference(_lastTypingSent) < const Duration(seconds: 3)) return;
    _lastTypingSent = now;
    unawaited(_api.typing(_thread.id).catchError((_) {}));
  }

  // ── ছোট খবর (নমুনার `.toast`) ───────────────────────────────────
  void _say(String text, {bool clock = false}) {
    _toastTimer?.cancel();
    setState(() {
      _toast = text;
      _toastClock = clock;
      _toastFading = false;
    });
    _toastTimer = Timer(const Duration(milliseconds: 1800), () {
      if (!mounted) return;
      setState(() => _toastFading = true);
      _toastTimer = Timer(const Duration(milliseconds: 300), () {
        if (mounted) setState(() => _toast = null);
      });
    });
  }

  // ── মাথার কাজ ─────────────────────────────────────────────────
  void _call({required bool video}) {
    final l = context.l10n;
    if (_thread.isGroup) {
      _say(video ? l.msgChatSoonGroupVideo : l.msgChatSoonGroupCall, clock: true);
      return;
    }
    final p = _thread.person;
    if (p != null) unawaited(Calls.start(context, p, video: video));
  }

  Future<void> _menu(ChatMenu m) async {
    final l = context.l10n;
    switch (m) {
      case ChatMenu.search:
        setState(() {
          _searching = true;
          _searched = false;
          _matches = const [];
          _query = '';
          _searchCtrl.clear();
        });
      case ChatMenu.media:
        await Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => ChatMediaPage(title: _thread.title, load: () => _api.media(_thread.id), onOpen: _openMedia),
        ));
      case ChatMenu.mute:
        final next = !_muted;
        try {
          await _api.mute(_thread.id, next);
          if (!mounted) return;
          setState(() => _muted = next);
          _say(next ? l.msgChatMutedToast : l.msgChatUnmutedToast);
        } catch (e) {
          if (mounted) _say(calmError(context, e));
        }
      case ChatMenu.members:
        await Navigator.of(context).push(MaterialPageRoute<void>(
          builder: (_) => ChatMembersPage(thread: _thread, load: () => _api.members(_thread.id)),
        ));
    }
  }

  void _openMedia(BuildContext pageContext, ChatMessage m) {
    switch (m.kind) {
      case MessageKind.image:
        _openImage(m);
      case MessageKind.paper:
        if (m.paper != null) _openPaper(m.paper!);
      default:
        _openFile(m);
    }
  }

  // ── খোঁজা ────────────────────────────────────────────────────
  Future<void> _runSearch(String q) async {
    q = q.trim();
    if (q.isEmpty) return;
    try {
      final found = await _api.search(_thread.id, q);
      if (!mounted) return;
      final ids = [for (final m in [...found]..sort(_order)) m.id];
      setState(() {
        _query = q;
        _searched = true;
        _matches = ids;
        _matchAt = ids.isEmpty ? 0 : ids.length - 1;
      });
      if (ids.isNotEmpty) await _goToMatch();
    } catch (e) {
      if (mounted) _say(calmError(context, e));
    }
  }

  Future<void> _goToMatch() async {
    final id = _matches[_matchAt];
    final want = int.tryParse(id);
    var guard = 0;
    while (!_msgs.any((m) => m.id == id) && _hasOlder && guard++ < 20) {
      final first = int.tryParse(_firstServerId ?? '');
      if (want != null && first != null && want > first) break;
      if (!await _loadOlder()) break;
    }
    if (!mounted) return;
    setState(() {});
    await _jumpTo(id);
  }

  Future<void> _jumpTo(String id) async {
    for (var i = 0; i < 8 && mounted; i++) {
      await WidgetsBinding.instance.endOfFrame;
      final c = _keys[id]?.currentContext;
      if (c != null && c.mounted) {
        await Scrollable.ensureVisible(c, alignment: .5, duration: const Duration(milliseconds: 250));
        return;
      }
      if (!_scroll.hasClients) return;
      // ⓘ এখনো আঁকা হয়নি — আন্দাজে কাছে যাই, তারপর আবার
      final idx = _msgs.indexWhere((m) => m.id == id);
      if (idx < 0) return;
      final fromEnd = (_msgs.length - 1 - idx) / math.max(1, _msgs.length);
      _scroll.jumpTo((_scroll.position.maxScrollExtent * fromEnd).clamp(0, _scroll.position.maxScrollExtent));
    }
  }

  void _closeSearch() => setState(() {
        _searching = false;
        _searched = false;
        _matches = const [];
        _query = '';
      });

  // ── বার্তা চেপে ধরা ────────────────────────────────────────────
  ChatMessage? get _selectedMessage => _selected == null ? null : _msgs.where((m) => m.id == _selected).firstOrNull;

  String _plain(ChatMessage m) => switch (m.kind) {
        MessageKind.paper => [m.paper?.title ?? '', m.paper?.no ?? '', m.text].where((s) => s.isNotEmpty).join(' '),
        MessageKind.file => m.text.isEmpty ? (m.attachment?.name ?? '') : m.text,
        MessageKind.location => m.lat == null ? '' : '${m.lat}, ${m.lng}',
        MessageKind.deleted => '',
        _ => m.text,
      };

  Future<void> _act(ChatAct a) async {
    final m = _selectedMessage;
    setState(() => _selected = null);
    if (m == null) return;
    final l = context.l10n;
    switch (a) {
      case ChatAct.reply:
        setState(() => _replyTo = m);
        _composerKey.currentState?.focus.requestFocus();
      case ChatAct.copy:
        await Clipboard.setData(ClipboardData(text: _plain(m)));
        if (mounted) _say(l.msgChatCopied);
      case ChatAct.forward:
        final to = await showForwardPicker(_sheetContext, load: () => _api.threads(), people: () => _api.people());
        if (to == null || to.isEmpty || !mounted) return;
        try {
          await _api.forward([m.id], to, clientId: _clientId());
          if (!mounted) return;
          _say(l.msgChatForwarded);
          if (to.contains('thread:${_thread.id}')) unawaited(_pull());
        } catch (e) {
          if (mounted) _say(e is MessengerNotAllowed && e.message != null ? e.message! : l.msgChatForwardFailed);
        }
      case ChatAct.delete:
        // ⓘ সবার কাছ থেকে — কেবল নিজের বার্তা, পাঠানোর ৬০ মিনিটের মধ্যে (সার্ভারের একই নিয়ম; পেরোলে কেবল আমার কাছ থেকে)
        final everyoneOk = m.mine && m.kind != MessageKind.deleted && _now().difference(m.at) <= const Duration(minutes: 60);
        final how = await askDelete(_sheetContext, mine: everyoneOk);
        if (how == null || !mounted) return;
        if (_isLocal(m.id)) {
          setState(() => _msgs.removeWhere((x) => x.id == m.id));
          return;
        }
        try {
          await _api.deleteMessage(m.id, everyone: how == DeleteFor.everyone);
          if (!mounted) return;
          setState(() {
            final i = _msgs.indexWhere((x) => x.id == m.id);
            if (i < 0) return;
            if (how == DeleteFor.everyone) {
              _msgs[i] = _msgs[i].copyWith(deleted: true);
            } else {
              _msgs.removeAt(i);
            }
          });
        } catch (e) {
          if (mounted) _say(e is MessengerNotAllowed && e.message != null ? e.message! : l.msgChatDeleteFailed);
        }
      case ChatAct.info:
        await showMessageInfo(_sheetContext, message: m, load: () => _api.info(m.id), now: _now());
    }
  }

  // ── বুদবুদের কাজ ──────────────────────────────────────────────
  /// সার্ভারের পাঠানো ঠিকানা (`/api/v1/messenger/files/{id}`), অ্যাপের নিজের Dio দিয়ে — টোকেন আর pinning একই।
  /// ⓘ "/api/v1/…" পথ সার্ভারের মূল ঠিকানার সাথে জোড়া হয় (Dio-র baseUrl-এর পরে নয়, নইলে "/api/v1" দুবার বসত)
  Future<Uint8List> _bytes(String url) async {
    final f = widget.loadBytes;
    if (f != null) return f(url);
    final full = Uri.parse(ApiClient.dio.options.baseUrl).resolve(url).toString();
    final r = await ApiClient.dio.get<List<int>>(full, options: Options(responseType: ResponseType.bytes));
    return Uint8List.fromList(r.data ?? const []);
  }

  static String _thumb(String url) => '$url${url.contains('?') ? '&' : '?'}thumb=1';

  /// বুদবুদে ছোট ছবি (`?thumb=1`); [full] হলে আসল ছবি (চাপলে)
  Future<Uint8List?> _imageOf(ChatMessage m, {bool full = false}) => _images.putIfAbsent(full ? '${m.id}:full' : m.id, () async {
        final local = m.clientId == null ? null : _uploads[m.clientId!]?.bytes;
        if (local != null) return local;
        final url = m.attachment?.url ?? '';
        if (url.isEmpty) return null;
        try {
          return await _bytes(full ? url : _thumb(url));
        } catch (_) {
          return null;
        }
      });

  Future<void> _openImage(ChatMessage m) async {
    final b = await _imageOf(m, full: true) ?? await _imageOf(m);
    if (b == null || !mounted) return;
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ChatPhotoPage(bytes: b, caption: m.text)));
  }

  Future<File> _toFile(ChatMessage m, String name) async {
    final local = m.clientId == null ? null : _uploads[m.clientId!]?.bytes;
    final bytes = local ?? await _bytes(m.attachment?.url ?? '');
    // ⓘ অ্যাপের নিজের কাগজের ফোল্ডারে — open_filex অনুমতি চায় না, আর বেরোলে PaperFiles.wipe() মুছে দেয়
    final dir = await PaperFiles.cache();
    final safe = name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
    final f = File('${dir.path}/chat-${m.id.replaceAll(RegExp(r'[^A-Za-z0-9-]'), '')}-$safe');
    await f.writeAsBytes(bytes, flush: true);
    return f;
  }

  Future<void> _openFile(ChatMessage m) async {
    final have = _files[m.id];
    if (have != null && File(have).existsSync()) {
      await OpenFilex.open(have);
      return;
    }
    if (_downloading.value.contains(m.id)) return;
    _downloading.value = {..._downloading.value, m.id};
    try {
      final name = (m.attachment?.name ?? '').isEmpty ? 'file' : m.attachment!.name;
      final f = await _toFile(m, name);
      _files[m.id] = f.path;
      await OpenFilex.open(f.path);
    } catch (_) {
      if (mounted) _say(context.l10n.msgChatDownloadFailed);
    } finally {
      if (mounted) _downloading.value = {..._downloading.value}..remove(m.id);
    }
  }

  Future<void> _toggleVoice(ChatMessage m) async {
    final now = _voiceNow.value;
    try {
      final p = _player ??= _newPlayer();
      if (now.id == m.id) {
        if (now.playing) {
          await p.pause();
        } else {
          unawaited(p.play());
        }
        return;
      }
      await p.stop();
      final f = await _toFile(m, 'voice.m4a');
      final d = await p.setFilePath(f.path);
      _voiceNow.value = VoiceNow(id: m.id, duration: d ?? Duration(milliseconds: m.attachment?.durationMs ?? 0));
      unawaited(p.play());
    } catch (_) {
      if (mounted) _say(context.l10n.msgChatVoiceFailed);
    }
  }

  AudioPlayer _newPlayer() {
    final p = AudioPlayer();
    _playerSubs.add(p.positionStream.listen((pos) {
      final v = _voiceNow.value;
      _voiceNow.value = VoiceNow(id: v.id, playing: v.playing, position: pos, duration: v.duration);
    }));
    _playerSubs.add(p.playerStateStream.listen((s) {
      final v = _voiceNow.value;
      if (s.processingState == ProcessingState.completed) {
        _voiceNow.value = VoiceNow(id: v.id, duration: v.duration);
        unawaited(p.pause());
        unawaited(p.seek(Duration.zero));
      } else {
        _voiceNow.value = VoiceNow(id: v.id, playing: s.playing, position: v.position, duration: v.duration);
      }
    }));
    return p;
  }

  Future<void> _openLocation(ChatMessage m) async {
    final lat = m.lat, lng = m.lng;
    if (lat == null || lng == null) return;
    try {
      if (await launchUrl(Uri.parse('geo:$lat,$lng?q=$lat,$lng'), mode: LaunchMode.externalApplication)) return;
    } catch (_) {}
    try {
      await launchUrl(Uri.parse('https://www.google.com/maps/search/?api=1&query=$lat,$lng'), mode: LaunchMode.externalApplication);
    } catch (_) {}
  }

  Future<void> _openPaper(PaperRef p) async {
    final l = context.l10n;
    if (!p.canOpen) {
      _say(l.msgChatPaperNoPermission);
      return;
    }
    final custom = widget.openPaper;
    if (custom != null) {
      await custom(context, p);
      return;
    }
    try {
      final pdf = await DocumentsApi.pdf(type: p.type, id: p.id);
      if (!mounted) return;
      await PdfViewPage.open(context, pdf: pdf, title: [p.title, p.no].where((s) => s.isNotEmpty).join(' '));
    } on DioException catch (e) {
      if (!mounted) return;
      final data = e.response?.data;
      if (e.response?.statusCode == 403) {
        _say(data is Map && data['message'] is String ? data['message'] as String : l.msgChatPaperNoPermission);
      } else {
        _say(l.msgChatPaperOpenFailed);
      }
    } catch (_) {
      if (mounted) _say(l.msgChatPaperOpenFailed);
    }
  }

  // ── সংযুক্তি ──────────────────────────────────────────────────
  static String _mimeOf(String name) {
    final e = name.contains('.') ? name.split('.').last.toLowerCase() : '';
    return switch (e) {
      'pdf' => 'application/pdf',
      'jpg' || 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'xls' => 'application/vnd.ms-excel',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'doc' => 'application/msword',
      'csv' => 'text/csv',
      'txt' => 'text/plain',
      'zip' => 'application/zip',
      'm4a' => 'audio/mp4',
      'mp3' => 'audio/mpeg',
      'mp4' => 'video/mp4',
      _ => 'application/octet-stream',
    };
  }

  Future<void> _photo(XFile? x) async {
    if (x == null) return;
    final b = await x.readAsBytes();
    final name = x.name.isEmpty ? 'photo.jpg' : x.name;
    final mime = _mimeOf(name);
    _sendUpload(ChatUpload(kind: MessageKind.image, bytes: b, name: name, mime: mime.startsWith('image/') ? mime : 'image/jpeg'));
  }

  Future<void> _camera() async {
    try {
      await _photo(await takeCameraShot(context, imageQuality: 85, maxWidth: 2400));
    } catch (_) {
      if (mounted) _say(context.l10n.msgChatAttachFailed);
    }
  }

  Future<void> _tray(TrayItem it) async {
    setState(() => _trayOpen = false);
    final l = context.l10n;
    try {
      switch (it) {
        case TrayItem.file:
          final r = await FilePicker.pickFiles(withData: true);
          final f = r?.files.firstOrNull;
          if (f == null) return;
          final bytes = f.bytes ?? (f.path == null ? null : await File(f.path!).readAsBytes());
          if (bytes == null) return;
          final mime = _mimeOf(f.name);
          _sendUpload(ChatUpload(kind: mime.startsWith('image/') ? MessageKind.image : MessageKind.file, bytes: bytes, name: f.name, mime: mime));
        case TrayItem.camera:
          await _camera();
        case TrayItem.gallery:
          await _photo(await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85, maxWidth: 2400));
        case TrayItem.paper:
          final paper = await showPaperPicker(_sheetContext, load: widget.recentPapers ?? _recentPapers);
          if (paper == null || !mounted) return;
          try {
            await _api.sendPaper(paper, ['thread:${_thread.id}'], clientId: _clientId());
            if (!mounted) return;
            _say(l.msgChatPaperSent);
            _lastActivity = DateTime.now();
            unawaited(_pull());
          } catch (e) {
            if (mounted) _say(e is MessengerNotAllowed && e.message != null ? e.message! : l.msgChatPaperSendFailed);
          }
        case TrayItem.location:
          await _location();
      }
    } catch (_) {
      if (mounted) _say(l.msgChatAttachFailed);
    }
  }

  static Future<List<PaperChoice>> _recentPapers() async {
    final prefs = RecentPapers.active;
    if (prefs == null) return const [];
    final kinds = RecentPapers.kinds;
    return [
      for (final e in await prefs.recents())
        if (e.path.startsWith('paper/'))
          () {
            final parts = Uri.parse(e.path).pathSegments;
            final kind = parts.length > 1 ? parts[1] : '';
            final id = parts.length > 2 ? parts[2] : '';
            final bits = e.label.split(' · ');
            return PaperChoice(
              label: e.label,
              paper: PaperRef(type: kind, id: id, no: bits.first, title: kinds[kind]?.$2 ?? '', party: bits.length > 1 ? bits[1] : '', path: e.path),
            );
          }(),
    ];
  }

  Future<void> _location() async {
    final l = context.l10n;
    if (!await Geolocator.isLocationServiceEnabled()) {
      if (mounted) _say(l.msgChatLocationOff);
      return;
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    if (perm == LocationPermission.denied || perm == LocationPermission.deniedForever) {
      if (mounted) _say(l.msgChatLocationDenied);
      return;
    }
    try {
      final pos = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 20)));
      if (!mounted) return;
      _post(_local(MessageKind.location, lat: pos.latitude, lng: pos.longitude));
    } catch (_) {
      if (mounted) _say(l.msgChatLocationFailed);
    }
  }

  // ── আঁকা ───────────────────────────────────────────────────
  String _dayLabel(DateTime at) {
    final n = _now();
    final today = DateTime(n.year, n.month, n.day);
    final day = DateTime(at.year, at.month, at.day);
    final d = today.difference(day).inDays;
    final l = context.l10n;
    if (d == 0) return l.msgChatToday;
    if (d == 1) return l.msgChatYesterday;
    return '${at.day} ${monthNames(l)[at.month - 1]} ${at.year}';
  }

  List<Widget> _items(WaPalette pal, BubbleActions actions) {
    final out = <Widget>[];
    DateTime? lastDay;
    ChatMessage? prev;
    final me = _me;
    final hit = _matches.toSet();
    final current = _matches.isEmpty ? null : _matches[_matchAt];
    for (final m in _msgs) {
      final day = DateTime(m.at.year, m.at.month, m.at.day);
      if (lastDay == null || day != lastDay) {
        out.add(DateChip(key: ValueKey('day-${day.toIso8601String()}'), text: _dayLabel(m.at), pal: pal));
        lastDay = day;
        prev = null;
      }
      if (m.kind == MessageKind.system) {
        out.add(SystemChip(key: ValueKey('sys-${m.id}'), text: m.text, pal: pal));
        prev = null;
        continue;
      }
      final first = prev == null || prev.mine != m.mine || (!m.mine && prev.sender?.id != m.sender?.id);
      prev = m;
      final key = _keys.putIfAbsent(m.id, GlobalKey.new);
      final selected = _selected == m.id;
      out.add(KeyedSubtree(
        key: key,
        child: GestureDetector(
          behavior: HitTestBehavior.translucent,
          onLongPress: () {
            HapticFeedback.selectionClick();
            _composerKey.currentState?.closePanels();
            setState(() {
              _selected = m.id;
              _trayOpen = false;
            });
          },
          onTap: _selected == null ? null : () => setState(() => _selected = null),
          child: Container(
            key: ValueKey('msg-${m.id}'),
            color: selected ? const Color(0x3300A884) : null,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: MessageRow(
              message: m,
              first: first,
              group: _thread.isGroup,
              pal: pal,
              actions: actions,
              me: me,
              query: hit.contains(m.id) ? _query : '',
              currentMatch: current == m.id,
            ),
          ),
        ),
      ));
    }
    if (_typing.isNotEmpty) {
      out.add(Padding(
        key: const ValueKey('typing'),
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: TypingBubble(people: _typing, pal: pal),
      ));
    }
    return out;
  }

  Widget _calm(WaPalette pal, String text, {bool retry = false}) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Container(
            padding: const EdgeInsets.fromLTRB(18, 14, 18, 8),
            decoration: BoxDecoration(color: pal.chip, borderRadius: BorderRadius.circular(10), boxShadow: WaPalette.bubbleShadow),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(text, key: const ValueKey('chat-calm'), textAlign: TextAlign.center, style: TextStyle(fontSize: 14.5, color: pal.chipInk)),
              if (retry)
                TextButton(
                  onPressed: _load,
                  child: Text(context.l10n.msgChatRetry, style: TextStyle(color: pal.link, fontWeight: FontWeight.w700)),
                )
              else
                const SizedBox(height: 6),
            ]),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final pal = WaPalette.of(context);
    final actions = BubbleActions(
      onOpenPaper: _openPaper,
      onRetry: _retry,
      onFile: _openFile,
      onImage: _openImage,
      onLocation: _openLocation,
      onVoice: _toggleVoice,
      image: _imageOf,
      voice: _voiceNow,
      downloading: _downloading,
    );

    final Widget head;
    final sel = _selectedMessage;
    if (sel != null) {
      head = ChatActionBar(
        pal: pal,
        count: 1,
        canCopy: sel.kind != MessageKind.deleted,
        onClear: () => setState(() => _selected = null),
        onAct: _act,
      );
    } else if (_searching) {
      head = ChatSearchBar(
        pal: pal,
        controller: _searchCtrl,
        onClose: _closeSearch,
        onSubmit: _runSearch,
        at: _matchAt + 1,
        of: _matches.length,
        searched: _searched,
        onPrev: _matchAt > 0
            ? () {
                setState(() => _matchAt--);
                unawaited(_goToMatch());
              }
            : null,
        onNext: _matchAt < _matches.length - 1
            ? () {
                setState(() => _matchAt++);
                unawaited(_goToMatch());
              }
            : null,
      );
    } else {
      head = ChatHeader(
        thread: _thread,
        pal: pal,
        online: _peerOnline ?? _thread.person?.online ?? false,
        muted: _muted,
        onBack: () => Navigator.of(context).maybePop(),
        onVideo: () => _call(video: true),
        onCall: () => _call(video: false),
        onMenu: _menu,
        typing: _typing,
        calls: widget.callsEnabled,
      );
    }

    Widget body;
    if (_loading && _msgs.isEmpty) {
      body = const Center(child: CircularProgressIndicator(color: WaPalette.green));
    } else if (_error != null && _msgs.isEmpty) {
      final e = _error;
      body = _calm(pal, calmError(context, e), retry: e is! MessengerDoorsMissing && e is! MessengerNotAllowed);
    } else {
      final items = _items(pal, actions).reversed.toList();
      body = ListView.builder(
        key: const ValueKey('chat-list'),
        controller: _scroll,
        reverse: true,
        padding: const EdgeInsets.only(top: 8, bottom: 10),
        itemCount: items.length,
        itemBuilder: (context, i) => items[i],
      );
    }

    final blocked = _error is MessengerDoorsMissing || _error is MessengerNotAllowed;

    return _sameInEveryLook(context, pal, PopScope(
      canPop: _selected == null && !_searching && !_trayOpen,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_selected != null) {
          setState(() => _selected = null);
        } else if (_trayOpen) {
          setState(() => _trayOpen = false);
        } else {
          _closeSearch();
        }
      },
      child: Scaffold(
        backgroundColor: pal.wall,
        body: Stack(children: [
          Column(children: [
            head,
            Expanded(
              child: Stack(children: [
                Positioned.fill(child: WaWallpaper(wall: pal.wall, doodle: pal.doodle)),
                Positioned.fill(child: body),
                if (_trayOpen) ...[
                  Positioned.fill(
                    child: GestureDetector(
                      key: const ValueKey('tray-barrier'),
                      behavior: HitTestBehavior.opaque,
                      onTap: () => setState(() => _trayOpen = false),
                    ),
                  ),
                  Positioned(left: 8, right: 8, bottom: 2, child: AttachTray(pal: pal, onPick: _tray)),
                ],
              ]),
            ),
            if (!blocked)
              SafeArea(
                top: false,
                child: ChatComposer(
                  key: _composerKey,
                  pal: pal,
                  trayOpen: _trayOpen,
                  onToggleTray: () {
                    _composerKey.currentState?.closePanels();
                    setState(() => _trayOpen = !_trayOpen);
                  },
                  onSendText: _sendText,
                  onTyping: _onTyping,
                  onCamera: _camera,
                  onVoiceMessage: _sendUpload,
                  toast: _say,
                  voice: widget.voice ?? PhoneCopilotVoice.instance,
                  replyTo: _replyTo,
                  onCancelReply: () => setState(() => _replyTo = null),
                ),
              ),
          ]),
          if (_toast != null)
            Positioned(
              left: 16,
              right: 16,
              bottom: 96,
              child: IgnorePointer(
                child: Center(
                  child: AnimatedOpacity(
                    opacity: _toastFading ? 0 : 1,
                    duration: const Duration(milliseconds: 250),
                    child: Container(
                      key: const ValueKey('chat-toast'),
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      decoration: BoxDecoration(
                        color: const Color(0xEB14141C),
                        borderRadius: BorderRadius.circular(999),
                        boxShadow: const [BoxShadow(color: Color(0x59000000), offset: Offset(0, 8), blurRadius: 20)],
                      ),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        if (_toastClock) ...[GlyphIcon(WaGlyphs.clock, size: 16, color: const Color(0xFFFBBF24)), const SizedBox(width: 6)],
                        Flexible(
                          child: Text(_toast!,
                              textAlign: TextAlign.center,
                              style: const TextStyle(color: Color(0xFFFFFFFF), fontSize: 13, fontWeight: FontWeight.w600)),
                        ),
                      ]),
                    ),
                  ),
                ),
              ),
            ),
        ]),
      ),
    ));
  }

  /// ⭐ তিন রূপেই এক — গ্লাস (স্বচ্ছ, অন্ধকার থিম) আর স্মার্ট (নিজের রঙের থিম)-এ চ্যাটের পাতার ভেতরে ক্লাসিকের সাধারণ আলো/অন্ধকার
  /// থিম ([WaPalette.of]-এর একই বাছাই), যাতে লেখার ঘর, বাছাইয়ের রং, শিট আর ডায়ালগ কোনো রূপের রং না নেয়।
  /// ⓘ লেখার ফন্ট এখনকার থিমেরটাই থাকে।
  Widget _sameInEveryLook(BuildContext context, WaPalette pal, Widget page) {
    // ⓘ শিট আর ডায়ালগ এই প্রসঙ্গ থেকে খোলে ([_sheetContext]) — তাই তারাও ভেতরের থিম নেয়, রূপেরটা নয়
    final child = Builder(builder: (inner) {
      _inner = inner;
      return page;
    });
    if (AppLooks.instance.look.value == AppLook.abos) return child;
    final now = Theme.of(context).textTheme.bodyMedium;
    final base = pal.dark ? AppTheme.dark() : AppTheme.light();
    return Theme(
      data: base.copyWith(
        textTheme: base.textTheme.apply(fontFamily: now?.fontFamily, fontFamilyFallback: now?.fontFamilyFallback),
        primaryTextTheme: base.primaryTextTheme.apply(fontFamily: now?.fontFamily, fontFamilyFallback: now?.fontFamilyFallback),
      ),
      child: child,
    );
  }
}

/// একটা কথোপকথন খোলে — তালিকা, "আমি" পাতা, নোটিফিকেশন সবাই এটাই ডাকে
/// ⓘ [calls] — কল/ভিডিও বোতাম; না দিলে `/me` features.calls ([callsOn])
Future<void> openChat(BuildContext context, ChatThread thread, {bool? calls}) {
  final on = calls ?? callsOn(context);
  return Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => ChatScreen(thread: thread, callsEnabled: on)));
}

/// কেবল নম্বর জানা থাকলে (নোটিফিকেশন চাপলে) — কথোপকথন খুঁজে খোলে
Future<void> openChatById(BuildContext context, String threadId) async {
  final api = ProviderScope.containerOf(context, listen: false).read(messengerApiProvider);
  final messenger = ScaffoldMessenger.maybeOf(context);
  final l = context.l10n;
  try {
    final page = await api.threads();
    final t = page.threads.where((t) => t.id == threadId).firstOrNull;
    if (!context.mounted) return;
    if (t == null) {
      messenger?.showSnackBar(SnackBar(content: Text(l.msgChatNotFound)));
      return;
    }
    await openChat(context, t);
  } catch (e) {
    if (context.mounted) messenger?.showSnackBar(SnackBar(content: Text(calmError(context, e))));
  }
}
