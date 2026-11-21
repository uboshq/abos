import 'dart:async';
import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/voice/copilot_voice.dart';
import 'copilot_mark.dart';
import 'copilot_screen.dart';

/// ⭐ উপরের মগজের বোতাম — কেবল জিজ্ঞাসা, পুরো পর্দায়, চ্যাটের মতো (মালিক, ১২ নভেম্বর ২০২৬: "সিরি এবং জেমিনাইয়ের মতো …
/// মোবাইল অ্যাপের ডিজাইনটাও … যেভাবে চ্যাট করে চ্যাট বক্সে"; নমুনা 3uJhK92DAMhwyELt63mSmr, ফোনের তিন ধাপ)।
///
/// <p>১ · খুলল — শুভেচ্ছা, পাতার উদাহরণ, নিচে লেখার ঘর আর মাইক। ২ · শুনছে — আলোর মগজ, কিনারায় রঙের আভা, বলা কথা সাথে সাথে
/// লেখা। ৩ · চ্যাট — প্রশ্ন ডানে, উত্তর বাঁয়ে মগজের পাশে; প্রতিটা উত্তর শোনা যায়, "পুরো দেখুন →" পুরো কোপাইলটে।
/// ⓘ সংখ্যা ERP-র নিজের হিসাব — ওয়েবের একই দরজা (`/copilot/ask`); মডেল কেবল প্রশ্ন বোঝে। ⛔ কিছু লেখা বা পাঠানো হয় না।
Future<void> openCopilotQuick(
  BuildContext context, {
  String page = '',
  CopilotApi api = const ServerCopilotApi(),
  CopilotVoice? voice,
  VoidCallback? onFull,
}) {
  return Navigator.of(context, rootNavigator: true).push(PageRouteBuilder<void>(
    opaque: false,
    barrierDismissible: false,
    fullscreenDialog: true,
    transitionDuration: const Duration(milliseconds: 220),
    pageBuilder: (context, _, __) => CopilotQuickSheet(page: page, api: api, voice: voice ?? PhoneCopilotVoice.instance, onFull: onFull),
    transitionsBuilder: (context, animation, _, child) => FadeTransition(opacity: animation, child: child),
  ));
}

class CopilotQuickSheet extends StatefulWidget {
  const CopilotQuickSheet({super.key, this.page = '', required this.api, required this.voice, this.onFull});

  final String page;
  final CopilotApi api;
  final CopilotVoice voice;
  final VoidCallback? onFull;

  @override
  State<CopilotQuickSheet> createState() => _CopilotQuickSheetState();
}

class _Turn {
  _Turn(this.question, {this.byVoice = false});

  final String question;
  final bool byVoice;
  Map<String, dynamic>? answer;
  int? vote;
}

