import 'dart:typed_data';

import 'package:abos_mobile/core/profile/square_face.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

/// ⭐ মুখের ছবি ফোনেই — মাঝখান থেকে বর্গ, ৫১২ বিন্দু, JPEG, EXIF ছাড়া (অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: লম্বা বা চওড়া যেকোনো ছবি → ৫১২×৫১২ JPEG, মাঝখানটা রেখে; ছোট ছবি বড় করা হয় না; ঘোরানো ছবি সোজা; ⛔ GPS-সহ EXIF
/// সার্ভারে যায় না (MASVS-PRIVACY); PNG-র স্বচ্ছ অংশ সাদা; ছবি না হলে [FormatException]।
void main() {
  Uint8List jpegOf(img.Image image) => img.encodeJpg(image, quality: 95);

  test('a tall photo becomes a 512 square JPEG cut from its middle', () {
    // উপরের আর নিচের ১০০ সারি লাল, মাঝখান সবুজ — মাঝখান থেকে কাটলে বর্গটা পুরো সবুজ
    final tall = img.Image(width: 1200, height: 1600);
    img.fill(tall, color: img.ColorRgb8(0, 200, 0));
    img.fillRect(tall, x1: 0, y1: 0, x2: 1199, y2: 199, color: img.ColorRgb8(255, 0, 0));
    img.fillRect(tall, x1: 0, y1: 1400, x2: 1199, y2: 1599, color: img.ColorRgb8(255, 0, 0));

    final out = squareFaceNow(jpegOf(tall));
    expect(out.sublist(0, 2), [0xFF, 0xD8], reason: 'JPEG');
    final face = img.decodeJpg(out)!;
    expect((face.width, face.height), (512, 512));
    for (final (x, y) in [(256, 2), (256, 509), (2, 256), (509, 256)]) {
      final px = face.getPixel(x, y);
      expect(px.r, lessThan(80), reason: 'কিনারায় ($x,$y) লাল — মাঝখান থেকে কাটা হয়নি');
      expect(px.g, greaterThan(150));
    }
  });

  test('a wide photo is cut from its middle as well', () {
    final wide = img.Image(width: 2000, height: 1000);
    img.fill(wide, color: img.ColorRgb8(0, 0, 220));
    img.fillRect(wide, x1: 0, y1: 0, x2: 449, y2: 999, color: img.ColorRgb8(255, 0, 0));
    img.fillRect(wide, x1: 1550, y1: 0, x2: 1999, y2: 999, color: img.ColorRgb8(255, 0, 0));
    final face = img.decodeJpg(squareFaceNow(jpegOf(wide)))!;
    expect((face.width, face.height), (512, 512));
    expect(face.getPixel(3, 256).r, lessThan(80));
    expect(face.getPixel(508, 256).r, lessThan(80));
  });

  test('a small photo is squared but never enlarged', () {
    final small = img.Image(width: 300, height: 200);
    img.fill(small, color: img.ColorRgb8(10, 10, 10));
    final face = img.decodeJpg(squareFaceNow(jpegOf(small)))!;
    expect((face.width, face.height), (200, 200));
    expect(faceEdge, 512);
  });

  test('where and on which phone it was taken never leaves the phone: EXIF is gone', () {
    final photo = img.Image(width: 800, height: 600);
    img.fill(photo, color: img.ColorRgb8(120, 120, 120));
    photo.exif.imageIfd['Make'] = img.IfdValueAscii('PhoneMaker');
    photo.exif.imageIfd['Model'] = img.IfdValueAscii('Model-X');
    photo.exif.gpsIfd['GPSLatitudeRef'] = img.IfdValueAscii('N');
    photo.exif.gpsIfd['GPSLatitude'] = img.IfdValueRational(24, 1);
    final source = jpegOf(photo);
    expect(img.decodeJpgExif(source)?.isEmpty, isFalse, reason: 'পরীক্ষার ছবিতে EXIF আছে');

    final out = squareFaceNow(source);
    final exif = img.decodeJpgExif(out);
    expect(exif == null || exif.isEmpty, isTrue, reason: '⛔ GPS আর ফোনের নাম সার্ভারে গেল');
    expect(String.fromCharCodes(out).contains('PhoneMaker'), isFalse);
  });

  test('a photo the camera stored on its side is turned upright first', () {
    // ৮০০ চওড়া × ৪০০ উঁচু, বাঁ অর্ধেক লাল; orientation 6 (৯০° ঘোরানো) — সোজা করলে লাল উপরে আসে
    final sideways = img.Image(width: 800, height: 400);
    img.fill(sideways, color: img.ColorRgb8(0, 0, 230));
    img.fillRect(sideways, x1: 0, y1: 0, x2: 399, y2: 399, color: img.ColorRgb8(255, 0, 0));
    sideways.exif.imageIfd.orientation = 6;
    final face = img.decodeJpg(squareFaceNow(jpegOf(sideways)))!;
    expect((face.width, face.height), (400, 400));
    expect(face.getPixel(200, 5).r, greaterThan(180), reason: 'সোজা করলে লাল উপরে');
    expect(face.getPixel(200, 394).b, greaterThan(150), reason: 'নিচে নীল');
  });

  test('a gallery file of another kind (TIFF) stored on its side is turned upright too', () {
    final sideways = img.Image(width: 800, height: 400);
    img.fill(sideways, color: img.ColorRgb8(0, 0, 230));
    img.fillRect(sideways, x1: 0, y1: 0, x2: 399, y2: 399, color: img.ColorRgb8(255, 0, 0));
    sideways.exif.imageIfd.orientation = 6;
    final face = img.decodeJpg(squareFaceNow(img.encodeTiff(sideways)))!;
    expect(face.getPixel(200, 5).r, greaterThan(180), reason: 'সোজা করলে লাল উপরে');
    expect(face.getPixel(200, 394).b, greaterThan(150));
  });

  test('a PNG with a clear background is put on white, not black', () {
    final png = img.Image(width: 300, height: 300, numChannels: 4);
    img.fill(png, color: img.ColorRgba8(0, 0, 0, 0));
    final face = img.decodeJpg(squareFaceNow(img.encodePng(png)))!;
    final px = face.getPixel(150, 150);
    expect(px.r, greaterThan(240), reason: 'স্বচ্ছ অংশ কালো হয়ে গেল');
  });

  test('something that is not a picture is refused', () {
    expect(() => squareFaceNow(Uint8List.fromList('not an image'.codeUnits)), throwsFormatException);
  });
}
