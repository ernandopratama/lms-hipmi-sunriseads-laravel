<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { CheckCircle2, Download, FileSpreadsheet, LoaderCircle, RefreshCw, ShieldCheck } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type ReportOption = { value: string; label: string };
type ExportJob = {
  id: string;
  reportType: string;
  format: string;
  status: string;
  rowCount: number;
  fileName: string | null;
  failureReason: string | null;
  expiresAt: string | null;
  createdAt: string;
  completedAt: string | null;
};
type Statistic = {
  id: string;
  title: string;
  cohortSize: number;
  minimumCohort: number;
  status: string;
  periodStart: string;
  periodEnd: string;
  metrics: Record<string, number>;
  school?: { name: string };
};

function formatDate(value: string | null) {
  if (!value) return "–";
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(value));
}

const statusClass: Record<string, string> = {
  QUEUED: "bg-amber-100 text-amber-800",
  PROCESSING: "bg-blue-100 text-blue-800",
  COMPLETED: "bg-emerald-100 text-emerald-800",
  FAILED: "bg-red-100 text-red-800",
  EXPIRED: "bg-slate-100 text-slate-700",
  PENDING_APPROVAL: "bg-amber-100 text-amber-800",
  APPROVED: "bg-emerald-100 text-emerald-800",
  REJECTED: "bg-red-100 text-red-800",
};

const options = ref<{ reportTypes: ReportOption[]; formats: ReportOption[]; notes: string[] } | null>(null);
const jobs = ref<ExportJob[]>([]);
const statistics = ref<Statistic[]>([]);
const canApproveStatistics = ref(false);
const reportType = ref("STUDENT_PROGRESS");
const periodDays = ref(30);
const statisticTitle = ref("Ringkasan Dampak Program");
const loading = ref(true);
const submitting = ref(false);
const error = ref("");
const notice = ref("");

async function load() {
  error.value = "";
  try {
    const sessionResponse = await fetch("/api/session", { cache: "no-store" });
    if (!sessionResponse.ok) throw new Error("Sesi tidak valid");
    const session = await sessionResponse.json();
    const canApprove = session.roles?.includes("super-admin") || session.permissions?.some((permission: { resource: string; action: string }) => permission.resource === "public-statistics" && permission.action === "approve");
    canApproveStatistics.value = Boolean(canApprove);
    const requests = [fetch("/api/backend/monitoring/reports/options", { cache: "no-store" }), fetch("/api/backend/monitoring/exports", { cache: "no-store" })];
    if (canApprove) requests.push(fetch("/api/backend/monitoring/public-statistics", { cache: "no-store" }));
    const responses = await Promise.all(requests);
    if (!responses[0].ok || !responses[1].ok) throw new Error("Data laporan belum dapat dimuat");
    options.value = await responses[0].json();
    jobs.value = await responses[1].json();
    if (canApprove && responses[2]?.ok) statistics.value = await responses[2].json();
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : "Data laporan belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void load(); });

const hasActiveJobs = computed(() => jobs.value.some((job) => ["QUEUED", "PROCESSING"].includes(job.status)));
watch(hasActiveJobs, (active, _previous, onCleanup) => {
  if (!active) return;
  const timer = window.setInterval(() => void load(), 3500);
  onCleanup(() => window.clearInterval(timer));
}, { immediate: true });

const createExport = async () => {
  submitting.value = true; error.value = ""; notice.value = "";
  try {
    const response = await csrfFetch("/api/backend/monitoring/exports", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ reportType: reportType.value, format: "CSV", timezone: "Asia/Jakarta", periodDays: periodDays.value }) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "Export belum dapat dibuat");
    notice.value = "Permintaan export masuk antrean. Halaman akan memperbarui status secara otomatis.";
    await load();
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "Export belum dapat dibuat"; }
  finally { submitting.value = false; }
};

