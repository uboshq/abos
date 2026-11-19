import 'dart:math' as math;

import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import 'package:abos_mobile/core/scan/slip_scan.dart';

/// ⭐ স্লিপের স্ক্যানার (0.4.58) — ওয়েবের scan.js-এর একই অঙ্ক: কোনা খোঁজা, সোজা করা, চেহারা, ছোট করা
img.Image _photo() {
  // কাঠের রঙের টেবিল, তার উপর সাদা স্লিপ (100,60)–(500,340); স্লিপে কালো লেখার দাগ
  final p = img.Image(width: 600, height: 400);
  img.fill(p, color: img.ColorRgb8(140, 90, 40));
  img.fillRect(p, x1: 100, y1: 60, x2: 500, y2: 340, color: img.ColorRgb8(235, 232, 225));
  for (var y = 100; y < 320; y += 30) {
    img.fillRect(p, x1: 130, y1: y, x2: 420, y2: y + 6, color: img.ColorRgb8(30, 30, 30));
  }
  return p;
}

void main() {
  test('the four corners of a white slip on a coloured table are found', () {
    final c = SlipScan.detectCorners(_photo())!;
    double near(SlipPoint p, double x, double y) => math.sqrt(math.pow(p.x - x, 2) + math.pow(p.y - y, 2));
    expect(near(c[0], 100, 60), lessThan(12), reason: 'বাঁ-উপর');
    expect(near(c[1], 500, 60), lessThan(12), reason: 'ডান-উপর');
    expect(near(c[2], 500, 340), lessThan(12), reason: 'ডান-নিচ');
    expect(near(c[3], 100, 340), lessThan(12), reason: 'বাঁ-নিচ');
  });

  test('a plain picture with no paper gives no corners, and the default sits 6% inside', () {
    final flat = img.Image(width: 200, height: 100);
    img.fill(flat, color: img.ColorRgb8(140, 90, 40));
    expect(SlipScan.detectCorners(flat), isNull);
    final d = SlipScan.cornersOrDefault(flat);
    expect(d.first, (x: 12.0, y: 6.0));
  });

  test('straightening cuts to the slip; black & white is only black and white', () {
    final photo = _photo();
    final flat = SlipScan.warp(photo, SlipScan.detectCorners(photo)!);
    expect((flat.width - 400).abs(), lessThan(16));
    expect((flat.height - 280).abs(), lessThan(16));
    final bw = SlipScan.enhance(flat, SlipLook.bw);
    final values = <num>{};
    for (final px in bw) {
      values.add(px.r);
    }
    expect(values.difference({0, 255}), isEmpty);
    expect(values, containsAll([0, 255]), reason: 'লেখা কালো, কাগজ সাদা');
    final clean = SlipScan.enhance(flat, SlipLook.clean);
    expect(clean.getPixel(200, 10).r, 255, reason: 'কাগজ পুরো সাদা হয়');
  });

  test('the whole job ends as a JPEG under the target size', () {
    final big = img.Image(width: 2400, height: 1600);
    // ঝিরঝিরে ছবি — ছোট করা সহজ নয়
    final rnd = math.Random(7);
    for (final px in big) {
      final v = 120 + rnd.nextInt(120);
      px
        ..r = v
        ..g = v
        ..b = v;
    }
    final job = SlipJob(
      bytes: img.encodeJpg(big, quality: 95),
      corners: SlipScan.cornersOrDefault(big),
      look: SlipLook.grey,
      quarterTurns: 1,
    );
    final out = SlipScan.process(job);
    expect(out.length, lessThanOrEqualTo(SlipScan.targetBytes));
    final decoded = img.decodeJpg(out)!;
    expect(decoded.height, greaterThan(decoded.width), reason: 'ঘোরানো হয়েছে (চওড়া ছবি খাড়া)');
    expect(math.max(decoded.width, decoded.height), lessThanOrEqualTo(SlipScan.maxEdge));
  });
}
