import '../sync_engine/live_sync.dart';
import '../sync_engine/background_sync.dart';
import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart' show WidgetsFlutterBinding;
import 'package:hive/hive.dart';
import 'package:path_provider/path_provider.dart';

import '../messenger/messenger_alerts.dart';
import '../messenger/messenger_api.dart';
import '../messenger/messenger_push.dart';
import '../permissions/permission_rationale.dart';
import 'local_alerts.dart';
import '../storage/hive_encryption.dart';

import '../api_client/api_client.dart';
import '../auth/token_storage.dart';

/// অ্যাপ বন্ধ থাকলেও বার্তা — Firebase Cloud Messaging (0.4.7, মালিকের আদেশ, ২ অক্টোবর ২০২৬)।
///
/// <p>⭐ কাজ তিনটা: অনুমতি চাওয়া (Android 13+), এই ফোনের টোকেন সার্ভারে জমা দেওয়া
/// (`POST /devices/push-token`, নিজের deviceId-তে), আর বার্তায় চাপলে কোথায় যাবে তা বলা ([destinationOf])।
///
/// <p>⛔ কিছুই অ্যাপ থামায় না: Firebase না উঠলে (google-services নেই, Play Services নেই) সব চুপচাপ বাদ —
/// বার্তা আসবে না, কিন্তু অ্যাপ আগের মতোই চলবে। লগআউটে সার্ভার নিজেই টোকেন মোছে, আর ফোনও ([[forget]])।
class PushService {
  PushService._();

  static final PushService instance = PushService._();

  bool _ready = false;
  StreamSubscription<String>? _refresh;

  /// ⭐ Firebase ওঠার আগে চাওয়া কাজ — উঠলে তখনই (ec, ১৯ অক্টোবর ২০২৬): Firebase এখন প্রথম ফ্রেমের পরে ওঠে ([[Startup]]),
  /// আর অ্যাপের শোনা ([listenForTaps], [listenForSync]) বসে তার আগেই — আগে সেগুলো চুপচাপ বাদ পড়ত।
  final List<void Function()> _pending = [];

  /// উঠে থাকলে এখনই, নাহলে ওঠার পরে। ⓘ Firebase না উঠলে কখনো নয় — আগের মতোই চুপ।
  @visibleForTesting
  void whenReady(void Function() attach) {
    if (_ready) {
      attach();
    } else {
      _pending.add(attach);
    }
  }

  void _attachPending() {
    final waiting = List.of(_pending);
    _pending.clear();
    for (final attach in waiting) {
      try {
        attach();
      } catch (_) {}
    }
  }

  /// পরীক্ষার জন্য — Firebase ছাড়াই "উঠল"
  @visibleForTesting
  void debugMarkReady() {
    _ready = true;
    _attachPending();
  }

  /// পরীক্ষার জন্য
  @visibleForTesting
  void debugReset() {
    _ready = false;
    _pending.clear();
  }

  /// অ্যাপ শুরুতে একবার। ⓘ ব্যর্থ হলে false, আর কিছু নয়।
  Future<bool> init() async {
    if (_ready) return true;
    try {
      await Firebase.initializeApp();
      _ready = true;
      // ⭐ পেছনে বা বন্ধ অবস্থায় নীরব ডাক — নিজের isolate-এ ([onBackgroundNudge]; a4-এর পরিকল্পনা §৪.১ ধাপ ৩)
      FirebaseMessaging.onBackgroundMessage(onBackgroundNudge);
    } catch (e) {
      debugPrint('ABOS push: Firebase did not start ($e) — pushes off this run');
    }
    if (_ready) {
      // ⭐ "জরুরি বার্তা" চ্যানেল, আর সামনে খোলা অ্যাপেও বার্তা দেখানো (মালিক, ২০ নভেম্বর ২০২৬; [[LocalAlerts]])
      await LocalAlerts.instance.init();
      _listenInForeground();
      _attachPending();
    }
    return _ready;
  }

