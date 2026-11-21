import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:abos_mobile/core/sync_engine/background_sync.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ লগআউট না চাপা পর্যন্ত ঢোকা (মালিক, ২০ নভেম্বর ২০২৬): অ্যাপ পেছনে চুপচাপ নবায়ন করে, ৩০ দিন না খুললেও বের নয়;
/// কেবল সার্ভারের নিশ্চিত "না" (নবায়নের দরজার ৪০১/৪০৩) সেশন শেষ করে।
ResponseBody _json(Object body, int status) => ResponseBody.fromString(jsonEncode(body), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });

/// নবায়নের দরজা [refreshStatus] দেয় (২০০ হলে নতুন জোড়া); বাকি সব ৪০১
class _Server implements HttpClientAdapter {
  _Server(this.refreshStatus, {this.refreshBody});

  final int refreshStatus;
  final Object? refreshBody;
  int refreshCalls = 0;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (options.path.endsWith('/auth/refresh')) {
      refreshCalls++;
      await Future<void>.delayed(const Duration(milliseconds: 20));
      if (refreshStatus == 200) return _json(refreshBody ?? {'accessToken': 'A2', 'refreshToken': 'R2'}, 200);
      return _json({'message': 'no'}, refreshStatus);
    }
    if (options.headers['Authorization'] == 'Bearer A2') return _json({'ok': true}, 200);
    return _json({'message': 'expired'}, 401);
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late HttpClientAdapter real;

  setUpAll(() {
    FakeSecureStorage.install();
    real = ApiClient.dio.httpClientAdapter;
    ApiClient.raceWait = const Duration(milliseconds: 10);
  });

  tearDownAll(() {
    ApiClient.dio.httpClientAdapter = real;
    ApiClient.onSessionExpired = null;
  });

  setUp(() async {
    FakeSecureStorage.reset();
    await TokenStorage.instance.saveTokens(accessToken: 'A1', refreshToken: 'R1');
  });

  group('only a definite refusal signs out', () {
    for (final status in [422, 404, 400, 500, 429]) {
      test('a $status from the refresh door keeps the tokens', () async {
        ApiClient.dio.httpClientAdapter = _Server(status);
        var expired = false;
        ApiClient.onSessionExpired = () => expired = true;

        await expectLater(ApiClient.dio.get<void>('/me'), throwsA(isA<DioException>()));

        expect(expired, isFalse, reason: '⛔ $status-এ মানুষ বের হয়ে গেলেন');
        expect(await TokenStorage.instance.refreshToken(), 'R1');
      });
    }

    test('an answer without tokens keeps the session', () async {
      ApiClient.dio.httpClientAdapter = _Server(200, refreshBody: {'ok': true});
      var expired = false;
      ApiClient.onSessionExpired = () => expired = true;

      await expectLater(ApiClient.dio.get<void>('/me'), throwsA(isA<DioException>()));

      expect(expired, isFalse, reason: '⛔ অসম্পূর্ণ উত্তরে মানুষ বের হয়ে গেলেন');
      expect(await TokenStorage.instance.refreshToken(), 'R1');
    });

    for (final status in [401, 403]) {
      test('a $status from the refresh door ends the session', () async {
        ApiClient.dio.httpClientAdapter = _Server(status);
        var expired = false;
        ApiClient.onSessionExpired = () => expired = true;

        await expectLater(ApiClient.dio.get<void>('/me'), throwsA(isA<DioException>()));

        expect(expired, isTrue);
        expect(await TokenStorage.instance.refreshToken(), isNull);
      });
    }

    test('a 403 on an ordinary call never touches the session', () async {
      ApiClient.dio.httpClientAdapter = _Forbidden();
      var expired = false;
      ApiClient.onSessionExpired = () => expired = true;

      await expectLater(ApiClient.dio.get<void>('/books'), throwsA(isA<DioException>()));

      expect(expired, isFalse);
      expect(await TokenStorage.instance.refreshToken(), 'R1');
    });

    test('requests that wait on one busy refresh all keep the session', () async {
      final server = _Server(503);
      ApiClient.dio.httpClientAdapter = server;
      var expired = false;
      ApiClient.onSessionExpired = () => expired = true;

      final calls = [for (var i = 0; i < 3; i++) ApiClient.dio.get<void>('/me').then((_) {}, onError: (_) {})];
      await Future.wait(calls);

      expect(server.refreshCalls, 1, reason: '⛔ একসাথে একাধিক নবায়ন');
      expect(expired, isFalse, reason: '⛔ অপেক্ষারত অনুরোধ সাময়িক ভুলকে "না" পড়ল');
      expect(await TokenStorage.instance.refreshToken(), 'R1');
    });
  });

  group('renewNow', () {
    test('renews and saves the new pair with its time', () async {
      ApiClient.dio.httpClientAdapter = _Server(200);
      final before = DateTime.now().toUtc().subtract(const Duration(seconds: 1));

      expect(await ApiClient.renewNow(), isTrue);

      expect(await TokenStorage.instance.refreshToken(), 'R2');
      expect((await TokenStorage.instance.tokensSavedAt())!.isAfter(before), isTrue);
    });

    test('no network keeps the tokens', () async {
      ApiClient.dio.httpClientAdapter = _Offline();
      var expired = false;
      ApiClient.onSessionExpired = () => expired = true;

      expect(await ApiClient.renewNow(), isFalse);

      expect(expired, isFalse);
      expect(await TokenStorage.instance.refreshToken(), 'R1');
    });
  });

  group('renewIfStale (background round)', () {
    test('a pair younger than three days is left alone', () async {
      var renewed = 0;
      final done = await renewIfStale(
          now: DateTime.now().add(const Duration(days: 2)), renew: () async => ++renewed > 0);
      expect(done, isFalse);
      expect(renewed, 0);
    });

    test('a pair older than three days is renewed', () async {
      var renewed = 0;
      final done = await renewIfStale(
          now: DateTime.now().add(const Duration(days: 4)), renew: () async => ++renewed > 0);
      expect(done, isTrue);
      expect(renewed, 1);
    });

    test('a pair with no saved time (older build) is renewed', () async {
      // ⓘ সময়ের চাবি ছাড়া — আগের বিল্ডের রাখা জোড়ার মতো
      FakeSecureStorage.remove('abos_tokens_saved_at');
      var renewed = 0;
      expect(await renewIfStale(renew: () async => ++renewed > 0), isTrue);
      expect(renewed, 1);
    });

    test('nobody signed in: nothing', () async {
      await TokenStorage.instance.clear();
      var renewed = 0;
      expect(await renewIfStale(now: DateTime.now().add(const Duration(days: 40)), renew: () async => ++renewed > 0),
          isFalse);
      expect(renewed, 0);
    });

    test('clear forgets the saved time', () async {
      await TokenStorage.instance.clear();
      expect(await TokenStorage.instance.tokensSavedAt(), isNull);
    });
  });
}

class _Forbidden implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async =>
      _json({'message': 'not allowed'}, 403);

  @override
  void close({bool force = false}) {}
}

class _Offline implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async =>
      throw DioException(requestOptions: options, type: DioExceptionType.connectionError);

  @override
  void close({bool force = false}) {}
}
