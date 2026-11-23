import 'dart:async';

import 'package:flutter/widgets.dart';

import '../../../core/messenger/messenger_alerts.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/messenger/messenger_push.dart';
import 'call_device.dart';
import 'call_engine.dart';

/// কলের ধাপ
enum CallPhase {
  /// অনুমতি আর সার্ভারের চাবি আনা হচ্ছে
  preparing,

  /// আমি কল দিয়েছি, ওপাশে রিং হচ্ছে
  ringing,

  /// কল আসছে — ধরুন/কেটে দিন
  incoming,

  /// ধরেছি, ঘরে ঢুকছি
  connecting,

  /// কথা চলছে — ঘড়ি চলে
  connected,

  ended,
}

/// কল কেন শেষ — পাতা সেই কথা বলে
enum CallEndReason {
  /// আমি কেটে দিলাম
  hungUp,

  /// আসা কল আমি কেটে দিলাম ("কেটে দিন", "বার্তা দিন", "পরে মনে করান") — পাতা সাথে সাথে বন্ধ
  declinedByMe,

  /// ৪৫ সেকেন্ডে কেউ ধরেননি
  noAnswer,

  /// ওপাশে কেটে দিয়েছেন
  declined,

  /// ওপাশের মানুষ চলে গেলেন / সার্ভার কল শেষ বলল
  ended,

  failed,

  /// সার্ভারে কল চালু নেই (৫০৩ calls_off)
  callsOff,

  /// রোলে অনুমতি নেই (৪০৩)
  notAllowed,

  /// পুরনো সার্ভার
  doorsMissing,

  /// ধরতে গিয়ে দেখা গেল কল আর বাজছে না (৪০৯ call_over)
  callOver,

  /// আমার অন্য ফোনে ধরা বা কাটা হলো — এই ফোনের রিং থামে, পাতা সাথে সাথে বন্ধ
  elsewhere,

  /// মাইকের অনুমতি দেওয়া হয়নি
  micRefused,
}

/// ⭐ একটা কল, শুরু থেকে শেষ — নমুনা 3nuwRUdn v5 "ঘ"-এর তিন পাতা এটাই পড়ে।
///
/// <p>দেওয়া কল: অনুমতি → `startCall` → ঘরে ঢোকা → রিং (৪৫ সেকেন্ডে "উত্তর দেননি") → ওপাশে এলে ঘড়ি চলে।
/// আসা কল: রিং → ধরুন (`answerCall` → ঘরে ঢোকা) বা কেটে দিন (`declineCall`)। শেষ: আমি কাটলে `endCall`; ওপাশ চলে গেলে,
/// সংযোগ ছিঁড়লে, বা `call_end` push এলে (status: missed · ended · declined)। ⛔ শেষে সব থামে — রিং, ঘড়ি, ঘর, সামনের-সেবা,
/// পর্দা জ্বালা, তালার উপরে দেখানো।
class CallController extends ChangeNotifier {
  CallController({
    required this.api,
    required CallEngine Function() engine,
    required this.device,
    required ChatPerson person,
    required bool video,
    required this.ongoingText,
    this.incomingCallId,
    this.threadId = '',
    Stream<MessengerEvent>? pushes,
    Future<void> Function(String callId)? cancelRingNotice,
  })  : _engineFactory = engine,
        _person = person,
        _video = video,
        _cancelRingNotice = cancelRingNotice ?? MessengerAlerts.instance.cancelCall {
    _pushes = (pushes ?? MessengerPush.instance.events).listen(_onPush);
    if (incomingCallId != null) {
      _callId = incomingCallId;
      _phase = CallPhase.incoming;
    }
  }

  final MessengerApi api;
  final CallDevice device;
  final CallEngine Function() _engineFactory;
  final Future<void> Function(String callId) _cancelRingNotice;

  /// সামনের-সেবার বার্তার লেখা ("কল চলছে — ফিরতে চাপুন")
  final String ongoingText;