  /// লগইন বা সেশন ফেরার পরে — টোকেন জমা; টোকেন বদলালে আবার। ⓘ অনুমতি এখানে নয় — [askPermissionOnce], পর্দা ওঠার পরে,
  /// বাংলা ব্যাখ্যা দিয়ে (আগে এখানেই সরাসরি Android-এর প্রশ্ন খুলত, কোনো ব্যাখ্যা ছাড়া)।
  Future<void> register() async {
    // ⓘ Firebase এখনো ওঠেনি (প্রথম ফ্রেমের পরে ওঠে) — উঠলে তখন
    if (!_ready) return whenReady(() => unawaited(register()));
    try {
      final messaging = FirebaseMessaging.instance;
      final token = await messaging.getToken();
      if (token != null) await _send(token);
      await _refresh?.cancel();
      _refresh = messaging.onTokenRefresh.listen((t) => unawaited(_send(t)));
    } catch (e) {
      debugPrint('ABOS push: register failed ($e)');
    }
  }

  /// ⭐ Android 13+ বার্তার অনুমতি — আগে বাংলা ব্যাখ্যা ([explain]), তারপর Android-এর প্রশ্ন; এই ফোনে একবারই
  /// ("এখন না" বললে প্রতিবার খোলায় আবার জিজ্ঞেস করা হয় না)। অনুমতি আগে থেকে থাকলে কিছুই নয়।
  Future<void> askPermissionOnce(Future<bool> Function() explain) async {
    if (!_ready) return;
    try {
      await askNotificationsOnce(
        granted: () => PermissionCheck.granted(AppPermission.notifications),
        alreadyAsked: _NotificationAskedFlag.read,
        markAsked: _NotificationAskedFlag.write,
        explain: explain,
        request: () => FirebaseMessaging.instance.requestPermission(),
      );
    } catch (e) {
      debugPrint('ABOS push: permission not asked ($e)');
    }
  }

  /// পরীক্ষার পথ — আসলটা এই ফোনের Firebase টোকেন বাতিল করা
  @visibleForTesting
  static Future<void> Function() tokenDeleter = _deleteToken;

  static Future<void> _deleteToken() async {
    if (Firebase.apps.isEmpty) return;
    await FirebaseMessaging.instance.deleteToken();
  }

