import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// ⭐ কাগজের নম্বর, পাশে পক্ষ — এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের
/// পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*)।
///
/// <p>পক্ষ আসে সার্ভারের আলাদা ঘর `party` থেকে — গ্রাহক হলে "নাম · পয়েন্ট", নয়তো সরবরাহকারী/কর্মী/ব্যক্তি
/// ([[ApprovalFacts]], ওয়েবের `<x-ui.party-cell>`-এর একই লেখা)। ⓘ নম্বর পুরো থাকে, লম্বা নাম "…"-এ কাটে; পুরো নাম ছোঁয়া-ধরে রাখলে।
/// ⚠️ পুরনো সার্ভার `party` পাঠায় না — তখন কেবল নম্বর, আগের মতো।
class NumberWithParty extends StatelessWidget {
  const NumberWithParty({super.key, required this.number, this.party, this.style});

  final String number;
  final String? party;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final base = style ?? DefaultTextStyle.of(context).style;
    final who = party?.trim() ?? '';
    final line = Text.rich(
      TextSpan(children: [
        TextSpan(text: number),
        if (who.isNotEmpty)
          TextSpan(text: ' · $who', style: base.copyWith(color: AppColors.onSurfaceMuted, fontWeight: FontWeight.w400)),
      ]),
      style: base,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      softWrap: false,
    );

    return who.isEmpty ? line : Tooltip(message: who, child: line);
  }
}

/// সারাংশ থেকে সামনের পক্ষটা বাদ — পক্ষ এখন নম্বরের লাইনে, দুইবার দেখানো নয়।
///
/// <p>ⓘ সার্ভারের সারাংশ পক্ষ দিয়েই শুরু হয় ("পক্ষ · কী বাবদ · কোথায় · শাখা"), যাতে পুরনো অ্যাপ পক্ষ হারায় না। পক্ষ না থাকলে বা
/// সারাংশ অন্যভাবে শুরু হলে সারাংশ হুবহু থাকে; কেবল পক্ষ থাকলে কিছুই নয় (null)।
String? summaryAfterParty(String? summary, String? party) {
  final text = summary?.trim() ?? '';
  final who = party?.trim() ?? '';
  if (text.isEmpty) return null;
  if (who.isEmpty) return text;
  if (text == who) return null;
  final lead = '$who · ';
  if (text.startsWith(lead)) return text.substring(lead.length);
  // ⓘ অঙ্ক বদলালে সারাংশের আগে একটা সতর্কতা বসে ("… বদলেছে · পক্ষ · …") — পক্ষ তখন মাঝখানে
  final middle = ' · $lead';
  final at = text.indexOf(middle);
  if (at >= 0) return text.substring(0, at) + text.substring(at + middle.length - 3);
  if (text.endsWith(' · $who')) return text.substring(0, text.length - who.length - 3);
  return text;
}