  /// আসা কল হলে তার নম্বর
  final String? incomingCallId;

  /// যাঁর সাথে কথা, তাঁর কথোপকথন (জানা থাকলে) — "বার্তা দিন" আর "পরে মনে করান"
  String threadId;

  late final StreamSubscription<MessengerEvent> _pushes;
  StreamSubscription<CallEngineEvent>? _engineEvents;
  CallEngine? _engine;
  Timer? _ringTimer;
  Timer? _clock;

  ChatPerson _person;
  bool _video;
  CallPhase _phase = CallPhase.preparing;
  CallEndReason? _endReason;
  String? _serverMessage;
  String? _callId;
  int _seconds = 0;
  bool _muted = false;
  bool _cameraOff = false;
  bool _speakerOn = false;
  bool _cameraRefused = false;
  bool _disposed = false;

  ChatPerson get person => _person;
  bool get video => _video;
  CallPhase get phase => _phase;
  CallEndReason? get endReason => _endReason;

  /// ৪০৩-এর সাথে সার্ভারের বাংলা বাক্য
  String? get serverMessage => _serverMessage;
  String? get callId => _callId;
  bool get isIncoming => incomingCallId != null;

  /// কথা চলার সেকেন্ড
  int get seconds => _seconds;
  bool get muted => _muted;
  bool get cameraOff => _cameraOff;
  bool get speakerOn => _speakerOn;

  /// ভিডিও কলে ক্যামেরার অনুমতি পাওয়া যায়নি — কেবল কথায় চলছে
  bool get cameraRefused => _cameraRefused;
  bool get isOver => _phase == CallPhase.ended;

  Widget? remoteVideo() => _engine?.remoteVideo();

  Widget? localVideo() => _cameraOff ? null : _engine?.localVideo();

  /// "03:12" — ইংরেজি অঙ্কে
  String get elapsed {
    final m = _seconds ~/ 60, s = _seconds % 60;
    final h = m ~/ 60;
    String two(int n) => n.toString().padLeft(2, '0');
    return h > 0 ? '$h:${two(m % 60)}:${two(s)}' : '${two(m)}:${two(s)}';
  }

  void _set(VoidCallback change) {
    if (_disposed) return;
    change();
    notifyListeners();
  }

  /// আসা কলে কে কল দিলেন — নাম, পদ, ছবি পরে আসে
  void updateCaller(ChatPerson p) => _set(() => _person = p);

  // ───────────── দেওয়া কল ─────────────

  /// ⭐ কল দেওয়া — অনুমতি থেকে রিং পর্যন্ত
  Future<void> startOutgoing() async {
    final permits = await device.ask(video: _video);
    if (isOver) return;
    if (!permits.mic) return _end(CallEndReason.micRefused);
    if (_video && !permits.camera) {
      _set(() {
        _video = false;
        _cameraRefused = true;
      });
    }
    final CallJoin join;
    try {
      join = await api.startCall(_person.id, video: _video);
    } catch (e) {
      return _endForError(e);
    }
    if (isOver) {
      // ⓘ চাবি আসার আগেই কেটে দিলেন — সার্ভারেও শেষ
      unawaited(_quietly(() => api.endCall(join.callId)));
      return;
    }
    _callId = join.callId;
    if (join.peer != null && join.peer!.id == _person.id) _person = join.peer!;
    _set(() => _phase = CallPhase.ringing);
    _ringTimer = Timer(callRingFor, () {
      if (_phase == CallPhase.ringing) {
        unawaited(_quietly(() => api.endCall(join.callId)));
        _end(CallEndReason.noAnswer);
      }
    });
    await _join(join);
  }

  // ───────────── আসা কল ─────────────

  /// আসা কলের পাতা উঠল — রিং শুরু (নোটিফিকেশনের রিং সরিয়ে ফোনের নিজের), ৪৫ সেকেন্ডে থামা
  Future<void> startRinging() async {
    final id = _callId;
    if (id != null) unawaited(_quietly(() => _cancelRingNotice(id)));
    await _quietly(() => device.ring(true));
    await _quietly(() => device.keepScreenOn(true));
    _ringTimer = Timer(callRingFor, () {
      if (_phase == CallPhase.incoming) _end(CallEndReason.noAnswer);
    });
  }

