import 'dart:io';

import 'package:hive/hive.dart';

/// A real Hive, pointed at a throwaway directory instead of the app's own
/// document path — deliberately `Hive.init(path)`, never
/// `Hive.initFlutter()`: the Flutter variant asks `path_provider` for a
/// directory over a platform channel that, like `flutter_secure_storage`'s,
/// has no native side under `flutter test`'s VM. A plain path needs no
/// channel at all, so there is nothing here to hang on.
///
/// <p>[tearDown] both closes every open box and deletes the directory —
/// closing alone is not enough: a box left on disk between tests is a stale
/// queue row from a previous test's `SyncEngine` silently present in the
/// next one's [SyncEngine.pendingCount].
class HiveTestHarness {
  HiveTestHarness._(this._dir);

  final Directory _dir;

  static Future<HiveTestHarness> setUp() async {
    final dir = await Directory.systemTemp.createTemp('abos_hive_test_');
    Hive.init(dir.path);
    return HiveTestHarness._(dir);
  }

  Future<void> tearDown() async {
    await Hive.close();
    if (await _dir.exists()) {
      await _dir.delete(recursive: true);
    }
  }
}