class _CopilotQuickSheetState extends State<CopilotQuickSheet> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final _turns = <_Turn>[];
  List<String> _examples = const [];
  String _name = 'Copilot';
  bool _asking = false;
  bool _listening = false;
  bool _canListen = false;
  String _heard = '';
  String _note = '';
  int? _speaking;
  StreamSubscription<VoiceEvent>? _voiceSub;

  // ⓘ শোনার অবস্থা — জমা কথা, কে থামাতে চাইল (null · keep · send · cancel), শুরুর সময়, ২ মিনিটের ঘড়ি, ঘর থেকে বলা কথা পাঠানো কি না
  String _kept = '';
  String? _stop;
  DateTime _listenStarted = DateTime.now();
  Timer? _listenTimer;
  Timer? _stopFallback;
  bool _voiceTyped = false;

  String get _locale => Localizations.localeOf(context).languageCode == 'en' ? 'en-US' : 'bn-BD';

  @override
  void initState() {
    super.initState();
    _voiceSub = widget.voice.events.listen(_onVoice);
    widget.voice.available().then((ok) {
      if (mounted) setState(() => _canListen = ok);
    });
    widget.api.today().then((t) {
      if (!mounted) return;
      setState(() {
        _name = (t['name'] as String?) ?? _name;
        _examples = ((t['examples'] as List?) ?? const []).map((e) => '$e').where((e) => e.isNotEmpty).take(4).toList();
      });
    }).catchError((_) {});
  }

  @override
  void dispose() {
    _voiceSub?.cancel();
    _listenTimer?.cancel();
    _stopFallback?.cancel();
    widget.voice.cancel();
    widget.voice.stopSpeaking();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  /// ⓘ আগে জমা কথা আর নতুন অংশ — মাঝে একটা ফাঁক
  static String joinSaid(String kept, String part) => [kept.trim(), part.trim()].where((s) => s.isNotEmpty).join(' ');

  void _onVoice(VoiceEvent e) {
    if (!mounted) return;
    final l = context.l10n;
    switch (e.kind) {
      case VoiceEventKind.partial:
        if (_listening) setState(() => _heard = joinSaid(_kept, e.text));
      case VoiceEventKind.done:
        if (!_listening) return;
        _kept = joinSaid(_kept, e.text);
        _heard = _kept;
        _againOrEnd();
      case VoiceEventKind.error:
        if (e.code == 'no_voice' || e.code == 'tts') {
          setState(() {
            _speaking = null;
            _note = l.copilotQuickNoVoice;
          });
          return;
        }
        if (!_listening) return;
        if (e.code == 'permission' || e.code == 'unavailable') {
          _note = e.code == 'permission' ? l.copilotQuickMicDenied : l.copilotQuickMicOff;
          _stop ??= 'keep';
        }
        // ⓘ চুপ থাকা ("no_speech") বা মাঝপথে কাটা ভুল নয় — মানুষ না থামানো পর্যন্ত আবার শোনা
        _againOrEnd();
      case VoiceEventKind.spoken:
        setState(() => _speaking = null);
      case VoiceEventKind.level:
        break;
    }
  }

  /// ⭐ যতক্ষণ মানুষ থামান না, ততক্ষণ শোনা (মালিক, ১৩ নভেম্বর ২০২৬: "Claude অ্যাপের মতো — যতক্ষণ মন চায় কথা বলা, শেষ হলে সেন্ড")।
  /// ⓘ ফোনের কথা-চেনা চুপ থাকলে থামে — জমা লেখা রেখে আবার শুরু; ভুলে খোলা থাকলে [maxListen]-এ নিজে থামে, বলা কথা ঘরে থাকে।
  static const maxListen = Duration(minutes: 2);

  void _againOrEnd() {
    final inTime = DateTime.now().difference(_listenStarted) < maxListen;
    if (_stop == null && _listening && inTime) {
      setState(() {});
      widget.voice.listen(_locale);
      return;
    }
    _endListening();
  }

  Future<void> _listen() async {
    await widget.voice.stopSpeaking();
    setState(() {
      _kept = _input.text.trim();
      _heard = _kept;
      _listening = true;
      _stop = null;
      _note = '';
      _speaking = null;
      _listenStarted = DateTime.now();
    });
    _listenTimer?.cancel();
    _listenTimer = Timer(maxListen, () => _finishListening(send: false));
    await widget.voice.listen(_locale);
  }

  /// মানুষ থামালেন — "পাঠান" হলে সঙ্গে সঙ্গে, "বলা শেষ" হলে লেখা ঘরে; ফোনের শেষ টুকরো এলে [_againOrEnd] শেষ করে, না এলে একটু পরে নিজে
  void _finishListening({required bool send}) {
    if (!_listening) return;
    _stop = send ? 'send' : 'keep';
    widget.voice.stopListening();
    _stopFallback?.cancel();
    _stopFallback = Timer(const Duration(milliseconds: 1500), () {
      if (mounted && _listening) _endListening();
    });
  }

  void _cancelListening() {
    _stop = 'cancel';
    widget.voice.cancel();
    _endListening();
  }

  void _endListening() {
    _listenTimer?.cancel();
    _stopFallback?.cancel();
    final said = _heard.trim();
    final mode = _stop;
    setState(() {
      _listening = false;
      _heard = '';
      if (mode != 'cancel') {
        _input.text = said;
        _voiceTyped = said.isNotEmpty;
      }
    });
    if (mode == 'send' && said.isNotEmpty) {
      _voiceTyped = false;
      _ask(said, byVoice: true);
    }
  }

  void _sendTyped() {
    // ⓘ মাইকে বলা কথা ঘর থেকে পাঠালেও "বলে জিজ্ঞেস করা" — উত্তর পড়ে শোনায়
    final byVoice = _voiceTyped;
    _voiceTyped = false;
    _ask(_input.text, byVoice: byVoice);
  }

  Future<void> _ask(String text, {bool byVoice = false}) async {
    final q = text.trim();
    if (q.isEmpty || _asking) return;
    final turn = _Turn(q, byVoice: byVoice);
    setState(() {
      _turns.add(turn);
      _asking = true;
      _note = '';
      _input.clear();
    });
    _toEnd();
    try {
      turn.answer = await widget.api.ask(q);
    } catch (e) {
      if (mounted) turn.answer = {'state': 'error', 'message': errorMessageFor(e, fallback: context.l10n.copilotFailed)};
    }
    if (!mounted) return;
    setState(() => _asking = false);
    _toEnd();
    // ⓘ বলে জিজ্ঞেস করলে উত্তরও শোনায় — লিখে জিজ্ঞেস করলে চুপ, "শুনুন" চাপলে শোনায় (নমুনা ধাপ ৩)
    if (byVoice) _speak(_turns.length - 1);
  }

  String _sayable(Map<String, dynamic>? a) {
    if (a == null) return '';
    if (a['state'] != 'answered') return '${a['message'] ?? ''}';
    return '${(a['answer'] as Map?)?['summary'] ?? ''}';
  }

  Future<void> _speak(int index) async {
    if (_speaking == index) {
      await widget.voice.stopSpeaking();
      setState(() => _speaking = null);
      return;
    }
    final text = _sayable(_turns[index].answer);
    if (text.isEmpty) return;
    setState(() => _speaking = index);
    await widget.voice.speak(text, _locale);
  }

  Future<void> _vote(_Turn turn, int value) async {
    final id = turn.answer?['answer_id'];
    if (id is! int) return;
    try {
      await widget.api.feedback(id, value);
      if (mounted) setState(() => turn.vote = value);
    } catch (_) {}
  }

  void _toEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 200), curve: Curves.easeOut);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final p = context.palette;
    final cs = Theme.of(context).colorScheme;

    return PopScope(
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) widget.voice.cancel();
      },
      child: Stack(children: [
        // ⓘ পেছনের পাতা ঝাপসা — বন্ধ করলে সেই পাতাতেই
        Positioned.fill(
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
            child: ColoredBox(color: cs.surface.withValues(alpha: 0.86)),
          ),
        ),
        if (_listening) const Positioned.fill(child: IgnorePointer(child: _EdgeGlow())),
        Scaffold(
          key: const ValueKey('copilot-quick'),
          backgroundColor: Colors.transparent,
          body: SafeArea(
            child: Column(children: [
              // ── মাথা: মগজ, নাম, কোন পাতায়, বন্ধ ──
              Padding(
                padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.xs, 0),
                child: Row(children: [
                  // ⓘ শোনা বা উত্তর ভাবার সময় চিহ্ন দ্বিগুণ জোরে চলে
                  CopilotMark(size: 30, busy: _listening || _asking),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(_name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                      if (widget.page.isNotEmpty)
                        Text(l.copilotQuickWhere(widget.page), style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
                    ]),
                  ),
                  IconButton(
                    key: const ValueKey('copilot-quick-close'),
                    tooltip: l.copilotQuickClose,
                    onPressed: () => Navigator.of(context).maybePop(),
                    icon: const Icon(Symbols.close),
                  ),
                ]),
              ),
              Expanded(
                child: _listening
                    ? _ListeningView(heard: _heard, onSend: () => _finishListening(send: true), onDone: () => _finishListening(send: false), onCancel: _cancelListening)
                    : ListView(
                        controller: _scroll,
                        padding: const EdgeInsets.all(AppSpacing.md),
                        children: [
                          _CopilotBubble(child: Text(l.copilotQuickHello, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600))),
                          if (_turns.isEmpty && _examples.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(left: 40, top: AppSpacing.sm),
                              child: Wrap(spacing: 6, runSpacing: 6, children: [
                                for (final e in _examples)
                                  ActionChip(key: ValueKey('copilot-quick-example-$e'), label: Text(e), onPressed: () => _ask(e)),
                              ]),
                            ),
                          for (final (i, t) in _turns.indexed) ...[
                            _MyBubble(text: t.question),
                            if (t.answer == null)
                              _CopilotBubble(busy: true, child: Text(l.copilotQuickThinking, style: TextStyle(color: p.onSurfaceMuted)))
                            else
                              _AnswerBubble(
                                answer: t.answer!,
                                speaking: _speaking == i,
                                vote: t.vote,
                                onSpeak: () => _speak(i),
                                onFull: widget.onFull == null
                                    ? null
                                    : () {
                                        Navigator.of(context).maybePop();
                                        widget.onFull!();
                                      },
                                onVote: (v) => _vote(t, v),
                              ),
                          ],
                          if (_note.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: AppSpacing.sm),
                              child: Text(_note, key: const ValueKey('copilot-quick-note'), style: TextStyle(color: p.onSurfaceMuted)),
                            ),
                          Padding(
                            padding: const EdgeInsets.only(top: AppSpacing.lg),
                            child: Text(l.copilotQuickPrivacy, style: TextStyle(color: p.onSurfaceMuted, fontSize: 11)),
                          ),
                        ],
                      ),
              ),
              // ── নিচে: লেখার ঘর, মাইক, পাঠান — আঙুলের কাছে ──
              if (!_listening)
                Padding(
                  padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.xs, AppSpacing.md, AppSpacing.md),
                  child: Row(children: [
                    Expanded(
                      child: TextField(
                        key: const ValueKey('copilot-quick-input'),
                        controller: _input,
                        textInputAction: TextInputAction.send,
                        onSubmitted: (_) => _sendTyped(),
                        minLines: 1,
                        maxLines: 3,
                        decoration: InputDecoration(
                          hintText: l.copilotPlaceholder,
                          filled: true,
                          fillColor: p.surface,
                          border: OutlineInputBorder(borderRadius: BorderRadius.circular(24)),
                          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                        ),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.xs),
                    if (_canListen)
                      IconButton.filledTonal(
                        key: const ValueKey('copilot-quick-mic'),
                        tooltip: l.copilotQuickMic,
                        onPressed: _asking ? null : _listen,
                        icon: const Icon(Symbols.mic),
                      ),
                    IconButton.filled(
                      key: const ValueKey('copilot-quick-send'),
                      tooltip: l.copilotQuickSend,
                      onPressed: _asking ? null : _sendTyped,
                      icon: const Icon(Symbols.send),
                    ),
                  ]),
                ),
            ]),
          ),
        ),
      ]),
    );
  }
}

