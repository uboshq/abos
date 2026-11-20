import 'dart:async';
import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/once_key.dart';
import '../printing/paper_files.dart';
import '../privacy/app_pin.dart';
import '../push/push_service.dart';
import '../privacy/phone_privacy.dart';
import '../api_client/api_client.dart';
import '../config/app_config.dart';
import '../locale/app_locale.dart';
import '../api_client/network_errors.dart';
import '../sync_engine/reference_cache.dart';
import '../sync_engine/reference_sync.dart';
import '../sync_engine/sync_engine.dart';
import '../launcher_widgets/hub/widget_hub.dart';
import '../launcher_widgets/hub/widget_hub_task.dart';
import '../launcher_widgets/launcher_widget_refresh.dart';
import 'auth_exceptions.dart';
import 'auth_state.dart';
import 'auth_user.dart';
import 'session_repository.dart';
import 'token_storage.dart';
import '../web_inside/web_inside.dart';

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
    // ⛔ ফোনের নিরাপদ ভাণ্ডার এক মুহূর্ত সাড়া না দিলে (ফোন সবে চালু, keystore ব্যস্ত) আগে শুরুটাই ভাঙত, বা প্রোফাইল "নেই"
    // পড়ে পুরো সেশন মুছে মানুষকে বের করে দিত (মালিক, ২০ নভেম্বর ২০২৬: লগআউট না চাপলে বের নয়)। এখন কয়েকবার চেষ্টা;
    // তবু না পড়া গেলে টোকেন রেখে কেবল এবারের জন্য লগইনের পর্দা — পরের খোলায় সেশন আবার ফেরে।
    String? refreshToken;
    AuthUser? savedUser;
    var readable = false;
    for (var attempt = 0; attempt < 3 && !readable; attempt++) {
      if (attempt > 0) await Future<void>.delayed(const Duration(milliseconds: 400));
      try {
        refreshToken = await TokenStorage.instance.refreshToken();
        savedUser = await SessionRepository.instance.readUserOrThrow();
        readable = true;
      } catch (_) {
        // ⓘ আবার চেষ্টা
      }
    }
    if (!readable) {
      _keptUnreadSession = true;
      state = const AuthState.signedOut();
      return;
    }
    if (refreshToken != null && refreshToken.isNotEmpty && savedUser != null) {
      SyncEngine.instance.actAs(savedUser.id);
      state = AuthState.signedIn(savedUser);
    } else {
      // ⛔ সেশন "সাইন আউট" ছাড়াই শেষ হলে (পটভূমির রিফ্রেশ ব্যর্থ, ৩০ দিনের মেয়াদ, বন্ধ করা মানুষ) কেবল টোকেন মুছত — আগের জনের
      // গ্রাহক, পাওনা, দাম, প্রোফাইল আর ওয়েবের কুকি থেকে যেত, আর পরের জন সেগুলো দেখতেন। সাইন আউটের একই সাফাই এখানেও
      // (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
      await _clearLocalSession();
    }
  }

  /// শুরুতে ভাণ্ডার পড়া যায়নি, তাই আগের সেশন না মুছে রাখা ([[restoreSession]]) — এই অবস্থায় কেউ লগইন করলে আগে
  /// সেটার পুরো সাফাই, যাতে নতুন জন আগের জনের তথ্য না দেখেন
  bool _keptUnreadSession = false;

  Future<void> login({
    required String identifier,
    required String password,
    String? code,
  }) async {
    if (_keptUnreadSession) {
      _keptUnreadSession = false;
      await _clearLocalSession();
    }
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
          // Optional per docs/Contract §১, and only for support — but support
          // is exactly the moment somebody needs it.
          'appVersion': AppConfig.appVersion,
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
        throw AuthFailure(AppLocale.l10n.authIncompleteAnswer);
      }

      await TokenStorage.instance
          .saveTokens(accessToken: accessToken, refreshToken: refreshToken);
      final user = AuthUser.fromJson(userJson);
      await SessionRepository.instance.saveUser(user);
      // ⛔ অফলাইন সারি এই মানুষের — আগের কারও রেখে যাওয়া সারি তাঁর নামে যায় না ([[SyncEngine.actAs]])
      SyncEngine.instance.actAs(user.id);
      state = AuthState.signedIn(user);
      unawaited(SyncEngine.instance.flushAll());
      // The home-screen widgets, now that there is a session to fill them
      // from. Not awaited: a launcher tile must not hold up a sign-in.
      unawaited(LauncherWidgetRefresh.refresh());
      // ⭐ নতুন উইজেটগুলো আর তাদের ৩০ মিনিটের পেছনের কাজ ([WidgetHub], [WidgetHubTask])
      unawaited(WidgetHub.refresh());
      unawaited(WidgetHubTask.start());
    } on DioException catch (error) {
      final status = error.response?.statusCode;
      if (status == 409) {
        final body = error.response?.data;
        final codeWasWrong =
            body is Map && body['codeWasWrong'] == true;
        throw MfaRequiredException(codeWasWrong: codeWasWrong);
      }
      throw AuthFailure(errorMessageFor(error,
          fallback: AppLocale.l10n.authSignInFailed));
    }
  }

  /// Whether somebody is signed in right now. For callers outside the
  /// provider tree (main() before runApp), which may not read [state].
  bool get isSignedIn => state.status == AuthStatus.signedIn;

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

  /// ⭐ এই ফোনটাকেই "আমার ফোনগুলো" থেকে বের করা হলো (a6, ২০ অক্টোবর ২০২৬) — সার্ভার টোকেন দুটো আগেই মুছে দিয়েছে, তাই
  /// `/auth/logout` আর ডাকা হয় না (মরা টোকেনে ৪০১ → নবায়নের চেষ্টা → আবার ৪০১ — দুটো বাড়তি ডাক আর দুবার মোছা)। বাকিটা
  /// [logout]-এর হুবহু একই স্থানীয় মোছা।
  Future<void> signOutHere() => _clearLocalSession();

  void _handleSessionExpired() {
    // The refresh token itself was rejected (stolen/rotated token reuse, or
    // the 30-day life ran out) — ApiClient already cleared the tokens; this
    // clears the rest of what a session left behind and drops to the login
    // screen the same way a manual sign-out does.
    _clearLocalSession();
  }

  Future<void> _clearLocalSession() async {
    SyncEngine.instance.actAs(null);
    await TokenStorage.instance.clear();
    await SessionRepository.instance.clear();
    // A tenant-boundary rule, not tidiness — see ReferenceCache.clearAll's
    // own doc comment: the next person on this device may be a different
    // company.
    await ReferenceCache.instance.clearAll();
    // Same boundary: the reason the last pull failed belongs to the session
    // that failed it. Left behind, it puts the previous account's 403 on the
    // next person'''s first empty screen.
    ReferenceSync.forgetLastFailure();
    // And the same boundary on the launcher: a home screen must not go on
    // showing one person's cash in hand and approval queue after they have
    // signed out, possibly to somebody at a different company.
    unawaited(LauncherWidgetRefresh.clear());
    // ⛔ নতুন ২১টা উইজেটও — নথি মোছা, রিসিভার বন্ধ, পেছনের কাজ বন্ধ ([WidgetHub.clear])
    unawaited(WidgetHub.clear());
    unawaited(WidgetHubTask.stop());
    // ⭐ অ্যাপের ভিতরের ওয়েব-জানালার সেশনও — পরের জন আগের জনের ওয়েবে নয় ([WebInsideSession])
    unawaited(WebInsideSession.clear());
    // ⛔ পর্দা আবার আড়াল — আগের কোম্পানি আড়াল বন্ধ রাখলে পরের জন `/me` আসা পর্যন্ত স্ক্রিনশট-যোগ্য পর্দায় থাকতেন
    // (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬); অ্যাপ শুরুর মতোই আড়ালের দিকে
    unawaited(PhonePrivacy.secure(true));
    unawaited(PushService.instance.forget());
    // ⛔ নামানো কাগজ — আগের জনের বিল-চালান পরের জনের ফোনে থাকে না (পূর্ণ নিরীক্ষা §৯ ⚠️১৩, [[PaperFiles]])
    unawaited(PaperFiles.wipe());
    // ⛔ একবারের চাবিও — পরের জনের কাজ আগের জনের চাবিতে নয় ([[OnceKey.forgetAll]])
    OnceKey.forgetAll();
    // ⛔ অ্যাপের PIN-ও — পরের জন নিজেরটা বসান (পূর্ণ নিরীক্ষা §৯ ⚠️১২, [[AppPin]]); অপেক্ষা করে, যাতে নতুন ঢোকা আগেরটা না দেখে
    try {
      await AppPin().clear();
    } catch (_) {
      // ⓘ নিরাপদ ভাণ্ডার নেই (পরীক্ষা) — মোছার কিছু নেই
    }
    state = const AuthState.signedOut();
  }
}
