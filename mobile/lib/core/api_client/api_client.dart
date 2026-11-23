import 'etag_cache.dart';
import 'network_errors.dart';
import 'once_key.dart';
import 'pinned_trust.dart';
import 'retired_build.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../auth/token_storage.dart';
import '../config/app_config.dart';
import '../locale/app_locale.dart';

/// One Dio instance for the whole app — every feature's data layer imports
/// this, never creates its own client. A second client would mean a second
/// place the Authorization header is attached, and one of them would
/// eventually be the one that forgets.
///
/// What it does on every request:
///  - attaches the Bearer access token from the keystore;
///  - on a 401, refreshes the token once and replays the request, so a
///    short-lived access token never surfaces as an error to the user;
///  - if the server definitely refuses the refresh (its own 401/403), clears
///    the session and fires [onSessionExpired] so the app returns to the
///    login screen. Any other failure (no network, 5xx, 429, 422, an odd
///    answer, a keystore hiccup) keeps the tokens — see [_isDefiniteRefusal].
///
/// <p><b>What the server must provide for this to work</b> — see
/// docs/Contract — মোবাইল সিঙ্ক প্রোটোকল.md:
///   POST /auth/refresh   {refreshToken}  →  {accessToken, refreshToken}
/// The mechanism behind it (Sanctum, JWT, anything else) is the server's
/// business; this file only needs a bearer token and a way to renew it.
class ApiClient {
  ApiClient._();

  /// Request-scoped flags, in `Options.extra`.
  static const _skipAuthRefreshKey = 'skipAuthRefresh';
  static const _retriedKey = 'abosRetried';

  /// Marks login/refresh/logout calls: a 401 there is a real auth failure and
  /// must not be retried. Use as
  /// `options: Options(extra: ApiClient.skipAuthRefresh)`.
  static const Map<String, dynamic> skipAuthRefresh = {
    _skipAuthRefreshKey: true,
  };

  /// Set once at startup by the auth layer. Kept as a plain callback so this
  /// file stays free of any state-management library — the HTTP client must
  /// not depend on one.
  static void Function()? onSessionExpired;

  static final Dio dio = buildFor(AppConfig.apiBaseUrl);

