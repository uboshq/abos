import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import 'call_controller.dart';

/// ⭐ কলের পাতা — নমুনা 3nuwRUdn v5 "ঘ": আসা কল (ফোন ১), একজনের সাথে ভিডিও (ফোন ২), আর চ্যাটের মাথা থেকে দেওয়া ভয়েস কল
/// (`outVoice`)। তিন রূপেই একই, সবসময় অন্ধকার (WhatsApp-ধাঁচ)।
///
/// <p>⛔ রং নমুনার CSS থেকে হুবহু; লেখা সব l10n-এ, অঙ্ক ইংরেজিতে। কল শেষ হলে কারণটা অবস্থার লাইনে দুই সেকেন্ড, তারপর পাতা বন্ধ;
/// মাইকের অনুমতি না দিলে বাংলায় কেন আর কোথায় চালু করবেন।
class CallScreen extends StatefulWidget {
  const CallScreen({super.key, required this.controller, this.onMessage, this.onRemindLater, this.closeAfter = const Duration(seconds: 2)});

  final CallController controller;

  /// আসা কলের "বার্তা দিন" — কল কেটে সেই মানুষের চ্যাট
  final Future<void> Function(BuildContext context)? onMessage;

  /// আসা কলের "পরে মনে করান" — কল কেটে ১০ মিনিট পরে বার্তা
  final Future<void> Function(BuildContext context)? onRemindLater;

  /// শেষের কথা কতক্ষণ দেখায়
  final Duration closeAfter;

  @override
  State<CallScreen> createState() => _CallScreenState();
}

// ⓘ নমুনার রং (.call, .v1, .vbar, .cc)
const _ink = Color(0xFFE9EDEF);
const _muted = Color(0xFF8696A0);
const _page = Color(0xFF0B141A);
const _glow = Color(0xFF1F3A3A);
const _bar = Color(0xFF1F2C34);
const _barGlass = Color(0xE01F2C34);
const _small = Color(0xFF2A3942);
const _smallIncoming = Color(0xFF233138);
const _red = Color(0xFFEA0038);
const _green = Color(0xFF25D366);
const _white = Color(0xFFFFFFFF);
const _shade = Color(0x73000000);

class _CallScreenState extends State<CallScreen> {
  CallController get c => widget.controller;
  Timer? _close;
  bool _closing = false;
  bool _popped = false;

  @override
  void initState() {
    super.initState();
    c.addListener(_changed);
    // ⓘ পাতা ওঠার আগেই কল শেষ হতে পারে (কল বন্ধ, মাইকের অনুমতি নেই) — তখনও শেষের কথা আর বন্ধ হওয়া
    if (c.isOver) WidgetsBinding.instance.addPostFrameCallback((_) => _changed());
  }

  @override
  void dispose() {
    c.removeListener(_changed);
    _close?.cancel();
    super.dispose();
  }

  void _changed() {
    if (!mounted) return;
    setState(() {});
    if (c.isOver && !_closing) {
      _closing = true;
      if (c.endReason == CallEndReason.micRefused) {
        unawaited(_explainMic());
      } else if (c.endReason == CallEndReason.declinedByMe || c.endReason == CallEndReason.elsewhere) {
        _pop();
      } else {
        _close = Timer(widget.closeAfter, _pop);
      }
    }
  }

  /// ⛔ একবারই, আর সরাসরি `pop` — `maybePop` কল শেষের মুহূর্তে (পাতা আবার আঁকার আগে) PopScope-এ আটকে আবার এখানেই
  /// ফিরত, অন্তহীন ঘুরত (আসা কল কেটে দিলে)
  void _pop() {
    if (!mounted || _popped) return;
    _popped = true;
    final navigator = Navigator.of(context);
    final route = ModalRoute.of(context);
    // ⓘ উপরে "কল শেষ করবেন?" খোলা থাকলে সেটাও সরে
    if (route != null && route.isActive) navigator.popUntil((r) => r == route);
    navigator.pop();
  }

