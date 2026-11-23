import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:home_widget/home_widget.dart';

import '../../api_client/network_errors.dart';
import '../../api_client/etag_cache.dart';
import '../../auth/token_storage.dart';
import '../../privacy/phone_privacy.dart';
import '../../profile/face_cache.dart';
import '../kinds/all_kinds.dart';
import '../../theme/theme_choice.dart';
import '../../locale/app_locale.dart';
import '../../locale/locale_choice.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'widget_summary_api.dart';

/// ⭐ উইজেটের কেন্দ্র — সার্ভারের এক সারাংশ থেকে সব উইজেটের নথি, আর কোন উইজেট এই ফোনে চালু (পরিকল্পনা ৪, অংশ ৯)।
///
/// <p>এক গোল ([refresh]): সারাংশ আনা → প্রতিটা [LauncherWidgetKind]-এর নথি `doc_<key>`-এ, আর তার Android রিসিভার চালু;
/// সার্ভার যে চাবি দেয়নি তার নথি মোছা আর রিসিভার **বন্ধ** — ⛔ যে উইজেট যাঁর নয়, তাঁর উইজেট-তালিকায় সেটা থাকেই না।
/// ফোনের নিজের উইজেট ([LauncherWidgetKind.localSummary]) সার্ভারের চাবি খোঁজে না।
///
/// <p>⛔ কখনো ছোড়ে না — উইজেট একটা সৌজন্য, সাইন-ইন বা সিঙ্ক ভাঙবে না ([LauncherWidgetStore]-এর মতোই)।
/// ⓘ নেট নেই → যা আছে থাকে (নথির নিজের "কখনকার হিসাব" পুরনো বলে ধূসর হয়); দরজা বন্ধ (৪০৩) বা পুরনো সার্ভার (৪০৪) →
/// কোনো সার্ভার-উইজেট নয়।
class WidgetHub {
  const WidgetHub._();

  static const _channel = MethodChannel('abos/launcher_widgets');

  /// গোপন অঙ্কের সুইচ — Kotlin আঁকিয়ে পড়ে: "1" = লুকাও
  static const amountsHiddenKey = 'widget_amounts_hidden';

  /// মানুষের আলো/অন্ধকার বাছাই — উইজেটও একই রঙে ([ThemeChoices]; "phone", "light", "dark")
  static const themeKey = 'widget_theme';

  /// ⭐ মানুষের ভাষা — "bn" বা "en" ([LocaleChoices]; 0.4.43); Kotlin আঁকিয়ে নিজের লেখা (যেমন "কখনকার হিসাব") এতে বাছে
  static const localeKey = 'widget_locale';

  static String docKey(String key) => 'doc_$key';

  /// `sales_today` → `com.abos.abos_mobile.widgets.SalesTodayWidgetReceiver`
  static String receiverOf(String key) {
    final camel = key.split('_').where((p) => p.isNotEmpty).map((p) => p[0].toUpperCase() + p.substring(1)).join();
    return 'com.abos.abos_mobile.widgets.${camel}WidgetReceiver';
  }

  // ── পরীক্ষার পথ — আসলগুলো home_widget আর "abos/launcher_widgets" চ্যানেল ──

  @visibleForTesting
  static Future<WidgetSummary> Function() fetch = WidgetSummaryApi.fetch;

  @visibleForTesting
  static List<LauncherWidgetKind> Function() kinds = () => allKinds;

  /// null = মোছা
  @visibleForTesting
  static Future<void> Function(String id, String? value) save = _save;

  @visibleForTesting
  static Future<void> Function(String key) redraw = _redraw;

  @visibleForTesting
  static Future<void> Function(String key, bool on) setEnabled = _setEnabled;

  @visibleForTesting
  static Future<void> Function() refreshAll = _refreshAll;

  @visibleForTesting
  static DateTime Function() clock = DateTime.now;

  /// সেশন সত্যিই শেষ কি না — ApiClient নবায়নে ব্যর্থ হলে টোকেন মোছে; নেট না থাকায় ব্যর্থ হলে রাখে
  @visibleForTesting
  static Future<bool> Function() sessionGone = _sessionGone;

  /// গোপন লেখার জায়গায় — Kotlin আঁকিয়ের `DocWidget.HIDDEN`-এর হুবহু
  static const hiddenText = '••••';