  /// ⓘ ঠিকানা ধরে — পরীক্ষা দেখে লাইভে pin বসে আর LAN-এ বসে না ([[PinnedTrust]])
  @visibleForTesting
  static Dio buildFor(String baseUrl) {
    final client = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: AppConfig.connectTimeout,
        receiveTimeout: AppConfig.receiveTimeout,
        contentType: Headers.jsonContentType,
        // 401 must reach the error interceptor rather than being pre-judged
        // as a success.
        validateStatus: (status) =>
            status != null && status >= 200 && status < 300,
      ),
    );

    // ⭐ লাইভে কেবল তিন মূল সার্টিফিকেট — pinning (অ্যাপের নিরাপত্তা, MASVS-NETWORK-2, ১৯ অক্টোবর ২০২৬; [[PinnedTrust]]);
    //   পরীক্ষার LAN/emulator ঠিকানায় নয়। APK নামানোও এই ক্লায়েন্টে, তাই সেটাও।
    if (PinnedTrust.pinsFor(Uri.parse(baseUrl))) {
      client.httpClientAdapter = PinnedTrust.adapter();
    }

    client.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          // Who is calling, on every request. SyncController registers the
          // device from these two headers on each pull and push; without them
          // the registry holds a row that cannot say what it is a row about.
          options.headers['X-App-Version'] = AppConfig.appVersion;
          // ⭐ বিল্ডের ছোট কোড — সার্ভার এর সাথে সর্বনিম্ন মেলায়, কম হলে লেখায় ৪২৬ (পূর্ণ নিরীক্ষা §৯ ⚠️৯, [[RetiredBuild]])
          options.headers['X-App-Code'] = '${AppConfig.appVersionCode}';
          options.headers['X-Platform'] = defaultTargetPlatform == TargetPlatform.iOS
              ? 'ios'
              : 'android';
          // ⭐ মানুষের ভাষা, প্রতিটা ডাকে — সার্ভারের বার্তা, ভুলের লেখা আর কাগজ একই ভাষায় (0.4.43; [[AppLocale.current]])
          options.headers['Accept-Language'] = AppLocale.code;

          if (options.extra[_skipAuthRefreshKey] != true) {
            final token = TokenStorage.instance.cachedAccessToken ??
                await TokenStorage.instance.accessToken();
            if (token != null && token.isNotEmpty) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          }
          // ⭐ একটা কাজ একবারই — পর্দা [OnceKey.send] দিয়ে পাঠালে এই জোনের প্রতিটা লেখায় চাবি (অডিট ফোন ⚠️১২)
          final once = OnceKey.current;
          // ⭐ সব লেখায় — POST, PUT, PATCH, DELETE (পূর্ণ নিরীক্ষা §৯ ⚠️৮, ১৮ অক্টোবর ২০২৬); আগে কেবল POST
          if (once != null && const {'POST', 'PUT', 'PATCH', 'DELETE'}.contains(options.method.toUpperCase())) {
            options.headers['Idempotency-Key'] = once;
          }
          handler.next(options);
        },
        onResponse: (response, handler) {
          // ⛔ The firewall's splash page can come back 2xx — HTML where a
          // screen expects JSON. Turned into the same network failure as its
          // 403, so nobody reads it as data ([[FirewallBlocked]]).
          if (looksBlockedByFirewall(response)) {
            return handler.reject(
                firewallBlockedError(response.requestOptions, response.statusCode));
          }
          handler.next(response);
        },
        onError: (DioException error, handler) async {
          final options = error.requestOptions;

          // ⛔ The host firewall (Imunify360) turned this network away — a
          // network failure with no response, never "you may not", never the
          // raw body ([[FirewallBlocked]], 20 Nov 2026).
          if (error.error is! FirewallBlocked && looksBlockedByFirewall(error.response)) {
            return handler.next(
                firewallBlockedError(options, error.response?.statusCode));
          }

          // ⛔ এই বিল্ড বন্ধ — আপডেটের দেয়াল তোলা ([[RetiredBuild]])
          if (error.response?.statusCode == 426) {
            RetiredBuild.noteRefusal();
          }

          final canRetry = error.response?.statusCode == 401 &&
              options.extra[_skipAuthRefreshKey] != true &&
              options.extra[_retriedKey] != true;

          if (!canRetry) {
            return handler.next(error);
          }

          final renewal = await _renew();
          final String? token = renewal.token;
          if (token == null && !renewal.refused) {
            // ⛔ নেট নেই, সার্ভার ব্যস্ত (৫xx, ৪২৯), ৪২২, অচেনা উত্তর, ভাণ্ডারের ভুল — সেশন শেষ নয়; টোকেন থাকে, মানুষ বের
            // হন না, অফলাইন তালিকাও মোছে না (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬; মালিক, ২০ নভেম্বর ২০২৬)। পরের ডাকে আবার নবায়ন।
            return handler.next(error);
          }
          if (token == null) {
            // ⛔ সার্ভার নিশ্চিত "না" বলেছে ([[_isDefiniteRefusal]]) — বের হন, টোকেন বাতিল; সেশন শেষ।
            await TokenStorage.instance.clear();
            onSessionExpired?.call();
            return handler.next(error);
          }

          try {
            // fetch() re-enters this interceptor chain, hence the retried flag.
            options.extra[_retriedKey] = true;
            options.headers['Authorization'] = 'Bearer $token';
            final response = await dio.fetch<dynamic>(options);
            return handler.resolve(response);
          } on DioException catch (retryError) {
            return handler.next(retryError);
          }
        },
      ),
    );

    // ⭐ ETag for the busiest reads — after the auth interceptor, so a 304 is served from the stored body only
    // once the token and firewall checks have had their say ([EtagInterceptor]; polling cut, 23 Nov 2026)
    client.interceptors.add(EtagInterceptor());

    return client;
  }

  /// Single-flight: several requests failing 401 at once must trigger exactly
  /// one refresh. If the server rotates the refresh token on every call —
  /// which it should, and which the contract document requires — parallel
  /// refreshes would invalidate each other and sign the user out for no
  /// reason at all.
  ///
  /// <p>⭐ যে অপেক্ষা করে সে পুরো ফলটাই পায় — টোকেন, আর না পেলে "সার্ভার না বলেছে" কি না। আগে একটা static পতাকা
  /// (`_lastRefreshWasTransient`) পরের ডাক শুরুতেই মুছে দিত, তাই একসাথে অপেক্ষারত দ্বিতীয় অনুরোধ নেটের ভুলকেও "না" পড়ে
  /// মানুষকে বের করে দিতে পারত (মালিক, ২০ নভেম্বর ২০২৬: "লগআউট না চাপলে বের নয়")।
  static Future<_Renewal>? _inFlightRefresh;

  /// শেষ নবায়নে যে refresh টোকেন পাঠানো হয়েছিল — ব্যর্থ হলে মিলিয়ে দেখার জন্য ([[_someoneElseRefreshed]])
  static String? _sentRefresh;

  /// নবায়ন হারলে অন্য isolate-এর নতুন জোড়ার জন্য কতক্ষণ অপেক্ষা ([[_someoneElseRefreshed]])
  @visibleForTesting
  static Duration raceWait = const Duration(milliseconds: 1500);

  /// ⭐ একটাই নবায়ন একসাথে (single-flight) — যারা এর মধ্যে আসে, তারা একই ফল পায়; ⛔ কখনো ছোড়ে না
  static Future<_Renewal> _renew() {
    final existing = _inFlightRefresh;
    if (existing != null) return existing;
    final started = _renewOnce();
    _inFlightRefresh = started;
    started.whenComplete(() {
      if (identical(_inFlightRefresh, started)) _inFlightRefresh = null;
    });
    return started;
  }

  static Future<_Renewal> _renewOnce() async {
    try {
      return _Renewal.got(await _performRefresh());
    } catch (error, stackTrace) {
      debugPrint('ABOS token refresh failed: $error');
      if (!_isDefiniteRefusal(error)) {
        // ⛔ নেট, ব্যস্ত সার্ভার, ৪২২/৪০৪, অচেনা উত্তর, ফোনের ভাণ্ডারের ভুল — সেশন থাকে
        return const _Renewal.transient();
      }
      assert(() {
        debugPrintStack(stackTrace: stackTrace);
        return true;
      }());
      try {
        final access = await _someoneElseRefreshed();
        return access == null ? const _Renewal.refused() : _Renewal.got(access);
      } catch (_) {
        // ⓘ ভাণ্ডার পড়া গেল না — নিশ্চিত নয়, তাই সেশন থাকে
        return const _Renewal.transient();
      }
    }
  }

  /// ⛔ কেবল এই দুটোই সেশন শেষ করে (মালিক, ২০ নভেম্বর ২০২৬):
  ///  - নবায়নের দরজার নিজের ৪০১ (টোকেন বাতিল — লগআউট, ওয়েবে ফোন সরানো, পাসওয়ার্ড বদল, মানুষ বন্ধ) বা ৪০৩ (দুই ধাপ
  ///    বসানো নেই; ফায়ারওয়ালের ৪০৩ আগেই নেটের ভুল বানানো, [[FirewallBlocked]]);
  ///  - ফোনে refresh টোকেনই নেই — নবায়নের কিছু নেই।
  /// বাকি সব (নেট, ৫xx, ৪২৯, ৪২২, ৪০৪, অচেনা/অসম্পূর্ণ উত্তর, ভাণ্ডারের ভুল) সাময়িক — টোকেন থাকে।
  static bool _isDefiniteRefusal(Object error) {
    if (error is _NoRefreshToken) return true;
    if (error is! DioException || isNetworkError(error) || error.error is FirewallBlocked) return false;
    final status = error.response?.statusCode;
    return status == 401 || status == 403;
  }

  /// ⭐ এখনই নবায়ন — পেছনের গোল ([[backgroundRound]]) ৩ দিনের পুরনো জোড়া আগেভাগে নতুন করে, যাতে ৩০ দিন না খুললেও
  /// মানুষ বের না হন (মালিক, ২০ নভেম্বর ২০২৬)। একই single-flight নবায়ন ([[_renew]]); ফেরে নতুন জোড়া পেল কি না।
  /// ⛔ নেটের বা সার্ভারের সাময়িক ভুলে টোকেন থাকে; কেবল সার্ভারের নিশ্চিত "না"-তে মোছে — ৪০১-এর পথের মতোই।
  static Future<bool> renewNow() async {
    final renewal = await _renew();
    if (renewal.token != null) return true;
    if (renewal.refused) {
      await TokenStorage.instance.clear();
      onSessionExpired?.call();
    }
    return false;
  }

  /// ⭐ নবায়ন ব্যর্থ, কিন্তু এর মধ্যে আরেক isolate (পটভূমির সিঙ্ক) একই টোকেনে নবায়ন করে নতুন জোড়া রেখে গেছে —
  /// সার্ভার একটা টোকেন একবারই মানে (৯ অক্টোবর ২০২৬), তাই দুজনের একজন ৪০১ পায়। তখন storage মুছে মানুষকে বের করে
  /// দেওয়া ভুল হত — জেতা জোড়াটাই মুছে যেত। storage-এর refresh টোকেন পাঠানোটার থেকে আলাদা হলে নতুন access টোকেন
  /// নিয়ে আবার চেষ্টা; একই থাকলে সত্যিই শেষ (null)।
  static Future<String?> _someoneElseRefreshed() async {
    final sent = _sentRefresh;
    var stored = await TokenStorage.instance.refreshToken();
    // ⛔ জেতা isolate দুইটা মান লেখে (access, তারপর refresh) — হারা পক্ষ ঠিক তার আগের মুহূর্তে পড়লে পুরনোটা দেখে মানুষকে বের
    // করে দিত, এমনকি জেতা জোড়াটাও মুছত। একটু অপেক্ষা করে আবার পড়া (ফোন লাইন-ধরে নিরীক্ষা §৭, ১৫ অক্টোবর ২০২৬)।
    if (sent != null && (stored == null || stored.isEmpty || stored == sent)) {
      await Future<void>.delayed(raceWait);
      stored = await TokenStorage.instance.refreshToken();
    }
    if (sent == null || stored == null || stored.isEmpty || stored == sent) return null;
    final access = await TokenStorage.instance.reloadAccessToken();
    return (access == null || access.isEmpty) ? null : access;
  }

  static Future<String> _performRefresh() async {
    // ⓘ আগের নবায়নের পুরনো মান যেন "অন্য কেউ নবায়ন করেছে" যাচাইয়ে না ঢোকে
    _sentRefresh = null;
    final refreshToken = await TokenStorage.instance.refreshToken();
    if (refreshToken == null || refreshToken.isEmpty) {
      throw const _NoRefreshToken();
    }
    _sentRefresh = refreshToken;

    // ⛔ ০.৪.৮ পর্যন্ত টোকেন কেবল body-তে যেত, হেডার ছাড়া, deviceId ছাড়া — সার্ভারের দরজা হেডার পড়ে, তাই প্রতিটা
    // নবায়ন ৪০১ আর ৩০ মিনিট পরে ফোন চুপ (মালিক, ৪ অক্টোবর ২০২৬)। এখন হেডারে, আর deviceId সাথে; body-তেও থাকে,
    // যাতে পুরনো আর নতুন সার্ভার দুটোই মানে।
    final deviceId = await TokenStorage.instance.deviceId();
    final response = await dio.post<Map<String, dynamic>>(
      '/auth/refresh',
      data: {'refreshToken': refreshToken, 'deviceId': deviceId},
      options: Options(extra: skipAuthRefresh, headers: {'Authorization': 'Bearer $refreshToken'}),
    );

    final body = response.data;
    final newAccess = body?['accessToken'] as String?;
    final newRefresh = body?['refreshToken'] as String?;
    if (newAccess == null || newRefresh == null) {
      throw StateError('Refresh response missing tokens');
    }

    await TokenStorage.instance
        .saveTokens(accessToken: newAccess, refreshToken: newRefresh);
    return newAccess;
  }
}

/// একটা নবায়নের ফল — নতুন access টোকেন, অথবা না পাওয়ার কারণ (সার্ভারের নিশ্চিত "না", নাকি সাময়িক)
class _Renewal {
  const _Renewal.got(String this.token) : refused = false;
  const _Renewal.transient()
      : token = null,
        refused = false;
  const _Renewal.refused()
      : token = null,
        refused = true;

  final String? token;

  /// সার্ভার নিশ্চিত "না" বলেছে, বা ফোনে নবায়নের টোকেনই নেই — কেবল তখনই সেশন শেষ
  final bool refused;
}

/// ফোনে refresh টোকেন নেই — নবায়নের কিছু নেই
class _NoRefreshToken implements Exception {
  const _NoRefreshToken();

  @override
  String toString() => 'No refresh token stored';
}
