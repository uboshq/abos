import 'dart:convert';

import 'package:abos_mobile/core/storage/hive_encryption.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⛔ কিস্টোর একবার পড়া না গেলে চাবি বদলায় না, অফলাইন সারি মোছে না — ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬
/// ([[HiveEncryptionKey]])।
///
/// ⓘ পড়া ব্যর্থ হলে "চাবি নেই" ধরে নতুন চাবি লেখা হত — পুরনো চাবিতে রাখা সারির বাক্স আর খোলা যেত না, আর সিঙ্ক সেটা মুছে
/// দিত: না-পাঠানো সব অর্ডার, কোনো বার্তা ছাড়া।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('plugins.it_nomads.com/flutter_secure_storage');
  final stored = base64Encode(List<int>.generate(32, (i) => i));

  test('a key read that fails is reported, and the stored key is never overwritten', () async {
    var writes = 0;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, (call) async {
      if (call.method == 'read') throw PlatformException(code: 'keystore', message: 'temporarily unavailable');
      if (call.method == 'write') writes++;
      return null;
    });

    await expectLater(HiveEncryptionKey.cipher(), throwsA(isA<HiveKeyUnavailable>()));
    expect(writes, 0, reason: '⛔ পড়া ব্যর্থ হতেই নতুন চাবি লেখা হলো — পুরনো সারির বাক্স আর খুলবে না ($stored)');
  });
}