  /// ⭐ নথি যেমন ফাইলে যায় — মালিকের সুইচ বন্ধ থাকলে প্রতিটা গোপন লেখা ([WidgetText.secret]) "••••", আর গোপন দাগের দৈর্ঘ্য ০
  /// (উইজেটের নিরাপত্তা-পর্যালোচনা ১, cb, ২০ অক্টোবর ২০২৬; MASVS-STORAGE-2)। ⛔ আগে আসল অঙ্ক ফাইলে থাকত, লুকানো হত কেবল
  /// আঁকার সময় — রুট-করা ফোন বা ফরেনসিক কপি হাতের নগদ আর লাভ পড়তে পারত। ⓘ সুইচ চালু হলে পরের গোলে আসল অঙ্ক।
  @visibleForTesting
  static String encodeFor(WidgetDoc doc, {required bool showAmounts}) =>
      showAmounts ? doc.encode() : jsonEncode(_masked(doc.toJson()));

  static Object? _masked(Object? node) {
    if (node is List) return [for (final item in node) _masked(item)];
    if (node is! Map) return node;

    final out = <String, Object?>{for (final e in node.entries) '${e.key}': _masked(e.value)};
    if (out['s'] == true && out.containsKey('t')) out['t'] = hiddenText;

    // ⓘ দাগের দৈর্ঘ্যও একটা অঙ্ক — লেখা বা মান গোপন হলে দাগ শূন্য (আঁকিয়েও তা-ই করে)
    bool secret(Object? text) => text is Map && text['s'] == true;
    final original = node;
    if (out.containsKey('fraction') && (secret(original['label']) || secret(original['value']))) out['fraction'] = 0.0;

    return out;
  }

  @visibleForTesting
  static void resetForTest() {
    fetch = WidgetSummaryApi.fetch;
    kinds = () => allKinds;
    save = _save;
    redraw = _redraw;
    setEnabled = _setEnabled;
    refreshAll = _refreshAll;
    clock = DateTime.now;
    sessionGone = _sessionGone;
    _running = null;
    _clearing = null;
    _generation = 0;
    WidgetSummaryApi.forget();
  }

  static Future<void>? _running;

  /// ⛔ [clear] বাড়ায় — তার আগে শুরু হওয়া গোল আর কিছু লেখে না (বেরিয়ে যাওয়া মানুষের নথি পরে ফিরে আসত)
  static int _generation = 0;

  /// এক গোল; একটা চলতে থাকলে সেটাই (সিঙ্ক আর নীরব ডাক একসাথে এলে দুবার নয়)
  static Future<void> refresh() {
    final running = _running;
    if (running != null) return running;
    late final Future<void> round;
    round = _round().whenComplete(() {
      if (identical(_running, round)) _running = null;
    });
    return _running = round;
  }

  static Future<void> _round() async {
    // ⛔ মোছা চলতে থাকলে আগে সেটা শেষ — নইলে মোছা আর নতুন লেখা মিশে যেত
    final clearing = _clearing;
    if (clearing != null) await clearing;
    final generation = _generation;
    bool current() => generation == _generation;
    try {
      // ⭐ ভাষা আগে — সারাংশের ডাকের `Accept-Language` আর প্রতিটা নথির লেখা [AppLocale.current] মানে; পেছনের isolate-এও
      // ভাণ্ডার থেকে পড়া, যাতে ভুল করে বাংলা ধরে না নেয়
      await LocaleChoices.instance.load();
      WidgetSummary? server;
      try {
        server = await fetch();
      } catch (error) {
        /*
         * ⛔ দূর থেকে বের করে দেওয়া — সব নথি মোছা (উইজেটের নিরাপত্তা-পর্যালোচনা ৬খ, cb, ২০ অক্টোবর ২০২৬; MASVS-AUTH-2)।
         * ⓘ পেছনের গোলে AuthController নেই, তাই সাইন-আউটের [clear] চলে না; আগে ৪০১-কে "নেট নেই" ধরা হত আর পুরনো অঙ্ক থেকে যেত।
         * ⓘ কেবল যখন সেশন সত্যিই শেষ: ApiClient-এর নবায়নের পরেও ৪০১, আর টোকেন মুছে গেছে ([sessionGone])। নেট না থাকায়
         * নবায়ন না হলে টোকেন থাকে — তখন মোছা নয়, পুরনো নথি "কখনকার হিসাব" নিয়ে থাকে।
         */
        if (error is DioException && error.response?.statusCode == 401 && await sessionGone()) {
          if (current()) await clear();
          return;
        }
        server = _shut(error) ? WidgetSummary.none(clock()) : null;
        if (server == null) debugPrint('ABOS widget hub: summary not fetched ($error)');
      }
      if (!current()) return;

      // ⭐ আগে সুইচ, তারপর নথি — আঁকিয়ে প্রথম আঁকাতেই ঠিক লুকায়
      final show = await PhonePrivacy.widgetAmounts();
      if (!current()) return;
      await _quiet(() => save(amountsHiddenKey, show ? '0' : '1'), 'amounts');
      // ⓘ পেছনের isolate-এ বাছাই আগে পড়া হয়নি — প্রতিবার ভাণ্ডার থেকে, যাতে ভুল করে "ফোনের মতো" না লেখে
      await ThemeChoices.instance.load();
      if (!current()) return;
      await _quiet(() => save(themeKey, ThemeChoices.instance.current.value.name), 'theme');
      await _quiet(() => save(localeKey, AppLocale.code), 'locale');

      for (final kind in kinds()) {
        if (!current()) return;
        await _one(kind, server, current, showAmounts: show);
      }
      if (!current()) return;
      await _quiet(refreshAll, 'refreshAll');
    } catch (error) {
      debugPrint('ABOS widget hub: refresh failed ($error)');
    }
  }

