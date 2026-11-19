import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/scan/slip_scan.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// স্ক্যানারের ফল — পাঠানোর ফাইল, অথবা "আবার তুলুন"
class SlipScanOutcome {
  const SlipScanOutcome.done(String this.path) : retake = false;
  const SlipScanOutcome.retake()
      : path = null,
        retake = true;

  final String? path;
  final bool retake;
}

/// ⭐ স্লিপ ঠিক করার পর্দা — জমার নমুনা ৩ (মালিক, ২০ নভেম্বর ২০২৬ "ok"): কোনাগুলো নিজে খুঁজে বসায়, ভুল হলে আঙুলে টেনে ঠিক;
/// পরিষ্কার · ধূসর · সাদা-কালো; ↺ ঘোরান; আবার তুলুন; "আসল ছবি 3.4 MB · পাঠাবে ≈ 180 KB"; "এই ছবিটা নিন"।
/// ⓘ ক্যামেরা আর গ্যালারি/WhatsApp দুই পথের ছবিই এখানে আসে। অঙ্ক [SlipScan]-এর (ওয়েবের স্ক্যানারের একই সূত্র)।
Future<SlipScanOutcome?> openSlipScanner(BuildContext context, String path) =>
    Navigator.of(context).push<SlipScanOutcome>(
        MaterialPageRoute(fullscreenDialog: true, builder: (_) => SlipScannerScreen(path: path)));

class SlipScannerScreen extends StatefulWidget {
  const SlipScannerScreen({super.key, required this.path, this.run, this.save});

  final String path;

  /// পরীক্ষার পথ — ভারী কাজ (isolate নয়, সোজা ডাক)
  final Future<Uint8List> Function(SlipJob job)? run;

  /// পরীক্ষার পথ — ফল কোথায় লেখা
  final Future<String> Function(Uint8List bytes)? save;

  @override
  State<SlipScannerScreen> createState() => _SlipScannerScreenState();
}

/// ছবি খোলা, সোজা করা আর পর্দার জন্য ছোট করা — isolate-এ
({Uint8List preview, int width, int height, List<SlipPoint> corners})? _prepare(Uint8List bytes) {
  final decoded = img.decodeImage(bytes);
  if (decoded == null) return null;
  final upright = img.bakeOrientation(decoded);
  final view = upright.width > 900 || upright.height > 900
      ? img.copyResize(upright,
          width: upright.width >= upright.height ? 900 : null, height: upright.height > upright.width ? 900 : null)
      : upright;
  final scale = upright.width / view.width;
  final corners = [for (final c in SlipScan.cornersOrDefault(view)) (x: c.x * scale, y: c.y * scale)];
  return (preview: img.encodeJpg(view, quality: 80), width: upright.width, height: upright.height, corners: corners);
}

class _SlipScannerScreenState extends State<SlipScannerScreen> {
  Uint8List? _original;
  Uint8List? _preview;
  int _width = 1, _height = 1;
  List<SlipPoint> _corners = const [];
  SlipLook _look = SlipLook.clean;
  int _turns = 0;

  Uint8List? _result;
  bool _working = false;
  String? _error;
  Timer? _debounce;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _open();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _open() async {
    try {
      final bytes = await File(widget.path).readAsBytes();
      final ready = widget.run != null ? _prepare(bytes) : await compute(_prepare, bytes);
      if (!mounted) return;
      if (ready == null) {
        setState(() => _error = context.l10n.scanSlipUnreadable);
        return;
      }
      setState(() {
        _original = bytes;
        _preview = ready.preview;
        _width = ready.width;
        _height = ready.height;
        _corners = ready.corners;
      });
      _schedule();
    } catch (_) {
      if (mounted) setState(() => _error = context.l10n.scanSlipUnreadable);
    }
  }

