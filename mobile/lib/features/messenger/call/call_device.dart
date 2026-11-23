import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/messenger/messenger_alerts.dart';

/// কল দেওয়া বা ধরার মুহূর্তে পাওয়া অনুমতি
@immutable
class CallPermits {
  const CallPermits({required this.mic, required this.camera});

  final bool mic;
  final bool camera;
}

/// ⭐ কলের ফোন-অংশ — অনুমতি, চলতি কলের সামনের-সেবা, পর্দা জ্বালা, রিং, তালার উপরে দেখানো, "পরে মনে করান"।
/// আসলটা Android-এর `call/CallChannel.kt`; পরীক্ষায় নকল।
abstract interface class CallDevice {
  Future<CallPermits> ask({required bool video});

  Future<void> startOngoing({required String title, required String text, required bool video});

  Future<void> stopOngoing();

  Future<void> keepScreenOn(bool on);

  Future<void> ring(bool on);

  Future<void> showOverLock(bool on);

  Future<void> remindLater({required int id, required String title, required String body, required String payload, required Duration after});
}

/// ⛔ কিছুই ছোড়ে না — Android ছাড়া (বা চ্যানেল না থাকলে) চুপ; অনুমতি তখন "আছে" ধরা, LiveKit নিজে জানাবে
class ChannelCallDevice implements CallDevice {
  const ChannelCallDevice([this._channel = const MethodChannel('com.abos.abos_mobile/call')]);

  final MethodChannel _channel;

  Future<T?> _call<T>(String method, [Map<String, Object?>? args]) async {
    try {
      return await _channel.invokeMethod<T>(method, args);
    } catch (e) {
      debugPrint('ABOS call: $method failed ($e)');
      return null;
    }
  }

  @override
  Future<CallPermits> ask({required bool video}) async {
    final r = await _call<Map<Object?, Object?>>('ask', {'video': video});
    if (r == null) return const CallPermits(mic: true, camera: true);
    return CallPermits(mic: r['mic'] == true, camera: r['camera'] == true);
  }

  @override
  Future<void> startOngoing({required String title, required String text, required bool video}) =>
      _call<bool>('startOngoing', {'title': title, 'text': text, 'video': video});

  @override
  Future<void> stopOngoing() => _call<bool>('stopOngoing');

  @override
  Future<void> keepScreenOn(bool on) => _call<void>('keepScreenOn', {'on': on});

  @override
  Future<void> ring(bool on) => _call<void>('ring', {'on': on});

  @override
  Future<void> showOverLock(bool on) => _call<void>('showOverLock', {'on': on});

  @override
  Future<void> remindLater({required int id, required String title, required String body, required String payload, required Duration after}) =>
      _call<bool>('remindLater', {'id': id, 'title': title, 'body': body, 'payload': payload, 'delayMs': after.inMilliseconds});
}

final callDeviceProvider = Provider<CallDevice>((ref) => const ChannelCallDevice());

/// আসা কলের রিং-নোটিফিকেশন সরানো ([MessengerAlerts.cancelCall]) — পরীক্ষা নকল দেয় (নোটিফিকেশনের প্লাগইন ছাড়া)
final callRingNoticeCancelProvider = Provider<Future<void> Function(String? callId)>((ref) => MessengerAlerts.instance.cancelCall);
