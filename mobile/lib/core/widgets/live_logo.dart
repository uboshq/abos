import 'dart:io' show Platform;
import 'dart:math' as math;

import 'package:flutter/material.dart';

/// ⭐ জীবন্ত ABOS লোগো — A, সোনালি বিন্দু আর "abos"; দশটা নড়াচড়া, প্রতিটা
/// ২.৮ সেকেন্ডের, একটার পর আরেকটা এলোমেলো ক্রমে, মাঝে বিরতি ছাড়া (মালিক,
/// ১ নভেম্বর ২০২৬: "App eo jibonto live logo dibe"; ওয়েবের
/// `x-brand.live-logo`-র একই দশটা)।
///
/// ⓘ প্রতিটা নড়াচড়া স্থির লোগোয় শুরু আর শেষ হয়, তাই জোড়ায় ঝাঁকি নেই।
/// একই নড়াচড়া পরপর দুবার কখনো নয় ([[nextMove]])।
/// ⓘ ফোনে "নড়াচড়া কমান" চালু থাকলে লোগো স্থির।
enum LiveMove { rise, type, wave, roll, shine, draw, hop, beat, line, flip }

/// পরের নড়াচড়া — আগেরটা বাদে বাকি নয়টার যেকোনো একটা।
LiveMove nextMove(LiveMove? previous, math.Random random) {
  final choices = LiveMove.values.where((m) => m != previous).toList();
  return choices[random.nextInt(choices.length)];
}

class LiveLogo extends StatefulWidget {
  const LiveLogo({super.key, this.width = 132, this.random, this.animate});

  final double width;

  /// পরীক্ষার জন্য — নির্দিষ্ট ক্রম চাইলে।
  final math.Random? random;

  /// নড়বে কি না। খালি থাকলে: হ্যাঁ, কেবল `flutter test`-এর ভেতরে না — চিরকাল চলা নড়াচড়া পর্দাকে কখনো
  /// স্থির হতে দেয় না, আর তখন অন্য পর্দার পরীক্ষা অপেক্ষায় আটকে যেত। নড়াচড়ার পরীক্ষা নিজে `animate: true` দেয়।
  final bool? animate;

  static const moveDuration = Duration(milliseconds: 2800);

  @override
  State<LiveLogo> createState() => _LiveLogoState();
}

