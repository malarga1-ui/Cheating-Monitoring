// إعدادات الموقع التسويقي — عدّل هذا الملف فقط عند تغيير البيانات

export const SITE = {
  name: 'مراقب الامتحانات',
  tagline: 'منصة كشف الغش الذكية لمودل (Moodle)',
  repoUrl: 'https://github.com/Jadallah1455/Exam-Monitor-Platform.git',
  githubNote:
    'الإضافة تُحمَّل من GitHub وتوضع في المسار القياسي mod/quiz/accessrule/exammonitor داخل ملفات مودل — ثم تُحدَّث وتُفعَّل من إدارة الموقع.',

  // أوامر التثبيت على خادم مودل — استبدل مسار مودل بمسارك إذا اختلف
  installCommands: [
    '# 1) الانتقال إلى مجلد إضافات الاختبارات في مودل (مسار قياسي)',
    '#    استبدل مسار مودل إذا اختلف، مثال:',
    '#    cd /var/www/moodle/mod/quiz/accessrule',
    'cd /home/luckhdvn/moodle.luckydraw.world/mod/quiz/accessrule',
    '',
    '# 2) تحميل الإضافة من GitHub (تنشئ مجلد exammonitor تلقائياً)',
    'git clone https://github.com/Jadallah1455/Exam-Monitor-Platform.git exammonitor',
    '',
    '# 3) تحديث مودل لتثبيت الإضافة وتفعيلها',
    'sudo -u www-data php /home/luckhdvn/moodle.luckydraw.world/admin/cli/upgrade.php',
    '',
    '# 4) التأكد من تثبيت الإضافة في مكانها الصحيح',
    'ls /home/luckhdvn/moodle.luckydraw.world/mod/quiz/accessrule/exammonitor',
  ],

  // الفريق — ضع صورة كل عضو في مجلد public/team ثم اكتب اسم الملف هنا
  supervisor: { name: 'الدكتور رامي لبد', role: 'المشرف', photo: '/team/ramy-labad-supervisor.png' },
  team: [
    { name: 'جاد الله خالد البنا', role: 'قائد الفريق', photo: '', bio: '' },
    { name: 'أحمد الرنتيسي', role: 'فريق التطوير', photo: '', bio: '' },
    { name: 'إبراهيم عوض', role: 'فريق التطوير', photo: '', bio: '' },
    { name: 'محمود العرجا', role: 'فريق التطوير', photo: '', bio: '' },
  ],
}
