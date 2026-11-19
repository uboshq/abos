import 'dart:math' as math;
import 'dart:typed_data';

import 'package:image/image.dart' as img;

/// ⭐ স্লিপের স্ক্যানার — ওয়েবের একই অঙ্ক (`resources/js/scan.js`, মালিক ১৬–১৭ নভেম্বর ২০২৬: CamScanner-এর মতো "স্পষ্ট
/// করা"), ফোনে: কোনা খোঁজা, কেটে সোজা করা, চেহারা (পরিষ্কার / ধূসর / সাদা-কালো) আর ছোট করে পাঠানো (জমার নমুনা ৩, ২০ নভেম্বর
/// ২০২৬ "ok")।
///
/// <p>সব বিশুদ্ধ ফাংশন — পর্দা নেই, তাই টেস্টে প্রমাণ করা যায় (`test/scan/slip_scan_test.dart`); ভারী কাজ পর্দা আলাদা
/// isolate-এ চালায় ([SlipScan.process])।
///
/// <p>⚠️ এটা সার্ভারের কাজের **বদলে** নয়: সার্ভার নিজের মাপ আর ধরন যাচাই করে। এখানে যা হয় তা বাড়তি — কাটা আর সোজা করা,
/// যেটা সার্ভার পারে না; আর ছোট করা, যাতে গ্রামের দুর্বল নেটেও যায়।
enum SlipLook { clean, grey, bw }

/// ছবির উপর একটা বিন্দু (ছবির নিজের পিক্সেলে)
typedef SlipPoint = ({double x, double y});

class SlipScan {
  const SlipScan._();

  /// ⭐ পাঠানোর লক্ষ্য — নমুনার "পাঠাবে ≈ 180 KB"; ওয়েবের স্ক্যানারের একই সীমা (সার্ভারের সর্বোচ্চ ৫ MB-এর অনেক নিচে)
  static const int targetBytes = 200 * 1024;

  /// সোজা করা ছবির লম্বা দিক এর বেশি নয় — স্লিপের লেখা পড়া যায়, ফাইল ছোট থাকে
  static const int maxEdge = 1600;

  /// ⭐ চার কোনা — বাঁ-উপর, ডান-উপর, ডান-নিচ, বাঁ-নিচ। না পেলে ছবির চারপাশ থেকে ৬% ভেতরে (মানুষ আঙুলে টেনে ঠিক করেন)।
  static List<SlipPoint> cornersOrDefault(img.Image source) {
    final found = detectCorners(source);
    if (found != null) return found;
    final w = source.width.toDouble(), h = source.height.toDouble();
    return [(x: w * 0.06, y: h * 0.06), (x: w * 0.94, y: h * 0.06), (x: w * 0.94, y: h * 0.94), (x: w * 0.06, y: h * 0.94)];
  }

  /// ⭐ কোনা খোঁজা — আগে রংহীন কাগজ (সাদা বা ধূসর, ছায়াতেও; টেবিল সাধারণত রঙিন), না পেলে কিনারার রং থেকে আলাদা বিন্দু।
  /// বিশ্বাসযোগ্য চতুর্ভুজ না হলে null ([_isSaneQuad])।
  static List<SlipPoint>? detectCorners(img.Image source) {
    if (source.width < 8 || source.height < 8) return null;
    return _neutralPaper(source) ?? _differentFromBorder(source, 42);
  }

