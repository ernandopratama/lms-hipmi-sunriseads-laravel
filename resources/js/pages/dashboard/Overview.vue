<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  Activity,
  AlertTriangle,
  BookOpenCheck,
  CheckCircle2,
  Clock3,
  Eye,
  GraduationCap,
  MousePointerClick,
  RefreshCw,
  School,
  ShoppingBag,
  Users,
  X,
} from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import AffiliateTrend from "@/components/dashboard/overview/AffiliateTrend.vue";
import BasicDashboard from "@/components/dashboard/overview/BasicDashboard.vue";
import DistributionBars from "@/components/dashboard/overview/DistributionBars.vue";
import MetricCard from "@/components/dashboard/overview/MetricCard.vue";
import QuickActions from "@/components/dashboard/overview/QuickActions.vue";
import { audienceCopy, formatDate, number, percent, valueOf, type BasicDashboardData, type Overview } from "@/components/dashboard/overview/shared";

type StudentLearningDetail = {
  student: { id: string; fullName: string; studentNumber: string; schoolName: string; className: string };
  modules: Array<{
    id: string;
    title: string;
    position: number;
    course: { id: string; code: string; name: string };
    lessons: Array<{
      id: string;
      title: string;
      position: number;
      estimatedMinutes: number | null;
      progress: { state: string; progressPercent: number; lastPosition: number | null; updatedAt: string | null };
    }>;
  }>;
};

type SessionProfile = {
  roles: string[];
  schoolId?: string | null;
  permissions: Array<{ resource: string; action: string }>;
};

type EffectiveAccess = {
  entitlements?: Record<string, unknown>;
};

function dashboardAudience(session: SessionProfile): Overview["audience"] {
  const roles = Array.isArray(session.roles) ? session.roles : [];
  if (roles.includes("student")) return "STUDENT";
  if (roles.includes("parent")) return "PARENT";
  if (roles.includes("teacher")) return "TEACHER";
  if (roles.some((role) => ["school-admin", "principal"].includes(role))) return "SCHOOL";
  return "PROGRAM";
}

async function loadBasicDashboard(session: SessionProfile) {
  const audience = dashboardAudience(session);
  const endpoint = audience === "STUDENT"
    ? "/api/backend/dashboard/student"
    : audience === "TEACHER"
      ? "/api/backend/dashboard/teacher"
      : session.schoolId
        ? "/api/backend/dashboard/school"
        : null;
  let data: BasicDashboardData | null = null;
  if (endpoint) {
    const basicResponse = await fetch(endpoint, { cache: "no-store" });
    if (basicResponse.ok) data = await basicResponse.json() as BasicDashboardData;
  }
  return { audience, data };
}

const overview = ref<Overview | null>(null);
const basicOverview = ref<{ audience: Overview["audience"]; data: BasicDashboardData | null } | null>(null);
const profile = ref<SessionProfile | null>(null);
const periodDays = ref(30);
const loading = ref(true);
const actionLoading = ref(false);
const error = ref("");
const notice = ref("");
const studentDetail = ref<StudentLearningDetail | null>(null);
const detailLoadingId = ref("");

async function load() {
  loading.value = true;
  error.value = "";
  basicOverview.value = null;
  try {
    const sessionResponse = await fetch("/api/session", { cache: "no-store" });
    if (!sessionResponse.ok) throw new Error("Sesi pengguna belum dapat dimuat. Silakan coba lagi.");
    const session = await sessionResponse.json() as SessionProfile;
    profile.value = session;

    const canReadMonitoring = session.roles.includes("super-admin")
      || session.permissions.some((item) => item.resource === "monitoring" && item.action === "read");
    const accessResponse = await fetch("/api/backend/access/effective", { cache: "no-store" });
    const access = accessResponse.ok ? await accessResponse.json() as EffectiveAccess : null;
    const advancedDashboard = access?.entitlements?.["dashboards.advanced.read"];
    if (!canReadMonitoring || advancedDashboard === false || advancedDashboard === "false") {
      basicOverview.value = await loadBasicDashboard(session);
      return;
    }

    const overviewResponse = await fetch(`/api/backend/monitoring/overview?periodDays=${periodDays.value}`, { cache: "no-store" });
    if (!overviewResponse.ok) {
      const problem = await overviewResponse.json().catch(() => null) as { message?: string } | null;
      const packageLimited = overviewResponse.status === 403 && problem?.message?.toLowerCase().includes("paket");
      if (packageLimited) {
        basicOverview.value = await loadBasicDashboard(session);
        return;
      }
      throw new Error(problem?.message || "Dashboard belum dapat dimuat. Silakan coba lagi.");
    }
    overview.value = await overviewResponse.json();
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : "Dashboard belum dapat dimuat.";
  } finally {
    loading.value = false;
  }
}

