import 'dart:io' show Platform;
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

/// ⭐ কোপাইলটের জীবন্ত মগজ — মালিকের বাছা "ঘ" (ক + খ + গ একসাথে, ১২ নভেম্বর ২০২৬; নমুনা Ve4fMPX3g6ksYwUSyDG99o):
/// রঙিন মগজ, রং ধীরে ঘোরে, চারপাশে দুইটা স্ফুলিঙ্গ। টপ বারে গোল বা বাক্স নেই ("শুধু ব্রেনটা আর জীবন্তটা")।
///
/// <p>ⓘ রংগুলো ব্র্যান্ডের — আলো আর অন্ধকার দুই মোডেই একই, পটের রং ছোঁয় না। "কম নড়াচড়া" চালু থাকলে স্থির।
class CopilotMark extends StatefulWidget {
  const CopilotMark({super.key, this.size = 26, this.listening = false});

  final double size;

  /// শোনার সময় একটু বড়-ছোট হয় — Siri-র আলোর মতো
  final bool listening;

  /// ⓘ বেগুনি → গোলাপি → সবুজাভ নীল — ওয়েবের `.cq-s1/2/3`-এর একই তিন রং
  static const colors = [Color(0xFF7C3AED), Color(0xFFDB2777), Color(0xFF0891B2)]; // dark-ok: ব্র্যান্ডের মগজ, দুই মোডেই একই

  @override
  State<CopilotMark> createState() => _CopilotMarkState();
}

final bool _underTest = !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');

class _CopilotMarkState extends State<CopilotMark> with SingleTickerProviderStateMixin {
  late final AnimationController _spin = AnimationController(vsync: this, duration: const Duration(seconds: 6));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // ⓘ পরীক্ষায় স্থির — অবিরাম ঘোরা `pumpAndSettle`-কে কখনো থামতে দিত না (মগজটা বারে থাকলে প্রতিটা হোমের পরীক্ষা আটকাত)
    final still = (MediaQuery.maybeDisableAnimationsOf(context) ?? false) || _underTest;
    if (still) {
      _spin.stop();
    } else if (!_spin.isAnimating) {
      _spin.repeat();
    }
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    return ExcludeSemantics(
      child: SizedBox.square(
        dimension: s,
        child: AnimatedBuilder(
          animation: _spin,
          builder: (context, _) {
            final t = _spin.value;
            final pulse = widget.listening ? 1 + 0.08 * math.sin(t * math.pi * 8) : 1.0;
            return Stack(alignment: Alignment.center, clipBehavior: Clip.none, children: [
              Transform.scale(
                scale: pulse,
                child: ShaderMask(
                  blendMode: BlendMode.srcIn,
                  shaderCallback: (rect) => SweepGradient(
                    colors: [...CopilotMark.colors, CopilotMark.colors.first],
                    transform: GradientRotation(t * 2 * math.pi),
                  ).createShader(rect),
                  child: Icon(Symbols.neurology, size: s, fill: 1, weight: 500),
                ),
              ),
              // ⓘ দুইটা স্ফুলিঙ্গ — উল্টো দিকে, মগজের কিনারা ঘেঁষে
              for (final (i, phase) in [0.0, 0.5].indexed)
                Transform.translate(
                  offset: Offset.fromDirection((t + phase) * 2 * math.pi * (i == 0 ? 1 : -1), s * 0.5),
                  child: Container(
                    width: s * 0.14,
                    height: s * 0.14,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: CopilotMark.colors[i == 0 ? 1 : 2],
                      boxShadow: [BoxShadow(color: CopilotMark.colors[i == 0 ? 1 : 2].withValues(alpha: 0.6), blurRadius: s * 0.12)],
                    ),
                  ),
                ),
            ]);
          },
        ),
      ),
    );
  }
}
