import 'dart:convert';

/// ⭐ হোম-স্ক্রিন উইজেটের "নথি" — একটা উইজেট কী দেখাবে, ছবির ভাষায় নয়, কথার ভাষায় (পরিকল্পনা ৪, অংশ ৯; মালিকের আদেশ,
/// ২০ অক্টোবর ২০২৬)।
///
/// <p>⭐ ভাগ: প্রতিটা উইজেট কেবল Dart-এ একটা খাঁটি ফাংশন লেখে — সার্ভারের সারাংশ থেকে [WidgetDoc] ([LauncherWidgetKind])।
/// আঁকে একটাই Kotlin আঁকিয়ে (Glance), সব উইজেটের জন্য: মাপ বুঝে বিন্যাস, আলো আর অন্ধকার, সারিতে চাপলে পাতা, আর ফোন তালাবন্ধ
/// থাকলে বা মালিকের সুইচ বন্ধ থাকলে [WidgetText.secret] অঙ্ক লুকানো। তাই ২০টা উইজেটের জন্য ২০টা Kotlin ফাইল নয়, আর প্রতিটা
/// উইজেটের দাবি এই PC-তেই flutter test-এ চলে।
///
/// <p>⛔ নিয়ম (প্রতিটা উইজেটের দাবিতে):
///  - গ্রাহকের নাম সবসময় "নাম · পয়েন্ট · শাখা" (সার্ভার যেমন পাঠায়), সরবরাহকারীর পাশে শাখা;
///  - টাকা `Money.taka` — ইংরেজি অঙ্ক, লাখের কমা — আর টাকার প্রতিটা লেখা [WidgetText.secret];
///  - বেতন কোনো উইজেটে নয়;
///  - [WidgetDoc.asOf] — "কখনকার হিসাব" — সবসময়; পুরনো হলে আঁকিয়ে নিজেই ধূসর করে।
class WidgetDoc {
  const WidgetDoc({
    required this.title,
    required this.asOf,
    this.link,
    this.hero,
    this.rows = const [],
    this.bars = const [],
    this.actions = const [],
    this.empty,
    this.more,
  });

  /// মাথার লেখা — "আজকের বিক্রি"
  final String title;

  /// সার্ভারের হিসাবের সময়
  final DateTime asOf;

  /// পুরো উইজেটে চাপলে কোথায় — ওয়েবের পথ (`/sales/invoices`) বা অ্যাপের পথ (`/home/approvals`)
  final String? link;

  /// বড় অঙ্ক আর তার নিচের এক লাইন
  final WidgetHero? hero;

  /// তালিকা — বড় মাপে বেশি, ছোট মাপে কম; আঁকিয়ে যতটা ধরে ততটা দেখায়, উপর থেকে
  final List<WidgetRow> rows;

  /// দাগ — লক্ষ্য বনাম অর্জন, মজুদের সীমা
  final List<WidgetBar> bars;

  /// দ্রুত কাজের বোতাম
  final List<WidgetAction> actions;

  /// কিছু নেই — "কোনো সই বাকি নেই"। ⓘ খালি তালিকা আর "এখনো আনা হয়নি" এক নয়: আনা না হলে নথিই থাকে না
  final String? empty;

  /// আরও আছে — "আরও ৪টা"
  final String? more;

  Map<String, Object?> toJson() => {
        'v': 1,
        'title': title,
        'asOf': asOf.toUtc().toIso8601String(),
        if (link != null) 'link': link,
        if (hero != null) 'hero': hero!.toJson(),
        if (rows.isNotEmpty) 'rows': [for (final r in rows) r.toJson()],
        if (bars.isNotEmpty) 'bars': [for (final b in bars) b.toJson()],
        if (actions.isNotEmpty) 'actions': [for (final a in actions) a.toJson()],
        if (empty != null) 'empty': empty,
        if (more != null) 'more': more,
      };

  String encode() => jsonEncode(toJson());

  /// নথির সব লেখা — দাবি-টেস্টের জন্য (নাম, টাকা, বেতন খোঁজা)
  Iterable<WidgetText> get texts sync* {
    yield WidgetText(title);
    if (empty != null) yield WidgetText(empty!);
    if (more != null) yield WidgetText(more!);
    if (hero != null) {
      yield hero!.figure;
      if (hero!.caption != null) yield hero!.caption!;
    }
    for (final r in rows) {
      yield r.title;
      if (r.second != null) yield r.second!;
      if (r.amount != null) yield r.amount!;
      if (r.chip != null) yield r.chip!;
    }
    for (final b in bars) {
      yield b.label;
      if (b.value != null) yield b.value!;
    }
  }
}

/// রঙের ধরন — আঁকিয়ে থিমের রঙে বসায় (আলো/অন্ধকার দুটোতে পড়া যায়)
enum WidgetTone { plain, good, bad, warn, muted }

/// এক টুকরো লেখা। [secret] হলে (টাকা) তালাবন্ধ ফোনে বা মালিকের সুইচ বন্ধ থাকলে "••••" হয়ে যায়।
class WidgetText {
  const WidgetText(this.text, {this.secret = false, this.tone = WidgetTone.plain});

  /// টাকার লেখা — সবসময় গোপন-যোগ্য
  const WidgetText.money(this.text, {this.tone = WidgetTone.plain}) : secret = true;

  final String text;
  final bool secret;
  final WidgetTone tone;

  Map<String, Object?> toJson() => {
        't': text,
        if (secret) 's': true,
        if (tone != WidgetTone.plain) 'c': tone.name,
      };
}

class WidgetHero {
  const WidgetHero(this.figure, {this.caption});

  final WidgetText figure;
  final WidgetText? caption;

  Map<String, Object?> toJson() => {'figure': figure.toJson(), if (caption != null) 'caption': caption!.toJson()};
}

class WidgetRow {
  const WidgetRow({required this.title, this.second, this.amount, this.chip, this.link});

  /// গ্রাহক হলে "নাম · পয়েন্ট · শাখা"
  final WidgetText title;

  /// কাগজের নম্বর আগে — "INV-2610-0391 · বিলের ছাড়"
  final WidgetText? second;
  final WidgetText? amount;
  final WidgetText? chip;

  /// এই সারিতে চাপলে কোথায় — ওয়েবের পথ বা অ্যাপের পথ
  final String? link;

  Map<String, Object?> toJson() => {
        'title': title.toJson(),
        if (second != null) 'second': second!.toJson(),
        if (amount != null) 'amount': amount!.toJson(),
        if (chip != null) 'chip': chip!.toJson(),
        if (link != null) 'link': link,
      };
}

class WidgetBar {
  const WidgetBar({required this.label, required this.fraction, this.value, this.tone = WidgetTone.plain});

  final WidgetText label;

  /// ০ থেকে ১ (বেশি হলে আঁকিয়ে ১-এ থামায়)
  final double fraction;
  final WidgetText? value;
  final WidgetTone tone;

  Map<String, Object?> toJson() => {
        'label': label.toJson(),
        'fraction': fraction.clamp(0.0, 1.0),
        if (value != null) 'value': value!.toJson(),
        if (tone != WidgetTone.plain) 'c': tone.name,
      };
}

class WidgetAction {
  const WidgetAction({required this.label, required this.icon, required this.link});

  final String label;

  /// আঁকিয়ের চেনা নাম: `order`, `collect`, `voucher`, `scan`, `approve`, `notice`
  final String icon;
  final String link;

  Map<String, Object?> toJson() => {'label': label, 'icon': icon, 'link': link};
}