  static List<SlipPoint>? _neutralPaper(img.Image source) {
    final width = source.width, height = source.height;
    final step = math.max(1, math.min(width, height) ~/ 200);
    final gw = (width / step).ceil(), gh = (height / step).ceil();
    final mask = Uint8List(gw * gh);
    for (var gy = 0; gy < gh; gy++) {
      for (var gx = 0; gx < gw; gx++) {
        final p = source.getPixel(math.min(gx * step, width - 1), math.min(gy * step, height - 1));
        final r = p.r.toDouble(), g = p.g.toDouble(), b = p.b.toDouble();
        final high = math.max(r, math.max(g, b)), low = math.min(r, math.min(g, b));
        mask[gy * gw + gx] = high > 70 && (high - low) / high < 0.18 ? 1 : 0;
      }
    }
    // সবচেয়ে বড় জোড়া-লাগা টুকরো — চার দিকের পাশাপাশি ঘর ধরে
    final seen = Uint8List(gw * gh);
    var biggest = <int>[];
    for (var start = 0; start < mask.length; start++) {
      if (mask[start] == 0 || seen[start] == 1) continue;
      final piece = <int>[];
      final stack = <int>[start];
      seen[start] = 1;
      while (stack.isNotEmpty) {
        final cell = stack.removeLast();
        piece.add(cell);
        final cx = cell % gw, cy = cell ~/ gw;
        for (final (nx, ny) in [(cx + 1, cy), (cx - 1, cy), (cx, cy + 1), (cx, cy - 1)]) {
          final next = ny * gw + nx;
          if (nx >= 0 && nx < gw && ny >= 0 && ny < gh && mask[next] == 1 && seen[next] == 0) {
            seen[next] = 1;
            stack.add(next);
          }
        }
      }
      if (piece.length > biggest.length) biggest = piece;
    }
    if (biggest.length < mask.length * 0.12 || biggest.length > mask.length * 0.95) return null;
    final corners = _extremes([for (final c in biggest) (x: ((c % gw) * step).toDouble(), y: ((c ~/ gw) * step).toDouble())]);
    return _isSaneQuad(corners, width, height) ? corners : null;
  }

  static List<SlipPoint>? _differentFromBorder(img.Image source, int tolerance) {
    final width = source.width, height = source.height;
    final bg = _borderColour(source);
    final step = math.max(1, math.min(width, height) ~/ 200);
    final points = <SlipPoint>[];
    for (var y = 0; y < height; y += step) {
      for (var x = 0; x < width; x += step) {
        final p = source.getPixel(x, y);
        final d = (p.r - bg[0]).abs() + (p.g - bg[1]).abs() + (p.b - bg[2]).abs();
        if (d > tolerance) points.add((x: x.toDouble(), y: y.toDouble()));
      }
    }
    final total = (width / step).ceil() * (height / step).ceil();
    if (points.length < total * 0.05 || points.length > total * 0.98) return null;
    final corners = _extremes(points);
    return _isSaneQuad(corners, width, height) ? corners : null;
  }

  /// কিনারার নমুনা থেকে পটভূমির রং — মধ্যমা, গড় নয় (কাগজ কিনারা ছুঁলে গড় দুটোর মাঝামাঝি একটা মিথ্যা রং দিত)
  static List<num> _borderColour(img.Image source) {
    final w = source.width, h = source.height;
    final rs = <num>[], gs = <num>[], bs = <num>[];
    void sample(int x, int y) {
      final p = source.getPixel(x, y);
      rs.add(p.r);
      gs.add(p.g);
      bs.add(p.b);
    }

    final step = math.max(1, w ~/ 50);
    for (var x = 0; x < w; x += step) {
      sample(x, 0);
      sample(x, h - 1);
    }
    for (var y = 0; y < h; y += step) {
      sample(0, y);
      sample(w - 1, y);
    }
    num middle(List<num> v) => (v..sort())[v.length ~/ 2];
    return [middle(rs), middle(gs), middle(bs)];
  }

  /// চরম চার বিন্দু — `x+y` আর `x−y`-এর সবচেয়ে ছোট ও বড়
  static List<SlipPoint> _extremes(List<SlipPoint> points) {
    var tl = points.first, tr = points.first, br = points.first, bl = points.first;
    for (final p in points) {
      if (p.x + p.y < tl.x + tl.y) tl = p;
      if (p.x + p.y > br.x + br.y) br = p;
      if (p.x - p.y > tr.x - tr.y) tr = p;
      if (p.x - p.y < bl.x - bl.y) bl = p;
    }
    return [tl, tr, br, bl];
  }