watch(periodDays, () => { void load(); }, { immediate: true });

const canManageWarnings = computed(() => profile.value?.roles.includes("super-admin") || profile.value?.permissions.some((item) => item.resource === "monitoring" && item.action === "manage"));

async function evaluateWarnings() {
  actionLoading.value = true;
  notice.value = "";
  try {
    const response = await csrfFetch("/api/backend/monitoring/early-warnings/evaluate", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ periodDays: periodDays.value }) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || "Evaluasi belum berhasil");
    notice.value = `Evaluasi selesai untuk ${result.evaluatedStudents} siswa: ${result.created} sinyal baru, ${result.autoResolved} terselesaikan otomatis.`;
    await load();
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : "Evaluasi belum berhasil";
  } finally {
    actionLoading.value = false;
  }
}

async function openStudentDetail(studentId: string) {
  detailLoadingId.value = studentId;
  error.value = "";
  try {
    const response = await fetch(`/api/backend/monitoring/students/${encodeURIComponent(studentId)}/learning-progress`, { cache: "no-store" });
    const body = await response.json().catch(() => null) as StudentLearningDetail & { message?: string } | null;
    if (!response.ok || !body?.student) throw new Error(body?.message || "Detail progres siswa belum dapat dimuat.");
    studentDetail.value = body;
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : "Detail progres siswa belum dapat dimuat.";
  } finally {
    detailLoadingId.value = "";
  }
}

const isIndividual = computed(() => overview.value?.audience === "STUDENT" || overview.value?.audience === "PARENT");
const cards = computed(() => {
  if (!overview.value) return [];
  const kpi = overview.value.kpi;
  return isIndividual.value ? [
    { label: "Progress belajar", value: percent(valueOf(kpi, "averageLearningProgress")), helper: overview.value.definitions.learningProgress, icon: GraduationCap, tone: "blue" as const },
    { label: "Tugas disetujui", value: number(valueOf(kpi, "approvedSubmissions")), helper: `${number(valueOf(kpi, "pendingReviews"))} sedang menunggu review`, icon: CheckCircle2, tone: "green" as const },
    { label: "Student Store", value: number(valueOf(kpi, "storeApproved")), helper: "Submission toko yang sudah disetujui", icon: ShoppingBag, tone: "orange" as const },
    { label: "Sinyal pendampingan", value: number(valueOf(kpi, "openWarnings")), helper: "Gunakan sebagai panduan dukungan, bukan hukuman", icon: AlertTriangle, tone: "amber" as const },
  ] : [
    { label: "Siswa aktif", value: `${number(valueOf(kpi, "activeStudents"))}/${number(valueOf(kpi, "totalStudents"))}`, helper: overview.value.definitions.activeStudents, icon: Users, tone: "blue" as const },
    { label: "Progress belajar", value: percent(valueOf(kpi, "averageLearningProgress")), helper: overview.value.definitions.learningProgress, icon: BookOpenCheck, tone: "green" as const },
    { label: "Antrean review", value: number(valueOf(kpi, "pendingReviews")), helper: `${number(valueOf(kpi, "revisionRequired"))} memerlukan revisi`, icon: Clock3, tone: "orange" as const },
    { label: "Early warning terbuka", value: number(valueOf(kpi, "openWarnings")), helper: overview.value.definitions.earlyWarning, icon: AlertTriangle, tone: "amber" as const },
  ];
});
</script>

