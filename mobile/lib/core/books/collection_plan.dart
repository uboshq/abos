import 'collection_entry.dart';

/// ⭐ আদায়ের হিসাব — কোন বিলে কত, ক্রেডিট কত, অপ্রয়োগকৃত কত (চূড়ান্ত নকশা, বিষয় ৩ আর ৪; ওয়েবের একই হিসাব)।
///
/// <p>সব টাকা পয়সায় (পূর্ণসংখ্যা) — ভগ্নাংশের ভুল নেই, ওয়েবের bcmath-এর একই ফল। মিল সবসময়:
/// <b>পাওয়া + কাটা (VDS, AIT) + ছাড় = বিলে প্রয়োগ + অপ্রয়োগকৃত</b>, যেখানে বিলে প্রয়োগ = বিলে বসানো − ক্রেডিট থেকে নেওয়া।
///
/// <p>⛔ একটা বিলে তার বাকির বেশি নয়, আর সব বিলে মিলে হাতের টাকার (পাওয়া + ছাড় + ক্রেডিট) বেশি নয় — বেশি চাইলে যতটা
/// যায় ততটাই বসে, আর [setBill] false ফেরায় (পর্দা কারণ দেখায়)।
class CollectionPlan {
  CollectionPlan({List<OpenItem> bills = const [], List<OpenItem> credits = const []})
      : bills = [for (final b in bills) PlanRow(b)],
        credits = [for (final c in credits) PlanRow(c)];

  final List<PlanRow> bills;
  final List<PlanRow> credits;

  /// পাওয়া টাকা, পয়সায়
  int received = 0;

  /// ছাড় বা রাউন্ডিং, পয়সায় (ধনাত্মক)
  int difference = 0;

  /// গ্রাহক কেটে রেখেছে — উৎসে ভ্যাট (VDS) আর উৎসে কর (AIT), পয়সায়; গ্রাহকের পাওনা থেকে কমে, ছাড়ের মতোই
  int withheld = 0;

  int get billsApplied => bills.fold(0, (s, r) => s + r.applied);
  int get creditsApplied => credits.fold(0, (s, r) => s + r.applied);

  /// হাতে যা আছে বিলে বসানোর জন্য
  int get pool => received + withheld + difference + creditsApplied;

  /// বিলে প্রয়োগ (ক্রেডিট বাদে) — নকশার "বিলে প্রয়োগ"
  int get netApplied => billsApplied - creditsApplied;

  /// অপ্রয়োগকৃত — গ্রাহকের খাতায় জমা (Cr) থাকবে
  int get unapplied => pool - billsApplied;

  /// পাওয়া + কাটা + ছাড় = প্রয়োগ + অপ্রয়োগকৃত
  bool get balanced => received + withheld + difference == netApplied + unapplied;

  bool get isEmpty => bills.isEmpty && credits.isEmpty;

  /// ⭐ নিজে থেকে: পুরনো আগে — আগে ক্রেডিট (খোলা বিলের মোট পর্যন্ত), তারপর বিল পুরনো থেকে নতুন, হাতের টাকা যতদূর যায়
  void autoAllocate() {
    final billsOpen = bills.fold(0, (s, r) => s + r.item.open);
    var room = billsOpen;
    for (final c in credits) {
      c.applied = c.item.open < room ? c.item.open : room;
      room -= c.applied;
    }
    var left = pool;
    for (final b in bills) {
      b.applied = b.item.open < left ? b.item.open : left;
      left -= b.applied;
    }
  }

  /// একটা বিলে কত বসানো যায় — তার বাকি, আর হাতের টাকার যা বাকি
  int maxFor(PlanRow row) {
    final free = pool - (billsApplied - row.applied);
    final cap = row.item.open < free ? row.item.open : free;
    return cap < 0 ? 0 : cap;
  }

  /// বিলে [paisa] বসানো; বেশি হলে যতটা যায় ততটা, আর false
  bool setBill(PlanRow row, int paisa) {
    final want = paisa < 0 ? 0 : paisa;
    final max = maxFor(row);
    row.applied = want > max ? max : want;
    return want <= max;
  }

  /// ক্রেডিট থেকে [paisa] নেওয়া; বাকির বেশি নয়, আর এতটা কম নয় যে বসানো বিলের টাকা হাতে না থাকে
  bool setCredit(PlanRow row, int paisa) {
    final want = paisa < 0 ? 0 : paisa;
    final others = received + withheld + difference + creditsApplied - row.applied;
    final lo = billsApplied - others;
    final min = lo < 0 ? 0 : lo;
    final max = row.item.open;
    row.applied = want > max ? max : (want < min ? min : want);
    return want >= min && want <= max;
  }

  /// পাঠানোর লাইন — a5-এর চুক্তি `lines:[{kind, id, amount}]`; বিলে ধনাত্মক, ক্রেডিটে ঋণাত্মক (সার্ভারের `items`-এর একই দিক)
  List<CollectionLine> get lines => [
        for (final b in bills)
          if (b.applied > 0) (kind: b.item.kind, id: b.item.id, amount: paisaText(b.applied)),
        for (final c in credits)
          if (c.applied > 0) (kind: c.item.kind, id: c.item.id, amount: paisaText(-c.applied)),
      ];
}

class PlanRow {
  PlanRow(this.item);

  final OpenItem item;

  /// বসানো, পয়সায়
  int applied = 0;

  bool get ticked => applied > 0;
}