  /// ⚠️ খুব ছোট মানে কাগজ পাওয়া যায়নি; প্রায় পুরোটা মানে কিছু কাটার নেই; কোনো বাহু প্রায় শূন্য মানে ত্রিভুজ বা রেখা
  static bool _isSaneQuad(List<SlipPoint> c, int width, int height) {
    final area = quadArea(c), whole = width * height;
    if (area < whole * 0.12 || area > whole * 0.995) return false;
    double span(SlipPoint a, SlipPoint b) => math.sqrt(math.pow(a.x - b.x, 2) + math.pow(a.y - b.y, 2));
    final sides = [span(c[0], c[1]), span(c[1], c[2]), span(c[2], c[3]), span(c[3], c[0])];
    return sides.reduce(math.min) > math.max(width, height) * 0.08;
  }

  /// চতুর্ভুজের ক্ষেত্রফল — জুতার ফিতার সূত্র
  static double quadArea(List<SlipPoint> c) {
    var area = 0.0;
    for (var i = 0; i < 4; i++) {
      final a = c[i], b = c[(i + 1) % 4];
      area += a.x * b.y - b.x * a.y;
    }
    return area.abs() / 2;
  }

  /// ⭐ কেটে সোজা করা — চার কোনার ভেতরটা আয়তক্ষেত্রে, লম্বা দিক [maxEdge]-এর বেশি নয়
  static img.Image warp(img.Image source, List<SlipPoint> c) {
    double span(SlipPoint a, SlipPoint b) => math.sqrt(math.pow(a.x - b.x, 2) + math.pow(a.y - b.y, 2));
    var w = math.max(span(c[0], c[1]), span(c[3], c[2])).round();
    var h = math.max(span(c[0], c[3]), span(c[1], c[2])).round();
    w = math.max(8, w);
    h = math.max(8, h);
    final long = math.max(w, h);
    if (long > maxEdge) {
      w = (w * maxEdge / long).round();
      h = (h * maxEdge / long).round();
    }
    img.Point p(SlipPoint s) => img.Point(s.x, s.y);
    return img.copyRectify(
      source,
      topLeft: p(c[0]),
      topRight: p(c[1]),
      bottomRight: p(c[2]),
      bottomLeft: p(c[3]),
      interpolation: img.Interpolation.linear,
      toImage: img.Image(width: w, height: h),
    );
  }

  /// ⭐ কাগজের চেহারা — জায়গা ধরে কাগজের রং মেপে ভাগ (ছায়া যায়, কাগজ সাদা): পরিষ্কার রঙিন সিল বাঁচায়, ধূসর, আর
  /// সাদা-কালো (কালি কালো, বাকি সাদা)। ওয়েবের `enhance()`-এর একই সূত্র।
  static img.Image enhance(img.Image source, SlipLook look) {
    final width = source.width, height = source.height;
    final out = img.Image(width: width, height: height);
    final paper = _paperShade(source);
    for (var y = 0; y < height; y++) {
      for (var x = 0; x < width; x++) {
        final p = source.getPixel(x, y);
        final lift = 255 / math.max(paper(x, y), 48);
        if (look == SlipLook.clean) {
          out.setPixelRgb(x, y, _curve(p.r * lift), _curve(p.g * lift), _curve(p.b * lift));
          continue;
        }
        final grey = math.min(255.0, (0.299 * p.r + 0.587 * p.g + 0.114 * p.b) * lift);
        final v = look == SlipLook.bw ? (grey < 255 * _inkCut ? 0 : 255) : _curve(grey);
        out.setPixelRgb(x, y, v, v, v);
      }
    }
    return out;
  }

  static const double _inkCut = 0.78;
  static const double _whiteFrom = 0.88;

  static int _curve(num value) {
    final v = math.min(1.0, math.min(255.0, value.toDouble()) / 255 / _whiteFrom);
    return (255 * math.pow(v, 1.6)).round();
  }

