import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⛔ ফোন ৩০ মিনিট পরে চুপ হয়ে যেত — মালিক, ৪ অক্টোবর ২০২৬: *"amar app e customer inventory pur sales sync kokhono na
/// dekhay"*। নবায়নে refresh টোকেন কেবল body-তে যেত, হেডার ছাড়া, deviceId ছাড়া — সার্ভারের দরজা হেডার পড়ে, তাই প্রতিটা
/// নবায়ন ৪০১।
/// দাবি: একটা অনুরোধ ৪০১ পেলে নবায়ন **হেডারে** refresh টোকেন আর body-তে deviceId পাঠায়, তারপর আসল অনুরোধ আবার চলে।
class _Stub implements HttpClientAdapter {
  final List<RequestOptions> seen = [];
  int meCalls = 0;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    seen.add(options);
    ResponseBody json(Object body, int status) => ResponseBody.fromString(jsonEncode(body), status, headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        });

    if (options.path.endsWith('/auth/refresh')) {
      return json({'accessToken': 'A2', 'refreshToken': 'R2'}, 200);
    }
    if (options.path.endsWith('/me')) {
      meCalls++;
      return meCalls == 1 ? json({'message': 'expired'}, 401) : json({'ok': true}, 200);
    }
    return json({}, 404);
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late HttpClientAdapter real;

  setUpAll(() {
    FakeSecureStorage.install();
    real = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() => ApiClient.dio.httpClientAdapter = real);

  test('an expired session refreshes with the refresh token in the header and the device id, then replays', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'A1', refreshToken: 'R1');
    final stub = _Stub();
    ApiClient.dio.httpClientAdapter = stub;

    final response = await ApiClient.dio.get<Map<String, dynamic>>('/me');

    expect(response.statusCode, 200, reason: '⛔ নবায়নের পরে আসল অনুরোধ আবার চলল না');
    final refresh = stub.seen.firstWhere((o) => o.path.endsWith('/auth/refresh'));
    expect(refresh.headers['Authorization'], 'Bearer R1', reason: '⛔ refresh টোকেন হেডারে নেই — সার্ভার ৪০১ দেবে');
    expect((refresh.data as Map)['deviceId'], isNotEmpty, reason: '⛔ নবায়নে deviceId নেই');
    expect(await TokenStorage.instance.refreshToken(), 'R2');
  });

  test('a refresh that loses to another isolate takes the pair that isolate saved instead of signing out (9 Oct 2026)', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'A1', refreshToken: 'R1');
    final stub = _Lost();
    ApiClient.dio.httpClientAdapter = stub;
    var expired = false;
    ApiClient.onSessionExpired = () => expired = true;

    final response = await ApiClient.dio.get<Map<String, dynamic>>('/me');

    expect(response.statusCode, 200, reason: '⛔ অন্য isolate-এর নতুন জোড়া থাকতেও অনুরোধ ব্যর্থ');
    expect(stub.replayedWith, 'Bearer A3', reason: '⛔ storage-এর নতুন access টোকেনে আবার চেষ্টা হয়নি');
    expect(expired, isFalse, reason: '⛔ জেতা জোড়া থাকতেও মানুষ বের হয়ে গেলেন');
    expect(await TokenStorage.instance.refreshToken(), 'R3');
  });

  test('a refresh the server refuses with nobody else having refreshed still ends the session', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'A1', refreshToken: 'R1');
    ApiClient.dio.httpClientAdapter = _Refused();
    var expired = false;
    ApiClient.onSessionExpired = () => expired = true;

    await expectLater(ApiClient.dio.get<Map<String, dynamic>>('/me'), throwsA(isA<DioException>()));
    expect(expired, isTrue);
    expect(await TokenStorage.instance.refreshToken(), isNull);
  });
}

ResponseBody _json(Object body, int status) => ResponseBody.fromString(jsonEncode(body), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });

/// নবায়নে হারে: সার্ভারের ৪০১-এর আগে আরেক isolate নতুন জোড়া (A3/R3) রেখে গেছে
class _Lost implements HttpClientAdapter {
  String? replayedWith;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (options.path.endsWith('/auth/refresh')) {
      await TokenStorage.instance.saveTokens(accessToken: 'A3', refreshToken: 'R3');
      return _json({'message': 'Unauthenticated.'}, 401);
    }
    if (options.path.endsWith('/me')) {
      if (options.headers['Authorization'] == 'Bearer A3') {
        replayedWith = 'Bearer A3';
        return _json({'ok': true}, 200);
      }
      return _json({'message': 'expired'}, 401);
    }
    return _json({}, 404);
  }

  @override
  void close({bool force = false}) {}
}

class _Refused implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async =>
      options.path.endsWith('/auth/refresh') ? _json({'message': 'Unauthenticated.'}, 401) : _json({'message': 'expired'}, 401);

  @override
  void close({bool force = false}) {}
}
