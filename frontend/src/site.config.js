// إعدادات الموقع التسويقي — عدّل هذا الملف فقط عند تغيير البيانات

export const SITE = {
  name: 'مراقب الامتحانات',
  tagline: 'منصة كشف الغش الذكية لمودل (Moodle)',
  repoUrl: 'https://github.com/Jadallah1455/Exam-Monitor-Platform.git',
  githubNote:
    'الإضافة تُحمَّل من GitHub وتوضع في المسار القياسي mod/quiz/accessrule/exammonitor داخل ملفات مودل — ثم تُحدَّث وتُفعَّل من إدارة الموقع.',

  // أوامر التثبيت على خادم مودل — تدعم السكربت الذكي أو التثبيت اليدوي القياسي
  autoInstallCommand: 'curl -fsSL https://jadallahkhaled.com/scripts/install_plugin.sh | bash',
  installCommands: [
    '# الطريقة الأولى (الموصى بها): التثبيت التلقائي الذكي بأمر واحد',
    '# يتعرف السكربت تلقائياً على مسار مودل، ينشئ مجلد exammonitor، ويحدث قاعدة البيانات فوراً',
    'curl -fsSL https://jadallahkhaled.com/scripts/install_plugin.sh | bash',
    '',
    '# الطريقة الثانية: التثبيت اليدوي خطوة بخطوة',
    '# 1) الانتقال إلى مجلد قواعد وصول الاختبارات داخل مودل (استبدل المسار بمسار خادمك إذا اختلف):',
    'cd /var/www/html/moodle/mod/quiz/accessrule',
    '',
    '# 2) استنساخ الإضافة من مستودع GitHub في مجلد exammonitor:',
    'git clone https://github.com/Jadallah1455/Exam-Monitor-Platform.git exammonitor',
    '',
    '# 3) ضبط الصلاحيات القياسية:',
    'chmod -R 755 exammonitor',
    '',
    '# 4) ترقية وتفعيل الإضافة في نظام مودل عبر موجه الأوامر (أو افتح إدارة الموقع من المتصفح):',
    'php /var/www/html/moodle/admin/cli/upgrade.php --non-interactive',
  ],

  // الفريق — ضع صورة كل عضو في مجلد public/team ثم اكتب اسم الملف هنا
  supervisor: { name: 'أ. د. رامي لبد', role: 'المشرف العام', photo: '/team/ramy-labad-supervisor.png' },
  team: [
    { name: 'جاد الله خالد البنا', role: 'قائد الفريق', photo: '/team/jadallah-banna.jpg', bio: '' },
    { name: 'أحمد منير الرنتيسي', role: 'فريق التطوير', photo: '', bio: '' },
    { name: 'إبراهيم عطا عوض', role: 'فريق التطوير', photo: '/team/ibrahim-awad.jpeg', bio: '' },
    { name: 'محمود عبد الخالق العرجا', role: 'فريق التطوير', photo: '/team/mahmoud-alarja.jpeg', bio: '' },
  ],
}
