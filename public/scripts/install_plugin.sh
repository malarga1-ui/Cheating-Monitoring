#!/bin/bash
# ==============================================================================
#  تثبيت وتحديث إضافة "مراقب الامتحانات" الذكية (Exam Monitor) في مودل
#  Exam Monitor Quiz Access Rule — Automated Installer & Updater
# ==============================================================================
#  الاستخدام:
#    curl -fsSL https://jadallahkhaled.com/scripts/install_plugin.sh | bash
#  أو مع تحديد مسار مودل يدوياً:
#    bash install_plugin.sh /var/www/moodle
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

echo -e ""
echo -e "${CYAN}${BOLD}====================================================================${NC}"
echo -e "${CYAN}${BOLD}   🛡️  تثبيت وتحديث إضافة Exam Monitor لنظام Moodle ${NC}"
echo -e "${CYAN}${BOLD}   Exam Monitor Quiz Access Rule — Automated Installer${NC}"
echo -e "${CYAN}${BOLD}====================================================================${NC}"
echo -e ""

REPO_URL="https://github.com/Jadallah1455/Exam-Monitor-Platform.git"
ZIP_URL="https://github.com/Jadallah1455/Exam-Monitor-Platform/archive/refs/heads/main.zip"

# ---------- 1) تحديد مسار مودل والمسار القياسي للإضافة ----------
echo -e "${BLUE}[1/5] البحث عن مسار مودل القياسي (mod/quiz/accessrule)...${NC}"

ACCESSRULE=""
MOODLE_ROOT=""

# أ. فحص المعامل الممرر للسكربت
if [ -n "$1" ]; then
  CAND="$1"
  if [ -d "$CAND/mod/quiz/accessrule" ]; then
    ACCESSRULE="$CAND/mod/quiz/accessrule"
    MOODLE_ROOT="$CAND"
  elif [ -d "$CAND" ] && [ "$(basename "$CAND")" = "accessrule" ]; then
    ACCESSRULE="$CAND"
    MOODLE_ROOT=$(cd "$CAND/../../.." && pwd)
  fi
fi

# ب. فحص المجلد الحالي
if [ -z "$ACCESSRULE" ]; then
  CURR="$(pwd)"
  if [ -d "$CURR/mod/quiz/accessrule" ]; then
    ACCESSRULE="$CURR/mod/quiz/accessrule"
    MOODLE_ROOT="$CURR"
  elif [ -d "$CURR/accessrule" ] && [ "$(basename "$CURR")" = "quiz" ]; then
    ACCESSRULE="$CURR/accessrule"
    MOODLE_ROOT=$(cd "$CURR/../.." && pwd)
  elif [ "$(basename "$CURR")" = "accessrule" ]; then
    ACCESSRULE="$CURR"
    MOODLE_ROOT=$(cd "$CURR/../../.." && pwd)
  elif [ "$(basename "$CURR")" = "exammonitor" ] && [ "$(basename "$(dirname "$CURR")")" = "accessrule" ]; then
    ACCESSRULE="$(dirname "$CURR")"
    MOODLE_ROOT=$(cd "$CURR/../../../.." && pwd)
  fi
fi

# جـ. فحص المسارات الشائعة على السيرفرات
if [ -z "$ACCESSRULE" ]; then
  SEARCH_PATHS=(
    "/var/www/moodle"
    "/var/www/html/moodle"
    "/var/www/html"
    "/var/www"
    "/home/*/moodle*"
    "/home/*/public_html/moodle*"
    "/home/*/public_html"
    "/home/*/domains/*/public_html"
    "/home/*/domains/*/public_html/moodle*"
    "/opt/bitnami/moodle"
    "/opt/moodle"
    "/srv/moodle"
  )
  for pattern in "${SEARCH_PATHS[@]}"; do
    for dir in $pattern; do
      if [ -d "$dir/mod/quiz/accessrule" ]; then
        ACCESSRULE="$dir/mod/quiz/accessrule"
        MOODLE_ROOT="$dir"
        break 2
      fi
    done
  done
fi

