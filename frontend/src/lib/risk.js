/**
 * Risk level definitions — NIST SP 800-30 aligned (Table 3.1).
 *
 * Levels:
 *   safe     [0%-4.99%]   Very Low
 *   low      [5%-20.99%]  Low
 *   medium   [21%-79.99%] Moderate (alert threshold)
 *   high     [80%-95.99%] High
 *   critical [96%-100%]   Very High
 */
export const RISK = {
  safe: {
    label: 'منخفض جداً',
    cyberLabel: 'Very Low',
    threatLevel: 'GREEN',
    badge: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
    solid: 'bg-emerald-500 text-white',
    bar: 'bg-emerald-500',
    text: 'text-emerald-600',
    dot: '#10b981',
    severity: 'Informational',
    range: '0-4.99%',
  },
  low: {
    label: 'منخفض',
    cyberLabel: 'Low',
    threatLevel: 'BLUE',
    badge: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
    solid: 'bg-sky-500 text-white',
    bar: 'bg-sky-500',
    text: 'text-sky-600',
    dot: '#0ea5e9',
    severity: 'Low',
    range: '5-20.99%',
  },
  medium: {
    label: 'متوسط',
    cyberLabel: 'Moderate',
    threatLevel: 'YELLOW',
    badge: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
    solid: 'bg-amber-500 text-white',
    bar: 'bg-amber-500',
    text: 'text-amber-600',
    dot: '#f59e0b',
    severity: 'Medium',
    range: '21-79.99%',
  },
  high: {
    label: 'مرتفع',
    cyberLabel: 'High',
    threatLevel: 'ORANGE',
    badge: 'bg-orange-50 text-orange-700 ring-1 ring-orange-200',
    solid: 'bg-orange-500 text-white',
    bar: 'bg-orange-500',
    text: 'text-orange-600',
    dot: '#f97316',
    severity: 'High',
    range: '80-95.99%',
  },
  critical: {
    label: 'مرتفع جداً',
    cyberLabel: 'Very High',
    threatLevel: 'RED',
    badge: 'bg-red-50 text-red-700 ring-1 ring-red-200',
    solid: 'bg-red-500 text-white',
    bar: 'bg-red-500',
    text: 'text-red-600',
    dot: '#ef4444',
    severity: 'Critical',
    range: '96-100%',
  },
}

export function riskMeta(level) {
  return RISK[level] || RISK.safe
}

// MITRE ATT&CK-style threat technique mapping
export const THREAT_TECHNIQUES = {
  copy: { technique: 'T1059.007', name: 'Execution via Clipboard', category: 'Defense Evasion' },
  paste: { technique: 'T1059.007', name: 'Execution via Clipboard', category: 'Defense Evasion' },
  right_click: { technique: 'T1204.002', name: 'User Execution: Malicious File', category: 'Initial Access' },
  window_blur: { technique: 'T1539', name: 'Steal Web Session Cookie', category: 'Credential Access' },
  tab_hidden: { technique: 'T1539', name: 'Steal Web Session Cookie', category: 'Credential Access' },
  devtools_opened: { technique: 'T1182', name: 'AppInit DLLs', category: 'Persistence' },
  screenshot_attempt: { technique: 'T1113', name: 'Screen Capture', category: 'Collection' },
  rapid_answer_changes: { technique: 'T1078', name: 'Valid Accounts', category: 'Lateral Movement' },
  network_offline: { technique: 'T1498', name: 'Network Denial of Service', category: 'Impact' },
  fullscreen_exit: { technique: 'T1531', name: 'Account Access Removal', category: 'Impact' },
  idle_detected: { technique: 'T1497', name: 'Virtualization/Sandbox Evasion', category: 'Defense Evasion' },
  suspicious_key: { technique: 'T1059', name: 'Command and Scripting Interpreter', category: 'Execution' },
}

export function threatTechnique(eventType) {
  return THREAT_TECHNIQUES[eventType] || null
}

export const EVENT_LABELS = {
  copy: 'نسخ',
  paste: 'لصق',
  right_click: 'النقر الأيمن',
  mouse_summary: 'ملخص الماوس',
  window_blur: 'مغادرة النافذة',
  window_focus: 'العودة للنافذة',
  tab_hidden: 'إخفاء التبويب',
  tab_visible: 'إظهار التبويب',
  tab_hidden_duration: 'مدة الإخفاء',
  heartbeat: 'نبض الاتصال',
  answer_changed: 'تغيير الإجابة',
  page_leave: 'مغادرة الصفحة',
  network_online: 'اتصال الشبكة',
  network_offline: 'انقطاع الشبكة',
  devtools_opened: 'فتح أدوات المطوّر',
  devtools_closed: 'إغلاق أدوات المطوّر',
  suspicious_key: 'مفتاح مشبوه',
  screenshot_attempt: 'محاولة لقطة شاشة',
  fullscreen_enter: 'دخول ملء الشاشة',
  fullscreen_exit: 'الخروج من ملء الشاشة',
  idle_detected: 'كشف الخمول',
  typing_summary: 'ملخص الكتابة',
  rapid_answer_changes: 'تغييرات إجابة سريعة',
  activity_summary: 'ملخص النشاط',
  teacher_action_received: 'إجراء المدرّس',
}

export function eventLabel(type) {
  return EVENT_LABELS[type] || type
}
