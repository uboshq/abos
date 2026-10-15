import 'package:image/image.dart' as img;

/// ESC/POS bytes for a receipt that is a picture, not text.
///
/// <p>⭐ Why a picture (owner, 14 Oct 2026: "mobile app theke thermal & normal
/// print"): the fonts inside a cheap 58/80 mm receipt printer are Latin and
/// Chinese only. Bengali sent as text comes out as question marks. So the
/// server's own 58/80 mm PDF is rasterised on the phone and sent as a bitmap
/// with `GS v 0` — every printer that speaks ESC/POS knows that command, and
/// the paper looks exactly like the web's thermal print.
///
/// <p>Written by hand rather than through a generator package: it is five
/// commands, and owning them means a test can check every byte that reaches
/// the printer.
class EscPosRaster {
  const EscPosRaster._();

  /// Head width in dots: 384 on a 58 mm printer, 576 on an 80 mm one (203 dpi
  /// over 48 mm / 72 mm of printable width). A wider bitmap is silently cut
  /// at the right edge by the printer — the amounts column, usually.
  static int dotsFor(int paperWidthMm) => paperWidthMm >= 80 ? 576 : 384;

  /// Rows sent per `GS v 0` command. Cheap printers have a small receive
  /// buffer; a whole receipt in one command overflows it on some models and
  /// prints garbage from the middle on. 128 rows is ~16 mm of paper.
  static const int bandRows = 128;

  /// How many blank lines are fed before the cut, so the last line clears
  /// the cutter (and the tear bar on printers without one).
  static const int feedLines = 4;

  /// `ESC @` — reset the printer, so a previous job's settings never apply.
  static const List<int> initialise = [0x1B, 0x40];

  /// `GS V 66 0` — feed to the cutter and partial-cut. Printers without a
  /// cutter ignore it, which is the behaviour wanted.
  static const List<int> cut = [0x1D, 0x56, 0x42, 0x00];

  /// `ESC d n` — feed n lines.
  static List<int> feed(int lines) => [0x1B, 0x64, lines.clamp(0, 255)];

  /// The whole job: reset, every page as bitmap bands, feed, cut.
  static List<int> receipt(List<img.Image> pages, {required int dots}) {
    final out = <int>[...initialise];
    for (final page in pages) {
      out.addAll(bitmap(page, dots: dots));
    }
    out
      ..addAll(feed(feedLines))
      ..addAll(cut);
    return out;
  }

  /// One page: scaled to exactly [dots] wide, turned black-and-white, and
  /// sent in bands of [bandRows].
  static List<int> bitmap(img.Image page, {required int dots}) {
    final scaled = page.width == dots
        ? page
        : img.copyResize(page,
            width: dots, interpolation: img.Interpolation.average);
    final widthBytes = (dots + 7) ~/ 8;
    final rows = _monochrome(scaled, dots, widthBytes);

    final out = <int>[];
    for (var top = 0; top < scaled.height; top += bandRows) {
      final height =
          (scaled.height - top) < bandRows ? scaled.height - top : bandRows;
      out.addAll(rasterHeader(widthBytes: widthBytes, height: height));
      out.addAll(
          rows.sublist(top * widthBytes, (top + height) * widthBytes));
    }
    return out;
  }

  /// `GS v 0 m xL xH yL yH` — m = 0 (normal size), x in bytes, y in dots.
  static List<int> rasterHeader({required int widthBytes, required int height}) =>
      [
        0x1D, 0x76, 0x30, 0x00, //
        widthBytes & 0xFF, (widthBytes >> 8) & 0xFF,
        height & 0xFF, (height >> 8) & 0xFF,
      ];

  /// One bit per dot, most significant bit leftmost, 1 = burn (black).
  /// Transparent pixels count as paper: a PDF page's background can come out
  /// of the rasteriser with alpha 0, and reading that as black would print a
  /// solid black receipt.
  static List<int> _monochrome(img.Image image, int dots, int widthBytes) {
    final bytes = List<int>.filled(widthBytes * image.height, 0);
    for (var y = 0; y < image.height; y++) {
      for (var x = 0; x < dots && x < image.width; x++) {
        final p = image.getPixel(x, y);
        final a = image.hasAlpha ? p.aNormalized : 1.0;
        final lum = (0.299 * p.rNormalized +
                0.587 * p.gNormalized +
                0.114 * p.bNormalized) *
            a +
            (1 - a);
        if (lum < 0.5) {
          bytes[y * widthBytes + (x >> 3)] |= 0x80 >> (x & 7);
        }
      }
    }
    return bytes;
  }
}
