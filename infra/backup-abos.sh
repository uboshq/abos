#!/usr/bin/env bash
#
# ABOS — শেল থেকে ডাটাবেজের ব্যাকআপ।
#
# ── ⛔ কেন এই স্ক্রিপ্টটা আছে, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────
# শেয়ার্ড হোস্টিংয়ে (cPanel) PHP-র `proc_open` বন্ধ, তাই PHP থেকে
# `mysqldump` চালানো যায় না। ⓘ ABOS এখন নিজেই PHP দিয়ে ডাম্প নিতে পারে
# (`App\Core\Services\Backup\PdoDumper`), কিন্তু সেটা PHP-র সীমার ভিতরে
# চলে — স্মৃতি, সময়, আর ওয়েব-প্রসেসের আয়ু।
#
# ⭐ এই স্ক্রিপ্টটা তার **দ্বিতীয় পথ**: cron থেকে সরাসরি, PHP ছাড়াই।
# দুইটা পথ থাকা এখানে অপচয় নয় — একটা ভাঙলে অন্যটা থাকে, আর ব্যাকআপ ঠিক
# সেই জিনিস যার একটা পথ থাকা যথেষ্ট নয়।
#
# ── ⚠️ কেন এটা রিপোর ভিতরে ───────────────────────────────────────────
# এটা আগে লাইভে হাতে বসানো ছিল (`~/backup-abos.sh`), রিপোতে নয়। ⛔ ফলে
# পরের সার্ভারে, বা এই সার্ভার নতুন করে বসালে, গর্তটা আবার ফিরে আসত —
# আর কেউ জানত না যে একটা সমাধান ছিল।
#
# ── বসানোর ধাপ (cPanel) ──────────────────────────────────────────────
#   1. এই ফাইলটা সার্ভারে কপি করুন:  ~/backup-abos.sh
#   2. চালানোর অনুমতি দিন:           chmod +x ~/backup-abos.sh
#   3. cPanel ▸ Cron Jobs ▸ Add New Cron Job:
#        রাত ২টা:  0 2 * * *  /bin/bash $HOME/backup-abos.sh >> $HOME/backup-cron.log 2>&1
#   4. একবার হাতে চালিয়ে দেখুন, আর শেষ লাইনে টেবিলের সংখ্যা মিলিয়ে নিন।
#
# ⚠️ ধাপ ৪ বাদ দেবেন না। ⓘ এই পুরো ঘটনাটাই ঘটেছে কারণ একটা ব্যাকআপ
# ব্যবস্থা কেউ কোনোদিন চালিয়ে দেখেনি — সে ছয় দিন "DONE" বলে গেছে।

set -euo pipefail

# ── কোথায় ABOS, কোথায় ব্যাকআপ ──────────────────────────────────────
APP_DIR="${ABOS_DIR:-$HOME/abos}"
OUT_DIR="${ABOS_BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS="${ABOS_BACKUP_KEEP_DAYS:-14}"

# ── .env থেকে ডাটাবেজের তথ্য ────────────────────────────────────────
#
# ⚠️ পাসওয়ার্ড কমান্ড লাইনে দেওয়া হয় না — দিলে সেটা `ps` তালিকায় দেখা
# যেত, একই মেশিনের যেকোনো ব্যবহারকারীর কাছে। ⓘ তাই একটা অস্থায়ী
# defaults ফাইল, ৬০০ অনুমতিতে, আর কাজ শেষে মুছে যায়।
env_value() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | tail -1 | tr -d '"' | tr -d "'" | tr -d '\r'
}

DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"
DB_HOST="$(env_value DB_HOST)"
DB_HOST="${DB_HOST:-127.0.0.1}"

if [ -z "$DB_NAME" ]; then
    echo "⛔ .env-এ DB_DATABASE পাওয়া গেল না ($APP_DIR/.env)" >&2
    exit 1
fi

mkdir -p "$OUT_DIR"

STAMP="$(date +%Y-%m-%d-%H%M%S)"
RAW="$OUT_DIR/abos-$STAMP.sql"
GZ="$RAW.gz"

DEFAULTS="$(mktemp)"
chmod 600 "$DEFAULTS"
cat > "$DEFAULTS" <<EOF
[client]
host=$DB_HOST
user=$DB_USER
password="$DB_PASS"
EOF

cleanup() { rm -f "$DEFAULTS" "$RAW"; }
trap cleanup EXIT

# ── ডাম্প ───────────────────────────────────────────────────────────
#
# ⓘ `--single-transaction` — টেবিল লক না করেই সামঞ্জস্যপূর্ণ ডাম্প, তাই
# ব্যাকআপ চলাকালীন কেউ বিল কাটতে গিয়ে আটকায় না।
echo "ডাম্প নেওয়া হচ্ছে: $DB_NAME"
mysqldump --defaults-extra-file="$DEFAULTS" \
    --single-transaction --quick --routines --triggers --events \
    --default-character-set=utf8mb4 \
    --result-file="$RAW" "$DB_NAME"

gzip -9 -c "$RAW" > "$GZ"

# ── ⭐ যাচাই — আর এটাই স্ক্রিপ্টটার আসল অংশ ─────────────────────────
#
# ⛔ যে ব্যাকআপ কখনো খুলে দেখা হয়নি সেটা ব্যাকআপ নয়, আশা। ⚠️ একটা
# ফাইল থাকতে পারে, আকার ঘোষণা করতে পারে, আর ভিতরে এক বাইটও না থাকতে
# পারে — ছয় দিনের ঐ "DONE"-ও ঠিক এই আকৃতির ছিল।
if ! gzip -t "$GZ"; then
    echo "⛔ gzip ফাইলটা ভাঙা — ব্যাকআপ রাখা হলো না: $GZ" >&2
    rm -f "$GZ"
    exit 1
fi

TABLES="$(gzip -dc "$GZ" | grep -c '^CREATE TABLE' || true)"

if [ "$TABLES" -lt 1 ]; then
    echo "⛔ ডাম্পে একটাও টেবিল নেই — ব্যাকআপ রাখা হলো না: $GZ" >&2
    rm -f "$GZ"
    exit 1
fi

BYTES="$(wc -c < "$GZ")"

# ── পুরনোগুলো ঘোরানো ───────────────────────────────────────────────
#
# ⓘ না মুছলে ডিস্ক ভরে যায়, আর ডিস্ক ভরলে নতুন ব্যাকআপ নেওয়াই বন্ধ হয় —
# অর্থাৎ যত বেশি ব্যাকআপ জমে, ব্যাকআপ থাকার সম্ভাবনা তত কম।
find "$OUT_DIR" -name 'abos-*.sql.gz' -type f -mtime "+$KEEP_DAYS" -delete

echo "✅ ব্যাকআপ হয়েছে: $(basename "$GZ") — $TABLES টেবিল, $BYTES বাইট"
