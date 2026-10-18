import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/approvals/approval_rows_api.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/hr/hr_register_api.dart';
import 'package:abos_mobile/core/orders/paper_scan_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ প্রতিটা লেখা একবারই — একবারের চাবি (Idempotency-Key) সহ (পূর্ণ নিরীক্ষা §৯ ⚠️৮, ১৮ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে অনুমোদন দেওয়া/ফেরানো, ছুটি মঞ্জুর/ফেরত, সইয়ের ভার, ভাউচার পাকা, গেট-আউট আর ডেলিভারি চাবি ছাড়া যেত — দুবার চাপলে বা
/// উত্তর হারালে দুবার; আর চাবি যেত কেবল POST-এ, DELETE বা PUT-এ নয়।
///
/// <p>⭐ দাবি: প্রতিটা লেখায় চাবি; উত্তর হারিয়ে আবার পাঠালে একই চাবি (সার্ভার আগের উত্তর ফেরায়); "হলো" বা "না" শোনার পরে নতুন;
/// বেরোলে সব চাবি ভোলা।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Server server;
  late HttpClientAdapter real;

  setUp(() {
    FakeSecureStorage.install();
    OnceKey.forgetAll();
    real = ApiClient.dio.httpClientAdapter;
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
  });
  tearDown(() => ApiClient.dio.httpClientAdapter = real);

  test('every listed write sends a key, DELETE included', () async {
    await ApprovalsApi.approve('a1');
    await ApprovalsApi.reject('a1', remarks: 'না');
    await const ServerHrRegisterApi().approve('l1');
    await const ServerHrRegisterApi().reject('l1', remarks: 'না');
    await const ServerApprovalRowsApi().revoke('d1');
    await const ServerVoucherApi().post('v1');
    await const ServerPaperScanApi().gateOut('t1');

    expect(server.asked.map((a) => a.method).toSet(), containsAll(['POST', 'DELETE']));
    for (final a in server.asked) {
      expect(a.key, isNotNull, reason: '⛔ ${a.method} ${a.path} চাবি ছাড়া গেল');
    }
    expect(server.asked.map((a) => a.key).toSet(), hasLength(server.asked.length), reason: 'আলাদা কাজ, আলাদা চাবি');
  });

  test('a lost answer resends the same key; after the answer a new one', () async {
    server.dropNext = true;
    await expectLater(ApprovalsApi.approve('a9'), throwsA(isA<DioException>()));
    await ApprovalsApi.approve('a9');
    expect(server.asked[0].key, server.asked[1].key, reason: '⛔ উত্তর হারিয়ে আবার পাঠাতে নতুন চাবি — সার্ভারে দুবার বসত');

    await ApprovalsApi.approve('a9');
    expect(server.asked[2].key, isNot(server.asked[1].key), reason: 'সার্ভার "হলো" বলার পরে একই চাবি আবার');
  });

  test('signing out forgets every key', () async {
    server.dropNext = true;
    await expectLater(const ServerPaperScanApi().gateOut('t7'), throwsA(isA<DioException>()));
    OnceKey.forgetAll();
    await const ServerPaperScanApi().gateOut('t7');
    expect(server.asked[1].key, isNot(server.asked[0].key), reason: '⛔ পরের জনের কাজ আগের জনের চাবিতে গেল');
  });
}

class _Asked {
  _Asked(this.method, this.path, this.key);
  final String method;
  final String path;
  final String? key;
}

class _Server implements HttpClientAdapter {
  final List<_Asked> asked = [];
  bool dropNext = false;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    asked.add(_Asked(options.method.toUpperCase(), options.path, options.headers['Idempotency-Key'] as String?));
    if (dropNext) {
      dropNext = false;
      throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    }
    return ResponseBody.fromString(jsonEncode({'posted': true, 'message': ''}), 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}