class _LiveLogoState extends State<LiveLogo> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: LiveLogo.moveDuration);
  late final math.Random _random = widget.random ?? math.Random();
  LiveMove? _move;

  @override
  void initState() {
    super.initState();
    _c.addStatusListener((status) {
      if (status == AnimationStatus.completed) _next();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final still = (MediaQuery.maybeOf(context)?.disableAnimations ?? false) ||
        !(widget.animate ?? !Platform.environment.containsKey('FLUTTER_TEST'));
    if (still) {
      _c.stop();
      _move = null;
    } else if (!_c.isAnimating) {
      _next();
    }
  }

  void _next() {
    setState(() => _move = nextMove(_move, _random));
    _c.forward(from: 0);
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = widget.width;
    final h = w * 239 / 748;
    return Semantics(
      label: 'ABOS',
      image: true,
      child: ExcludeSemantics(
        child: SizedBox(
          width: w,
          height: h,
          child: AnimatedBuilder(
            animation: _c,
            builder: (context, _) => _frame(w, h, _move, _c.value),
          ),
        ),
      ),
    );
  }

  // ── একটা মুহূর্তের ছবি: t ০ থেকে ১ ─────────────────────────────────────
  Widget _frame(double w, double h, LiveMove? move, double t) {
    final iconW = w * .358;
    final dotW = iconW * .226;
    final wordLeft = w * .388;
    final wordTop = h * .155;
    final wordW = w * .612;
    final wordH = h * .653;
    const letters = ['a', 'b', 'o', 's'];
    const lefts = [0.0, .29, .552, .811];
    const widths = [.246, .239, .241, .189];

    // ⓘ "ওঠা-নামা" — ০→peak→০, ঘণ্টার মতো
    double bell(double a, double b) => t <= a || t >= b ? 0 : math.sin(math.pi * (t - a) / (b - a));

    Widget letter(int i) {
      var dy = 0.0;
      var opacity = 1.0;
      var sy = 1.0;
      final delay = i * .045;
      switch (move) {
        case LiveMove.rise:
          final k = bell(delay, .55 + delay);
          dy = k * wordH * .4;
          opacity = 1 - k;
        case LiveMove.type:
          final back = [.30, .42, .54, .66][i];
          opacity = (t > .02 * (4 - i) && t < back) ? 0 : 1;
        case LiveMove.wave:
          final k = bell(delay * 2.6, .45 + delay * 2.6);
          dy = -k * wordH * .16;
        case LiveMove.hop:
          final at = [.20, .38, .56, .74][i];
          sy = 1 - bell(at - .03, at + .04) * .06;
          dy = bell(at - .03, at + .04) * wordH * .08;
        default:
          break;
      }
      return Positioned(
        left: wordW * lefts[i],
        top: dy,
        width: wordW * widths[i],
        height: wordH,
        child: Opacity(
          opacity: opacity.clamp(0, 1),
          child: Transform.scale(
            scaleY: sy,
            alignment: Alignment.bottomCenter,
            child: Image.asset('assets/branding/live/l-${letters[i]}.png', fit: BoxFit.fill),
          ),
        ),
      );
    }

    // বিন্দুর নড়াচড়া
    var dotDx = 0.0, dotDy = 0.0, dotScale = 1.0, dotTurn = 0.0, dotGlow = 0.0, dotOpacity = 1.0;
    switch (move) {
      case LiveMove.roll:
        final k = bell(0, 1);
        dotDx = k * dotW * 7.6;
        dotTurn = k * 4 * math.pi;
      case LiveMove.beat:
        dotScale = 1 + .28 * bell(0, .22) + .28 * bell(.44, .66) + .12 * bell(.22, .44) + .12 * bell(.66, .88);
        dotGlow = bell(0, .22) + bell(.44, .66);
      case LiveMove.wave:
        dotScale = 1 + .2 * bell(0, .3);
      case LiveMove.type:
        dotOpacity = ((t * 10).floor() % 2 == 0) ? 1 : .25;
      case LiveMove.shine:
        dotGlow = bell(.6, .86);
      case LiveMove.line:
      case LiveMove.flip:
        dotGlow = move == LiveMove.line ? bell(0, .25) : bell(.5, .75);
      case LiveMove.hop:
        // অক্ষর থেকে অক্ষরে লাফ — চারটা চূড়া, তারপর ঘরে ফেরা
        final hops = [.20, .38, .56, .74];
        final xs = [1.85, 3.75, 5.55, 7.1];
        if (t < .88) {
          var x = 0.0;
          for (var i = 0; i < 4; i++) {
            if (t >= (i == 0 ? 0 : hops[i - 1]) && t < hops[i]) {
              final from = i == 0 ? 0.0 : xs[i - 1];
              final f = (t - (i == 0 ? 0 : hops[i - 1])) / (hops[i] - (i == 0 ? 0 : hops[i - 1]));
              x = from + (xs[i] - from) * f;
              dotDy = -math.sin(math.pi * f) * dotW * 1.2 - dotW * 2.15;
            }
          }
          if (t >= hops[3]) {
            final f = (t - hops[3]) / (.88 - hops[3]);
            x = xs[3] * (1 - f);
            dotDy = -dotW * 2.15 * (1 - f);
          }
          if (t < hops[0]) dotDy = -dotW * 2.15 * (t / hops[0]) - math.sin(math.pi * t / hops[0]) * dotW;
          dotDx = x * dotW;
        }
      default:
        break;
    }

    // A — আঁকা হওয়া আর ঘুরে আসা
    var iconClip = 0.0;
    var iconTurn = 0.0;
    var wordClip = 0.0;
    var wordShift = 0.0;
    var wordFade = 1.0;
    if (move == LiveMove.draw) {
      iconClip = t < .12 ? t / .12 : (t < .5 ? 1 - (t - .12) / .38 : 0);
      wordClip = t < .12 ? t / .12 : (t < .4 ? 1 : (t < .75 ? 1 - (t - .4) / .35 : 0));
    }
    if (move == LiveMove.flip) {
      iconTurn = t < .6 ? 2 * math.pi * (t / .6) : 0;
      wordShift = bell(0, .6) * wordW * .08;
      wordFade = 1 - .6 * bell(0, .6);
    }

    const gold = Color(0xFFF5B800);
    final icon = Stack(clipBehavior: Clip.none, children: [
      Positioned.fill(child: Image.asset('assets/branding/live/a.png', fit: BoxFit.fill)),
      Positioned(
        left: iconW * .7815 + dotDx,
        top: h * .75 + dotDy,
        width: dotW,
        height: dotW,
        child: Opacity(
          opacity: dotOpacity,
          child: Transform.rotate(
            angle: dotTurn,
            child: Transform.scale(
              scale: dotScale,
              child: DecoratedBox(
                decoration: BoxDecoration(shape: BoxShape.circle, boxShadow: [
                  if (dotGlow > 0) BoxShadow(color: gold.withValues(alpha: .8 * dotGlow), blurRadius: 10 * dotGlow),
                ]),
                child: Image.asset('assets/branding/live/dot.png', fit: BoxFit.fill),
              ),
            ),
          ),
        ),
      ),
    ]);

    return Stack(clipBehavior: Clip.none, children: [
      Positioned(
        left: 0,
        top: 0,
        width: iconW,
        height: h,
        child: Transform(
          alignment: Alignment.center,
          transform: Matrix4.identity()
            ..setEntry(3, 2, .002)
            ..rotateY(iconTurn),
          child: ClipRect(
            clipper: _FromBottom(iconClip),
            clipBehavior: Clip.none,
            child: icon,
          ),
        ),
      ),
      Positioned(
        left: wordLeft + wordShift,
        top: wordTop,
        width: wordW,
        height: wordH,
        child: Opacity(
          opacity: wordFade,
          child: ClipRect(
            clipper: _FromRight(wordClip),
            child: Stack(clipBehavior: Clip.none, children: [for (var i = 0; i < 4; i++) letter(i)]),
          ),
        ),
      ),
      if (move == LiveMove.line)
        Positioned(
          left: w * .30,
          bottom: h * .06,
          width: w * .70 * (t < .5 ? t / .5 : 1),
          height: h * .04,
          child: Opacity(
            opacity: t < .75 ? 1 : (1 - (t - .75) / .25),
            child: const DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.all(Radius.circular(4)),
                gradient: LinearGradient(colors: [Color(0xFFB88A00), Color(0xFFF5B800), Color(0xFFFFD54F)]),
              ),
            ),
          ),
        ),
      if (move == LiveMove.shine)
        Positioned.fill(
          child: IgnorePointer(
            child: Opacity(
              opacity: t < .7 ? .75 : (1 - (t - .7) / .3) * .75,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment(-3 + 6 * t, -.4),
                    end: Alignment(-2 + 6 * t, .4),
                    colors: const [Color(0x00FFFFFF), Color(0xCCFFFFFF), Color(0x99FFECAA), Color(0x00FFFFFF)],
                    stops: const [.0, .45, .55, 1],
                  ),
                ),
              ),
            ),
          ),
        ),
    ]);
  }
}

/// নিচ থেকে কেটে রাখা — `hidden` ১ মানে পুরোটা লুকানো
class _FromBottom extends CustomClipper<Rect> {
  const _FromBottom(this.hidden);
  final double hidden;
  @override
  Rect getClip(Size size) => Rect.fromLTRB(-size.width, size.height * hidden, size.width * 2, size.height * 2);
  @override
  bool shouldReclip(_FromBottom old) => old.hidden != hidden;
}

/// ডান থেকে কেটে রাখা — `hidden` ১ মানে পুরোটা লুকানো
class _FromRight extends CustomClipper<Rect> {
  const _FromRight(this.hidden);
  final double hidden;
  @override
  Rect getClip(Size size) => Rect.fromLTRB(-size.width, -size.height, size.width * (1 - hidden), size.height * 2);
  @override
  bool shouldReclip(_FromRight old) => old.hidden != hidden;
}