  Future<void> _explainMic() async {
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        key: const Key('call-mic-refused'),
        title: Text(context.l10n.msgCallPermTitle),
        content: Text(context.l10n.msgCallMicRefused),
        actions: [TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.l10n.permissionOk))],
      ),
    );
    _pop();
  }

  Future<void> _askLeave() async {
    if (c.isOver) return _pop();
    final l = context.l10n;
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        key: const Key('call-leave'),
        title: Text(l.msgCallLeaveTitle),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(l.msgCallLeaveNo)),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: _red, foregroundColor: _white),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(l.msgCallEnd),
          ),
        ],
      ),
    );
    if (yes == true) await c.hangUp();
  }

  String _endWords() {
    final l = context.l10n;
    return switch (c.endReason) {
      CallEndReason.noAnswer => l.msgCallNoAnswer,
      CallEndReason.declined => l.msgCallDeclined,
      CallEndReason.failed => l.msgCallFailed,
      CallEndReason.callsOff => l.msgCallsOff,
      CallEndReason.notAllowed => (c.serverMessage ?? '').trim().isNotEmpty ? c.serverMessage!.trim() : l.msgCallNotAllowed,
      CallEndReason.doorsMissing => l.msgCallDoorsMissing,
      CallEndReason.callOver => l.msgCallOver,
      _ => l.msgCallEnded,
    };
  }

  /// অবস্থার লাইন: রিং হচ্ছে… · যুক্ত হচ্ছে… · 03:12 · শেষের কারণ
  String _status() {
    final l = context.l10n;
    return switch (c.phase) {
      CallPhase.preparing || CallPhase.ringing => l.msgCallRinging,
      CallPhase.incoming => c.video ? l.msgCallIncomingVideo : l.msgCallIncomingVoice,
      CallPhase.connecting => l.msgCallConnecting,
      CallPhase.connected => c.elapsed,
      CallPhase.ended => _endWords(),
    };
  }

  @override
  Widget build(BuildContext context) {
    final showVideo = c.phase != CallPhase.incoming && (c.video || (c.phase == CallPhase.connected && c.remoteVideo() != null));
    return PopScope(
      canPop: c.isOver,
      onPopInvokedWithResult: (didPop, _) {
        // ⓘ কল শেষ হলে পাতা নিজেই বন্ধ হয় ([_pop]) — এখানে কিছু নয়
        if (didPop || c.isOver) return;
        unawaited(c.phase == CallPhase.incoming ? c.decline() : _askLeave());
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: SystemUiOverlayStyle.light,
        child: Scaffold(
          backgroundColor: _page,
          body: DefaultTextStyle.merge(
            style: const TextStyle(color: _ink),
            child: showVideo ? _video(context) : _voice(context),
          ),
        ),
      ),
    );
  }

  // ───────────── ভয়েস (দেওয়া / আসা) — .call ─────────────

  Widget _voice(BuildContext context) {
    final l = context.l10n;
    final incoming = c.phase == CallPhase.incoming;
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: RadialGradient(center: Alignment(0, -0.36), radius: 0.9, colors: [_glow, Color(0x001F3A3A)], stops: [0, 0.7]),
      ),
      child: SafeArea(
        child: LayoutBuilder(
          builder: (context, box) => SingleChildScrollView(
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: box.maxHeight),
              child: IntrinsicHeight(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 22, 20, 34),
                  child: Column(
                    children: [
                      const SizedBox(height: 64),
                      CallAvatar(person: c.person, size: 128),
                      const SizedBox(height: 20),
                      Text(c.person.name,
                          key: const Key('call-name'),
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 25, fontWeight: FontWeight.w600, color: _ink)),
                      if (c.person.role.isNotEmpty)
                        Text(c.person.role, textAlign: TextAlign.center, style: const TextStyle(fontSize: 13.5, color: _muted)),
                      const SizedBox(height: 6),
                      _statusLine(),
                      if (c.cameraRefused) ...[
                        const SizedBox(height: 8),
                        Text(l.msgCallCameraRefused, textAlign: TextAlign.center, style: const TextStyle(fontSize: 13, color: _muted)),
                      ],
                      const Spacer(),
                      if (incoming) ..._incomingButtons(context) else _voiceBar(context),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _statusLine() => Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Symbols.call, size: 16, color: _ink),
          const SizedBox(width: 6),
          Flexible(
            child: Text(_status(),
                key: const Key('call-status'),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 14, color: _ink, fontFeatures: [FontFeature.tabularFigures()])),
          ),
        ],
      );

  List<Widget> _incomingButtons(BuildContext context) {
    final l = context.l10n;
    return [
      Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _Labelled(
            label: l.msgCallMessage,
            child: _RoundButton(
              key: const Key('call-message'),
              size: 52,
              colour: _smallIncoming,
              icon: Symbols.chat,
              iconSize: 22,
              tooltip: l.msgCallMessage,
              onTap: widget.onMessage == null ? null : () => widget.onMessage!(context),
            ),
          ),
          _Labelled(
            label: l.msgCallRemindLater,
            child: _RoundButton(
              key: const Key('call-remind'),
              size: 52,
              colour: _smallIncoming,
              icon: Symbols.schedule,
              iconSize: 22,
              tooltip: l.msgCallRemindLater,
              onTap: widget.onRemindLater == null ? null : () => widget.onRemindLater!(context),
            ),
          ),
        ],
      ),
      const SizedBox(height: 26),
      Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _Labelled(
            label: l.msgCallDecline,
            child: _RoundButton(
              key: const Key('call-decline'),
              size: 66,
              colour: _red,
              icon: Symbols.call,
              fill: true,
              iconSize: 28,
              rotate: true,
              tooltip: l.msgCallDecline,
              onTap: c.decline,
            ),
          ),
          _Labelled(
            label: l.msgCallAnswer,
            child: _RoundButton(
              key: const Key('call-answer'),
              size: 66,
              colour: _green,
              icon: Symbols.call,
              fill: true,
              iconSize: 28,
              tooltip: l.msgCallAnswer,
              onTap: c.answer,
            ),
          ),
        ],
      ),
    ];
  }

  Widget _voiceBar(BuildContext context) {
    final l = context.l10n;
    return Container(
      key: const Key('call-voice-bar'),
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
      decoration: BoxDecoration(color: _bar, borderRadius: BorderRadius.circular(30)),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: [
          _RoundButton(
              key: const Key('call-speaker'),
              icon: Symbols.volume_up,
              on: c.speakerOn,
              tooltip: l.msgCallSpeaker,
              onTap: c.isOver ? null : c.toggleSpeaker),
          _RoundButton(
              key: const Key('call-mute'),
              icon: c.muted ? Symbols.mic_off : Symbols.mic,
              on: c.muted,
              tooltip: c.muted ? l.msgCallUnmute : l.msgCallMute,
              onTap: c.isOver ? null : c.toggleMute),
          _RoundButton(
              key: const Key('call-to-video'),
              icon: Symbols.videocam,
              tooltip: l.msgCallVideo,
              onTap: c.isOver ? null : c.switchToVideo),
          _RoundButton(
              key: const Key('call-end'),
              icon: Symbols.call,
              fill: true,
              rotate: true,
              colour: _red,
              tooltip: l.msgCallDecline,
              onTap: c.isOver ? null : c.hangUp),
        ],
      ),
    );
  }

  // ───────────── একজনের সাথে ভিডিও — .v1 ─────────────

  Widget _video(BuildContext context) {
    final l = context.l10n;
    final remote = c.remoteVideo();
    final mine = c.localVideo();
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment(-0.17, -1),
          end: Alignment(0.17, 1),
          colors: [Color(0xFF2B3D6B), Color(0xFF162042), _page],
          stops: [0, 0.55, 1],
        ),
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          if (remote != null)
            KeyedSubtree(key: const Key('call-remote-video'), child: remote)
          else
            const CustomPaint(key: Key('call-remote-silhouette'), painter: _Silhouette(head: 15, headY: 46, body: 0x29FFFFFF, wide: true)),
          SafeArea(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(2, 6, 124, 0),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      IconButton(
                        key: const Key('call-back'),
                        tooltip: l.msgCallBack,
                        onPressed: _askLeave,
                        icon: const Icon(Symbols.arrow_back, size: 22, color: _white),
                      ),
                      Expanded(
                        child: Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(c.person.name,
                                  key: const Key('call-name'),
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600, color: _white, height: 1.25, shadows: _textShadow)),
                              Text(_status(),
                                  key: const Key('call-status'),
                                  style: const TextStyle(
                                      fontSize: 13, color: Color(0xD9FFFFFF), shadows: _textShadow, fontFeatures: [FontFeature.tabularFigures()])),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const Spacer(),
                Container(
                  key: const Key('call-video-bar'),
                  margin: const EdgeInsets.fromLTRB(10, 12, 10, 18),
                  padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
                  decoration: BoxDecoration(color: _barGlass, borderRadius: BorderRadius.circular(30)),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      _RoundButton(
                          key: const Key('call-mute'),
                          icon: Symbols.mic_off,
                          on: c.muted,
                          tooltip: c.muted ? l.msgCallUnmute : l.msgCallMute,
                          onTap: c.isOver ? null : c.toggleMute),
                      _RoundButton(
                          key: const Key('call-camera'),
                          icon: Symbols.videocam_off,
                          on: c.cameraOff,
                          tooltip: c.cameraOff ? l.msgCallCameraOn : l.msgCallCameraOff,
                          onTap: c.isOver ? null : c.toggleCamera),
                      _RoundButton(
                          key: const Key('call-flip'),
                          icon: Symbols.flip_camera_android,
                          tooltip: l.msgCallFlipCamera,
                          onTap: c.isOver || c.cameraOff ? null : c.switchCamera),
                      _RoundButton(
                          key: const Key('call-end'),
                          icon: Symbols.call,
                          fill: true,
                          rotate: true,
                          colour: _red,
                          tooltip: l.msgCallEnd,
                          onTap: c.isOver ? null : c.hangUp),
                    ],
                  ),
                ),
              ],
            ),
          ),
          // ⓘ আমার ছোট ছবি — ডানে উপরে 100×146
          SafeArea(
            child: Align(
              alignment: Alignment.topRight,
              child: Padding(
                padding: const EdgeInsets.only(top: 96, right: 14),
                child: Container(
                  key: const Key('call-self-view'),
                  width: 100,
                  height: 146,
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0x4DFFFFFF), width: 2),
                    boxShadow: const [BoxShadow(color: Color(0x73000000), blurRadius: 18, offset: Offset(0, 8))],
                    gradient: const LinearGradient(
                      begin: Alignment(-0.34, -1),
                      end: Alignment(0.34, 1),
                      colors: [Color(0xFF7C3A12), Color(0xFF2B1306)],
                    ),
                  ),
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      mine ?? const CustomPaint(painter: _Silhouette(head: 17, headY: 44, body: 0x24FFFFFF, wide: false)),
                      Positioned(
                        left: 6,
                        bottom: 6,
                        right: 6,
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 7),
                            decoration: BoxDecoration(color: _shade, borderRadius: BorderRadius.circular(999)),
                            child: Text(l.msgCallYou,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: _white)),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

