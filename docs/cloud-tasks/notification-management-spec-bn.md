# ABOS ERP — Notification Management Module (মালিকের স্পেক, ১০ অক্টোবর ২০২৬)

Enterprise Grade 10/10 · International Standard · AI Excluded. সব মডিউলের নোটিফিকেশন একটি Central Notification Engine দিয়ে; প্রতিটি মডিউলের নিজস্ব ব্যবসায়িক কাজ ও অনুমতি আলাদা থাকবে। কোনো AI নেই।

## ১. উদ্দেশ্য
ঘটনা সময়মতো জানানো; Approval, Pending Task, Due Date, Stock Alert, Payment Due, System Alert; In-app, Email, SMS, Web Push; কে কোনটা কখন কোন মাধ্যমে পাবেন তার নিয়ন্ত্রণ; পাঠানো/ব্যর্থ/পুনরায় পাঠানো/পড়ার পূর্ণ ইতিহাস; পুনরাবৃত্তি, অননুমোদিত তথ্য প্রকাশ ও অতিরিক্ত নোটিফিকেশন রোধ।

## ২. মেনু (১৮টা)
Dashboard · Notification Center · My Notifications · Notification Rules · Notification Templates · Recipient Management · Channel Configuration · Notification Schedule · Delivery Queue · Delivery Logs · Failed Notifications · User Preferences · Quiet Hours & Digest · Escalation Management · Notification Analytics · Channel Health · Notification Archive · Settings & Permissions

## ৩. Dashboard
KPI: Total, Unread, Delivered, Failed, Pending, Channel Health (delivery success rate); Recent Notifications (শিরোনাম, মডিউল, সময়, ধরন)। ফিল্টার: Date Range, Company, Branch, Module, Channel, Status; অনুমতি অনুযায়ী দেখাবে।

## ৪. স্ক্রিনের কাজ
Center: Search, Filter, Sort, Bulk Read, Archive · My: Read/Unread, Quick Action · Rules: Event, Condition, Recipient, Channel, Priority · Templates: Subject, Body, Variables, Preview, Version · Recipients: User, Role, Department, Branch, Group · Channel: Provider, Credentials, Sender ID, Connection Test · Schedule: Scheduled Send, Time Zone, Recurrence · Queue: Queued, Processing, Retry, Cancel · Logs: Timestamp, Provider Reference, Status, Error · Failed: Reason, Retry, Cancel, Resolution · Preferences: Channel, Categories, Frequency · Quiet Hours & Digest: Quiet Period, Digest Frequency, Exceptions · Escalation: Deadline, Level, Responsible Person · Analytics: Success/Failure Rate, Volume, Latency · Channel Health: Availability, Last Check, Error Rate · Archive: Retention, Search, Export by Permission · Settings: Access Control, Retention, Limits, Defaults.

## ৫. Priority
Critical (নিরাপত্তা, গুরুতর সিস্টেম সমস্যা) · High (Approval Pending, Credit Limit Exceeded, Stock Critical, গুরুত্বপূর্ণ Payment Overdue) · Normal (নতুন অর্ডার, সাধারণ Approval, Assigned Task, Due Reminder) · Low (Status Update, Report Ready)। Priority আর Channel আলাদা; Critical-এ SMS/Email স্বয়ংক্রিয়ভাবে বাধ্যতামূলক নয়, নীতি অনুযায়ী।

## ৬. মডিউলভিত্তিক উদাহরণ
Sales (New Order, DO Pending, Invoice Created, Return) · Purchase (Request, PO Approval, GRN Pending) · Inventory (Minimum/Out of Stock, Expiry, Batch) · Accounts (Voucher Approval, Posting Error, Reconciliation Pending) · Finance (Payment Due, Receivable Overdue, Cash Limit) · Supplier/Customer (Document Expiry, Credit Limit, Outstanding Due) · HR (Attendance Exception, Leave Approval, Payroll) · Approval Center (Requested, Approved, Rejected, Escalated) · Promotion (Start/End, Scheme Expiry, Gift Stock) · Documentation (Uploaded, Review Due, Published, Expiry) · Backup (Failed, Restore Completed, Storage Threshold) · System (Security Event, Integration Failure, Job Failure)।
⛔ Engine কেবল জানাবে: Stock Alert-এ নিজে থেকে Adjustment নয়, Payment Due-তে টাকা কাটা নয়, Approval Notification-এ অনুমোদন নয়।

## ৭. Channel
In-App (Bell, Unread Counter, Drawer, Mark as Read, View Details, Open Record) · Email (SMTP/API, HTML/Text Template, Delivery Status, Bounce, Message ID) · SMS (Gateway, Sender ID, Delivery Receipt, Retry) · Web Push (Permission, Subscription, Expiry, Revocation)। পরে Mobile Push, Teams, Slack, WhatsApp — আলাদা Integration; অনুমোদন ছাড়া "সংযুক্ত" দেখানো যাবে না।

