import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;

/// ⭐ মুখের ছবি ফোনেই ছোট করা — মাঝখান থেকে বর্গ, বড় দিক ৫১২ বিন্দু, JPEG (a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>সার্ভারও ৫১২-র বর্গ রাখে ([ImageEngine::FACE_EDGE]) — তাই ফোনের ১২ মেগাপিক্সেলের ছবি (৪–৮ MB) দোকানের ধীর নেটে না পাঠিয়ে
/// ৩০–৬০ KB পাঠানো; ৫ MB-এর সীমায় কখনো ঠেকে না। ⓘ নতুন প্যাকেজ নয় — `image` আগেই আছে (থার্মাল প্রিন্টারের জন্য)।
///
/// <p>⛔ <b>গোপনীয়তা (MASVS-PRIVACY)</b>: ক্যামেরার ছবিতে EXIF থাকে — কোথায় তোলা (GPS), কোন ফোন, কখন। নতুন করে আঁকা ছবিতে
/// EXIF মুছে দেওয়া হয়, কেবল ছবিটা যায়। ঘোরানো ছবি (EXIF orientation) আগে সোজা করা হয়, তারপর EXIF বাদ।
///
/// <p>ছোট ছবি বড় করা হয় না (৫১২-র কম হলে ছোট দিকটাই বর্গের মাপ)। PNG-র স্বচ্ছ অংশ সাদা পটে (JPEG-এ স্বচ্ছতা নেই — নইলে কালো)।
const int faceEdge = 512;

/// [compute]-এ চালায়, যাতে ছবি ভাঙা-জোড়ার সময় পর্দা না আটকায়
Future<Uint8List> squareFace(Uint8List source) => compute(squareFaceNow, source);

/// ⛔ পড়া না গেলে [FormatException] — পর্দা বাংলায় বলে
@visibleForTesting
Uint8List squareFaceNow(Uint8List source) {
  final decoded = img.decodeImage(source);
  if (decoded == null) throw const FormatException('not an image');
  final upright = img.bakeOrientation(decoded);
  final edge = math.min(faceEdge, math.min(upright.width, upright.height));
  final square = img.copyResizeCropSquare(upright, size: edge, interpolation: img.Interpolation.average);
  // ⓘ স্বচ্ছ অংশ: JPEG-এর encoder নিজেই সাদা পটে মেশায় (JpegEncoder-এর backgroundColor) — আলাদা করে আঁকা লাগে না
  square.exif = img.ExifData();
  return img.encodeJpg(square, quality: 85);
}