const _textShadow = [Shadow(color: Color(0x80000000), blurRadius: 3, offset: Offset(0, 1))];

/// গোল বোতাম (.cc) — ছোট 50 (#2a3942), আসা কলে 52/66; [on] হলে উল্টো রং (চালু থাকা টগল)
class _RoundButton extends StatelessWidget {
  const _RoundButton({
    super.key,
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.size = 50,
    this.iconSize = 22,
    this.colour = _small,
    this.on = false,
    this.fill = false,
    this.rotate = false,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onTap;
  final double size;
  final double iconSize;
  final Color colour;
  final bool on;
  final bool fill;

  /// কাটার বোতাম — ফোনের চিহ্ন ১৩৫° ঘোরানো
  final bool rotate;

  @override
  Widget build(BuildContext context) {
    Widget glyph = Icon(icon, size: iconSize, fill: fill ? 1 : 0, color: on ? _page : _white);
    if (rotate) glyph = Transform.rotate(angle: math.pi * 3 / 4, child: glyph);
    return Tooltip(
      message: tooltip,
      child: Semantics(
        button: true,
        toggled: on,
        label: tooltip,
        excludeSemantics: true,
        child: Material(
          color: on ? _ink : colour,
          shape: const CircleBorder(),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: SizedBox(width: size, height: size, child: Center(child: glyph)),
          ),
        ),
      ),
    );
  }
}

/// বোতামের নিচে লেখা (.cb)
class _Labelled extends StatelessWidget {
  const _Labelled({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 140,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            child,
            const SizedBox(height: 8),
            Text(label, textAlign: TextAlign.center, maxLines: 2, style: const TextStyle(fontSize: 13, color: _ink)),
          ],
        ),
      );
}