  /// কোনা, চেহারা বা ঘোরানো বদলালে — একটু থেমে নতুন করে বানানো, যাতে "পাঠাবে ≈ … KB" সত্যি মাপ বলে
  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _process);
  }

  Future<Uint8List?> _process() async {
    final original = _original;
    if (original == null) return null;
    final generation = ++_generation;
    setState(() => _working = true);
    try {
      final job = SlipJob(bytes: original, corners: _corners, look: _look, quarterTurns: _turns);
      final out = await (widget.run ?? (j) => compute(SlipScan.process, j))(job);
      if (!mounted || generation != _generation) return out;
      setState(() {
        _result = out;
        _working = false;
      });
      return out;
    } catch (_) {
      if (mounted && generation == _generation) {
        setState(() {
          _working = false;
          _error = context.l10n.scanSlipUnreadable;
        });
      }
      return null;
    }
  }

  Future<void> _accept() async {
    _debounce?.cancel();
    final bytes = (!_working ? _result : null) ?? await _process();
    if (bytes == null || !mounted) return;
    final path = await (widget.save ?? _saveToTemp)(bytes);
    if (mounted) Navigator.of(context).pop(SlipScanOutcome.done(path));
  }

  static Future<String> _saveToTemp(Uint8List bytes) async {
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/slip-${DateTime.now().millisecondsSinceEpoch}.jpg');
    await file.writeAsBytes(bytes, flush: true);
    return file.path;
  }

  static String _size(int bytes) =>
      bytes >= 1024 * 1024 ? '${(bytes / 1024 / 1024).toStringAsFixed(1)} MB' : '${(bytes / 1024).round()} KB';

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final palette = context.palette;
    return Scaffold(
      appBar: AbosScreenBar(
        title: l.scanSlipTitle,
        actions: [
          IconButton(
            key: const ValueKey('slip-accept-top'),
            tooltip: l.scanSlipAccept,
            onPressed: _preview == null ? null : _accept,
            icon: const Icon(Icons.check),
          ),
        ],
      ),
      body: _error != null && _preview == null
          ? Center(child: Padding(padding: const EdgeInsets.all(AppSpacing.lg), child: Text(_error!)))
          : _preview == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  children: [
                    _CornerEditor(
                      key: const ValueKey('slip-corners'),
                      preview: _preview!,
                      width: _width,
                      height: _height,
                      corners: _corners,
                      onChanged: (c) {
                        setState(() => _corners = c);
                        _schedule();
                      },
                    ),
                    const SizedBox(height: AppSpacing.xs),
                    Text(l.scanSlipCornersHint, style: TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted)),
                    const SizedBox(height: AppSpacing.sm),
                    SegmentedButton<SlipLook>(
                      key: const ValueKey('slip-look'),
                      segments: [
                        ButtonSegment(value: SlipLook.clean, label: Text(l.scanSlipClean)),
                        ButtonSegment(value: SlipLook.grey, label: Text(l.scanSlipGrey)),
                        ButtonSegment(value: SlipLook.bw, label: Text(l.scanSlipBw)),
                      ],
                      selected: {_look},
                      onSelectionChanged: (s) {
                        setState(() => _look = s.first);
                        _schedule();
                      },
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Row(children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const ValueKey('slip-rotate'),
                          onPressed: () {
                            setState(() => _turns = (_turns + 3) % 4);
                            _schedule();
                          },
                          icon: const Icon(Icons.rotate_left),
                          label: Text(l.scanSlipRotate),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const ValueKey('slip-retake'),
                          onPressed: () => Navigator.of(context).pop(const SlipScanOutcome.retake()),
                          icon: const Icon(Icons.photo_camera_outlined),
                          label: Text(l.scanSlipRetake),
                        ),
                      ),
                    ]),
                    const SizedBox(height: AppSpacing.sm),
                    Row(children: [
                      Expanded(
                        child: Text(l.scanSlipOriginal(_size(_original?.length ?? 0)),
                            key: const ValueKey('slip-original-size'),
                            style: TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted)),
                      ),
                      Text(
                        _working || _result == null ? l.scanSlipWorking : l.scanSlipWillSend(_size(_result!.length)),
                        key: const ValueKey('slip-send-size'),
                        style: TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted),
                      ),
                    ]),
                    if (_result != null) ...[
                      const SizedBox(height: AppSpacing.sm),
                      // ⓘ যা যাবে তার ছোট ছবি — চেহারা আর ঘোরানো এখানে দেখা যায়
                      Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxHeight: 160),
                          child: Image.memory(_result!, key: const ValueKey('slip-result'), gaplessPlayback: true),
                        ),
                      ),
                    ],
                    const SizedBox(height: AppSpacing.md),
                    FilledButton(
                      key: const ValueKey('slip-accept'),
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                      onPressed: _accept,
                      child: Text(l.scanSlipAccept),
                    ),
                  ],
                ),
    );
  }
}

/// ছবির উপর চার কোনার গোল — আঙুলে টানা যায়; মাঝে চতুর্ভুজের রেখা
class _CornerEditor extends StatelessWidget {
  const _CornerEditor({
    super.key,
    required this.preview,
    required this.width,
    required this.height,
    required this.corners,
    required this.onChanged,
  });

  final Uint8List preview;
  final int width, height;
  final List<SlipPoint> corners;
  final ValueChanged<List<SlipPoint>> onChanged;

  @override
  Widget build(BuildContext context) {
    final accent = Theme.of(context).colorScheme.tertiary;
    return LayoutBuilder(builder: (context, box) {
      final viewW = box.maxWidth;
      final viewH = viewW * height / width;
      const maxH = 360.0;
      final scale = viewH > maxH ? maxH / height : viewW / width;
      final w = width * scale, h = height * scale;
      return Center(
        child: SizedBox(
          width: w,
          height: h,
          child: Stack(clipBehavior: Clip.none, children: [
            Positioned.fill(child: Image.memory(preview, fit: BoxFit.fill, gaplessPlayback: true)),
            Positioned.fill(child: CustomPaint(painter: _QuadPainter([for (final c in corners) Offset(c.x * scale, c.y * scale)], accent))),
            for (final (i, c) in corners.indexed)
              Positioned(
                left: c.x * scale - 22,
                top: c.y * scale - 22,
                child: GestureDetector(
                  key: ValueKey('slip-corner-$i'),
                  behavior: HitTestBehavior.opaque,
                  onPanUpdate: (d) {
                    final next = [...corners];
                    next[i] = (
                      x: (c.x + d.delta.dx / scale).clamp(0, width.toDouble()).toDouble(),
                      y: (c.y + d.delta.dy / scale).clamp(0, height.toDouble()).toDouble(),
                    );
                    onChanged(next);
                  },
                  child: SizedBox(
                    width: 44,
                    height: 44,
                    child: Center(
                      child: Container(
                        width: 20,
                        height: 20,
                        decoration: BoxDecoration(
                          color: accent,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 3), // dark-ok: কোনার গোলের সাদা কিনারা, ছবির উপরে
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ]),
        ),
      );
    });
  }
}

class _QuadPainter extends CustomPainter {
  _QuadPainter(this.points, this.colour);

  final List<Offset> points;
  final Color colour;

  @override
  void paint(Canvas canvas, Size size) {
    if (points.length != 4) return;
    final path = Path()..addPolygon(points, true);
    canvas.drawPath(
        path,
        Paint()
          ..color = colour
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2);
  }

  @override
  bool shouldRepaint(_QuadPainter old) => old.points != points || old.colour != colour;
}
