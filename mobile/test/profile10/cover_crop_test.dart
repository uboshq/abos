import 'dart:typed_data';

import 'package:abos_mobile/core/profile/cover_crop.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

/// ⭐ মলাট ফোনেই ৩:১, বড়জোর ১৫০০×৫০০, JPEG, EXIF ছাড়া
void main() {
  Uint8List photo(int w, int h) {
    final image = img.Image(width: w, height: h);
    img.fill(image, color: img.ColorRgb8(30, 120, 200));
    image.exif.imageIfd['Make'] = 'PhoneMaker';
    return img.encodeJpg(image);
  }

  img.Image out(Uint8List bytes) => img.decodeJpg(coverCropNow(bytes))!;

  test('a tall photo is cut from the middle to 3:1 and never enlarged', () {
    final cover = out(photo(900, 1200));
    expect(cover.width, 900);
    expect(cover.height, 300);
  });

  test('a big wide photo is scaled to 1500×500', () {
    final cover = out(photo(4000, 1000));
    expect(cover.width, 1500);
    expect(cover.height, 500);
  });

  test('no EXIF goes out', () {
    final cover = out(photo(3000, 2000));
    expect(cover.exif.imageIfd['Make'], isNull);
  });

  test('not a picture is refused', () {
    expect(() => coverCropNow(Uint8List.fromList([1, 2, 3])), throwsFormatException);
  });
}