## ৮. Rule Engine
Rule ID/Name, Module, Event; Company, Branch, Scope; Trigger (Created, Updated, Submitted, Approved, Rejected, Overdue, Failed); Conditions (Amount, Status, Due Date, Stock Level, Department); Recipient (User, Role, Department, Branch, Responsible Employee); Channel; Priority, Template, Schedule, Expiry; Duplicate Prevention, Retry, Escalation; Active/Inactive, Effective Date, Version, Audit Trail। নোটিফিকেশন মূল লেনদেনের অবস্থা বদলাবে না।

## ৯. UI নিয়ম
A. Global Bell: সব পাতায় একই Header; Unread Count; Drawer; ফিল্টার All/Unread/Approvals/Tasks/System; Title, Message, Time, Priority, Source; View Details (Access থাকলে); Mark Read/All/Archive; Critical-এর আলাদা চিহ্ন; Real-time বা নিরাপদ Polling fallback।
B. Notification Center (All/Unread/Read, Mark all read)।
C. Template Studio: Code, Name, Category, Channels, Subject, Title, Body, Approved Variables, বাংলা ও ইংরেজি, Preview, Test Send, Version, Approval, Publish, Rollback, Variable Validation। ব্যবহারকারীর ইনপুট HTML হিসেবে Render নয়।

## ১০. Architecture নীতি
Transactional Outbox · Asynchronous Delivery · Idempotency · Retry with Backoff + Jitter · Dead-Letter Queue · Delivery Receipt আর Read Status আলাদা · Graceful Degradation (Email/SMS বন্ধেও In-App আর ERP চলবে)।

## ১১. Database (যুক্তিগত)
notifications, notification_recipients, notification_rules, notification_rule_versions, notification_templates, notification_template_versions, notification_channels, notification_preferences, notification_jobs, notification_delivery_attempts, notification_provider_events, notification_escalations, notification_digests, notification_subscriptions, notification_suppressions, notification_audit_logs। company_id/branch_id; Content আর Recipient Status আলাদা; Credential আলাদা ও এনক্রিপ্টেড; Read/Unread ব্যবহারকারী-ভিত্তিক; Index, Unique, Retention।

## ১২. API (প্রস্তাবিত)
notifications list/unread-count/details/read/read-all/archive; notification-preferences get/put; notification-rules list/create/update/test; notification-templates list/create/publish; notification-deliveries list/retry; notification-health। Pagination, Validation, Standard Error, Rate Limit, Auth, Authorization বাধ্যতামূলক।

## ১৩. Security
RBAC; Company/Branch/Department/Record Access; ন্যূনতম Sensitive Data; Email/SMS-এ গোপন তথ্যের আগে Policy যাচাই; Credential Encryption ও Rotation; Callback Signature; URL/Variable/HTML Validation; Rate Limit; Audit (Actor, Action, Time, Target, Outcome); Password/Secret/Token লগে নিষিদ্ধ; Retention/Export/Deletion Policy।

## ১৪. Retry, Escalation, Quiet Hours
Retry: Error-ভিত্তিক, Max Attempts, Backoff + Jitter, Retry-After, Duplicate Suppression, DLQ, Manual Retry Permission, Audit; স্থায়ী ভুলে অন্ধ Retry নয়। Escalation উদাহরণ: ৩০ মিনিটে Reminder, ২ ঘণ্টায় Supervisor; Escalation মানে Approval নয়। Quiet Hours (User Time Zone), Critical Exception, Daily/Weekly Digest, Grouping, Frequency/Max Limit, ছুটিতে Delegate।

## ১৫. Performance লক্ষ্য
In-App P95 ≤ 2s; UI ≤ 3s; Queue ৬০ সেকেন্ডে শুরু; API P95 ≤ 500ms; 99.9% লক্ষ্য; Monitoring: Queue Depth, Failure Rate, Latency, Worker Health, Stuck Jobs, DLQ Count।

## ১৬. Technology (মালিকের মূল লেখায় Python/FastAPI/PostgreSQL/React/Redis — ABOS-এর জন্য প্রযোজ্য নয়; cloud task ফাইল দেখুন)

## ১৭. Reports (১৭টা)
Summary, User-wise, Module-wise, Priority-wise, Channel-wise Delivery, Delivered/Failed/Pending, Unread/Read, Delivery Attempt History, Provider Error Analysis, Retry & Dead-Letter, Rule Execution, Template Usage, Escalation, Latency, Channel Availability, Preference & Suppression, Audit। ফিল্টার: Date, Company, Branch, Module, User, Priority, Channel, Status; CSV/XLSX/PDF অনুমতি অনুযায়ী।

## ১৮. QA Checklist
সব মডিউলের Event পৌঁছায় · In-App ও Unread ঠিক · Company/Branch/Permission প্রয়োগ · Rule/Template/Recipient কাজ করে · প্রতিটা Provider Adapter আলাদা পরীক্ষিত · Duplicate নিয়ন্ত্রিত · Receipt আর Read আলাদা · Failed/Retry/DLQ · Quiet Hours/Digest/Escalation · মূল লেনদেন অপরিবর্তিত · Audit/Retention/Security · Load/Failure Recovery/Monitoring · কোনো AI নেই।

## ১৯. Phase
1 Core Engine · 2 Delivery Channels · 3 Rule & Template Studio · 4 Enterprise Operations · 5 Production Readiness।
