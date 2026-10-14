import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⛔ সই যায় দেখা অঙ্কসহ — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬; সার্ভার TheSignerSignsTheAmountTheySawTest।
void main() {
  late HttpClientAdapter real;
  late _Server server;

  setUpAll(() {
    FakeSecureStorage.install();
    real = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() => ApiClient.dio.httpClientAdapter = real);

  setUp(() {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
  });

  test('the approval carries the amount the card showed, exactly as the server wrote it', () async {
    await ApprovalsApi.approve('ap-1', seenAmount: '39990.0000');
    expect(server.body, {'seenAmount': '39990.0000'});

    await ApprovalsApi.approve('ap-1', remarks: 'ঠিক আছে');
    expect(server.body, {'remarks': 'ঠিক আছে'}, reason: 'অঙ্ক না জানলে কিছু পাঠায় না');
  });

  test('a 412 reads as "the amount changed", not as "already decided"', () {
    final error = DioException(
      requestOptions: RequestOptions(path: '/approvals/x/approve'),
      response: Response(requestOptions: RequestOptions(path: '/approvals/x/approve'), statusCode: 412),
    );
    expect(approvalFailureOf(error), ApprovalFailure.amountChanged);
  });
}

class _Server implements HttpClientAdapter {
  Map<String, dynamic>? body;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    final data = options.data;
    body = Map<String, dynamic>.from(data is String ? jsonDecode(data) as Map : data as Map);
    return ResponseBody.fromString(jsonEncode({'id': 'ap-1', 'status': 'approved', 'currentLevel': 1}), 200,
        headers: {Headers.contentTypeHeader: [Headers.jsonContentType]});
  }

  @override
  void close({bool force = false}) {}
}