  /// ধরুন
  Future<void> answer() async {
    final id = _callId;
    if (_phase != CallPhase.incoming || id == null) return;
    _ringTimer?.cancel();
    await _quietly(() => device.ring(false));
    _set(() => _phase = CallPhase.connecting);
    final permits = await device.ask(video: _video);
    if (isOver) return;
    if (!permits.mic) {
      unawaited(_quietly(() => api.declineCall(id)));
      return _end(CallEndReason.micRefused);
    }
    if (_video && !permits.camera) {
      _set(() {
        _video = false;
        _cameraRefused = true;
      });
    }
    final CallJoin join;
    try {
      join = await api.answerCall(id);
    } catch (e) {
      return _endForError(e);
    }
    if (isOver) return;
    if (join.peer != null) _person = join.peer!;
    await _join(join);
  }

  /// কেটে দিন (আসা কল) — "বার্তা দিন" আর "পরে মনে করান"-ও এটাই
  Future<void> decline() async {
    final id = _callId;
    if (_phase != CallPhase.incoming) return;
    _end(CallEndReason.declinedByMe);
    if (id != null) await _quietly(() => api.declineCall(id));
  }

  // ───────────── কথা চলার সময় ─────────────

  Future<void> _join(CallJoin join) async {
    final engine = _engineFactory();
    _engine = engine;
    _engineEvents = engine.events.listen(_onEngine);
    await _quietly(() => device.keepScreenOn(true));
    await _quietly(() => device.startOngoing(title: _person.name, text: ongoingText, video: _video));
    try {
      await engine.connect(join.url, join.token, video: _video, speaker: _video);
    } catch (e) {
      debugPrint('ABOS call: connect failed ($e)');
      unawaited(_quietly(() => api.endCall(join.callId)));
      return _end(CallEndReason.failed);
    }
    if (isOver) return;
    _speakerOn = _video;
    if (engine.peerPresent) {
      _connected();
    } else if (_phase == CallPhase.connecting) {
      // ⓘ ধরেছি কিন্তু ওপাশ এখনো ঘরে নেই — একটু অপেক্ষা, তারপর "যুক্ত হলো না"
      _ringTimer = Timer(const Duration(seconds: 20), () {
        if (_phase == CallPhase.connecting) {
          unawaited(_quietly(() => api.endCall(join.callId)));
          _end(CallEndReason.failed);
        }
      });
    }
    notifyListeners();
  }

  void _connected() {
    if (_phase == CallPhase.connected || isOver) return;
    _ringTimer?.cancel();
    _set(() {
      _phase = CallPhase.connected;
      _seconds = 0;
    });
    _clock = Timer.periodic(const Duration(seconds: 1), (_) => _set(() => _seconds++));
  }

  void _onEngine(CallEngineEvent e) {
    if (isOver) return;
    switch (e.type) {
      case CallEngineEventType.peerJoined:
        _connected();
      case CallEngineEventType.peerLeft:
        if (_phase == CallPhase.connected) {
          final id = _callId;
          if (id != null) unawaited(_quietly(() => api.endCall(id)));
          _end(CallEndReason.ended);
        }
      case CallEngineEventType.disconnected:
        final id = _callId;
        if (id != null) unawaited(_quietly(() => api.endCall(id)));
        _end(_phase == CallPhase.connected ? CallEndReason.ended : CallEndReason.failed);
      case CallEngineEventType.videoChanged:
        notifyListeners();
    }
  }

