import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';
import '../api_client/network_errors.dart';
import '../sync_engine/reference_cache.dart';
import 'auth_exceptions.dart';
import 'auth_state.dart';
import 'auth_user.dart';
import 'session_repository.dart';
import 'token_storage.dart';

/// Owns the sign-in lifecycle: restoring a saved session at startup,
/// `/auth/login`, and `/auth/logout`. Screens read [AuthState] through
/// [authStateProvider] and never call `TokenStorage`/`ApiClient` directly for
/// auth — one place decides what "signed in" means, matching how
/// [ApiClient] is the one place a bearer header gets attached.
class AuthController extends StateNotifier<AuthState> {
  AuthController() : super(const AuthState.unknown()) {
    ApiClient.onSessionExpired = _handleSessionExpired;
  }

  /// Called once from main.dart, after the core singletons this depends on
  /// (`TokenStorage`, `SessionRepository`) are ready but before the app shows
  /// anything other than a splash — see main.dart's own ordering comment.
  ///
  /// <p>There is no `/me` endpoint yet (contract §৪), so this cannot ask the
  /// server "who is this" — it can only trust the profile [SessionRepository]
  /// saved at the last successful login. A refresh token with no saved
  /// profile (a state this file itself never produces, but an upgrade from a
  /// build that predates [SessionRepository] could) is treated as signed out
  /// rather than signed in with an empty name, so a screen never has to
  /// handle a user with a blank identity.
  Future<void> restoreSession() async {
    final refreshToken = await TokenStorage.instance.refreshToken();
    final savedUser = await SessionRepository.instance.readUser();
    if (refreshToken != null && refreshToken.isNotEmpty && savedUser != null) {
      state = AuthState.signedIn(savedUser);
    } else {
      state = const AuthState.signedOut();
    }
  }

  Future<void> login({
    required String identifier,
    required String password,
    String? code,
  }) async {
    final deviceId = await TokenStorage.instance.deviceId();
    try {
      final response = await ApiClient.dio.post<Map<String, dynamic>>(
        '/auth/login',
        data: {
          'identifier': identifier,
          'password': password,
          if (code != null && code.isNotEmpty) 'code': code,
          'deviceId': deviceId,
          'platform': Platform.isIOS ? 'ios' : 'android',
        },
        // No token exists to attach yet, and a 401 here (there is none —
        // login answers 422/409 — but a stale Authorization header from a
        // previous session must never ride along on this call) is not a
        // session to refresh.
        options: Options(extra: ApiClient.skipAuthRefresh),
      );

      final body = response.data ?? const {};
      final accessToken = body['accessToken'] as String?;
      final refreshToken = body['refreshToken'] as String?;
      final userJson = body['user'] as Map<String, dynamic>?;
      if (accessToken == null || refreshToken == null || userJson == null) {
        throw const AuthFailure('সার্ভার থেকে অসম্পূর্ণ উত্তর এসেছে। আবার চেষ্টা করুন।');
      }

      await TokenStorage.instance
          .saveTokens(accessToken: accessToken, refreshToken: refreshToken);
      final user = AuthUser.fromJson(userJson);
      await SessionRepository.instance.saveUser(user);
      state = AuthState.signedIn(user);
    } on DioException catch (error) {
      final status = error.response?.statusCode;
      if (status == 409) {
        final body = error.response?.data;
        final codeWasWrong =
            body is Map && body['codeWasWrong'] == true;
        throw MfaRequiredException(codeWasWrong: codeWasWrong);
      }
      throw AuthFailure(errorMessageFor(error,
          fallback: 'ঢোকা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'));
    }
  }

  /// Signs this device out. Best-effort against the server — a phone with no
  /// signal at the moment someone taps "sign out" still has to leave the
  /// session locally, or the button would do nothing on the one occasion it
  /// is most likely to be pressed in a hurry.
  Future<void> logout() async {
    final deviceId = await TokenStorage.instance.deviceId();
    try {
      await ApiClient.dio
          .post<void>('/auth/logout', data: {'deviceId': deviceId});
    } catch (_) {
      // Offline or already-expired token — the local sign-out below still
      // has to happen.
    }
    await _clearLocalSession();
  }

  void _handleSessionExpired() {
    // The refresh token itself was rejected (stolen/rotated token reuse, or
    // the 30-day life ran out) — ApiClient already cleared the tokens; this
    // clears the rest of what a session left behind and drops to the login
    // screen the same way a manual sign-out does.
    _clearLocalSession();
  }

  Future<void> _clearLocalSession() async {
    await TokenStorage.instance.clear();
    await SessionRepository.instance.clear();
    // A tenant-boundary rule, not tidiness — see ReferenceCache.clearAll's
    // own doc comment: the next person on this device may be a different
    // company.
    await ReferenceCache.instance.clearAll();
    state = const AuthState.signedOut();
  }
}
