import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/voice/copilot_voice.dart';
import 'chat_bubbles.dart';
import 'emoji_panel.dart';
import 'wa_glyphs.dart';
import 'wa_palette.dart';

/// ⭐ লেখার জায়গা (নমুনা 3nuwRUdn v7-এর `.comp`) — বড়ির ভেতরে ইমোজি · লেখা · জুড়ুন · ক্যামেরা · বলে লিখুন; ডানে সবুজ
/// গোল বোতাম: মাইক (চেপে ধরে ভয়েস বার্তা), লেখা থাকলে বা শোনার সময় পাঠান।
///
/// <p>⭐ বলে লিখুন — ফোনের নিজের কথা-চেনা ([CopilotVoice], কোপাইলটের একই): বলার সাথে সাথে লেখা বাক্সে বসে, আগের লেখার পরে;
/// আবার চাপলে থামে; নিজে থেকে কিছু যায় না। ⛔ অডিও কোথাও জমা হয় না। ফোনে কথা-চেনা না থাকলে চিহ্নটাই নেই।
class ChatComposer extends StatefulWidget {
  const ChatComposer({
    super.key,
    required this.pal,
    required this.trayOpen,
    required this.onToggleTray,
    required this.onSendText,
    required this.onTyping,
    required this.onCamera,
    required this.onVoiceMessage,
    required this.toast,
    required this.voice,
    this.replyTo,
    this.onCancelReply,
  });

  final WaPalette pal;
  final bool trayOpen;
  final VoidCallback onToggleTray;
  final ValueChanged<String> onSendText;
  final VoidCallback onTyping;
  final VoidCallback onCamera;
  final ValueChanged<ChatUpload> onVoiceMessage;
  final void Function(String text) toast;
  final CopilotVoice voice;

  /// উত্তর লেখার সময় ওপরে আগের বার্তা
  final ChatMessage? replyTo;
  final VoidCallback? onCancelReply;

  @override
  State<ChatComposer> createState() => ChatComposerState();
}

class ChatComposerState extends State<ChatComposer> with SingleTickerProviderStateMixin {
  final text = TextEditingController();
  final focus = FocusNode();
  bool _emoji = false;