/// ⭐ কলের বড় গোল ছবি — ছবি থাকলে সেটা, নইলে নামের প্রথম অক্ষর রঙিন গোলে (সার্ভারের রং, না দিলে নাম থেকে স্থির)
class CallAvatar extends StatelessWidget {
  const CallAvatar({super.key, required this.person, required this.size});

  final ChatPerson person;
  final double size;

  static const _palette = [0xFF4338CA, 0xFF0F766E, 0xFFBE185D, 0xFF4D7C0F, 0xFF0E7490, 0xFFEA580C, 0xFF7C3AED, 0xFFB45309];

  Color get _colour {
    final hex = person.colour;
    if (hex != null) return Color(0xFF000000 | int.parse(hex.substring(1), radix: 16));
    final seed = person.name.runes.fold<int>(0, (a, r) => (a * 31 + r) & 0x7fffffff);
    return Color(_palette[seed % _palette.length]);
  }

  @override
  Widget build(BuildContext context) {
    final base = _colour;
    final dark = Color.lerp(base, const Color(0xFF000000), 0.35)!;
    final photo = person.photoUrl;
    final letter = Center(
      child: Text(person.initial,
          style: TextStyle(fontSize: size * 0.375, fontWeight: FontWeight.w700, color: _white, height: 1.1), textScaler: TextScaler.noScaling),
    );
    return ExcludeSemantics(
      child: Container(
        width: size,
        height: size,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [base, dark]),
        ),
        child: photo != null && photo.startsWith('http')
            ? Image.network(photo, fit: BoxFit.cover, errorBuilder: (_, __, ___) => letter)
            : letter,
      ),
    );
  }
}