  static double Function(int x, int y) _paperShade(img.Image source) {
    final width = source.width, height = source.height;
    final block = math.max(16, (math.max(width, height) / 40).round());
    final gw = (width / block).ceil(), gh = (height / block).ceil();
    final raw = Float32List(gw * gh);
    for (var y = 0; y < height; y++) {
      final row = (y ~/ block) * gw;
      for (var x = 0; x < width; x++) {
        final p = source.getPixel(x, y);
        final lum = 0.299 * p.r + 0.587 * p.g + 0.114 * p.b;
        final cell = row + x ~/ block;
        if (lum > raw[cell]) raw[cell] = lum.toDouble();
      }
    }
    final grid = Float32List(gw * gh);
    for (var gy = 0; gy < gh; gy++) {
      for (var gx = 0; gx < gw; gx++) {
        var best = 0.0;
        for (var dy = -1; dy <= 1; dy++) {
          for (var dx = -1; dx <= 1; dx++) {
            final ny = gy + dy, nx = gx + dx;
            if (ny >= 0 && ny < gh && nx >= 0 && nx < gw && raw[ny * gw + nx] > best) best = raw[ny * gw + nx];
          }
        }
        final own = raw[gy * gw + gx];
        // ⚠️ পাশেরটা কেবল যখন নিজের ঘর পুরো কালিতে ঢাকা (< ৬০%) — নইলে ছায়ার ঢাল ধূসর থেকে যেত
        grid[gy * gw + gx] = own < best * 0.6 ? best : own;
      }
    }
    return (x, y) {
      final fx = math.min(gw - 1.0, math.max(0.0, x / block - 0.5));
      final fy = math.min(gh - 1.0, math.max(0.0, y / block - 0.5));
      final x0 = fx.floor(), y0 = fy.floor();
      final x1 = math.min(gw - 1, x0 + 1), y1 = math.min(gh - 1, y0 + 1);
      final tx = fx - x0, ty = fy - y0;
      final top = grid[y0 * gw + x0] * (1 - tx) + grid[y0 * gw + x1] * tx;
      final bottom = grid[y1 * gw + x0] * (1 - tx) + grid[y1 * gw + x1] * tx;
      return top * (1 - ty) + bottom * ty;
    };
  }

  /// ⭐ ছোট করে JPEG — মান ৮২ থেকে নামে, তবু না হলে মাপ ছোট; [targetBytes]-এর নিচে, কিন্তু লম্বা দিক ৭০০-এর কম কখনো নয়
  /// (তার নিচে স্লিপের অঙ্ক পড়া যায় না — তখন যা হলো তা-ই)
  static Uint8List compress(img.Image image, {int target = targetBytes}) {
    var current = image;
    Uint8List bytes = img.encodeJpg(current, quality: 82);
    while (true) {
      for (final q in [82, 72, 62, 52, 45]) {
        bytes = img.encodeJpg(current, quality: q);
        if (bytes.length <= target) return bytes;
      }
      final long = math.max(current.width, current.height);
      if (long <= 700) return bytes;
      current = img.copyResize(current,
          width: current.width >= current.height ? (long * 0.8).round() : null,
          height: current.height > current.width ? (long * 0.8).round() : null,
          interpolation: img.Interpolation.average);
    }
  }

  /// ⭐ পুরো কাজ এক ডাকে — কাটা, সোজা, চেহারা, ঘোরানো (ঘড়ির কাঁটায় ৯০-এর গুণ), ছোট করা; isolate-এ চলার মতো (কেবল সরল মান)
  static Uint8List process(SlipJob job) {
    final decoded = img.decodeImage(job.bytes);
    if (decoded == null) throw const FormatException('slip image unreadable');
    final upright = img.bakeOrientation(decoded);
    var out = warp(upright, job.corners);
    out = enhance(out, job.look);
    if (job.quarterTurns % 4 != 0) out = img.copyRotate(out, angle: 90 * (job.quarterTurns % 4));
    return compress(out, target: job.target);
  }
}

/// isolate-এ পাঠানোর কাজ — ছবির বাইট, কোনা (ছবির পিক্সেলে, [img.bakeOrientation]-এর পরে), চেহারা, ঘোরানো, লক্ষ্য
class SlipJob {
  const SlipJob({
    required this.bytes,
    required this.corners,
    this.look = SlipLook.clean,
    this.quarterTurns = 0,
    this.target = SlipScan.targetBytes,
  });

  final Uint8List bytes;
  final List<SlipPoint> corners;
  final SlipLook look;
  final int quarterTurns;
  final int target;
}
