import 'dart:async';

import 'package:flutter/services.dart';

/// ⭐ কোপাইলটের কথা — ফোনের নিজের কথা-চেনা আর পড়ে-শোনানো (android/…/voice/VoiceChannel.kt)।
///
/// <p>⛔ অডিও কোথাও জমা হয় না: ফোন শুনে লেখা দেয়, কোপাইলট কেবল লেখাটা পায় (ওয়েবের মাইকের একই নিয়ম)।
/// ⓘ পরীক্ষায় [FakeCopilotVoice] — চ্যানেল নেই, ঘটনা হাতে পাঠানো।
abstract class CopilotVoice {
  /// শোনার ঘটনা — [VoiceEvent.partial] বলার সাথে সাথে, [VoiceEvent.done] শেষে, [VoiceEvent.error] ভুলে
  Stream<VoiceEvent> get events;

  Future<bool> available();
  Future<void> listen(String locale);
  Future<void> stopListening();
  Future<void> cancel();
  Future<void> speak(String text, String locale);
  Future<void> stopSpeaking();
}

enum VoiceEventKind { partial, done, level, error, spoken }

class VoiceEvent {
  const VoiceEvent(this.kind, {this.text = '', this.level = 0, this.code = ''});

  final VoiceEventKind kind;
  final String text;
  final double level;

  /// permission · unavailable · no_speech · network · no_voice · tts · other
  final String code;
}

class PhoneCopilotVoice implements CopilotVoice {
  PhoneCopilotVoice._() {
    _channel.setMethodCallHandler((call) async {
      final args = (call.arguments as Map?) ?? const {};
      switch (call.method) {
        case 'partial':
          _events.add(VoiceEvent(VoiceEventKind.partial, text: '${args['text'] ?? ''}'));
        case 'final':
          _events.add(VoiceEvent(VoiceEventKind.done, text: '${args['text'] ?? ''}'));
        case 'level':
          _events.add(VoiceEvent(VoiceEventKind.level, level: (args['rms'] as num?)?.toDouble() ?? 0));
        case 'error':
          _events.add(VoiceEvent(VoiceEventKind.error, code: '${args['code'] ?? 'other'}'));
        case 'spoken':
          _events.add(const VoiceEvent(VoiceEventKind.spoken));
      }
    });
  }

  static final PhoneCopilotVoice instance = PhoneCopilotVoice._();

  static const _channel = MethodChannel('com.abos.abos_mobile/voice');
  final _events = StreamController<VoiceEvent>.broadcast();

  @override
  Stream<VoiceEvent> get events => _events.stream;

  Future<T?> _call<T>(String method, [Map<String, Object?>? args]) async {
    try {
      return await _channel.invokeMethod<T>(method, args);
    } on MissingPluginException {
      return null; // ⓘ চ্যানেল নেই (ডেস্কটপ, পরীক্ষা) — কথা নেই, লেখায় চলে
    } on PlatformException {
      return null;
    }
  }

  @override
  Future<bool> available() async => (await _call<bool>('available')) ?? false;

  @override
  Future<void> listen(String locale) => _call<bool>('listen', {'locale': locale});

  @override
  Future<void> stopListening() => _call<bool>('stopListening');

  @override
  Future<void> cancel() => _call<bool>('cancel');

  @override
  Future<void> speak(String text, String locale) => _call<bool>('speak', {'text': text, 'locale': locale});

  @override
  Future<void> stopSpeaking() => _call<bool>('stopSpeaking');
}

/// ⓘ পরীক্ষার কথা — কী ডাকা হলো মনে রাখে, ঘটনা [emit] দিয়ে পাঠানো যায়
class FakeCopilotVoice implements CopilotVoice {
  FakeCopilotVoice({this.canListen = true});

  final bool canListen;
  final calls = <String>[];
  final spoken = <String>[];
  final _events = StreamController<VoiceEvent>.broadcast();

  void emit(VoiceEvent e) => _events.add(e);

  @override
  Stream<VoiceEvent> get events => _events.stream;

  @override
  Future<bool> available() async => canListen;

  @override
  Future<void> listen(String locale) async => calls.add('listen:$locale');

  @override
  Future<void> stopListening() async => calls.add('stopListening');

  @override
  Future<void> cancel() async => calls.add('cancel');

  @override
  Future<void> speak(String text, String locale) async {
    calls.add('speak:$locale');
    spoken.add(text);
  }

  @override
  Future<void> stopSpeaking() async => calls.add('stopSpeaking');
}
