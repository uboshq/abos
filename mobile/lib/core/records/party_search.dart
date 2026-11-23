/// ⭐ পক্ষ খোঁজার একটাই নিয়ম, ফোনের ভেতরে — সার্ভারের `App\Core\Support\PartySearch`-এর হুবহু রূপ (মালিক, ২৩ নভেম্বর ২০২৬):
/// *পক্ষকে নাম, মোবাইল, ঠিকানা বা পয়েন্ট — যেকোনোটা দিয়ে খুঁজে পেতে হবে*। অফলাইনে জমা পক্ষের তালিকা তাই অনলাইনের মতোই মেলে।
///
/// নিয়ম:
///   - প্রতিটা শব্দ কোনো না কোনো ঘরে মিলতে হবে (শব্দে শব্দে AND, ঘরে ঘরে OR), বড়-ছোট হাতের অক্ষর মানা হয় না।
///   - সংখ্যার মতো পুরো লেখা (স্পেস, ড্যাশ, +88 সহ) এক টুকরো: খালি অঙ্ক, আর ফোনের ঘরে ফাঁক-ড্যাশ বাদ দিয়ে "ভেতরে আছে" —
///     তাই মোবাইলের শেষ চার অঙ্কেও পক্ষ আসে।
library;

/// এর বেশি শব্দ এলে বাকিগুলো বাদ — সার্ভারের `MAX_WORDS`।
const int _maxWords = 6;

/// সংখ্যার মতো লেখা হলে খালি অঙ্ক — "+880 1711-223344" → "01711223344"; না হলে null।
/// চার অঙ্কের কম হলে null — সার্ভারের `PartySearch::phoneDigits()`।
String? partyPhoneDigits(String term) {
  final t = term.trim();
  if (t.isEmpty || !RegExp(r'^\+?[\d\s\-().]+$').hasMatch(t)) return null;
  var digits = t.replaceAll(RegExp(r'\D+'), '');
  if (digits.length > 11 && digits.startsWith('880')) digits = digits.substring(2);
  return digits.length >= 4 ? digits : null;
}

/// লেখাটা টুকরো করে — প্রতিটা (শব্দ, অঙ্ক বা null)। সংখ্যার মতো পুরো লেখা একটাই টুকরো।
List<({String word, String? digits})> partySearchWords(String term) {
  final t = term.trim();
  if (t.isEmpty) return const [];
  final whole = partyPhoneDigits(t);
  if (whole != null) return [(word: whole, digits: whole)];
  final words = <String>[];
  for (final w in t.split(RegExp(r'\s+'))) {
    if (w.isNotEmpty && !words.contains(w)) words.add(w);
  }
  return [for (final w in words.take(_maxWords)) (word: w, digits: partyPhoneDigits(w))];
}

/// [query]-র প্রতিটা শব্দ [fields] (নাম, কোড, ঠিকানা, পয়েন্ট …) বা [phones]-এর কোনোটায় মেলে কি না। খালি খোঁজায় true।
bool partyMatches(String query, {required Iterable<String?> fields, Iterable<String?> phones = const []}) {
  final words = partySearchWords(query);
  if (words.isEmpty) return true;
  final texts = [
    for (final f in [...fields, ...phones])
      if (f != null && f.trim().isNotEmpty) f.toLowerCase(),
  ];
  final phoneDigits = [
    for (final p in phones)
      if (p != null) p.replaceAll(RegExp(r'[\s\-+.]'), ''),
  ];
  for (final w in words) {
    final needle = w.word.toLowerCase();
    final hit = texts.any((t) => t.contains(needle)) ||
        (w.digits != null && phoneDigits.any((p) => p.contains(w.digits!)));
    if (!hit) return false;
  }
  return true;
}
