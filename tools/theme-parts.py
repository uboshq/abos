# -*- coding: utf-8 -*-
"""প্রতিটা রূপের চিহ্ন-অংশ — আসল ERP-তে যা দেখে ওটাকে চেনা যায়।

এই তালিকাটা `tools/verify-themes.py`-তে ঢুকবে, আর যেটা নেই সেটার জন্য
স্ক্রিপ্ট লাল হবে। আজ পর্যন্ত স্ক্রিপ্টটা কেবল টোকেন মাপত — তাই
"১০৩/১০৩ মিলেছে" বলেও লঞ্চার, শেভরন বার, লঞ্চপ্যাড টালি কিছুই ছিল না।
"""
PARTS = {
 "apps": [       # Odoo
   ("app-launcher",  "[data-app-launcher]",  "৯-ফোঁটা → পুরো-পর্দার অ্যাপ টালির শিট"),
   ("facet-chips",   "[data-facet]",         "ছাঁকনি খোঁজার ঘরের ভেতরে সরানো-যায় এমন পিল"),
   ("view-switch",   "[data-view-switch]",   "list · kanban · calendar"),
   ("upper-heads",   "th[data-upper]",       "কলামের শিরোনাম ছোট, বড়-হাতের"),
 ],
 "tiles": [      # SAP Fiori
   ("launchpad",     "[data-launchpad]",     "টাইলের পাতা — সংখ্যাসহ কাজের টালি"),
   ("filter-bar",    "[data-filter-bar]",    "ছাঁকনির পটি, তার নিচে লিস্ট-রিপোর্ট"),
   ("list-report",   "[data-list-report]",   "কার্ডে ঘেরা তালিকা, শিরোনাম ও গোনা"),
 ],
 "suite": [      # Oracle NetSuite
   ("caret-menu",    "[data-caret-menu]",    "ক্যারেটওয়ালা আড়াআড়ি মেনু"),
   ("filter-strip",  "[data-filter-strip]",  "ধূসর ছাঁকনির স্ট্রিপ"),
 ],
 "dynamic": [    # Microsoft Dynamics 365
   ("waffle",        "[data-waffle]",        "৯-ফোঁটার অ্যাপ ওয়াফল"),
   ("process-bar",   "[data-process-bar]",   "শেভরন — কোন ধাপে কতটা"),
   ("view-selector", "[data-view-selector]", "তালিকার নামটাই ড্রপডাউন"),
 ],
 "redwood": [    # Oracle Fusion
   ("brand-strip",   "[data-brand-strip]",   "উপরের ৪px ইটরঙা পটি"),
   ("springboard",   "[data-springboard]",   "বাঁ পাশের টালি-প্যানেল"),
   ("stat-cards",    "[data-stat-cards]",    "বড় গোল কোণের সংখ্যা-কার্ড"),
 ],
 "classic": [    # খতিয়ান রূপ
   ("kpi-strip",     "[data-kpi-strip]",     "জোড়া লাগানো চার-ঘরের KPI পটি"),
   ("stage-strip",   "[data-stage-strip]",   "ধাপের স্ট্রিপ — গোনা ও টাকা"),
 ],
 "rose":  [("gold-hairline", "[data-gold-hairline]", "সোনার সরু রেখা")],
 "navy":  [],    # এটাই আজকের ABOS — বাড়তি কিছু নেই
}
