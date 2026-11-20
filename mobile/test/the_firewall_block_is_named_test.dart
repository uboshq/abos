import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/network_errors.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_exceptions.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/features/auth/login_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⛔ The server's host firewall (Imunify360) turns some home and office WiFi
/// IPs away — a 403 on every call, or its "One moment, please…" splash page.
/// The login screen said "Could not sign in" and the owner thought the app
/// was broken (20 Nov 2026). Now every generic failure names the network and
/// the way round it; a 403 of our own keeps its meaning; the raw body is
/// never shown.
const _bn = 'এই WiFi/নেটওয়ার্ক থেকে সার্ভারে ঢোকা আটকানো আছে — মোবাইল ডেটা দিয়ে চেষ্টা করুন, বা অফিসকে জানান।';
const _en = "This WiFi/network is blocked by the server's firewall — try mobile data, or tell the office.";

const _imunifyJson =
    '{"message": "Access denied by Imunify360 bot-protection. IPs used for automation should be whitelisted"}';
const _splash = '<!DOCTYPE html><html><head><title>One moment, please...</title></head>'
    '<body><form id="wsidchk-form" action="/z0f76a1d14fd21a8fb5fd0d03e0fdc3d3cedae52f" method="get">'
    '<input type="hidden" name="wssplashchk" value="x"></form>One moment, please...</body></html>';

RequestOptions _api(String path) => RequestOptions(baseUrl: 'https://erp.adi.com.bd/api/v1', path: path);

DioException _failure(int status, Object? body, {String type = 'application/json', String path = '/auth/login'}) {
  final options = _api(path);
  return DioException(
    requestOptions: options,
    response: Response(
      requestOptions: options,
      statusCode: status,
      data: body,
      headers: Headers.fromMap({
        Headers.contentTypeHeader: [type],
      }),
    ),
    type: DioExceptionType.badResponse,
  );
}

/// One fixed answer for every call through the real client.
class _Answers implements HttpClientAdapter {
  _Answers(this.status, this.body, this.type);

  final int status;
  final String body;
  final String type;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async =>
      ResponseBody.fromString(body, status, headers: {
        Headers.contentTypeHeader: [type],
      });

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  tearDown(() => AppLocale.current = AppLocale.locale);

  group('the sentence', () {
    test('an Imunify360 403 is named as the firewall, in Bangla and English', () {
      final blocked = _failure(403, jsonDecode(_imunifyJson));
      expect(isFirewallBlock(blocked), isTrue);
      expect(errorMessageFor(blocked, fallback: 'প্রবেশ করা গেল না।'), _bn);

      AppLocale.current = AppLocale.english;
      expect(errorMessageFor(blocked, fallback: 'Could not sign in.'), _en);
    });

    test('the same 403 as plain text is the firewall too', () {
      expect(errorMessageFor(_failure(403, _imunifyJson, type: 'text/plain'), fallback: 'x'), _bn);
    });

    test('the splash page is the firewall, whatever its status', () {
      for (final status in [200, 403, 415, 503]) {
        final splash = _failure(status, _splash, type: 'text/html; charset=UTF-8');
        expect(isFirewallBlock(splash), isTrue, reason: 'splash with $status');
        expect(errorMessageFor(splash, fallback: 'x'), _bn);
      }
      // ⓘ asked for bytes (a PDF) — the splash is still recognised
      final bytes = _failure(403, utf8.encode(_splash), type: 'text/html', path: '/documents/SalesInvoice/1/pdf');
      expect(errorMessageFor(bytes, fallback: 'x'), _bn);
    });

    test('any HTML answer to an /api call is the firewall', () {
      final html = _failure(500, '<html><body>Forbidden</body></html>', type: 'text/html');
      expect(errorMessageFor(html, fallback: 'x'), _bn);
      final noType = _failure(403, '<!doctype html><html></html>', type: '');
      expect(errorMessageFor(noType, fallback: 'x'), _bn);
    });

    test("a proxy's bad-gateway page is the server being down, not this network", () {
      for (final status in [502, 503, 504]) {
        final down = _failure(status, '<html><body>502 Bad Gateway</body></html>', type: 'text/html');
        expect(isFirewallBlock(down), isFalse, reason: '$status');
        expect(errorMessageFor(down, fallback: 'আনা গেল না।'), 'আনা গেল না।');
      }
    });

    test('⛔ a normal JSON 403 of our own keeps its meaning', () {
      final ours = _failure(403, {'message': 'এই কাজের অনুমতি আপনার নেই।'});
      expect(isFirewallBlock(ours), isFalse);
      expect(errorMessageFor(ours, fallback: 'আনা গেল না।'), 'এই কাজের অনুমতি আপনার নেই।');

      final english = _failure(403, {'message': 'This action is unauthorized.'});
      expect(isFirewallBlock(english), isFalse);
      expect(errorMessageFor(english, fallback: 'আনা গেল না।'), 'আনা গেল না।');
      expect(isNetworkError(english), isFalse);
    });

    test('never the raw body', () {
      final message = errorMessageFor(_failure(403, jsonDecode(_imunifyJson)), fallback: 'x');
      expect(message, isNot(contains('Imunify')));
      expect(message, isNot(contains('whitelisted')));
    });
  });