  // বলে লেখা
  bool _canListen = false;
  bool _listening = false;
  String _base = '';
  StreamSubscription<VoiceEvent>? _voiceSub;
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1300));

  // ভয়েস বার্তা
  AudioRecorder? _recorder;
  bool _recording = false;
  DateTime? _recStart;
  Timer? _recTick;
  double _dragX = 0;
  final List<double> _levels = [];
  StreamSubscription<Amplitude>? _ampSub;

  @override
  void initState() {
    super.initState();
    text.addListener(() => setState(() {}));
    focus.addListener(() {
      if (focus.hasFocus && _emoji) setState(() => _emoji = false);
    });
    _voiceSub = widget.voice.events.listen(_onVoice);
    widget.voice.available().then((ok) {
      if (mounted) setState(() => _canListen = ok);
    }, onError: (_) {});
  }

  @override
  void dispose() {
    _voiceSub?.cancel();
    if (_listening) unawaited(widget.voice.cancel());
    _pulse.dispose();
    _recTick?.cancel();
    _ampSub?.cancel();
    unawaited(_recorder?.dispose());
    text.dispose();
    focus.dispose();
    super.dispose();
  }

  bool get _hasText => text.text.trim().isNotEmpty;

  String get _locale => Localizations.localeOf(context).languageCode == 'en' ? 'en-US' : 'bn-BD';

  // ── বলে লেখা ─────────────────────────────────────────────────
  void _onVoice(VoiceEvent e) {
    if (!mounted) return;
    switch (e.kind) {
      case VoiceEventKind.partial:
        if (_listening) _put(e.text);
      case VoiceEventKind.done:
        if (_listening) _put(e.text);
        _stopUi();
      case VoiceEventKind.error:
        final was = _listening;
        _stopUi();
        if (!was) return;
        if (e.code == 'permission') {
          widget.toast(context.l10n.msgChatVoiceTypeDenied);
        } else if (e.code != 'no_speech') {
          widget.toast(context.l10n.msgChatVoiceTypeFailed);
        }
      case VoiceEventKind.level:
      case VoiceEventKind.spoken:
        break;
    }
  }

  void _put(String heard) {
    final h = heard.trim();
    final v = h.isEmpty ? _base : (_base.trim().isEmpty ? h : '${_base.trimRight()} $h');
    text.value = TextEditingValue(text: v, selection: TextSelection.collapsed(offset: v.length));
  }

  void _stopUi() {
    if (!_listening) return;
    _pulse.stop();
    setState(() => _listening = false);
  }

  Future<void> _toggleListen() async {
    if (_listening) {
      _stopUi();
      await widget.voice.stopListening();
      return;
    }
    _base = text.text;
    setState(() {
      _listening = true;
      _emoji = false;
    });
    if (!(MediaQuery.maybeDisableAnimationsOf(context) ?? false)) unawaited(_pulse.repeat());
    await widget.voice.listen(_locale);
  }

  // ── পাঠানো ──────────────────────────────────────────────────
  Future<void> _send() async {
    if (_listening) {
      _stopUi();
      await widget.voice.stopListening();
    }
    final t = text.text.trim();
    if (t.isEmpty) return;
    text.clear();
    widget.onSendText(t);
  }

  void _toggleEmoji() {
    if (_emoji) {
      setState(() => _emoji = false);
      focus.requestFocus();
    } else {
      focus.unfocus();
      setState(() => _emoji = true);
    }
  }

  void _insert(String e) {
    final v = text.value;
    final sel = v.selection.isValid ? v.selection : TextSelection.collapsed(offset: v.text.length);
    final next = v.text.replaceRange(sel.start, sel.end, e);
    text.value = TextEditingValue(text: next, selection: TextSelection.collapsed(offset: sel.start + e.length));
    widget.onTyping();
  }

  /// বাইরের ছোঁয়ায় (থ্রেডে চাপ) ইমোজির ঘর বন্ধ
  void closePanels() {
    if (_emoji) setState(() => _emoji = false);
  }

  // ── ভয়েস বার্তা (চেপে ধরে) ────────────────────────────────────
  Future<void> _recStartNow() async {
    final r = _recorder ??= AudioRecorder();
    try {
      if (!await r.hasPermission()) {
        if (mounted) widget.toast(context.l10n.msgChatMicDenied);
        return;
      }
      final dir = await getTemporaryDirectory();
      final path = '${dir.path}/voice-${DateTime.now().millisecondsSinceEpoch}.m4a';
      await r.start(const RecordConfig(encoder: AudioEncoder.aacLc, numChannels: 1), path: path);
      _levels.clear();
      _ampSub = r.onAmplitudeChanged(const Duration(milliseconds: 100)).listen((a) {
        _levels.add(((a.current + 60) / 60).clamp(0.0, 1.0));
      });
      _recStart = DateTime.now();
      _recTick = Timer.periodic(const Duration(milliseconds: 250), (_) {
        if (mounted) setState(() {});
      });
      if (mounted) {
        setState(() {
          _recording = true;
          _dragX = 0;
        });
      }
    } catch (_) {
      if (mounted) widget.toast(context.l10n.msgChatMicDenied);
    }
  }

  Future<void> _recEnd({required bool cancel}) async {
    if (!_recording) return;
    final r = _recorder!;
    _recTick?.cancel();
    await _ampSub?.cancel();
    _ampSub = null;
    final started = _recStart ?? DateTime.now();
    setState(() => _recording = false);
    if (cancel) {
      await r.cancel();
      return;
    }
    final path = await r.stop();
    final ms = DateTime.now().difference(started).inMilliseconds;
    if (path == null || ms < 1000) return;
    final f = File(path);
    final bytes = await f.readAsBytes();
    unawaited(f.delete().catchError((_) => f));
    final wave = _wave(_levels, 30);
    widget.onVoiceMessage(ChatUpload(kind: MessageKind.voice, bytes: bytes, name: 'voice.m4a', mime: 'audio/mp4', durationMs: ms, waveform: wave));
  }

  static List<double> _wave(List<double> levels, int n) {
    if (levels.isEmpty) return const [];
    return [
      for (var i = 0; i < n; i++)
        () {
          final from = (i * levels.length / n).floor();
          final to = math.max(from + 1, ((i + 1) * levels.length / n).floor());
          var peak = 0.0;
          for (var j = from; j < to && j < levels.length; j++) {
            peak = math.max(peak, levels[j]);
          }
          return double.parse(peak.toStringAsFixed(2));
        }(),
    ];
  }

  // ── আঁকা ───────────────────────────────────────────────────
  Widget _pi({required Glyph glyph, required String label, required VoidCallback onTap, Color? colour, double turns = 0, Key? key, bool? expanded}) =>
      Semantics(
        button: true,
        label: label,
        expanded: expanded,
        child: InkResponse(
          key: key,
          onTap: onTap,
          radius: 22,
          child: SizedBox(
            width: 48,
            height: 48,
            child: Center(child: Transform.rotate(angle: turns, child: GlyphIcon(glyph, size: 23, color: colour ?? widget.pal.ico))),
          ),
        ),
      );

  Widget _pillContent(BuildContext context) {
    final pal = widget.pal;
    final l = context.l10n;
    if (_recording) {
      final secs = DateTime.now().difference(_recStart ?? DateTime.now());
      return Row(children: [
        const SizedBox(width: 12),
        GlyphIcon(WaGlyphs.micf, size: 22, color: WaPalette.failed),
        const SizedBox(width: 8),
        Text(voiceTime(secs), style: TextStyle(fontSize: 15, color: pal.ink)),
        const Spacer(),
        Flexible(
          flex: 4,
          child: Text('‹ ${l.msgChatRecordingCancel}',
              maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 13.5, color: pal.ph)),
        ),
        const SizedBox(width: 12),
      ]);
    }
    return Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
      _pi(
        key: const ValueKey('composer-emoji'),
        glyph: _emoji ? WaGlyphs.kbd : WaGlyphs.smile,
        label: _emoji ? l.msgChatKeyboard : l.msgChatEmoji,
        colour: _emoji ? WaPalette.green : null,
        expanded: _emoji,
        onTap: _toggleEmoji,
      ),
      Expanded(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (_listening)
              Padding(
                padding: const EdgeInsets.only(top: 2, left: 2),
                child: Text(l.msgChatListening, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: WaPalette.green)),
              ),
            TextField(
              key: const ValueKey('composer-text'),
              controller: text,
              focusNode: focus,
              minLines: 1,
              maxLines: 6,
              keyboardType: TextInputType.multiline,
              textCapitalization: TextCapitalization.sentences,
              onChanged: (_) => widget.onTyping(),
              style: TextStyle(fontSize: 15, color: pal.ink, height: 1.35),
              cursorColor: WaPalette.green,
              decoration: InputDecoration(
                isDense: true,
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                disabledBorder: InputBorder.none,
                filled: false,
                contentPadding: const EdgeInsets.fromLTRB(2, 9, 2, 9),
                hintText: _listening ? null : l.msgChatHint,
                hintMaxLines: 1,
                hintStyle: TextStyle(fontSize: 15, color: pal.ph),
              ),
            ),
          ]),
        ),
      ),
      _pi(
        key: const ValueKey('composer-attach'),
        glyph: WaGlyphs.clip,
        label: l.msgChatAttach,
        turns: -math.pi / 4,
        colour: widget.trayOpen ? WaPalette.green : null,
        expanded: widget.trayOpen,
        onTap: widget.onToggleTray,
      ),
      if (!_listening) _pi(key: const ValueKey('composer-camera'), glyph: WaGlyphs.camera, label: l.msgChatCamera, onTap: widget.onCamera),
      if (_canListen)
        Semantics(
          button: true,
          toggled: _listening,
          label: l.msgChatVoiceType,
          child: InkResponse(
            key: const ValueKey('composer-voice-type'),
            onTap: _toggleListen,
            radius: 22,
            child: SizedBox(
              width: 48,
              height: 48,
              child: Center(
                child: _listening
                    ? AnimatedBuilder(
                        animation: _pulse,
                        builder: (context, child) => Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: WaPalette.green,
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(color: WaPalette.green.withValues(alpha: .55 * (1 - _pulse.value)), spreadRadius: 12 * _pulse.value),
                            ],
                          ),
                          child: child,
                        ),
                        child: Center(child: GlyphIcon(WaGlyphs.vtype, size: 24, color: const Color(0xFFFFFFFF))),
                      )
                    : GlyphIcon(WaGlyphs.vtype, size: 24, color: WaPalette.green),
              ),
            ),
          ),
        ),
    ]);
  }

  Widget _reply(BuildContext context) {
    final r = widget.replyTo!;
    final pal = widget.pal;
    final who = r.mine ? context.l10n.msgChatYou : (r.sender?.name ?? '');
    final edge = r.sender != null && !r.mine ? senderColour(r.sender!, pal) : WaPalette.green;
    return Container(
      key: const ValueKey('composer-reply'),
      margin: const EdgeInsets.fromLTRB(6, 6, 6, 0),
      decoration: BoxDecoration(
        color: pal.inset,
        borderRadius: BorderRadius.circular(8),
        border: Border(left: BorderSide(color: edge, width: 4)),
      ),
      child: Row(children: [
        Expanded(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(10, 6, 4, 6),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              if (who.isNotEmpty) Text(who, style: TextStyle(color: edge, fontSize: 12.5, fontWeight: FontWeight.w700)),
              Text(replyText(context, r.kind, r.text), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: pal.meta)),
            ]),
          ),
        ),
        IconButton(
          tooltip: context.l10n.msgChatReplyCancel,
          onPressed: widget.onCancelReply,
          icon: GlyphIcon(WaGlyphs.x, size: 18, color: pal.ico),
        ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final pal = widget.pal;
    final l = context.l10n;
    final sendMode = _hasText || _listening;
    final pill = Container(
      constraints: const BoxConstraints(minHeight: 48),
      decoration: BoxDecoration(color: pal.pill, borderRadius: BorderRadius.circular(24), boxShadow: WaPalette.bubbleShadow),
      padding: const EdgeInsets.symmetric(horizontal: 2),
      child: widget.replyTo == null
          ? _pillContent(context)
          : Column(mainAxisSize: MainAxisSize.min, children: [_reply(context), _pillContent(context)]),
    );
    final button = Semantics(
      button: true,
      label: sendMode ? l.msgChatSend : l.msgChatMic,
      child: GestureDetector(
        key: const ValueKey('composer-mic'),
        onTap: sendMode ? _send : () => widget.toast(l.msgChatMicHint),
        onLongPressStart: sendMode ? null : (_) => _recStartNow(),
        onLongPressMoveUpdate: sendMode
            ? null
            : (d) {
                _dragX = d.offsetFromOrigin.dx;
                if (_dragX < -100) unawaited(_recEnd(cancel: true));
              },
        onLongPressEnd: sendMode ? null : (_) => _recEnd(cancel: false),
        child: Container(
          width: 48,
          height: 48,
          decoration: const BoxDecoration(
            color: WaPalette.green,
            shape: BoxShape.circle,
            boxShadow: [BoxShadow(color: Color(0x33000000), offset: Offset(0, 1), blurRadius: 3)],
          ),
          alignment: Alignment.center,
          child: GlyphIcon(sendMode ? WaGlyphs.send : WaGlyphs.micf, size: 23, color: const Color(0xFFFFFFFF)),
        ),
      ),
    );
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Padding(
        padding: EdgeInsets.fromLTRB(8, 6, 8, _emoji ? 8 : 16),
        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Expanded(child: pill),
          const SizedBox(width: 6),
          button,
        ]),
      ),
      if (_emoji) EmojiPanel(pal: pal, onPick: _insert),
    ]);
  }
}

