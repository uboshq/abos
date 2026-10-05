import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../api_client/api_client.dart';
import '../auth/token_storage.dart';

/// অ্যাপ বন্ধ থাকলেও বার্তা — Firebase Cloud Messaging (0.4.7, মালিকের আদেশ, ২ অক্টোবর ২০২৬)।
///
/// <p>⭐ কাজ তিনটা: অনুমতি চাওয়া (Android 13+), এই ফোনের টোকেন সার্ভারে জমা দেওয়া
/// (`POST /devices/push-token`, নিজের deviceId-তে), আর বার্তায় চাপলে কোথায় যাবে তা বলা ([destinationOf])।
///
/// <p>⛔ কিছুই অ্যাপ থামায় না: Firebase না উঠলে (google-services নেই, Play Services নেই) সব চুপচাপ বাদ —
/// বার্তা আসবে না, কিন্তু অ্যাপ আগের মতোই চলবে। লগআউটে সার্ভার নিজেই টোকেন মোছে।
class PushService {
  PushService._();

  static final PushService instance = PushService._();

  bool _ready = false;
  StreamSubscription<String>? _refresh;

  /// অ্যাপ শুরুতে একবার। ⓘ ব্যর্থ হলে false, আর কিছু নয়।
  Future<bool> init() async {
    if (_ready) return true;
    try {
      await Firebase.initializeApp();
      _ready = true;
    } catch (e) {
      debugPrint('ABOS push: Firebase did not start ($e) — pushes off this run');
    }
    return _ready;
  }

  /// লগইন বা সেশন ফেরার পরে — অনুমতি, তারপর টোকেন জমা; টোকেন বদলালে আবার।
  Future<void> register() async {
    if (!_ready) return;
    try {
      final messaging = FirebaseMessaging.instance;
      await messaging.requestPermission();
      final token = await messaging.getToken();
      if (token != null) await _send(token);
      await _refresh?.cancel();
      _refresh = messaging.onTokenRefresh.listen((t) => unawaited(_send(t)));
    } catch (e) {
      debugPrint('ABOS push: register failed ($e)');
    }
  }

  Future<void> _send(String token) async {
    try {
      await ApiClient.dio.post<void>('/devices/push-token', data: {
        'deviceId': await TokenStorage.instance.deviceId(),
        'token': token,
      });
    } catch (e) {
      // পুরনো সার্ভারে দরজাটা নেই (৪০৪) বা সংযোগ নেই — পরের শুরুতে আবার
      debugPrint('ABOS push: token not saved ($e)');
    }
  }

  /// বার্তায় চেপে অ্যাপ খুলল — শুরুতে একবার আর চলার সময়। ঠিকানা দেয় [destinationOf]।
  void listenForTaps(void Function(String path) open) {
    if (!_ready) return;
    try {
      FirebaseMessaging.instance.getInitialMessage().then((m) {
        final path = destinationOf(m?.data);
        if (path != null) open(path);
      }).catchError((Object _) {});
      FirebaseMessaging.onMessageOpenedApp.listen((m) {
        final path = destinationOf(m.data);
        if (path != null) open(path);
      });
    } catch (_) {}
  }
}

/// বার্তার `data` থেকে অ্যাপের ঠিকানা — সার্ভার পাঠায় `{open: tracking, kind, id}` ([[TrackingNotices]])।
/// ⓘ অচেনা কিছু হলে null — অ্যাপ যেখানে ছিল সেখানেই খোলে।
String? destinationOf(Map<String, dynamic>? data) {
  if (data == null) return null;
  return switch (data['open']) {
    'tracking' => '/home/tracking',
    _ => null,
  };
}