  /// ⭐ `call_end` (status: answered · declined · missed · ended)। ⛔ answered যায় যাঁকে কল করা হলো তাঁর সব ফোনে, ধরা ফোনটাতেও —
  /// এই ফোন কলে থাকলে কিছু নয়; কেবল যেসব ফোন এখনো বাজছে সেগুলো থামে।
  void _onPush(MessengerEvent e) {
    if (isOver || e.type != 'call_end' || e.callId == null || e.callId != _callId) return;
    final ringingHere = _phase == CallPhase.incoming;
    switch (e.status) {
      case 'answered':
        if (ringingHere) _end(CallEndReason.elsewhere);
      case 'declined':
        _end(ringingHere ? CallEndReason.elsewhere : CallEndReason.declined);
      case 'missed':
        _end(isIncoming ? CallEndReason.ended : CallEndReason.noAnswer);
      default:
        _end(CallEndReason.ended);
    }
  }

  Future<void> hangUp() async {
    if (isOver) return;
    final id = _callId;
    _end(CallEndReason.hungUp);
    if (id != null) await _quietly(() => api.endCall(id));
  }

  Future<void> toggleMute() async {
    _set(() => _muted = !_muted);
    await _quietly(() async => _engine?.setMic(!_muted));
  }

  Future<void> toggleSpeaker() async {
    _set(() => _speakerOn = !_speakerOn);
    await _quietly(() async => _engine?.setSpeaker(_speakerOn));
  }

  Future<void> toggleCamera() async {
    _set(() => _cameraOff = !_cameraOff);
    await _quietly(() async => _engine?.setCamera(!_cameraOff));
  }

  Future<void> switchCamera() => _quietly(() async => _engine?.switchCamera());

  /// ভয়েস কলের "ভিডিও" — ক্যামেরার অনুমতি পেলে নিজের ক্যামেরা চালু, পাতা ভিডিওতে
  Future<void> switchToVideo() async {
    if (_video || isOver) return;
    final permits = await device.ask(video: true);
    if (!permits.camera) {
      _set(() => _cameraRefused = true);
      return;
    }
    _set(() {
      _video = true;
      _cameraOff = false;
      _cameraRefused = false;
      _speakerOn = true;
    });
    await _quietly(() async => _engine?.setCamera(true));
    await _quietly(() async => _engine?.setSpeaker(true));
    await _quietly(() => device.startOngoing(title: _person.name, text: ongoingText, video: true));
  }

  // ───────────── শেষ ─────────────

  void _endForError(Object e) {
    _serverMessage = e is MessengerNotAllowed ? e.message : null;
    _end(switch (e) {
      CallsOff() => CallEndReason.callsOff,
      CallOver() => CallEndReason.callOver,
      MessengerNotAllowed() => CallEndReason.notAllowed,
      MessengerDoorsMissing() => CallEndReason.doorsMissing,
      _ => CallEndReason.failed,
    });
  }

  void _end(CallEndReason reason) {
    if (isOver) return;
    _ringTimer?.cancel();
    _clock?.cancel();
    _set(() {
      _phase = CallPhase.ended;
      _endReason = reason;
    });
    unawaited(_engineEvents?.cancel());
    final engine = _engine;
    if (engine != null) unawaited(_quietly(engine.leave));
    unawaited(_quietly(() => device.ring(false)));
    unawaited(_quietly(() => device.stopOngoing()));
    unawaited(_quietly(() => device.keepScreenOn(false)));
    unawaited(_quietly(() => device.showOverLock(false)));
  }

  static Future<void> _quietly(Future<void> Function() body) async {
    try {
      await body();
    } catch (e) {
      debugPrint('ABOS call: $e');
    }
  }

  @override
  void dispose() {
    if (!isOver) {
      // ⓘ পাতা বন্ধ হলো কল চলতে চলতে — সার্ভারেও শেষ
      final id = _callId;
      if (id != null) unawaited(_quietly(() => isIncoming && _phase == CallPhase.incoming ? api.declineCall(id) : api.endCall(id)));
      _end(CallEndReason.hungUp);
    }
    _disposed = true;
    unawaited(_pushes.cancel());
    super.dispose();
  }
}
