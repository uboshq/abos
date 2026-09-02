import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../auth/token_storage.dart';
import '../config/app_config.dart';

/// One Dio instance for the whole app — every feature's data layer imports
/// this, never creates its own client. A second client would mean a second
/// place the Authorization header is attached, and one of them would
/// eventually be the one that forgets.
///
/// What it does on every request:
///  - attaches the Bearer access token from the keystore;
///  - on a 401, refreshes the token once and replays the request, so a
///    short-lived access token never surfaces as an error to the user;
///  - if the refresh itself fails, clears the session and fires
///    [onSessionExpired] so the app returns to the login screen.
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

  static final Dio dio = _build();

  static Dio _build() {
    final client = Dio(
      BaseOptions(
        baseUrl: AppConfig.apiBaseUrl,
        connectTimeout: AppConfig.connectTimeout,
        receiveTimeout: AppConfig.receiveTimeout,
        contentType: Headers.jsonContentType,
        // 401 must reach the error interceptor rather than being pre-judged
        // as a success.
        validateStatus: (status) =>
            status != null && status >= 200 && status < 300,
      ),
    );

    client.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          if (options.extra[_skipAuthRefreshKey] != true) {
            final token = TokenStorage.instance.cachedAccessToken ??
                await TokenStorage.instance.accessToken();
            if (token != null && token.isNotEmpty) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          }
          handler.next(options);
        },
        onError: (DioException error, handler) async {
          final options = error.requestOptions;

          final canRetry = error.response?.statusCode == 401 &&
              options.extra[_skipAuthRefreshKey] != true &&
              options.extra[_retriedKey] != true;

          if (!canRetry) {
            return handler.next(error);
          }

          final String? token = await _refreshAccessToken();
          if (token == null) {
            // Refresh token missing, expired or rejected — the session is over.
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

    return client;
  }

  /// Single-flight: several requests failing 401 at once must trigger exactly
  /// one refresh. If the server rotates the refresh token on every call —
  /// which it should, and which the contract document requires — parallel
  /// refreshes would invalidate each other and sign the user out for no
  /// reason at all.
  static Future<String>? _inFlightRefresh;

  static Future<String?> _refreshAccessToken() async {
    final existing = _inFlightRefresh;
    if (existing != null) {
      try {
        return await existing;
      } catch (_) {
        return null;
      }
    }

    final started = _performRefresh();
    _inFlightRefresh = started;
    try {
      return await started;
    } catch (error, stackTrace) {
      debugPrint('ABOS token refresh failed: $error');
      assert(() {
        debugPrintStack(stackTrace: stackTrace);
        return true;
      }());
      return null;
    } finally {
      if (_inFlightRefresh == started) {
        _inFlightRefresh = null;
      }
    }
  }

  static Future<String> _performRefresh() async {
    final refreshToken = await TokenStorage.instance.refreshToken();
    if (refreshToken == null || refreshToken.isEmpty) {
      throw StateError('No refresh token stored');
    }

    final response = await dio.post<Map<String, dynamic>>(
      '/auth/refresh',
      data: {'refreshToken': refreshToken},
      options: Options(extra: skipAuthRefresh),
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