  group('through the real client', () {
    late HttpClientAdapter real;

    setUpAll(() {
      FakeSecureStorage.install();
      real = ApiClient.dio.httpClientAdapter;
    });

    tearDownAll(() {
      ApiClient.dio.httpClientAdapter = real;
      ApiClient.onSessionExpired = null;
    });

    Future<DioException> failWith(int status, String body, String type) async {
      ApiClient.dio.httpClientAdapter = _Answers(status, body, type);
      try {
        await ApiClient.dio.get<Map<String, dynamic>>('/me');
      } on DioException catch (e) {
        return e;
      }
      fail('the call should have failed');
    }

    test('an Imunify 403 becomes a network failure with no body, never "you may not"', () async {
      final e = await failWith(403, _imunifyJson, 'application/json');
      expect(e.error, isA<FirewallBlocked>());
      expect(e.response, isNull, reason: '⛔ the raw body must not reach a screen, nor its 403 a permission check');
      expect(isNetworkError(e), isTrue, reason: 'the sync queue keeps its rows, a refresh does not sign out');
      expect(errorMessageFor(e, fallback: 'x'), _bn);
    });

    test('a 200 splash page is refused, not read as data', () async {
      final e = await failWith(200, _splash, 'text/html; charset=UTF-8');
      expect(e.error, isA<FirewallBlocked>());
      expect(errorMessageFor(e, fallback: 'x'), _bn);
    });

    test('⛔ a normal JSON 403 passes through untouched', () async {
      final e = await failWith(403, '{"message":"এই কাজের অনুমতি আপনার নেই।"}', 'application/json');
      expect(e.error, isNot(isA<FirewallBlocked>()));
      expect(e.response?.statusCode, 403);
      expect(isNetworkError(e), isFalse);
      expect(errorMessageFor(e, fallback: 'x'), 'এই কাজের অনুমতি আপনার নেই।');
    });

    test('the sign-in names the firewall, not "Could not sign in"', () async {
      ApiClient.dio.httpClientAdapter = _Answers(403, _imunifyJson, 'application/json');
      final auth = AuthController();
      await expectLater(
        auth.login(identifier: 'owner', password: 'secret'),
        throwsA(isA<AuthFailure>().having((f) => f.message, 'message', _bn)),
      );
      auth.dispose();
    });
  });

  testWidgets('the login screen shows the firewall sentence', (tester) async {
    final auth = _BlockedAuth();
    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => auth)],
      child: MaterialApp(
        home: LoginScreen(
          now: () => DateTime.utc(2026, 11, 20, 4),
          readRemembered: () async => null,
          saveRemembered: (_) async {},
        ),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('login-identifier')), 'owner');
    await tester.enterText(find.byKey(const ValueKey('login-password')), 'secret');
    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text(_bn), findsOneWidget);
    expect(find.textContaining('Imunify'), findsNothing);
  });
}

/// Fails the way [AuthController.login] does for a raw Imunify 403.
class _BlockedAuth extends AuthController {
  @override
  Future<void> login({required String identifier, required String password, String? code}) async {
    throw AuthFailure(errorMessageFor(_failure(403, jsonDecode(_imunifyJson)), fallback: AppLocale.l10n.authSignInFailed));
  }
}