const download = async (job: ExportJob) => {
  error.value = "";
  try {
    const response = await fetch(`/api/backend/monitoring/exports/${job.id}/download`, { cache: "no-store" });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "File belum dapat diunduh");
    window.location.assign(body.url);
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "File belum dapat diunduh"; }
};

const createStatistic = async () => {
  submitting.value = true; error.value = ""; notice.value = "";
  try {
    const response = await csrfFetch("/api/backend/monitoring/public-statistics", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ title: statisticTitle.value, periodDays: periodDays.value }) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "Snapshot statistik belum dapat dibuat");
    notice.value = "Snapshot agregat dibuat dan menunggu persetujuan sebelum dapat tampil publik.";
    await load();
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "Snapshot statistik belum dapat dibuat"; }
  finally { submitting.value = false; }
};

const reviewStatistic = async (id: string, decision: "APPROVED" | "REJECTED") => {
  submitting.value = true; error.value = ""; notice.value = "";
  try {
    const response = await csrfFetch(`/api/backend/monitoring/public-statistics/${id}/review`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ decision, notes: decision === "APPROVED" ? "Agregat dan ambang privasi telah diperiksa." : "Snapshot belum layak dipublikasikan." }) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "Review statistik belum berhasil");
    notice.value = decision === "APPROVED" ? "Statistik disetujui dan dapat tampil di kanal publik." : "Statistik ditolak dan tidak dipublikasikan.";
    await load();
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "Review statistik belum berhasil"; }
  finally { submitting.value = false; }
};
</script>