/// ছবি আসার আগের মানুষের আবছা ছায়া (নমুনার svg, preserveAspectRatio xMidYMax slice)
class _Silhouette extends CustomPainter {
  const _Silhouette({required this.head, required this.headY, required this.body, required this.wide});

  final double head;
  final double headY;
  final int body;

  /// বড় পর্দার ছায়া (কাঁধ চওড়া) নাকি ছোট ছবির
  final bool wide;

  @override
  void paint(Canvas canvas, Size size) {
    final scale = math.max(size.width, size.height) / 100;
    final ox = (size.width - 100 * scale) / 2;
    final oy = size.height - 100 * scale;
    Offset p(double x, double y) => Offset(ox + x * scale, oy + y * scale);
    final paint = Paint()..color = Color(body);
    canvas.drawCircle(p(50, headY), head * scale, paint);
    final path = Path();
    if (wide) {
      path
        ..moveTo(p(14, 100).dx, p(14, 100).dy)
        ..cubicTo(p(17, 76).dx, p(17, 76).dy, p(31, 63).dx, p(31, 63).dy, p(50, 63).dx, p(50, 63).dy)
        ..cubicTo(p(69, 63).dx, p(69, 63).dy, p(83, 76).dx, p(83, 76).dy, p(86, 100).dx, p(86, 100).dy);
    } else {
      path
        ..moveTo(p(18, 100).dx, p(18, 100).dy)
        ..cubicTo(p(21, 78).dx, p(21, 78).dy, p(34, 66).dx, p(34, 66).dy, p(50, 66).dx, p(50, 66).dy)
        ..cubicTo(p(66, 66).dx, p(66, 66).dy, p(79, 78).dx, p(79, 78).dy, p(82, 100).dx, p(82, 100).dy);
    }
    path.close();
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(_Silhouette old) => false;
}