  /// ⛔ বেরোলে এই ফোনের বার্তার টোকেন বাতিল — সার্ভার নিজে মোছে কেবল লগআউট সার্ভারে পৌঁছালে; নেট ছাড়া বেরোলে বা সেশন নিজে শেষ
  /// হলে আগের জনের সইয়ের ফল, কাগজের নাম আর মন্তব্য এই ফোনে আসতেই থাকত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)। নতুন
  /// টোকেন পরের লগইনে [register] নিজে আনে; সার্ভার বাতিল টোকেনের সারি নিজে ফেলে দেয়।
  Future<void> forget() async {
    await _refresh?.cancel();
    _refresh = null;
    try {
      await tokenDeleter();
    } catch (e) {
      debugPrint('ABOS push: token not deleted ($e)');
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

  /// ⭐ সামনে খোলা (বা তালার পর্দা ঢাকা) অ্যাপে আসা বার্তা — Firebase নিজে দেখায় না, তাই [showInForeground] দেখায়;
  /// নীরব সিঙ্ক-ডাক বাদ ([listenForSync] ওটা ধরে)। ⓘ [init]-এ একবারই।
  void _listenInForeground() {
    try {
      FirebaseMessaging.onMessage.listen((m) {
        // ⭐ বার্তা ও কল — নিজের পথে ([handleMessengerPush]); বাকি সব আগের মতো
        final e = MessengerPush.fromData(m.data);
        if (e != null) {
          unawaited(handleMessengerPush(e,
              foreground: true,
              hasNotification: m.notification != null,
              title: m.notification?.title,
              body: m.notification?.body,
              api: messengerApiForPush()));
          // ⓘ বাকি লেখাসহ বার্তা (যেমন সার্ভারের "মিসড কল — নাম") — অন্য সব নোটিশের মতোই দেখানো
          if (!messengerShowsItself(e)) unawaited(showInForeground(m, LocalAlerts.instance));
          return;
        }
        if (isNoticePush(m.data)) noteNoticePush();
        unawaited(showInForeground(m, LocalAlerts.instance));
      });
    } catch (_) {}
  }

  /// ⭐ Bumped on every `{abos: notice}` push — a new notification for this person; the home refreshes its bell
  /// count on it instead of asking on every screen return (polling cut, 23 Nov 2026).
  final ValueNotifier<int> notices = ValueNotifier<int>(0);

  /// A notice push arrived (the open app).
  void noteNoticePush() => notices.value++;

  /// ⭐ রিয়েল-টাইম সিঙ্ক — অফিসে কিছু লেখা হলে সার্ভারের নীরব ডাক ([isSyncNudge]); খোলা অ্যাপ তখনই
  /// [run] চালায় (মালিক, ১০ অক্টোবর ২০২৬)। ⓘ অ্যাপ বন্ধ থাকলে কিছু নয় — খুললেই নিজের গোল চলে।
  void listenForSync(Future<void> Function() run) => whenReady(() {
        try {
          FirebaseMessaging.onMessage.listen((m) {
            if (isSyncNudge(m.data)) unawaited(run().catchError((Object _) {}));
          });
        } catch (_) {}
      });

  /// বার্তায় চেপে অ্যাপ খুলল — শুরুতে একবার আর চলার সময়। ঠিকানা দেয় [destinationOf]।
  /// ⭐ সামনে দেখানো বার্তার চাপও একই [open]-এ ([LocalAlerts.onTap]) — তালার পর্দা খোলা পর্যন্ত ঢেকে রাখে।
  void listenForTaps(void Function(String path) open) {
    LocalAlerts.instance.onTap(open);
    _listenForFirebaseTaps(open);
  }

  void _listenForFirebaseTaps(void Function(String path) open) => whenReady(() {
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
      });
}

/// বার্তার অনুমতির ধাপগুলো, ফোন ছাড়া পরীক্ষার জন্য আলাদা। true = Android-এর প্রশ্ন খোলা হল।
///
/// <p>⛔ ক্রম: অনুমতি আছে → কিছু নয়; আগে একবার জিজ্ঞেস হয়েছে → কিছু নয়; তারপর "জিজ্ঞেস হল" লেখা, বাংলা ব্যাখ্যা,
/// আর কেবল "ঠিক আছে" বললে তবে [request]।
Future<bool> askNotificationsOnce({
  required Future<bool?> Function() granted,
  required Future<bool> Function() alreadyAsked,
  required Future<void> Function() markAsked,
  required Future<bool> Function() explain,
  required Future<void> Function() request,
}) async {
  if (await granted() == true) return false;
  if (await alreadyAsked()) return false;
  await markAsked();
  if (!await explain()) return false;
  await request();
  return true;
}

/// "বার্তার অনুমতি একবার জিজ্ঞেস হয়েছে" — এই ফোনে, এনক্রিপ্ট করা বাক্সে। ⓘ খুলতে না পারলে "হয়নি" ধরা।
class _NotificationAskedFlag {
  static const String _boxName = 'abos_permissions';
  static const String _key = 'notifications_asked';

  static Future<bool> read() async {
    try {
      final box = await HiveEncryptionKey.openBox<Map>(_boxName);
      return box.get(_key)?['v'] == true;
    } catch (_) {
      return false;
    }
  }

  static Future<void> write() async {
    try {
      final Box<Map> box = await HiveEncryptionKey.openBox<Map>(_boxName);
      await box.put(_key, <String, dynamic>{'v': true});
    } catch (_) {}
  }
}

/// ⭐ পেছনের নীরব ডাক — অ্যাপ পেছনে বা বন্ধ (মালিক ১০ অক্টোবর "ক"; a4-এর পরিকল্পনা সংস্করণ ২ §৪.১ ধাপ ৩)।
///
/// <p>নিজের isolate-এ চলে (FlutterFire-এর নিয়ম), তাই Firebase আবার চালু। জমা কাজ পাঠায় আর উইজেট ভরে ([backgroundRound]),
/// আর একটা চিহ্ন রাখে — অ্যাপ সামনে এলেই টানে ([NudgeMark])। ⓘ ব্যবহারকারী কিছু দেখেন না; বার্তায় তথ্য নেই।
@pragma('vm:entry-point')
Future<void> onBackgroundNudge(RemoteMessage message) async {
  // ⭐ বার্তা ও কল — পেছনে বা বন্ধ অবস্থায়: কলে রিং (পূর্ণ-পর্দা), call_end-এ থামা, লেখাহীন চ্যাটে নিজের বার্তা
  final messenger = MessengerPush.fromData(message.data);
  if (messenger != null) {
    WidgetsFlutterBinding.ensureInitialized();
    await MessengerAlerts.instance.initInBackground();
    await handleMessengerPush(messenger,
        foreground: false,
        hasNotification: message.notification != null,
        title: message.notification?.title,
        body: message.notification?.body,
        api: messengerApiForPush());
    return;
  }
  // ⭐ a new notification while the app is away — only a mark; the bell is asked when the app comes back ([NoticeMark])
  if (isNoticePush(message.data)) return NoticeMark.set();
  if (!isSyncNudge(message.data)) return;
  try {
    await Firebase.initializeApp();
  } catch (_) {}
  await NudgeMark.set();
  await backgroundRound();
}

/// সার্ভারের নীরব "সিঙ্ক করো" ডাক — `{abos: sync}` ([[SyncNudge]]); ভেতরে আর কিছু নেই।
bool isSyncNudge(Map<String, dynamic>? data) => data?['abos'] == 'sync';

/// ⭐ A notice push came while the app was in the background — the bell count is asked on return.
/// ⓘ A small file, like [NudgeMark]: the push handler runs in its own isolate.
class NoticeMark {
  const NoticeMark._();

  static Future<File> _file() async => File('${(await getApplicationSupportDirectory()).path}/abos-notice-mark');

  static Future<void> set() async {
    try {
      await (await _file()).writeAsString(DateTime.now().toIso8601String(), flush: true);
    } catch (_) {}
  }

  /// Was there one — and the mark is gone once read.
  static Future<bool> take() async {
    try {
      final file = await _file();
      if (!await file.exists()) return false;
      await file.delete();
      return true;
    } catch (_) {
      return false;
    }
  }
}

/// ⭐ A new notification for this person — `{abos: notice}`; the bell count is asked again ([PushService.notices]).
bool isNoticePush(Map<String, dynamic>? data) => data?['abos'] == 'notice';

/// বার্তার `data` থেকে অ্যাপের ঠিকানা — সার্ভার পাঠায় `{open: tracking, kind, id}` ([[TrackingNotices]])।
/// ⓘ অচেনা কিছু হলে null — অ্যাপ যেখানে ছিল সেখানেই খোলে।
String? destinationOf(Map<String, dynamic>? data) {
  if (data == null) return null;
  return switch (data['open']) {
    'tracking' => '/home/tracking',
    // ⭐ বার্তা ও কল — রাউটারের পথ নয়, `messenger:` ([MessengerPaths]); main.dart কলের ভাগে দেয়
    'messenger' => MessengerPaths.fromData(data),
    // ⭐ সাধারণ নোটিফিকেশন (MobilePushChannel) — সেটাই খোলা; আগে চাপলে কিছুই হত না
    'notification' => switch (data['id']) {
        final String id when id.isNotEmpty => '/home/notifications?open=${Uri.encodeQueryComponent(id)}',
        _ => '/home/notifications',
      },
    _ => null,
  };
}

/// push থেকে সার্ভারে ("পৌঁছেছে") — পরীক্ষা বদলায়
@visibleForTesting
MessengerApi Function() messengerApiForPush = () => const ServerMessengerApi();

/// নোটিফিকেশনের যা লাগে — পরীক্ষায় নকল
abstract interface class MessengerAlertSink {
  Future<void> showCall(MessengerEvent e, {String caller});

  Future<void> cancelCall(String? callId);

  Future<void> showChat(MessengerEvent e, {String? title, String? body});
}

class _RealSink implements MessengerAlertSink {
  const _RealSink();

  @override
  Future<void> showCall(MessengerEvent e, {String caller = ''}) => MessengerAlerts.instance.showCall(e, caller: caller);

  @override
  Future<void> cancelCall(String? callId) => MessengerAlerts.instance.cancelCall(callId);

  @override
  Future<void> showChat(MessengerEvent e, {String? title, String? body}) => MessengerAlerts.instance.showChat(e, title: title, body: body);
}

/// ⭐ বার্তা ও কলের push — সামনে ([foreground]) বা পেছনে/বন্ধ অবস্থায়, একই সিদ্ধান্ত (সার্ভারের `data`: open=messenger,
/// type chat|call|call_end, thread_id, message_id, call_id, video, from, call_end-এ status)।
///
/// <ul>
///   <li>chat → "পৌঁছেছে" সার্ভারে (চুপচাপ); সামনে: নিজের নোটিফিকেশন (সেই কথোপকথন খোলা থাকলে নয়); পেছনে: সার্ভারের লেখা
///       না থাকলে তবেই (থাকলে Android নিজেই দেখায়)। চাপলে সেই কথোপকথন।</li>
///   <li>call → ফোন রিং করে: পূর্ণ-পর্দা কলের নোটিফিকেশন, রিংটোন, ধরুন/কেটে দিন; সামনে আসা কলের পাতাও খোলে ([open];
///       অ্যাপ-তালা থাকলে খোলার পরে)।</li>
///   <li>call_end (answered · declined · missed · ended) → এই ফোনের রিং থামে; খোলা কলের পাতা নিজে শোনে
///       ([MessengerPush.events]) — যে ফোনে কল চলছে সে answered-এ কিছু করে না।</li>
/// </ul>
/// ⛔ কখনো ছোড়ে না।
Future<void> handleMessengerPush(
  MessengerEvent e, {
  required bool foreground,
  bool hasNotification = false,
  String? title,
  String? body,
  MessengerApi? api,
  MessengerAlertSink alerts = const _RealSink(),
  void Function(String path)? open,
}) async {
  try {
    if (foreground) MessengerPush.instance.emit(e);
    switch (e.type) {
      case 'chat':
        final thread = e.threadId, message = e.messageId;
        if (api != null && thread != null && thread.isNotEmpty && message != null && message.isNotEmpty) {
          // ⓘ ছুড়ে দেওয়া — না গেলেও push থামে না
          unawaited(api.delivered({thread: message}).timeout(const Duration(seconds: 10)).catchError((Object _) {}));
        }
        if (foreground) {
          if (MessengerPush.instance.visibleThread.value != thread) await alerts.showChat(e, title: title, body: body);
        } else if (!hasNotification) {
          await alerts.showChat(e, title: title, body: body);
        }
      // ⓘ সার্ভারের "মিসড কল — নাম" (type call, status missed, লেখাসহ) — রিং নয়; বাজা থামে, বার্তাটা সাধারণ নোটিশের পথে
      case 'call' when isMissedCall(e):
        await alerts.cancelCall(e.callId);
      case 'call':
        final named = (title ?? '').trim();
        await alerts.showCall(e, caller: named.isNotEmpty ? named : callerNameOf(e));
        if (foreground) (open ?? LocalAlerts.instance.open)(MessengerPaths.call(e));
      case 'call_end':
        await alerts.cancelCall(e.callId);
    }
  } catch (err) {
    debugPrint('ABOS messenger: push not handled ($err)');
  }
}

/// চ্যাট আর আসা কলের নোটিফিকেশন [handleMessengerPush] নিজে দেখায়; বাকি ধরন (call_end, মিসড কল …) সাধারণ নোটিশের পথে
bool messengerShowsItself(MessengerEvent e) => e.type == 'chat' || (e.type == 'call' && !isMissedCall(e));

/// সার্ভারের মিসড-কলের বার্তা (CallService::pushMissed): type `call` কিন্তু status `missed`
bool isMissedCall(MessengerEvent e) => e.type == 'call' && e.status == 'missed';

/// কলের নোটিফিকেশনের নাম — সার্ভার নাম দিলে সেটা; `from` কেবল সংখ্যা (user id) হলে খালি (তখন "ABOS ভয়েস কল আসছে…")
String callerNameOf(MessengerEvent e) {
  final named = e.data['from_name'] ?? e.data['name'] ?? '';
  if (named.trim().isNotEmpty) return named.trim();
  final from = e.from ?? '';
  return RegExp(r'^\d+$').hasMatch(from) ? '' : from;
}