<template>
  <main class="p-5 sm:p-6 lg:p-8">
    <header>
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-orange-600">Reporting &amp; export</p>
      <h2 class="mt-2 flex items-center gap-3 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"><FileSpreadsheet aria-hidden="true" class="h-7 w-7 text-orange-600" /> Laporan yang aman dan terukur</h2>
      <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Pilih jenis laporan dan periode. File dibuat di background, tersimpan privat, tidak memuat dokumen identitas atau credential, lalu kedaluwarsa otomatis.</p>
    </header>

    <div v-if="notice" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ notice }}</div>
    <div v-if="error" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</div>

    <section class="mt-6 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(300px,0.55fr)]">
      <div class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm sm:p-6">
        <h3 class="font-bold text-slate-900">Buat export baru</h3>
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <label class="text-sm font-medium text-slate-700">Jenis laporan<select v-model="reportType" class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 outline-none focus:ring-2 focus:ring-orange-400"><option v-for="option in options?.reportTypes" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
          <label class="text-sm font-medium text-slate-700">Periode data<select :value="periodDays" @change="periodDays = Number(($event.target as HTMLSelectElement).value)" class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 outline-none focus:ring-2 focus:ring-orange-400"><option value="30">30 hari</option><option value="90">90 hari</option><option value="180">180 hari</option><option value="365">1 tahun</option></select></label>
        </div>
        <button @click="createExport" :disabled="submitting || loading" class="mt-5 flex min-h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 px-5 text-sm font-bold text-white shadow-sm hover:opacity-90 disabled:opacity-60"><LoaderCircle v-if="submitting" aria-hidden="true" class="h-4 w-4 animate-spin" /><Download v-else aria-hidden="true" class="h-4 w-4" /> Masukkan ke antrean export</button>
      </div>
      <aside class="rounded-2xl border border-emerald-100 bg-emerald-50 p-5">
        <div class="flex items-center gap-2 font-bold text-emerald-900"><ShieldCheck aria-hidden="true" class="h-5 w-5" /> Perlindungan data</div>
        <ul class="mt-4 space-y-3 text-sm leading-5 text-emerald-900/80"><li v-for="note in options?.notes" :key="note" class="flex gap-2"><CheckCircle2 aria-hidden="true" class="mt-0.5 h-4 w-4 shrink-0" />{{ note }}</li></ul>
      </aside>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-orange-100 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-orange-100 px-5 py-4"><div><h3 class="font-bold text-slate-900">Riwayat export</h3><p class="mt-1 text-xs text-slate-500">Status diperbarui otomatis selama job diproses.</p></div><button @click="void load()" class="rounded-lg p-2 text-orange-700 hover:bg-orange-50" aria-label="Perbarui riwayat export"><RefreshCw aria-hidden="true" class="h-4 w-4" /></button></div>
      <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Laporan</th><th class="px-5 py-3">Dibuat</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Baris</th><th class="px-5 py-3">Kedaluwarsa</th><th class="px-5 py-3 text-right">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-if="jobs.length === 0"><td colspan="6" class="px-5 py-8 text-center text-slate-500">Belum ada permintaan export.</td></tr><template v-else><tr v-for="job in jobs" :key="job.id"><td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ job.reportType.replaceAll("_", " ") }}</p><p class="text-xs text-slate-500">{{ job.fileName || job.format }}</p></td><td class="px-5 py-4 text-xs text-slate-600">{{ formatDate(job.createdAt) }}</td><td class="px-5 py-4"><span :class="['rounded-full px-2.5 py-1 text-xs font-bold', statusClass[job.status] || 'bg-slate-100']">{{ job.status }}</span></td><td class="px-5 py-4">{{ job.rowCount }}</td><td class="px-5 py-4 text-xs text-slate-600">{{ formatDate(job.expiresAt) }}</td><td class="px-5 py-4 text-right"><button v-if="job.status === 'COMPLETED'" @click="void download(job)" class="rounded-lg bg-orange-600 px-3 py-2 text-xs font-bold text-white">Unduh</button><LoaderCircle v-else-if="['QUEUED', 'PROCESSING'].includes(job.status)" aria-hidden="true" class="ml-auto h-4 w-4 animate-spin text-blue-600" /><span v-else class="text-xs text-red-600">{{ job.failureReason || "Tidak tersedia" }}</span></td></tr></template></tbody></table></div>
    </section>

    <section v-if="canApproveStatistics" class="mt-6 rounded-2xl border border-blue-100 bg-white p-5 shadow-sm sm:p-6">
      <div><p class="text-xs font-bold uppercase tracking-wider text-blue-600">Governance statistik publik</p><h3 class="mt-1 text-lg font-bold text-slate-900">Snapshot agregat dengan persetujuan</h3><p class="mt-1 text-sm text-slate-500">Publikasi hanya dapat disetujui jika cohort memenuhi ambang privasi minimum.</p></div>
      <div class="mt-5 flex flex-col gap-3 sm:flex-row"><input v-model="statisticTitle" class="min-h-11 flex-1 rounded-xl border border-slate-200 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-400" aria-label="Judul statistik publik" /><button @click="createStatistic" :disabled="submitting || !statisticTitle.trim()" class="min-h-11 rounded-xl bg-blue-700 px-5 text-sm font-bold text-white disabled:opacity-60">Buat snapshot</button></div>
      <div class="mt-5 grid gap-3 lg:grid-cols-2"><p v-if="statistics.length === 0" class="text-sm text-slate-500">Belum ada snapshot statistik.</p><template v-else><article v-for="statistic in statistics" :key="statistic.id" class="rounded-xl border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><h4 class="font-semibold text-slate-900">{{ statistic.title }}</h4><p class="mt-1 text-xs text-slate-500">{{ statistic.school?.name }} · cohort {{ statistic.cohortSize }}/{{ statistic.minimumCohort }}</p></div><span :class="['rounded-full px-2 py-1 text-[10px] font-bold', statusClass[statistic.status] || 'bg-slate-100']">{{ statistic.status }}</span></div><div v-if="statistic.status === 'PENDING_APPROVAL'" class="mt-4 flex gap-2"><button @click="void reviewStatistic(statistic.id, 'APPROVED')" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white">Setujui</button><button @click="void reviewStatistic(statistic.id, 'REJECTED')" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700">Tolak</button></div></article></template></div>
    </section>
  </main>
</template>
