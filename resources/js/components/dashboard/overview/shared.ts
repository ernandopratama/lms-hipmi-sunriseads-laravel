// Types and formatters shared by the dashboard home (pages/dashboard/Overview.vue) and its sub-views.
export type Metric = { key: string; count: number };
export type TrendPoint = { date: string; views: number; clicks: number; orders: number };
export type Warning = {
  id: string;
  studentId: string;
  severity: string;
  status: string;
  title: string;
  message: string;
  triggeredAt: string;
};
export type StudentActivity = {
  id: string;
  fullName: string;
  studentNumber: string;
  className: string;
  progressPercent: number;
  completedLessons: number;
  totalLessons: number;
  submissionCompleted: number;
  warningCount: number;
  lastActivity: string | null;
};
export type SchoolComparison = {
  schoolId: string;
  schoolName: string;
  studentCount: number;
  activeRate: number;
  averageProgress: number;
  approvedSubmissionRate: number;
};
export type Overview = {
  audience: "STUDENT" | "PARENT" | "TEACHER" | "SCHOOL" | "PROGRAM";
  period: { days: number; start: string; end: string; timezone: string };
  lastUpdated: string;
  definitions: Record<string, string>;
  kpi: Record<string, number | null>;
  distributions: {
    submissions: Metric[];
    stores: Metric[];
    storePlatforms: Metric[];
    affiliateContent: Metric[];
    warnings: Metric[];
    grades: Metric[];
    imports: Metric[];
    identityVerification: Metric[];
  };
  trends: { affiliate: TrendPoint[] };
  warnings: Warning[];
  students: StudentActivity[];
  schools: SchoolComparison[];
};

export type BasicDashboardData = {
  studentInfo?: { fullName: string; className: string };
  kpi?: {
    views?: number;
    clicks?: number;
    orders?: number;
    totalStudents?: number;
    pendingReviewsCount?: number;
    activeAssignmentsCount?: number;
  };
  stats?: { totalStudents?: number; totalTeachers?: number; totalClasses?: number };
  activeAssignmentsCount?: number;
  pendingTasksCount?: number;
  recentVideosCount?: number;
};

export const audienceCopy = {
  STUDENT: { title: "Ringkasan perkembangan saya", subtitle: "Pantau pembelajaran, tugas praktik, dan performa affiliate dari data terbaru." },
  PARENT: { title: "Perkembangan anak terhubung", subtitle: "Informasi aman untuk mendampingi progres belajar tanpa menampilkan data sensitif." },
  TEACHER: { title: "Monitoring kelas dan pendampingan", subtitle: "Temukan antrean kerja, progres siswa, serta sinyal yang membutuhkan perhatian." },
  SCHOOL: { title: "Kinerja program sekolah", subtitle: "Pantau adopsi, penyelesaian tugas, praktik digital, dan kualitas data sekolah." },
  PROGRAM: { title: "Monitoring program lintas sekolah", subtitle: "Lihat agregat partisipasi dan hasil program sesuai ruang lingkup organisasi." },
};

export function valueOf(kpi: Overview["kpi"], key: string) {
  return Number(kpi[key] || 0);
}

export function percent(value: number) {
  return `${new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 }).format(value)}%`;
}

export function number(value: number) {
  return new Intl.NumberFormat("id-ID", { notation: value > 9999 ? "compact" : "standard", maximumFractionDigits: 1 }).format(value);
}

export function formatDate(value: string | null) {
  if (!value) return "Belum ada aktivitas";
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(value));
}
