import 'package:flutter/material.dart';

import '../../core/records/notice_bar.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ ওয়েবের চলমান নোটিশ, ফোনের হোমের মাথার নিচে — মালিক, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত:
/// "ওয়েবের চলমান নোটিশ ফোনেও দেখাবে")।
///
/// <p>ওয়েবের বারের নিয়মগুলোই ([[statusbar.blade.php]]):
/// <ul>
///   <li>কিছু না থাকলে বারটা চুপ — কোনো ফাঁকা পট্টি নয় ("সব ঠিক আছে" ঘুরলে মানুষ তাকানো বন্ধ করে)</li>
///   <li>লেখাটা চলে, আর ধরে গেলে স্থির — চলার দরকার নেই</li>
///   <li>যিনি ফোনে নড়াচড়া বন্ধ রেখেছেন, তাঁর পট্টি নড়ে না (ওয়েবের `motion-safe`)</li>
///   <li>নীল — খবর, সতর্কতা নয় (ওয়েবের `info`)</li>
/// </ul>
/// <p>চাপলে পুরো লেখাগুলো — চলন্ত লেখা থেকে কেউ পুরোটা পড়তে না পারলে তার পথ।
///
/// <p>ⓘ টাইমারে পোল করে না: হোম খুললে, অ্যাপ সামনে ফিরলে আর কোম্পানি বদলালে (নতুন key) আবার আনে —
/// নোটিশ মিনিটে মিনিটে বদলায় না, আর পটভূমির টাইমার ব্যাটারি খায়।
class NoticeTicker extends StatefulWidget {
  const NoticeTicker({super.key, this.fetch});

  /// পরীক্ষার জন্য — না দিলে সার্ভার
  final Future<List<NoticeBarItem>> Function()? fetch;

  @override
  State<NoticeTicker> createState() => _NoticeTickerState();
}

/// ওয়েবের `--color-badge-info-ink`-এর কাছাকাছি নীল, হালকা জমিনে
const _ink = Color(0xFF1D4E89);
const _ground = Color(0xFFEAF2FB);

/// পর্দায় সেকেন্ডে কত পিক্সেল সরে — পড়ার মতো ধীরে
const _pixelsPerSecond = 40.0;

class _NoticeTickerState extends State<NoticeTicker>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  List<NoticeBarItem> _items = const [];
  late final AnimationController _run = AnimationController(vsync: this);
  double _loopWidth = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _run.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load();
  }

  Future<void> _load() async {
    try {
      final items = await (widget.fetch ?? NoticeBarApi.fetch)();
      if (mounted) setState(() => _items = items);
    } catch (_) {
      // ⓘ সিগন্যাল নেই বা পুরনো সার্ভার — আগের লাইন থাকে, না থাকলে চুপ
    }
  }

  String get _line => _items.map((n) => '●  ${n.title}').join('        ');

  void _showAll() {
    showModalBottomSheet<void>(
      context: context,
      builder: (sheet) => SafeArea(
        child: ListView(
          key: const ValueKey('notice-ticker-sheet'),
          shrinkWrap: true,
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Text('নোটিশ', style: Theme.of(sheet).textTheme.titleMedium),
            for (final n in _items)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.campaign_outlined, color: _ink),
                title: Text(n.title),
              ),
          ],
        ),
      ),
    );
  }

  /// লাইনটা কত চওড়া হলে চালাতে হবে, কত সময়ে এক পাক — না ধরলে চলে, ধরলে থামে
  void _fit(double lineWidth, double room, bool still) {
    final loop = lineWidth + 48;
    final moves = !still && lineWidth > room;
    if (!moves) {
      if (_run.isAnimating) _run.stop();
      _loopWidth = 0;
      return;
    }
    if (_loopWidth != loop || !_run.isAnimating) {
      _loopWidth = loop;
      _run.duration =
          Duration(milliseconds: (loop / _pixelsPerSecond * 1000).round());
      // ⓘ বিল্ডের মাঝে নয় — ফ্রেম শেষে চালু, নইলে চলার প্রথম খবরটা বিল্ডের ভেতরেই আসত
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && _loopWidth > 0) _run.repeat();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_items.isEmpty) {
      if (_run.isAnimating) _run.stop();
      return const SizedBox.shrink(key: ValueKey('notice-ticker-quiet'));
    }

    const style =
        TextStyle(color: _ink, fontSize: 13, fontWeight: FontWeight.w500);
    final still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    return Material(
      key: const ValueKey('notice-ticker'),
      color: _ground,
      child: InkWell(
        onTap: _showAll,
        child: SizedBox(
          height: 32,
          child: Row(
            children: [
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: AppSpacing.sm),
                child: Icon(Icons.campaign_outlined, size: 18, color: _ink),
              ),
              Expanded(
                child: LayoutBuilder(builder: (context, box) {
                  final painter = TextPainter(
                    text: TextSpan(text: _line, style: style),
                    textDirection: TextDirection.ltr,
                    maxLines: 1,
                  )..layout();
                  final lineWidth = painter.width;
                  painter.dispose();
                  _fit(lineWidth, box.maxWidth, still);

                  if (_loopWidth == 0) {
                    return Align(
                      alignment: Alignment.centerLeft,
                      child: Text(_line,
                          key: const ValueKey('notice-ticker-text'),
                          style: style,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis),
                    );
                  }

                  // ⓘ দুই কপি পাশাপাশি, এক কপির সমান সরে — লুপটা নির্বিঘ্ন (ওয়েবের একই কৌশল)
                  return ClipRect(
                    child: AnimatedBuilder(
                      animation: _run,
                      builder: (context, child) => Transform.translate(
                        offset: Offset(-_run.value * _loopWidth, 0),
                        child: child,
                      ),
                      child: OverflowBox(
                        alignment: Alignment.centerLeft,
                        maxWidth: double.infinity,
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            SizedBox(
                              width: _loopWidth,
                              child: Text(_line,
                                  key: const ValueKey('notice-ticker-text'),
                                  style: style,
                                  maxLines: 1,
                                  softWrap: false),
                            ),
                            ExcludeSemantics(
                              child: SizedBox(
                                width: _loopWidth,
                                child: Text(_line,
                                    style: style, maxLines: 1, softWrap: false),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
                }),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
