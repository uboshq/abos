import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/orders/deposit_request_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ একটা লেখা একবারই — অডিট ফোন ⚠️১২; সার্ভার 2914831d (ThePhoneWroteItTwiceTest)। আসল [ApiClient] আর তার ইন্টারসেপ্টর,
/// কেবল সার্ভার নকল।
void main() {
  late _Server server;
  late HttpClientAdapter real;

  setUpAll(() {
    FakeSecureStorage.install();
    real = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() => ApiClient.dio.httpClientAdapter = real);

  setUp(() {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
  });

  test('a write sent through the key carries it; the same action after no network carries the same key', () async {
    final once = OnceKey();
    server.offline = true;
    await expectLater(once.send(() => ApiClient.dio.post<void>('/sales/leads', data: const {})), throwsA(isA<DioException>()));
    server.offline = false;
    await once.send(() => ApiClient.dio.post<void>('/sales/leads', data: const {}));

    expect(server.keys, hasLength(2));
    expect(server.keys[0], isNotNull);
    expect(server.keys[1], server.keys[0], reason: '⛔ নেট ফেরার পরে নতুন চাবি — দুবার বসতে পারত');
    expect(server.keys[0], matches(RegExp(r'^[0-9a-f]{32}$')));
  });

  test('after it lands, or after the server refuses it, the next action gets a new key', () async {
    final once = OnceKey();
    await once.send(() => ApiClient.dio.post<void>('/sales/leads', data: const {}));
    server.refuse = true;
    await expectLater(once.send(() => ApiClient.dio.post<void>('/sales/leads', data: const {})), throwsA(isA<DioException>()));
    server.refuse = false;
    await once.send(() => ApiClient.dio.post<void>('/sales/leads', data: const {}));

    expect(server.keys.toSet(), hasLength(3), reason: '⛔ বসে যাওয়া বা ফেরানো কাজের চাবি আবার');
  });

  test('outside the key, and on a read, nothing is added', () async {
    await ApiClient.dio.post<void>('/sales/leads', data: const {});
    await OnceKey().send(() => ApiClient.dio.get<void>('/sales/leads'));
    expect(server.keys, [null, null]);
  });

  test('the real deposit advice API goes through the key when the screen sends it so', () async {
    final once = OnceKey();
    await once.send(() => const ServerDepositRequestApi()
        .send(customerId: 'c1', date: DateTime(2026, 10, 7), amount: '500', method: 'cash'));
    expect(server.paths.single, '/sales/deposit-requests');
    expect(server.keys.single, isNotNull);
  });
}

class _Server implements HttpClientAdapter {
  bool offline = false;
  bool refuse = false;
  final List<String?> keys = [];
  final List<String> paths = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    keys.add(options.headers['Idempotency-Key'] as String?);
    paths.add(options.path);
    if (offline) throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    return ResponseBody.fromString(
      jsonEncode(refuse ? {'message': 'ফেরানো'} : {'id': 'x', 'status': 'pending', 'amount': '500.00'}),
      refuse ? 422 : 201,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