  static Future<void> _one(LauncherWidgetKind kind, WidgetSummary? server, bool Function() current,
      {required bool showAmounts}) async {
    final key = kind.key;
    try {
      final local = kind.localSummary();
      final Map<String, dynamic>? summary;
      final DateTime asOf;
      if (local != null) {
        summary = local;
        asOf = clock();
      } else {
        // ⓘ ফোনের-নিজের উইজেট এই মুহূর্তে কিছু জানে না — যেমন ছিল তেমন; সার্ভারে খুঁজে বন্ধ করা নয়
        if (kind.answersLocally) return;
        // নেট নেই — এই উইজেট যেমন ছিল তেমন
        if (server == null) return;
        summary = server.widgets[key];
        asOf = server.asOf;
      }
      if (summary == null) {
        await _quiet(() => save(docKey(key), null), key);
        await _quiet(() => setEnabled(key, false), key);
        return;
      }
      final doc = encodeFor(kind.build(summary, asOf: asOf), showAmounts: showAmounts);
      if (!current()) return;
      await _quiet(() => save(docKey(key), doc), key);
      await _quiet(() => setEnabled(key, true), key);
      await _quiet(() => redraw(key), key);
    } catch (error) {
      // ⓘ একটা উইজেটের ভুলে বাকিগুলো থামে না; এটার পুরনো নথি থাকে
      debugPrint('ABOS widget hub: $key not built ($error)');
    }
  }

  /// ⭐ সাইন-আউট বা কোম্পানি বদল — সব ২১টা নথি মোছা, সব রিসিভার বন্ধ; চলতি গোল আর কিছু লেখে না
  static Future<void> clear() {
    _generation++;
    _running = null;
    WidgetSummaryApi.forget();
    // ⛔ the last person's stored ETag bodies and the short in-memory answers too ([EtagCache.forget])
    final etags = EtagCache.forget();
    late final Future<void> wipe;
    wipe = Future.wait([_wipe(), etags]).then((_) {}).whenComplete(() {
      if (identical(_clearing, wipe)) _clearing = null;
    });
    return _clearing = wipe;
  }

  static Future<void>? _clearing;

  static Future<void> _wipe() async {
    // ⛔ মুখের জমাও — বেরোনো, কোম্পানি বদল আর দূর থেকে বের করা তিনটাই এখানে আসে (fe, ২০ অক্টোবর ২০২৬; [FaceCache])
    await FaceCache.instance.wipe();
    try {
      for (final key in widgetKeys) {
        await _quiet(() => save(docKey(key), null), key);
        await _quiet(() => setEnabled(key, false), key);
      }
      await _quiet(refreshAll, 'refreshAll');
    } catch (error) {
      debugPrint('ABOS widget hub: clear failed ($error)');
    }
  }

  /// দরজা এই মানুষের জন্য বন্ধ — কোনো সার্ভার-উইজেট নয়। ⓘ ৪০১ এখানে নয়: সেশন সত্যিই শেষ হলে [_round] নিজেই [clear] ডাকে
  /// (সামনে সাইন-আউটও ডাকে); নেট না থাকায় নবায়ন না হওয়া ৪০১ কিছুই মোছে না
  static bool _shut(Object error) {
    if (isNetworkError(error)) return false;
    if (isRouteAbsent(error)) return true;
    return error is DioException && error.response?.statusCode == 403;
  }

  static Future<void> _quiet(Future<void> Function() call, String what) async {
    try {
      await call();
    } catch (error) {
      debugPrint('ABOS widget hub: $what not written ($error)');
    }
  }

  static Future<bool> _sessionGone() async {
    final refresh = await TokenStorage.instance.refreshToken();
    return refresh == null || refresh.isEmpty;
  }

  static Future<void> _save(String id, String? value) async {
    await HomeWidget.saveWidgetData<String>(id, value);
  }

  static Future<void> _redraw(String key) async {
    await HomeWidget.updateWidget(qualifiedAndroidName: receiverOf(key));
  }

  static Future<void> _setEnabled(String key, bool on) =>
      _channel.invokeMethod<void>('setEnabled', {'key': key, 'on': on});

  static Future<void> _refreshAll() => _channel.invokeMethod<void>('refreshAll');
}