/// কোপাইলটের বুদবুদ — বাঁয়ে, মগজের পাশে
class _CopilotBubble extends StatelessWidget {
  const _CopilotBubble({required this.child, this.busy = false});

  final Widget child;

  /// উত্তর ভাবছে — চিহ্ন দ্বিগুণ জোরে
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm, right: 32),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(padding: const EdgeInsets.only(top: 6), child: CopilotMark(size: 28, busy: busy)),
        const SizedBox(width: AppSpacing.sm),
        Flexible(
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: p.surface,
              border: Border.all(color: p.border),
              borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(4), topRight: Radius.circular(18), bottomLeft: Radius.circular(18), bottomRight: Radius.circular(18)),
            ),
            child: Padding(padding: const EdgeInsets.all(12), child: child),
          ),
        ),
      ]),
    );
  }
}

/// নিজের প্রশ্ন — ডানে
class _MyBubble extends StatelessWidget {
  const _MyBubble({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.md, left: 56),
      child: Align(
        alignment: Alignment.centerRight,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: cs.primary,
            borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(18), topRight: Radius.circular(4), bottomLeft: Radius.circular(18), bottomRight: Radius.circular(18)),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Text(text, style: TextStyle(color: cs.onPrimary, fontSize: 15)),
          ),
        ),
      ),
    );
  }
}