/// ⭐ সংযুক্তির ঘর (নমুনার `.tray`) — ফাইল, ক্যামেরা, ছবি, ERP কাগজ, অবস্থান
enum TrayItem { file, camera, gallery, paper, location }

class AttachTray extends StatelessWidget {
  const AttachTray({super.key, required this.pal, required this.onPick});

  final WaPalette pal;
  final ValueChanged<TrayItem> onPick;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final items = <(TrayItem, Glyph, Color, String)>[
      (TrayItem.file, WaGlyphs.doc, const Color(0xFF7F66FF), l.msgChatTrayFile),
      (TrayItem.camera, WaGlyphs.camera, const Color(0xFFFF2E74), l.msgChatCamera),
      (TrayItem.gallery, WaGlyphs.image, const Color(0xFFBF59CF), l.msgChatPhoto),
      (TrayItem.paper, WaGlyphs.paper, const Color(0xFF00A884), l.msgChatTrayPaper),
      (TrayItem.location, WaGlyphs.pin, const Color(0xFF1FA855), l.msgChatLocation),
    ];
    Widget cell((TrayItem, Glyph, Color, String) it) {
      final hl = it.$1 == TrayItem.paper;
      return Expanded(
        child: Semantics(
          button: true,
          label: it.$4,
          child: InkWell(
            key: ValueKey('tray-${it.$1.name}'),
            borderRadius: BorderRadius.circular(12),
            onTap: () => onPick(it.$1),
            child: ExcludeSemantics(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Container(
                  width: 54,
                  height: 54,
                  decoration: BoxDecoration(
                    color: it.$3,
                    shape: BoxShape.circle,
                    boxShadow: hl
                        ? [BoxShadow(color: pal.sheet, spreadRadius: 3), const BoxShadow(color: WaPalette.green, spreadRadius: 5)]
                        : null,
                  ),
                  alignment: Alignment.center,
                  child: GlyphIcon(it.$2, size: 25, color: const Color(0xFFFFFFFF)),
                ),
                const SizedBox(height: 7),
                Text(it.$4,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 12.5, height: 1.2, color: pal.ink, fontWeight: hl ? FontWeight.w700 : FontWeight.w400)),
              ]),
            ),
          ),
        ),
      );
    }

    return Container(
      key: const ValueKey('attach-tray'),
      padding: const EdgeInsets.fromLTRB(10, 20, 10, 18),
      decoration: BoxDecoration(
        color: pal.sheet,
        borderRadius: BorderRadius.circular(16),
        boxShadow: const [BoxShadow(color: Color(0x420B141A), offset: Offset(0, 8), blurRadius: 28)],
      ),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final it in items.take(3)) cell(it)]),
        const SizedBox(height: 18),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final it in items.skip(3)) cell(it), const Spacer()]),
      ]),
    );
  }
}
