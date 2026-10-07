import 'package:flutter/services.dart';

/// How much room is free where a downloaded update would land — asked before
/// the download starts, not discovered when it fails part way with the phone
/// full.
class StorageCheck {
  const StorageCheck._();

  static const _channel = MethodChannel('com.abos.abos_mobile/storage');

  /// Null when the platform could not answer. Null is not "no space": see
  /// [hasEnoughSpace].
  static Future<int?> freeBytes() async {
    try {
      return await _channel.invokeMethod<int>('freeBytes');
    } catch (_) {
      return null;
    }
  }
}

/// A margin on top of the download's own size, because the installer needs
/// scratch room beyond the APK's bytes (it unpacks and verifies before
/// replacing the old install), and because "exactly enough" on a phone that
/// is also taking photos between now and the tap is not enough by the time
/// the download finishes.
const int updateSpaceMargin = 20 * 1024 * 1024;

/// Whether [free] bytes is enough to download [requiredBytes] safely.
///
/// <p>An unknown [free] is read as enough. A phone this cannot measure must
/// not be left permanently unable to update; the download itself fails
/// cleanly if there truly is no room.
bool hasEnoughSpace(int? free, int requiredBytes) {
  if (free == null) return true;
  return free >= requiredBytes + updateSpaceMargin;
}

/// "অন্তত 95MB" — what has to be free, rounded up so the number on screen is
/// never one megabyte short of the truth.
int megabytesNeeded(int requiredBytes) =>
    ((requiredBytes + updateSpaceMargin) / (1024 * 1024)).ceil();