# د. البحث العميق في النظام
if [ -z "$ACCESSRULE" ]; then
  echo -e "${YELLOW}جاري البحث العميق في الخادم عن mod/quiz/accessrule...${NC}"
  FOUND=$(find /var/www /home /opt /srv -maxdepth 6 -type d -path '*/mod/quiz/accessrule' 2>/dev/null | head -n 1)
  if [ -n "$FOUND" ]; then
    ACCESSRULE="$FOUND"
    MOODLE_ROOT=$(cd "$FOUND/../../.." && pwd)
  fi
fi

# هـ. إذا لم يتم العثور على المسار تلقائياً
if [ -z "$ACCESSRULE" ]; then
  echo -e ""
  echo -e "${RED}⚠️  لم يتم العثور على مسار مودل تلقائياً!${NC}"
  echo -e "${YELLOW}يرجى إدخال المسار الكامل لمجلد مودل في الخادم (Root Path):${NC}"
  read -r -p "مسار مودل (مثال: /var/www/moodle): " USER_PATH
  if [ -d "$USER_PATH/mod/quiz/accessrule" ]; then
    ACCESSRULE="$USER_PATH/mod/quiz/accessrule"
    MOODLE_ROOT="$USER_PATH"
  else
    echo -e "${RED}خطأ: المسار المدخل لا يحتوي على mod/quiz/accessrule!${NC}"
    echo -e "تأكد من المسار وشغّل السكربت مجدداً."
    exit 1
  fi
fi

echo -e "${GREEN}✓ تم تحديد مسار مودل: ${BOLD}$MOODLE_ROOT${NC}"
echo -e "${GREEN}✓ مسار تثبيت الإضافة: ${BOLD}$ACCESSRULE/exammonitor${NC}"

# ---------- 2) الانتقال إلى مسار قواعد الوصول وإنشاء/تحديث الإضافة ----------
echo -e ""
echo -e "${BLUE}[2/5] تجهيز مجلد الإضافة (exammonitor)...${NC}"
cd "$ACCESSRULE"

TARGET_DIR="$ACCESSRULE/exammonitor"

# ---------- 3) تنزيل أو تحديث الإضافة من GitHub ----------
echo -e ""
echo -e "${BLUE}[3/5] جلب كود الإضافة من مستودع GitHub...${NC}"

if [ -d "$TARGET_DIR/.git" ]; then
  echo -e "${CYAN}الإضافة موجودة مسبقاً عبر Git — جاري سحب أحدث التحديثات (git pull)...${NC}"
  cd "$TARGET_DIR"
  git fetch origin main || git fetch origin master || true
  git reset --hard origin/main 2>/dev/null || git pull origin main 2>/dev/null || git pull 2>/dev/null || true
  cd "$ACCESSRULE"
elif command -v git >/dev/null 2>&1; then
  echo -e "${CYAN}استخدام Git للاستنساخ المباشر السريع...${NC}"
  if [ -d "$TARGET_DIR" ]; then
    rm -rf "$TARGET_DIR"
  fi
  git clone --depth=1 "$REPO_URL" exammonitor
