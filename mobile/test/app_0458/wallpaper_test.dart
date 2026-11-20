import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/core/theme/glass_wallpaper.dart';
import 'package:abos_mobile/features/profile/look_screen.dart';

/// ⭐ Liquid Glass-এর পটভূমি (মালিক, ২০ নভেম্বর ২০২৬): নয়টা, সবই নিজেদের আঁকা; শুরুতে রঙিন বল; লেখা সব পটে পড়া যায়;
/// বেগুনি ঢেউ বাছাইয়ে প্রথমে, কিন্তু শুরুর পট নয়
AppLooks _memory(Map<String, String> box) => AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);

double _contrast(Color a, Color b) {
  final la = a.computeLuminance(), lb = b.computeLuminance();
  return ((la > lb ? la : lb) + 0.05) / ((la > lb ? lb : la) + 0.05);
}

void main() {
  late AppLooks saved;
  setUp(() => saved = AppLooks.instance);
  tearDown(() => AppLooks.instance = saved);

  test('nine backgrounds, purple wave first; the colour balls are still the default; a choice is kept and read back', () async {
    expect(GlassWallpaper.values, hasLength(9));
    expect(GlassWallpaper.values.first, GlassWallpaper.purpleWave);
    final box = <String, String>{};
    final first = _memory(box);
    await first.load();
    expect(first.wallpaper.value, GlassWallpaper.balls, reason: 'শুরুতে আগের রঙিন বল');
    await first.chooseWallpaper(GlassWallpaper.purple);
    final next = _memory(box);
    await next.load();
    expect(next.wallpaper.value, GlassWallpaper.purple);
    await next.chooseWallpaper(GlassWallpaper.purpleWave);
    final third = _memory(box);
    await third.load();
    expect(third.wallpaper.value, GlassWallpaper.purpleWave);
    expect(AppLooks.parseWallpaper('nonsense'), GlassWallpaper.balls);
  });

  // ⓘ সবচেয়ে খারাপ অবস্থা: প্রতিটা রং (আভা আর ঢেউ নিজের স্বচ্ছতায়, পটের সবচেয়ে উজ্জ্বল রঙের ওপর), তার ওপর কালচে পর্দা,
  // তার ওপর সবচেয়ে ঘন কাচের সাদা আভা (১৮%) — সাদা লেখা তবু ৪.৫:১ (WCAG 1.4.3)। ⓘ দুই আভা একে অন্যের ওপর পড়লে একটু
  // উজ্জ্বল হতে পারে — এটা এক-স্তরের হিসাব
  // ⓘ বেগুনি ঢেউ এই হিসাবে নয় — নিচে আঁকা ছবির পিক্সেল ধরে, যেখানে লেখা বসে
  for (final paper in GlassWallpaper.values.where((w) => w != GlassWallpaper.balls && w != GlassWallpaper.purpleWave)) {
    test('white text on the glass reads at 4.5:1 everywhere on "${paper.name}"', () {
      final spec = paper.spec;
      final brightest = spec.base.reduce((a, b) => a.computeLuminance() >= b.computeLuminance() ? a : b);
      final points = <String, Color>{
        for (final c in spec.base) 'base $c': c,
        for (final g in spec.glows) 'glow ${g.color}': Color.alphaBlend(g.color.withValues(alpha: g.alpha), brightest),
        for (final w in spec.waves) ...{
          'wave ${w.to}': Color.alphaBlend(w.to.withValues(alpha: w.alpha), brightest),
          'wave ${w.from}': Color.alphaBlend(w.from.withValues(alpha: w.alpha), brightest),
        },
      };
      final glass = GlassSpec.of(100).fill;
      for (final MapEntry(key: where, value: c) in points.entries) {
        final scrimmed = Color.alphaBlend(Color.fromRGBO(0, 0, 0, spec.scrim), c);
        final underGlass = Color.alphaBlend(Colors.white.withValues(alpha: glass), scrimmed);
        expect(_contrast(Colors.white, underGlass), greaterThanOrEqualTo(4.5), reason: '${paper.name}: $where');
      }
    });
  }

  // ⭐ বেগুনি ঢেউ — মালিকের নমুনার মতো উজ্জ্বল (পর্দা ০.১২), তাই পুরো পটে ৪.৫:১ নয়; মাপা হয় আঁকা ছবির প্রতিটা পিক্সেলে, কেবল
  // যেখানে লেখা বসে — হোমের ৪১২×৯০০ ছবির (home_glass_purpleWave.png) জায়গা ধরে:
  //  - উপরের বার: কোম্পানি-শাখার লেখা ৪.৫:১, ডানের চিহ্ন ৩:১ (WCAG 1.4.11) — উপরের কালো পট্টি এটা রাখে;
  //  - বাঁ-উপরের শুভেচ্ছা আর শিরোনাম, সরাসরি পটে — ৪.৫:১;
  //  - কাচের কার্ডের ভেতরের লেখা — পট কার্ডের ঝাপসায় (BackdropFilter-এর একই sigma) আর সাদা আভায়, তার ওপর ৪.৫:১;
  //    ঝাপসা ১৫ থেকে (শুরুর ৪০ সহ)। ⓘ ১৫-র নিচে ("একদম স্বচ্ছ") উজ্জ্বল ফিতার ওপর ২.১:১ পর্যন্ত নামে — সেখানে লেখার
  //    নিজের ছায়া ভরসা, এটা সেই বাছাইয়ের জেনে-নেওয়া দাম
  group('purple wave: white text reads where text sits', () {
    const size = Size(412, 900);
    const barText = Rect.fromLTRB(50, 4, 176, 50);
    const barIcons = Rect.fromLTRB(268, 8, 402, 50);
    const greetingAndTitle = Rect.fromLTRB(12, 70, 158, 128);
    const cards = Rect.fromLTRB(14, 146, 398, 528);

    Future<ByteData> render(WidgetTester tester, {int? glassLevel}) async => (await tester.runAsync(() async {
          final recorder = ui.PictureRecorder();
          final canvas = Canvas(recorder);
          if (glassLevel == null) {
            const PurpleWavePainter().paint(canvas, size);
          } else {
            final spec = GlassSpec.of(glassLevel);
            final inner = ui.PictureRecorder();
            const PurpleWavePainter().paint(Canvas(inner), size);
            canvas.saveLayer(Offset.zero & size,
                Paint()..imageFilter = ui.ImageFilter.blur(sigmaX: spec.sigma, sigmaY: spec.sigma));
            canvas.drawPicture(inner.endRecording());
            canvas.restore();
            canvas.drawRect(Offset.zero & size, Paint()..color = Colors.white.withValues(alpha: spec.fill));
          }
          final image = await recorder.endRecording().toImage(size.width.toInt(), size.height.toInt());
          return (await image.toByteData(format: ui.ImageByteFormat.rawRgba))!;
        }))!;

    /// সাদা লেখার সবচেয়ে কম তুলনা — অঞ্চলের প্রতিটা পিক্সেলে
    double worst(ByteData pixels, Rect region) {
      var low = double.infinity;
      for (var y = region.top.toInt(); y < region.bottom; y++) {
        for (var x = region.left.toInt(); x < region.right; x++) {
          final i = (y * size.width.toInt() + x) * 4;
          final c = Color.fromARGB(255, pixels.getUint8(i), pixels.getUint8(i + 1), pixels.getUint8(i + 2));
          final ratio = _contrast(Colors.white, c);
          if (ratio < low) low = ratio;
        }
      }
      return low;
    }

    testWidgets('the top bar band: text 4.5:1, icons 3:1', (tester) async {
      final bare = await render(tester);
      expect(worst(bare, barText), greaterThanOrEqualTo(4.5), reason: 'কোম্পানি আর শাখার লেখা');
      expect(worst(bare, barIcons), greaterThanOrEqualTo(3), reason: 'ডানের চিহ্ন — ডান-উপরের উজ্জ্বল গোলাপির ওপর');
    });

    testWidgets('the greeting and title at the top-left: 4.5:1', (tester) async {
      expect(worst(await render(tester), greetingAndTitle), greaterThanOrEqualTo(4.5));
    });

    testWidgets('text inside a glass card, over the card blur and tint: 4.5:1 from blur 15 up', (tester) async {
      for (final level in [15, AppLooks.defaultBlur, 100]) {
        expect(worst(await render(tester, glassLevel: level), cards), greaterThanOrEqualTo(4.5), reason: 'ঝাপসা $level');
      }
    });

    test('the picture stays vivid: a light scrim, a top band that fades out by 22%', () {
      expect(PurpleWavePainter.scrim, 0.12);
      expect(PurpleWavePainter.topBand, 0.45);
      expect(PurpleWavePainter.topBandReach, 0.22);
    });
  });

  testWidgets('a chosen background is a still picture (cached), not the moving balls', (tester) async {
    AppLooks.instance = _memory({});
    AppLooks.instance.look.value = AppLook.glass;
    await tester.pumpWidget(const MaterialApp(home: GlassBackdrop(child: SizedBox.expand())));
    expect(find.byKey(const ValueKey('wallpaper-purple')), findsNothing);
    await AppLooks.instance.chooseWallpaper(GlassWallpaper.purple);
    await tester.pump();
    final paint = tester.widget<CustomPaint>(find.byKey(const ValueKey('wallpaper-purple')));
    expect(paint.isComplex, isTrue);
    expect(paint.willChange, isFalse);
    expect(find.ancestor(of: find.byKey(const ValueKey('wallpaper-purple')), matching: find.byType(RepaintBoundary)), findsWidgets);
    // ⓘ নড়ে না — আর কোনো ফ্রেম বাকি নেই
    expect(tester.hasRunningAnimations, isFalse);
  });

  test('purple wave is drawn by its own still painter, the others by the shared one', () {
    expect(GlassWallpaper.purpleWave.painter, isA<PurpleWavePainter>());
    expect(GlassWallpaper.purpleWave.painter.shouldRepaint(const PurpleWavePainter()), isFalse);
    for (final w in GlassWallpaper.values.where((w) => w != GlassWallpaper.purpleWave)) {
      expect(w.painter, isA<WallpaperPainter>(), reason: w.name);
    }
  });

  testWidgets('purple wave is a still picture (cached) too', (tester) async {
    AppLooks.instance = _memory({});
    AppLooks.instance.look.value = AppLook.glass;
    await AppLooks.instance.chooseWallpaper(GlassWallpaper.purpleWave);
    await tester.pumpWidget(const MaterialApp(home: GlassBackdrop(child: SizedBox.expand())));
    final paint = tester.widget<CustomPaint>(find.byKey(const ValueKey('wallpaper-purpleWave')));
    expect(paint.painter, isA<PurpleWavePainter>());
    expect(paint.isComplex, isTrue);
    expect(paint.willChange, isFalse);
    expect(tester.takeException(), isNull);
    expect(tester.hasRunningAnimations, isFalse);
  });

  testWidgets('the picker: only under Liquid Glass, nine swatches with names, purple wave first, a tap chooses', (tester) async {
    final looks = _memory({});
    AppLooks.instance = looks;
    tester.view.physicalSize = const Size(412, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: LookScreen(looks: looks)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('look-wallpaper-purple')), findsNothing, reason: 'ম্যাটেরিয়াল/ক্লাসিকে পটভূমি নেই');
    await tester.tap(find.byKey(const ValueKey('look-glass')));
    await tester.pumpAndSettle();
    for (final w in GlassWallpaper.values) {
      expect(find.byKey(ValueKey('look-wallpaper-${w.name}')), findsOneWidget, reason: w.name);
    }
    expect(find.text('গ্লাস চেহারার পটভূমি'), findsOneWidget);
    for (final name in ['বেগুনি ঢেউ', 'রঙিন বল', 'বেগুনি আভা', 'নীল ঢেউ', 'সূর্যাস্ত', 'সবুজ আলো', 'সমুদ্র', 'রাতের কালো', 'রুপালি']) {
      expect(find.text(name), findsOneWidget, reason: name);
    }
    // ⭐ বেগুনি ঢেউ প্রথম ঘর — রঙিন বলের আগে
    final wave = tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-purpleWave')));
    final balls = tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-balls')));
    expect(wave.dy, balls.dy);
    expect(wave.dx, lessThan(balls.dx));
    expect(looks.wallpaper.value, GlassWallpaper.balls, reason: 'প্রথমে থাকলেও শুরুর পট রঙিন বল');
    // ⓘ পটভূমি কাচের ঝাপসার দাগের ঠিক ওপরে
    expect(tester.getTopLeft(find.byKey(const ValueKey('look-wallpaper-balls'))).dy,
        lessThan(tester.getTopLeft(find.byKey(const ValueKey('look-blur'))).dy));
    await tester.tap(find.byKey(const ValueKey('look-wallpaper-sunset')));
    await tester.pumpAndSettle();
    expect(looks.wallpaper.value, GlassWallpaper.sunset);
  });
}
