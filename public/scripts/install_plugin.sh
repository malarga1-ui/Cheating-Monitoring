#!/bin/bash
# ============================================================
#  تثبيت إضافة "مراقب الامتحانات" (Exam Monitor) في مودل
#
#  ماذا يفعل السكربت:
#    1) يبحث تلقائياً عن المسار القياسي  mod/quiz/accessrule
#    2) ينشئ مجلد الإضافة  exammonitor
#    3) يحمّل الكود المضغوط من GitHub
#    4) يفك الضغط ويضع الملفات مباشرة داخل المجلد
#    5) يتحقق من اكتمال الملفات ثم يحذف الملف المضغوط
#
#  التشغيل على خادم مودل:
#    bash install_plugin.sh
# ============================================================

set -e

echo ""
echo "============================================================"
echo "   تثبيت إضافة Exam Monitor في Moodle"
echo "============================================================"

# ---------- 1) البحث عن المسار القياسي mod/quiz/accessrule ----------
echo ""
echo "[1/5] البحث عن المسار: mod/quiz/accessrule ..."

ACCESSRULE=""
for dir in "$(pwd)" /var/www/moodle /var/www/html/moodle /var/www/html /var/www \
           /home/*/moodle* /home/*/public_html/moodle* /home/*/domains/*/public_html; do
  if [ -d "$dir/mod/quiz/accessrule" ]; then
    ACCESSRULE="$dir/mod/quiz/accessrule"
    break
  fi
done

if [ -z "$ACCESSRULE" ]; then
  # بحث أعمق في النظام كاملاً (قد يستغرق ثوانٍ)
  ACCESSRULE=$(find / -type d -path '*/mod/quiz/accessrule' 2>/dev/null | head -n 1)
fi

if [ -z "$ACCESSRULE" ]; then
  echo "خطأ: لم يتم العثور على المسار mod/quiz/accessrule."
  echo "تأكد أن مودل مثبت على هذا الخادم، أو ثبّت الإضافة يدوياً."
  exit 1
fi

echo "تم العثور على المسار: $ACCESSRULE"
cd "$ACCESSRULE"

# ---------- 2) إنشاء مجلد الإضافة ----------
echo ""
echo "[2/5] إنشاء مجلد الإضافة exammonitor ..."
mkdir -p exammonitor
cd exammonitor

# ---------- 3) تنزيل الكود المضغوط من GitHub ----------
echo ""
echo "[3/5] تحميل الكود من GitHub ..."

ZIP_FILE="exammonitor.zip"
if command -v curl >/dev/null 2>&1; then
  curl -fsSL -o "$ZIP_FILE" "https://github.com/Jadallah1455/Exam-Monitor-Platform/archive/refs/heads/main.zip"
elif command -v wget >/dev/null 2>&1; then
  wget -q -O "$ZIP_FILE" "https://github.com/Jadallah1455/Exam-Monitor-Platform/archive/refs/heads/main.zip"
else
  echo "خطأ: curl أو wget غير متوفر على هذا الخادم."
  exit 1
fi

if [ ! -s "$ZIP_FILE" ]; then
  echo "خطأ: فشل التنزيل — تحقق من الاتصال بالإنترنت."
  exit 1
fi
echo "تم التنزيل بنجاح."

# ---------- 4) فك الضغط ونقل الملفات إلى المجلد مباشرة ----------
echo ""
echo "[4/5] فك الضغط ..."

if command -v unzip >/dev/null 2>&1; then
  unzip -q -o "$ZIP_FILE"
elif command -v php >/dev/null 2>&1 && php -m | grep -qi zip; then
  php -r "\$z = new ZipArchive; if (\$z->open('$ZIP_FILE') !== true) { exit(1); } \$z->extractTo('.'); \$z->close();"
else
  echo "خطأ: أداة unzip غير متوفرة. ثبّتها أو فك الضغط يدوياً."
  exit 1
fi

# نقل الملفات من المجلد الداخلي (إن وُجد) إلى مجلد الإضافة مباشرة
SRC=""
for cand in Exam-Monitor-Platform-main Exam-Monitor-Platform-master Exam-Monitor-Platform; do
  if [ -d "$cand" ]; then
    SRC="$cand"
    break
  fi
done
if [ -n "$SRC" ]; then
  echo "تم نقل الملفات من المجلد الداخلي: $SRC"
  cp -r "$SRC"/. .
  rm -rf "$SRC"
fi

# ---------- 5) التحقق ثم حذف الملف المضغوط ----------
echo ""
echo "[5/5] التحقق من اكتمال الملفات ..."

if [ -f "rule.php" ] && [ -f "version.php" ] && [ -d "classes" ] && [ -d "lang" ]; then
  rm -f "$ZIP_FILE"
  MOODLE_ROOT=$(cd "$ACCESSRULE/../../.." && pwd)
  echo ""
  echo "============================================================"
  echo "   تم تثبيت الإضافة بنجاح"
  echo "   المكان: $ACCESSRULE/exammonitor"
  echo ""
  echo "   الخطوة التالية — حدّث مودل لتثبيت الإضافة وتفعيلها:"
  echo "     php $MOODLE_ROOT/admin/cli/upgrade.php"
  echo ""
  echo "   أو من المتصفح افتح إدارة مودل وسيكتمل التحديث تلقائياً."
  echo "============================================================"
else
  echo "خطأ: الملفات غير مكتملة — يرجى التحقق يدوياً من المحتوى:"
  ls -lah
  exit 1
fi
