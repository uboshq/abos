import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/session_watch.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// সার্ভার সেশন বাতিল করেছে — সব টোকেন মোছা: `/me` ৪০১, নবায়নও ৪০১
class _Revoked implements HttpClientAdapter {
  int meCalls = 0;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (options.path.endsWith('/me')) meCalls++;
    return ResponseBody.fromString(jsonEncode({'message': 'Unauthenticated.'}), 401, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}

/// ⭐ দূর থেকে বের করা — সার্ভার সেশন বাতিল করলে ফোন সামনে আসামাত্র বের হয় (অ্যাপের নিরাপত্তা, MASVS-AUTH-1,
/// ১৯ অক্টোবর ২০২৬; [[SessionWatch]])।
///
/// <p>⛔ আগে ফোন জানত কেবল পরের সিঙ্কে — ১০ মিনিট পর্যন্ত বাতিল সেশনের পর্দা খোলা। দাবি:
///  - সার্ভার বাতিল করলে সামনে আসামাত্র বের (আসল ApiClient-এর ৪০১ → নবায়ন ব্যর্থ → সেশন শেষ);
///  - সামনে এলে যাচাই, পেছনে গেলে নয়; ঢোকা না থাকলে নয়; ৩০ সেকেন্ডে দুবার নয়;
///  - নেটের ভুলে কিছু ভাঙে না।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('against the real client', () {
    late HttpClientAdapter real;

    setUpAll(() {
      FakeSecureStorage.install();
      real = ApiClient.dio.httpClientAdapter;
    });

    tearDownAll(() {
      ApiClient.dio.httpClientAdapter = real;
      ApiClient.onSessionExpired = null;
    });

    test('a session the server revoked signs the phone out the moment it comes to the front', () async {
      await TokenStorage.instance.saveTokens(accessToken: 'A1', refreshToken: 'R1');
      final server = _Revoked();
      ApiClient.dio.httpClientAdapter = server;
      var signedOut = false;
      ApiClient.onSessionExpired = () => signedOut = true;

      final watch = SessionWatch(signedIn: () => true);
      watch.didChangeAppLifecycleState(AppLifecycleState.resumed);
      // ⓘ ৪০১ → নবায়ন (নিরাপদ ভাণ্ডার আর দ্বিতীয় অনুরোধ) → সেশন শেষ — কয়েক ধাপ; ফল আসা পর্যন্ত, সর্বোচ্চ ২ সেকেন্ড
      for (var i = 0; i < 40 && !signedOut; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 50));
      }

      expect(server.meCalls, greaterThanOrEqualTo(1), reason: '⛔ সামনে এসে সেশন যাচাই হলো না');
      expect(signedOut, isTrue, reason: '⛔ সার্ভার বাতিল করার পরেও ফোন ঢোকা অবস্থায় রইল');
      expect(await TokenStorage.instance.refreshToken(), isNull, reason: '⛔ বাতিল সেশনের টোকেন ফোনে রয়ে গেল');
    });
  });

  group('when it asks', () {
    late DateTime now;
    late int asked;
    late bool signedIn;

    SessionWatch watch({Future<void> Function()? check}) => SessionWatch(
          signedIn: () => signedIn,
          check: check ??
              () async {
                asked++;
              },
          clock: () => now,
        );

    setUp(() {
      now = DateTime(2026, 10, 20, 1);
      asked = 0;
      signedIn = true;
    });

    test('coming to the front asks; going to the back does not; signed out never asks', () async {
      final w = watch();
      w.didChangeAppLifecycleState(AppLifecycleState.paused);
      await pumpEventQueue();
      expect(asked, 0, reason: '⛔ পেছনে গিয়েও সার্ভারকে ডাকল');

      w.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await pumpEventQueue();
      expect(asked, 1, reason: '⛔ সামনে এসে যাচাই নেই');

      signedIn = false;
      now = now.add(const Duration(minutes: 5));
      w.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await pumpEventQueue();
      expect(asked, 1, reason: '⛔ বেরোনো অবস্থায় সেশন যাচাই');
    });

    test('not twice within thirty seconds, again after', () async {
      final w = watch();
      expect(await w.checkNow(), isTrue);
      now = now.add(const Duration(seconds: 20));
      expect(await w.checkNow(), isFalse, reason: '⛔ ৩০ সেকেন্ডে দুবার — বারবার সামনে-পেছনে গেলে সার্ভার ভাসত');
      now = now.add(const Duration(seconds: 11));
      expect(await w.checkNow(), isTrue);
      expect(asked, 2);
    });

    test('a network error breaks nothing', () async {
      final w = watch(check: () async => throw DioException(requestOptions: RequestOptions(path: '/me')));
      expect(await w.checkNow(), isTrue);
    });
  });
}
