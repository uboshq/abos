import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;

/// ⭐ মলাট-ছবি ফোনেই ছোট করা — মাঝখান থেকে ৩:১, বড়জোর ১৫০০×৫০০, JPEG, EXIF ছাড়া ("আমি" পাতা, আইটেম ১০; মালিকের অনুমোদিত
/// নমুনা "ক", ২০ নভেম্বর ২০২৬)। [squareFace]-এর একই নিয়ম: ঘোরানো ছবি আগে সোজা, তারপর EXIF (GPS, ফোন, সময়) মোছা।
///
/// <p>ছোট ছবি বড় করা হয় না — ৩:১-এর কাটা অংশ ১৫০০-র কম চওড়া হলে সেটাই মাপ।
const int coverWidth = 1500;
const int coverHeight = 500;

/// [compute]-এ চালায়, যাতে ছবি ভাঙা-জোড়ার সময় পর্দা না আটকায়
Future<Uint8List> coverCrop(Uint8List source) => compute(coverCropNow, source);

/// ⛔ পড়া না গেলে [FormatException] — পর্দা বাংলায় বলে
@visibleForTesting
Uint8List coverCropNow(Uint8List source) {
  img.Image? decoded;
  try {
    decoded = img.decodeImage(source);
  } catch (_) {
    decoded = null;
  }
  if (decoded == null) throw const FormatException('not an image');
  final upright = img.bakeOrientation(decoded);
  // মাঝখান থেকে সবচেয়ে বড় ৩:১ অংশ
  var w = upright.width;
  var h = (w / 3).floor();
  if (h > upright.height) {
    h = upright.height;
    w = h * 3;
  }
  w = math.max(1, w);
  h = math.max(1, h);
  final cut = img.copyCrop(upright, x: (upright.width - w) ~/ 2, y: (upright.height - h) ~/ 2, width: w, height: h);
  final outW = math.min(coverWidth, w);
  final outH = math.max(1, math.min(coverHeight, (outW / 3).round()));
  final cover = outW == w && outH == h
      ? cut
      : img.copyResize(cut, width: outW, height: outH, interpolation: img.Interpolation.average);
  cover.exif = img.ExifData();
  return img.encodeJpg(cover, quality: 85);
}