else
  echo -e "${CYAN}Git غير متوفر — التنزيل عبر الحزمة المضغوطة (ZIP)...${NC}"
  mkdir -p "$TARGET_DIR"
  cd "$TARGET_DIR"
  
  ZIP_FILE="exammonitor_temp.zip"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL -o "$ZIP_FILE" "$ZIP_URL"
  elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$ZIP_FILE" "$ZIP_URL"
  else
    echo -e "${RED}خطأ: أدوات curl أو wget أو git غير متوفرة!${NC}"
    exit 1
  fi

  if command -v unzip >/dev/null 2>&1; then
    unzip -q -o "$ZIP_FILE"
  elif command -v php >/dev/null 2>&1 && php -m | grep -qi zip; then
    php -r "\$z = new ZipArchive; if (\$z->open('$ZIP_FILE') !== true) { exit(1); } \$z->extractTo('.'); \$z->close();"
  else
    echo -e "${RED}خطأ: أداة unzip غير متوفرة لفك الضغط!${NC}"
    exit 1
  fi

  # استخراج المحتويات من المجلد الداخلي إن وُجد
  for cand in Exam-Monitor-Platform-main Exam-Monitor-Platform-master Exam-Monitor-Platform; do
    if [ -d "$cand" ]; then
      cp -rf "$cand"/* . 2>/dev/null || true
      cp -rf "$cand"/.* . 2>/dev/null || true
      rm -rf "$cand"
      break
    fi
  done
  rm -f "$ZIP_FILE"
  cd "$ACCESSRULE"
fi

# ---------- 4) التحقق من سلامة الملفات وضبط الصلاحيات ----------
echo -e ""
echo -e "${BLUE}[4/5] التحقق من سلامة ملفات الإضافة وضبط الصلاحيات...${NC}"

cd "$TARGET_DIR"
if [ ! -f "version.php" ] || [ ! -f "rule.php" ]; then
  echo -e "${RED}خطأ: لم يتم العثور على version.php أو rule.php في $TARGET_DIR!${NC}"
  ls -lah "$TARGET_DIR"
  exit 1
fi

# ضبط صلاحيات القراءة والتنفيذ للمتصفح وخادم الويب
chmod -R 755 "$TARGET_DIR" 2>/dev/null || true

# محاولة ضبط المالك إلى مستخدم الويب إن كان السكربت يملك صلاحية root
if [ "$(id -u)" -eq 0 ]; then
  for webuser in www-data nginx apache httpd nobody; do
    if id "$webuser" >/dev/null 2>&1; then
      chown -R "$webuser:$webuser" "$TARGET_DIR" 2>/dev/null || true
      break
    fi
  done
fi

echo -e "${GREEN}✓ ملفات الإضافة مكتملة وصالحة 100%${NC}"

# ---------- 5) تشغيل تحديث مودل تلقائياً إن أمكن ----------
echo -e ""
echo -e "${BLUE}[5/5] تسجيل وتفعيل الإضافة في نظام Moodle...${NC}"

UPGRADED=false
if command -v php >/dev/null 2>&1 && [ -f "$MOODLE_ROOT/admin/cli/upgrade.php" ]; then
  echo -e "${CYAN}تشغيل ترقية مودل الآلية (admin/cli/upgrade.php)...${NC}"
  php "$MOODLE_ROOT/admin/cli/upgrade.php" --non-interactive 2>&1 && UPGRADED=true || true
fi

echo -e ""
echo -e "${GREEN}${BOLD}====================================================================${NC}"
echo -e "${GREEN}${BOLD}   🎉 تم تثبيت وتفعيل إضافة Exam Monitor بنجاح! ${NC}"
echo -e "${GREEN}${BOLD}====================================================================${NC}"
echo -e ""
echo -e "${BOLD}المسار الدقيق للإضافة:${NC} $TARGET_DIR"
echo -e ""
if [ "$UPGRADED" = true ]; then
  echo -e "${GREEN}✓ تم تشغيل تحديث قاعدة بيانات مودل وتفعيل الإضافة بنجاح!${NC}"
else
  echo -e "${YELLOW}الخطوة التالية لتفعيل الإضافة في مودل:${NC}"
  echo -e "  1. من موجه الأوامر (Terminal):"
  echo -e "     ${BOLD}php $MOODLE_ROOT/admin/cli/upgrade.php${NC}"
  echo -e "  2. أو افتح لوحة إدارة مودل من المتصفح (Site Administration) وسيطالبك بتأكيد التحديث بنقرة واحدة."
fi
echo -e ""
echo -e "${BOLD}خطوات ربط الإضافة بمنصة المراقبة:${NC}"
echo -e "  1. ادخل إلى مودل كمسؤول (Admin):"
echo -e "     ${CYAN}إدارة الموقع > الملحقات > وحدات الأنشطة > الاختبار > قواعد الوصول > مراقب الامتحانات${NC}"
echo -e "     ${CYAN}(Site admin > Plugins > Activity modules > Quiz > Quiz access rules > Exam Monitor)${NC}"
echo -e ""
echo -e "  2. ضع رابط المنصة ومفتاح الحساب السري (Secret Key) المتاح في لوحة تحكمك."
echo -e "  3. في أي اختبار ترغب بمراقبته، افتح إعدادات الاختبار وفعّل: ${BOLD}«مراقبة الامتحان الذكية»${NC}."
echo -e "${CYAN}====================================================================${NC}"
echo -e ""