class _AnswerBubble extends StatelessWidget {
  const _AnswerBubble({
    required this.answer,
    required this.speaking,
    required this.vote,
    required this.onSpeak,
    required this.onFull,
    required this.onVote,
  });

  final Map<String, dynamic> answer;
  final bool speaking;
  final int? vote;
  final VoidCallback onSpeak;
  final VoidCallback? onFull;
  final ValueChanged<int> onVote;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final p = context.palette;
    final result = answer['answer'] as Map?;
    final answered = answer['state'] == 'answered' && result != null;
    final columns = ((result?['columns'] as List?) ?? const []).cast<String>();
    final rows = ((result?['rows'] as List?) ?? const []).cast<Map>().take(5).toList();
    final sources = ((result?['sources'] as List?) ?? const []).map((s) => '${(s as Map)['label']}').where((s) => s.isNotEmpty).join(' · ');

    return _CopilotBubble(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (!answered)
          Text('${answer['message'] ?? ''}', key: const ValueKey('copilot-quick-state'))
        else ...[
          Text('${result['summary']}', key: const ValueKey('copilot-quick-summary'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          for (final row in rows)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(children: [
                for (final (i, col) in columns.indexed)
                  i == 0
                      ? Expanded(child: Text('${row[col] ?? ''}', style: TextStyle(color: p.onSurfaceMuted)))
                      : Padding(
                          padding: const EdgeInsets.only(left: 8),
                          child: Text('${row[col] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600, fontFeatures: [FontFeature.tabularFigures()])),
                        ),
              ]),
            ),
          if (sources.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('${l.copilotSource}: $sources', style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
            ),
        ],
        const SizedBox(height: 4),
        Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 4, children: [
          TextButton.icon(
            key: const ValueKey('copilot-quick-speak'),
            onPressed: onSpeak,
            icon: Icon(speaking ? Symbols.stop_circle : Symbols.volume_up, size: 18),
            label: Text(speaking ? l.copilotQuickStop : l.copilotQuickListen),
          ),
          if (onFull != null)
            TextButton(key: const ValueKey('copilot-quick-full'), onPressed: onFull, child: Text(l.copilotQuickFull)),
          if (answered) ...[
            IconButton(
              tooltip: l.copilotRight,
              visualDensity: VisualDensity.compact,
              onPressed: () => onVote(1),
              icon: Icon(Symbols.thumb_up, size: 18, fill: vote == 1 ? 1 : 0),
            ),
            IconButton(
              tooltip: l.copilotWrong,
              visualDensity: VisualDensity.compact,
              onPressed: () => onVote(-1),
              icon: Icon(Symbols.thumb_down, size: 18, fill: vote == -1 ? 1 : 0),
            ),
          ],
        ]),
      ]),
    );
  }
}