<template>
  <div v-if="loading && !overview && !basicOverview" class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8"><div class="animate-pulse rounded-2xl border border-orange-100 bg-white p-8 text-sm font-semibold text-orange-700">Menyiapkan ringkasan dari data terbaru…</div></div>

  <div v-else-if="error && !overview" class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8"><div class="rounded-2xl border border-amber-200 bg-amber-50 p-6"><p class="font-semibold text-amber-900">{{ error }}</p><div class="mt-4 flex flex-wrap gap-2"><button @click="load()" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white">Coba lagi</button><a v-if="error.toLowerCase().includes('paket')" href="/paket" class="rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-800">Lihat paket</a></div></div></div>

  <BasicDashboard v-else-if="basicOverview" :audience="basicOverview.audience" :data="basicOverview.data" />

  <main v-else-if="overview" class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8">
    <header class="relative overflow-hidden rounded-[28px] bg-slate-950 p-6 text-white shadow-sm lg:p-8">
      <div class="pointer-events-none absolute -right-20 -top-36 size-80 rounded-full bg-orange-500/25 blur-3xl" />
      <div class="pointer-events-none absolute -bottom-32 left-1/3 size-64 rounded-full bg-yellow-400/10 blur-3xl" />
      <div class="relative flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
        <div class="max-w-3xl">
          <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-amber-300">Ringkasan terbaru</p>
          <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.03em] text-white sm:text-3xl lg:text-4xl">{{ audienceCopy[overview.audience].title }}</h2>
          <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">{{ audienceCopy[overview.audience].subtitle }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-white/10 bg-white/5 p-2 backdrop-blur-sm">
          <label for="period" class="pl-2 text-xs font-semibold text-slate-300">Periode</label>
          <select id="period" :value="periodDays" @change="periodDays = Number(($event.target as HTMLSelectElement).value)" class="min-h-11 rounded-xl border border-white/15 bg-white px-3 text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-orange-400">
            <option :value="30">30 hari</option><option :value="90">90 hari</option><option :value="180">180 hari</option><option :value="365">1 tahun</option>
          </select>
          <button @click="load()" :disabled="loading" class="flex min-h-11 items-center gap-2 rounded-xl bg-orange-500 px-4 text-sm font-extrabold text-white transition hover:bg-orange-400 disabled:opacity-60"><RefreshCw aria-hidden="true" :class="['h-4 w-4', loading ? 'animate-spin' : '']" /> Perbarui</button>
        </div>
      </div>
      <p class="relative mt-6 inline-flex rounded-full bg-white/10 px-3 py-1.5 text-[11px] font-medium text-slate-300">Diperbarui {{ formatDate(overview.lastUpdated) }} · {{ overview.period.timezone }}</p>
    </header>

    <div v-if="notice" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ notice }}</div>
    <div v-if="error" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</div>

    <QuickActions :audience="overview.audience" />

    <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <MetricCard v-for="card in cards" :key="card.label" v-bind="card" />
    </section>

    <section class="mt-6 grid gap-5 lg:grid-cols-3">
      <AffiliateTrend :points="overview.trends.affiliate" />
      <section class="rounded-2xl border border-[#E4F1EE] bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
          <div><h3 class="font-bold text-slate-900">Sinyal pendampingan</h3><p class="mt-1 text-xs leading-5 text-slate-500">Bahasa dan tindakan harus suportif.</p></div>
          <button v-if="canManageWarnings" @click="evaluateWarnings" :disabled="actionLoading" class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-bold text-white hover:bg-orange-700 disabled:opacity-60">{{ actionLoading ? "Mengevaluasi…" : "Evaluasi" }}</button>
        </div>
        <div class="mt-4 space-y-3">
          <div v-if="overview.warnings.length === 0" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800"><CheckCircle2 aria-hidden="true" class="mb-2 h-5 w-5" />Tidak ada sinyal terbuka pada ruang lingkup ini.</div>
          <template v-else>
            <article v-for="warning in overview.warnings.slice(0, 6)" :key="warning.id" class="rounded-xl border border-amber-100 bg-amber-50/60 p-3">
              <div class="flex items-center justify-between gap-3"><p class="text-sm font-bold text-slate-900">{{ warning.title }}</p><span class="rounded-full bg-white px-2 py-1 text-[10px] font-bold text-amber-700">{{ warning.severity }}</span></div>
              <p class="mt-1 text-xs leading-5 text-slate-600">{{ warning.message }}</p>
            </article>
          </template>
        </div>
      </section>
    </section>

    <section class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <DistributionBars title="Status submission" :items="overview.distributions.submissions" empty-text="Belum ada submission pada ruang lingkup ini." />
      <DistributionBars title="Student Store" :items="overview.distributions.stores" empty-text="Belum ada submission toko." />
      <DistributionBars title="Distribusi nilai" :items="overview.distributions.grades" empty-text="Belum ada nilai yang dipublikasikan." />
    </section>

    <section v-if="overview.audience === 'PROGRAM'" class="mt-6 overflow-hidden rounded-2xl border border-[#E4F1EE] bg-white shadow-sm">
      <div class="border-b border-orange-100 px-5 py-4"><h3 class="font-bold text-slate-900">Ringkasan sekolah</h3><p class="mt-1 text-xs text-slate-500">Perbandingan dinormalisasi berdasarkan jumlah siswa atau kewajiban tugas, dan disusun alfabetis tanpa peringkat.</p></div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[700px] text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Sekolah</th><th class="px-5 py-3">Siswa</th><th class="px-5 py-3">Aktif</th><th class="px-5 py-3">Progress rata-rata</th><th class="px-5 py-3">Tugas disetujui</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="overview.schools.length === 0"><td colspan="5" class="px-5 py-8 text-center text-slate-500">Belum ada data sekolah pada ruang lingkup ini.</td></tr>
            <template v-else>
              <tr v-for="school in overview.schools" :key="school.schoolId" class="text-slate-700"><td class="px-5 py-4 font-semibold text-slate-900">{{ school.schoolName }}</td><td class="px-5 py-4">{{ number(school.studentCount) }}</td><td class="px-5 py-4">{{ percent(school.activeRate) }}</td><td class="px-5 py-4">{{ percent(school.averageProgress) }}</td><td class="px-5 py-4">{{ percent(school.approvedSubmissionRate) }}</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="!isIndividual && overview.audience !== 'PROGRAM'" class="mt-6 overflow-hidden rounded-2xl border border-[#E4F1EE] bg-white shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100 px-5 py-4"><div><h3 class="font-bold text-slate-900">Siswa yang perlu dipantau</h3><p class="mt-1 text-xs text-slate-500">Diurutkan berdasarkan jumlah sinyal, tanpa leaderboard performa.</p></div><Activity aria-hidden="true" class="h-5 w-5 text-orange-600" /></div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left text-sm">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Siswa</th><th class="px-5 py-3">Kelas</th><th class="px-5 py-3">Progress</th><th class="px-5 py-3">Tugas selesai</th><th class="px-5 py-3">Sinyal</th><th class="px-5 py-3">Aktivitas terakhir</th><th class="px-5 py-3"><span class="sr-only">Detail</span></th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="overview.students.length === 0"><td colspan="7" class="px-5 py-8 text-center text-slate-500">Belum ada siswa pada filter ini.</td></tr>
            <template v-else>
              <tr v-for="student in overview.students" :key="student.id" class="text-slate-700"><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ student.fullName }}</p><p class="text-xs text-slate-500">{{ student.studentNumber }}</p></td><td class="px-5 py-4">{{ student.className }}</td><td class="px-5 py-4"><div class="flex items-center gap-2"><div class="h-2 w-20 rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(student.progressPercent, 100)}%` }" /></div><span>{{ student.progressPercent }}%</span></div><p class="mt-1 text-[11px] text-slate-500">{{ student.completedLessons }}/{{ student.totalLessons }} materi selesai</p></td><td class="px-5 py-4">{{ student.submissionCompleted }}</td><td class="px-5 py-4"><span :class="['rounded-full px-2.5 py-1 text-xs font-bold', student.warningCount ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800']">{{ student.warningCount }}</span></td><td class="px-5 py-4 text-xs">{{ formatDate(student.lastActivity) }}</td><td class="px-5 py-4"><button type="button" @click="openStudentDetail(student.id)" :disabled="detailLoadingId === student.id" class="min-h-10 rounded-lg border border-orange-200 px-3 text-xs font-bold text-orange-700 hover:bg-orange-50 disabled:opacity-60">{{ detailLoadingId === student.id ? "Memuat…" : "Lihat materi" }}</button></td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="studentDetail" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/55 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="dialog" aria-modal="true" aria-labelledby="student-progress-title" @mousedown.self="studentDetail = null">
      <section class="max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl">
        <header class="flex items-start justify-between gap-4 border-b border-orange-100 px-5 py-4 sm:px-6">
          <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-orange-600">Detail pembelajaran</p><h2 id="student-progress-title" class="mt-1 text-xl font-extrabold text-slate-950">{{ studentDetail.student.fullName }}</h2><p class="mt-1 text-xs text-slate-500">{{ studentDetail.student.studentNumber }} · {{ studentDetail.student.className }} · {{ studentDetail.student.schoolName }}</p></div>
          <button type="button" @click="studentDetail = null" class="grid size-11 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50" aria-label="Tutup detail progres"><X aria-hidden="true" class="size-5" /></button>
        </header>
        <div class="max-h-[calc(92vh-105px)] space-y-4 overflow-y-auto p-5 sm:p-6">
          <p v-if="studentDetail.modules.length === 0" class="rounded-xl bg-slate-50 p-5 text-sm text-slate-600">Belum ada materi yang dapat diakses siswa ini.</p>
          <template v-else>
            <article v-for="courseModule in studentDetail.modules" :key="courseModule.id" class="overflow-hidden rounded-2xl border border-slate-200">
              <div class="bg-slate-50 px-4 py-3"><p class="text-[11px] font-bold uppercase tracking-wide text-orange-700">{{ courseModule.course.code }} · Modul {{ courseModule.position }}</p><h3 class="mt-1 font-bold text-slate-900">{{ courseModule.title }}</h3></div>
              <div class="divide-y divide-slate-100"><div v-for="lesson in courseModule.lessons" :key="lesson.id" class="flex items-center gap-4 px-4 py-3"><span :class="['grid size-9 shrink-0 place-items-center rounded-full text-xs font-bold', lesson.progress.state === 'COMPLETED' ? 'bg-emerald-100 text-emerald-800' : lesson.progress.progressPercent > 0 ? 'bg-orange-100 text-orange-800' : 'bg-slate-100 text-slate-500']">{{ lesson.progress.progressPercent }}%</span><div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-900">{{ lesson.title }}</p><p class="mt-0.5 text-xs text-slate-500">{{ lesson.progress.state === "COMPLETED" ? "Selesai" : lesson.progress.progressPercent > 0 ? `Sedang dipelajari · posisi ${lesson.progress.lastPosition || 1}` : "Belum dimulai" }}{{ lesson.progress.updatedAt ? ` · ${formatDate(lesson.progress.updatedAt)}` : "" }}</p></div></div></div>
            </article>
          </template>
        </div>
      </section>
    </div>

    <section class="mt-6 grid gap-4 rounded-2xl border border-orange-100 bg-orange-50/60 p-5 text-sm text-slate-600 md:grid-cols-3">
      <div class="flex gap-3"><Eye aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-orange-600" /><p><strong class="block text-slate-900">{{ number(valueOf(overview.kpi, "views")) }} views</strong>Snapshot terverifikasi terbaru per konten.</p></div>
      <div class="flex gap-3"><MousePointerClick aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-orange-600" /><p><strong class="block text-slate-900">{{ number(valueOf(overview.kpi, "clicks")) }} clicks</strong>Data sesuai periode terpilih.</p></div>
      <div class="flex gap-3"><School aria-hidden="true" class="mt-0.5 h-5 w-5 shrink-0 text-orange-600" /><p><strong class="block text-slate-900">{{ number(valueOf(overview.kpi, "totalClasses")) }} kelas</strong>Agregasi mengikuti izin pengguna.</p></div>
    </section>
  </main>
</template>
