import '../../../core/records/party_search.dart';

/// ⭐ বার্তার খোঁজা — হোয়াটসঅ্যাপের মতো সাথে সাথে (২৩ নভেম্বর ২০২৬)।
///
/// বার্তার তিনটা খোঁজার ঘর (বার্তার তালিকা, মানুষ বাছাই, "বার্তায় পাঠান") সার্ভারকে জিজ্ঞেস করে — সেই ডাক
/// [SearchDebouncer]-এর নিয়মেই থাকে (৩ অক্ষর, ৬০০ ms, Enter সাথে সাথে)। কিন্তু ফোনে যা আগে থেকে আছে,
/// তা প্রতিটা চাপেই ছেঁকে দেখানো হয় — সার্ভারের উত্তর এলে সেটাই বসে (যেমন বার্তার ভেতরের লেখায় মিল)।
///
/// - [typed] = বাক্সে এখন যা লেখা (ছাঁটা)।
/// - [answered] = সার্ভার শেষ যে লেখার ([answeredFor]) উত্তর দিয়েছে।
/// - [everything] = খালি খোঁজায় আনা পুরো তালিকা।
///
/// লেখা ঠিক [answeredFor] হলে সার্ভারের উত্তরই, হাত না দিয়ে। লেখা সেটা থেকে লম্বা হলে সেই উত্তর থেকেই ছাঁকা;
/// না হলে পুরো তালিকা থেকে। মেলার নিয়ম [partyMatches]-এর: প্রতিটা শব্দ কোনো না কোনো ঘরে, বড়-ছোট হাত মানা হয় না।
List<T> narrowNow<T>({
  required String typed,
  required String answeredFor,
  required List<T> answered,
  required List<T> everything,
  required Iterable<String?> Function(T row) fields,
}) {
  final text = typed.trim();
  if (text == answeredFor) return answered;
  final from = answeredFor.isNotEmpty && text.startsWith(answeredFor) ? answered : everything;
  if (text.isEmpty) return from;
  return [
    for (final row in from)
      if (partyMatches(text, fields: fields(row))) row,
  ];
}
