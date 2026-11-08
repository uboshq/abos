import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/api_client/once_key.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';

/// ⭐ প্রত্যাখ্যানের কারণের কোড সার্ভারে যায় — মালিকের নমুনা "অনুমোদনের নতুন ইনবক্স" v4, ছবি ৫ ("ok", ৮ নভেম্বর ২০২৬): সার্ভার
/// `reasonCode` আগে থেকেই নেয় ([[ApprovalDecision::REASONS]]), অ্যাপ এতদিন পাঠাত না — তাই রিপোর্টে সব প্রত্যাখ্যান "অব্যক্ত"।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late HttpClientAdapter real;
  late _Catch server;

  setUp(() {
    FakeSecureStorage.install();
    OnceKey.forgetAll();
    real = ApiClient.dio.httpClientAdapter;
    server = _Catch();
    ApiClient.dio.httpClientAdapter = server;
  });
  tearDown(() => ApiClient.dio.httpClientAdapter = real);

  test('the picked reason travels as reasonCode beside the note', () async {
    await ApprovalsApi.reject('a1', remarks: 'দর বেশি', reasonCode: 'price');
    expect(server.bodies.single, {'remarks': 'দর বেশি', 'reasonCode': 'price'}, reason: '⛔ কারণের কোড সার্ভারে যায়নি');
  });

  test('an older caller without a code sends the note alone', () async {
    await ApprovalsApi.reject('a2', remarks: 'না');
    expect(server.bodies.single, {'remarks': 'না'}, reason: '⛔ কোড না দিলেও একটা কোড গেল');
  });
}

class _Catch implements HttpClientAdapter {
  final List<Map<String, dynamic>> bodies = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final data = options.data;
    bodies.add(data is Map ? data.cast<String, dynamic>() : (jsonDecode(data.toString()) as Map).cast<String, dynamic>());
    return ResponseBody.fromString('{}', 200, headers: {
      Headers.contentTypeHeader: ['application/json'],
    });
  }

  @override
  void close({bool force = false}) {}
}