/// ⭐ শুনছে — মাঝে বড় আলোর মগজ, নিচে বলা কথা সাথে সাথে, "বলা শেষ" আর "বাতিল" (নমুনা ধাপ ২)
class _ListeningView extends StatelessWidget {
  const _ListeningView({required this.heard, required this.onSend, required this.onDone, required this.onCancel});

  final String heard;
  final VoidCallback onSend;
  final VoidCallback onDone;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final p = context.palette;
    return Column(key: const ValueKey('copilot-quick-listening'), mainAxisAlignment: MainAxisAlignment.center, children: [
      const CopilotMark(size: 96, busy: true),
      const SizedBox(height: AppSpacing.lg),
      Text(l.copilotQuickListening, style: TextStyle(color: p.onSurfaceMuted)),
      Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Text(heard, textAlign: TextAlign.center, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600)),
      ),
      Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        // ⓘ "পাঠান" — সঙ্গে সঙ্গে; "বলা শেষ" — লেখা ঘরে, দেখে পাঠানো (মালিক, ১৩ নভেম্বর ২০২৬)
        FilledButton(key: const ValueKey('copilot-quick-send-now'), onPressed: onSend, child: Text(l.copilotQuickSend)),
        const SizedBox(width: AppSpacing.sm),
        OutlinedButton(key: const ValueKey('copilot-quick-done'), onPressed: onDone, child: Text(l.copilotQuickDoneSpeaking)),
        const SizedBox(width: AppSpacing.sm),
        OutlinedButton(key: const ValueKey('copilot-quick-cancel'), onPressed: onCancel, child: Text(l.copilotQuickCancel)),
      ]),
    ]);
  }
}

/// ⓘ পর্দার কিনারায় রঙের আভা — Siri-র মতো, কেবল শোনার সময়
class _EdgeGlow extends StatelessWidget {
  const _EdgeGlow();

  @override
  Widget build(BuildContext context) {
    const c = CopilotMark.colors;
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: c[0].withValues(alpha: 0.55), width: 3),
        gradient: RadialGradient(
          radius: 1.2,
          colors: [Colors.transparent, c[1].withValues(alpha: 0.10), c[2].withValues(alpha: 0.22)],
          stops: const [0.6, 0.85, 1],
        ),
      ),
    );
  }
}
