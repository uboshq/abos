import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// Answers `flutter_secure_storage`'s platform channel with an in-memory
/// map instead of the real Android keystore / iOS Keychain.
///
/// <p>Every test in this suite that touches [TokenStorage], [SessionRepository]
/// or [HiveEncryptionKey] needs this — none of those classes have a seam to
/// inject a fake store, by design (the real keystore is the whole point of
/// their own doc comments). Without this mock, the plugin's method channel
/// has no native side under `flutter test`'s VM and every call either throws
/// `MissingPluginException` or, on some platforms, simply never returns —
/// which is exactly the class of hang a prior widget test in this
/// organisation's sibling app was lost to (a real Hive write left running
/// past `tearDownAll`). Call [install] in `setUp`, [FakeSecureStorage.reset]
/// between tests that must not see each other's keys, and never let a test
/// finish without it having run at least once — a real platform channel call
/// left pending is the failure mode this file exists to rule out entirely.
class FakeSecureStorage {
  FakeSecureStorage._();

  static final Map<String, String> _values = {};

  static void reset() => _values.clear();

  static void install() {
    TestWidgetsFlutterBinding.ensureInitialized();
    const channel = MethodChannel('plugins.it_nomads.com/flutter_secure_storage');
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
      switch (call.method) {
        case 'write':
          final args = (call.arguments as Map).cast<String, dynamic>();
          _values[args['key'] as String] = args['value'] as String;
          return null;
        case 'read':
          final args = (call.arguments as Map).cast<String, dynamic>();
          return _values[args['key'] as String];
        case 'delete':
          final args = (call.arguments as Map).cast<String, dynamic>();
          _values.remove(args['key'] as String);
          return null;
        case 'readAll':
          return _values;
        case 'deleteAll':
          _values.clear();
          return null;
        case 'containsKey':
          final args = (call.arguments as Map).cast<String, dynamic>();
          return _values.containsKey(args['key'] as String);
        default:
          return null;
      }
    });
  }
}
