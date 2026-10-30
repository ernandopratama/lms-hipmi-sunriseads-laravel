<script setup lang="ts">
import { onMounted, ref } from "vue";
import { AlertTriangle, BadgeCheck, CheckCircle2, Clock3, ExternalLink, FileUp, RefreshCw, ShieldAlert, UserRoundCheck, XCircle } from "@lucide/vue";
import DashLink from "@/components/dashboard/DashLink.vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const statusLabels: Record<string, string> = { PENDING_VERIFICATION: "Menunggu verifikasi", UNVERIFIED: "Belum diverifikasi", NEEDS_REVIEW: "Perlu diperiksa", VERIFIED: "Terverifikasi", REJECTED: "Ditolak", QUEUED: "Dalam antrean", PROCESSING: "Sedang diproses", COMPLETED: "Selesai", COMPLETED_WITH_ERRORS: "Selesai dengan catatan", FAILED: "Gagal", SUBMITTED: "Baru dikirim", RESUBMITTED: "Dikirim ulang" };

function money(value: string | number) {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value || 0));
}

function parseCsv(text: string) {
  const lines = text.trim().split(/\r?\n/).filter(Boolean);
  if (lines.length < 2) throw new Error("CSV harus memiliki header dan minimal satu baris data");
  const headers = lines[0].split(",").map((item) => item.trim());
  const required = ["videoId", "snapshotDate", "views", "clicks", "orders", "unitsSold", "gmv", "commission"];
  if (required.some((key) => !headers.includes(key))) throw new Error(`Header wajib: ${required.join(", ")}`);
  return lines.slice(1).map((line) => {
    const cells = line.split(",").map((item) => item.trim());
    const row = Object.fromEntries(headers.map((header, index) => [header, cells[index] || ""]));
    return {
      videoId: row.videoId,
      snapshotDate: new Date(row.snapshotDate).toISOString(),
      views: Number(row.views || 0), likes: Number(row.likes || 0), comments: Number(row.comments || 0), shares: Number(row.shares || 0),
      clicks: Number(row.clicks || 0), orders: Number(row.orders || 0), unitsSold: Number(row.unitsSold || 0), refunds: Number(row.refunds || 0),
      gmv: Number(row.gmv || 0), commission: Number(row.commission || 0), currency: row.currency || "IDR", evidenceUrl: row.evidenceUrl || undefined,
    };
  });
}

const queue = ref<any>({ accounts: [], metrics: [], flags: [] });
const submissions = ref<any[]>([]);
const imports = ref<any[]>([]);
const loading = ref(true);
const busyId = ref("");
const notes = ref<Record<string, string>>({});
const error = ref("");
const message = ref("");
const csvText = ref("videoId,snapshotDate,views,clicks,orders,unitsSold,gmv,commission,likes,comments,shares,refunds,currency,evidenceUrl\n");
const sourceName = ref(`affiliate-metrics-${new Date().toISOString().slice(0, 10)}.csv`);

const handleImportFile = async (file?: File) => {
  if (!file) return;
  error.value = "";
  try {
    sourceName.value = file.name;
    if (file.name.toLowerCase().endsWith(".csv")) {
      csvText.value = await file.text();
      return;
    }
    if (!file.name.toLowerCase().endsWith(".xlsx")) throw new Error("Gunakan file CSV atau XLSX");
    const XLSX = await import("@e965/xlsx");
    const workbook = XLSX.read(await file.arrayBuffer(), { type: "array", cellDates: true });
    const worksheet = workbook.Sheets[workbook.SheetNames[0]];
    if (!worksheet) throw new Error("Workbook tidak memiliki worksheet");
    const rows = XLSX.utils.sheet_to_json<Array<string | number>>(worksheet, { header: 1, raw: false, defval: "" });
    const lines = rows.filter((row) => row.some((value) => String(value).trim())).map((row) => row.map((value) => String(value ?? "").replaceAll(",", " ")).join(","));
    csvText.value = lines.join("\n");
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : "File belum dapat dibaca";
  }
};

async function load() {
  loading.value = true; error.value = "";
  try {
    const [queueResponse, submittedResponse, resubmittedResponse, importsResponse] = await Promise.all([
      fetch("/api/backend/affiliate/verification-queue", { cache: "no-store" }),
      fetch("/api/backend/submissions?status=SUBMITTED", { cache: "no-store" }),
      fetch("/api/backend/submissions?status=RESUBMITTED", { cache: "no-store" }),
      fetch("/api/backend/affiliate/metric-imports", { cache: "no-store" }),
    ]);
    const [queueData, submittedData, resubmittedData, importsData] = await Promise.all([queueResponse.json(), submittedResponse.json(), resubmittedResponse.json(), importsResponse.json()]);
    if (!queueResponse.ok) throw new Error(queueData.message || "Antrean verifikasi belum dapat dimuat");
    queue.value = queueData;
    submissions.value = [...(submittedData.data || []), ...(resubmittedData.data || [])].filter((item: any) => item.affiliateVideos?.length);
    imports.value = Array.isArray(importsData) ? importsData : [];
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Antrean belum dapat dimuat"; }
  finally { loading.value = false; }
}

onMounted(() => { void load(); });

const act = async (id: string, url: string, body: object, success: string) => {
  busyId.value = id; error.value = ""; message.value = "";
  try {
    const response = await csrfFetch(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
    const data = await response.json();
    if (!response.ok) throw new Error(Array.isArray(data.message) ? data.message.join(", ") : data.message || "Tindakan belum dapat diproses");
    message.value = success; await load();
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Tindakan belum dapat diproses"; }
  finally { busyId.value = ""; }
};

const handleImport = async () => {
  busyId.value = "import"; error.value = ""; message.value = "";
  try {
    const rows = parseCsv(csvText.value);
    const response = await csrfFetch("/api/backend/affiliate/metric-imports", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ sourceName: sourceName.value, rows }) });
    const data = await response.json();
    if (!response.ok) throw new Error(Array.isArray(data.message) ? data.message.join(", ") : data.message || "Impor belum dapat dimulai");
    message.value = `${rows.length} baris masuk antrean pemrosesan latar belakang.`; await load();
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Impor belum dapat dimulai"; }
  finally { busyId.value = ""; }
};
</script>

<template>
  <main v-if="loading" class="min-h-screen bg-[#FFFFFF] p-6 sm:p-8"><p class="font-semibold text-orange-600">Memuat antrean affiliate...</p></main>
  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8"><div class="mx-auto max-w-7xl space-y-8">
    <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><p class="text-xs font-extrabold uppercase tracking-[0.18em] text-orange-600">Review &amp; verifikasi manusia</p><h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Verifikasi Affiliate</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Periksa akun, konten, snapshot metrik, serta flag kualitas. Flag tidak menghukum siswa otomatis dan selalu memerlukan keputusan reviewer.</p></div><button @click="void load()" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-orange-200 bg-white px-4 text-sm font-bold text-orange-700"><RefreshCw aria-hidden="true" :size="17" /> Muat ulang</button></header>
    <div v-if="error || message" role="status" :class="['rounded-xl border p-4 text-sm font-semibold', error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700']">{{ error || message }}</div>
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><article v-for="[label, value, Icon] in ([['Review konten', submissions.length, Clock3], ['Akun', queue.accounts.length, UserRoundCheck], ['Snapshot', queue.metrics.length, BadgeCheck], ['Flag terbuka', queue.flags.length, ShieldAlert]] as any[])" :key="label" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><component :is="Icon" aria-hidden="true" class="mb-3 text-orange-600" :size="21" /><p class="text-xs font-bold uppercase text-slate-500">{{ label }}</p><p class="mt-1 text-2xl font-extrabold text-slate-900">{{ value }}</p></article></section>

    <section><h2 class="text-xl font-extrabold text-slate-900">Konten menunggu review akademik</h2><div class="mt-4 grid gap-4 lg:grid-cols-2"><template v-if="submissions.length"><article v-for="submission in submissions" :key="submission.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase text-orange-600">{{ statusLabels[submission.status] }}</p><h3 class="mt-1 font-extrabold text-slate-900">{{ submission.assignment.title }}</h3><p class="mt-1 text-sm text-slate-600">{{ submission.student.fullName }} · {{ submission.student.studentNumber }}</p></div><span class="rounded-full bg-orange-100 px-3 py-1 text-xs font-bold text-orange-700">{{ submission._count?.versions || 1 }} versi</span></div><DashLink :to="`/dashboard/review/${submission.id}`" class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white">Buka review &amp; rubrik <ExternalLink aria-hidden="true" :size="15" /></DashLink></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500 lg:col-span-2">Tidak ada konten yang menunggu review.</p></div></section>

    <section><h2 class="text-xl font-extrabold text-slate-900">Verifikasi akun publik</h2><div class="mt-4 grid gap-4 lg:grid-cols-2"><template v-if="queue.accounts.length"><article v-for="account in queue.accounts" :key="account.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="flex items-start justify-between"><div><p class="text-xs font-bold uppercase text-orange-600">{{ account.platform }}</p><h3 class="mt-1 font-extrabold">@{{ account.username }}</h3><p class="mt-1 text-sm text-slate-600">{{ account.student.fullName }} · {{ account.student.studentNumber }}</p><a :href="account.profileUrl" target="_blank" rel="noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-orange-700">Buka profil publik <ExternalLink aria-hidden="true" :size="13" /></a></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700">{{ statusLabels[account.status] }}</span></div><textarea :value="notes[account.id] || ''" @input="notes = { ...notes, [account.id]: ($event.target as HTMLTextAreaElement).value }" placeholder="Catatan reviewer (wajib jika ditolak)" class="mt-4 min-h-20 w-full rounded-xl border p-3 text-sm" /><div class="mt-3 flex gap-2"><button :disabled="busyId === account.id" @click="void act(account.id, `/api/backend/affiliate/accounts/${account.id}/review`, { status: 'VERIFIED', notes: notes[account.id] }, 'Akun berhasil diverifikasi.')" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-3 text-sm font-bold text-white"><CheckCircle2 aria-hidden="true" :size="16" /> Verifikasi</button><button :disabled="busyId === account.id" @click="void act(account.id, `/api/backend/affiliate/accounts/${account.id}/review`, { status: 'REJECTED', notes: notes[account.id] }, 'Akun ditolak dengan catatan.')" class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl border border-red-200 px-3 text-sm font-bold text-red-700"><XCircle aria-hidden="true" :size="16" /> Tolak</button></div></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500 lg:col-span-2">Semua akun telah ditangani.</p></div></section>

    <section><h2 class="text-xl font-extrabold text-slate-900">Verifikasi snapshot metrik</h2><div class="mt-4 space-y-4"><template v-if="queue.metrics.length"><article v-for="metric in queue.metrics" :key="metric.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="grid gap-5 lg:grid-cols-[1fr_auto]"><div><div class="flex flex-wrap items-center gap-2"><span :class="['rounded-full px-3 py-1 text-xs font-bold', metric.verification === 'NEEDS_REVIEW' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600']">{{ statusLabels[metric.verification] }}</span><span class="text-xs text-slate-500">{{ new Date(metric.snapshotDate).toLocaleString("id-ID") }} · {{ metric.source }}</span></div><h3 class="mt-2 font-extrabold">{{ metric.student.fullName }} · {{ metric.affiliateVideo.productName || "Konten affiliate" }}</h3><a :href="metric.affiliateVideo.videoUrl" target="_blank" rel="noreferrer" class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-orange-700">Buka konten <ExternalLink aria-hidden="true" :size="13" /></a><div class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5"><div v-for="[label, value] in [['Views', metric.views], ['Klik', metric.clicks], ['Pesanan', metric.orders], ['GMV', money(metric.gmv)], ['Komisi', money(metric.commission)]]" :key="String(label)" class="rounded-lg bg-orange-50 p-3"><p class="text-xs text-slate-500">{{ label }}</p><p class="mt-1 font-extrabold">{{ value }}</p></div></div><div v-if="metric.fraudFlags?.length > 0" class="mt-4 rounded-xl bg-amber-50 p-4"><p class="flex items-center gap-2 text-sm font-bold text-amber-800"><AlertTriangle aria-hidden="true" :size="17" /> Alasan pemeriksaan</p><ul class="mt-2 list-inside list-disc text-sm text-amber-800"><li v-for="flag in metric.fraudFlags" :key="flag.id">{{ flag.reason }}</li></ul></div></div><div class="w-full lg:w-72"><textarea :value="notes[metric.id] || ''" @input="notes = { ...notes, [metric.id]: ($event.target as HTMLTextAreaElement).value }" placeholder="Catatan verifikasi" class="min-h-20 w-full rounded-xl border p-3 text-sm" /><div class="mt-2 grid grid-cols-2 gap-2"><button :disabled="busyId === metric.id" @click="void act(metric.id, `/api/backend/affiliate/metrics/${metric.id}/verify`, { decision: 'VERIFIED', notes: notes[metric.id] }, 'Snapshot terverifikasi.')" class="min-h-11 rounded-xl bg-emerald-600 px-3 text-sm font-bold text-white">Verifikasi</button><button :disabled="busyId === metric.id" @click="void act(metric.id, `/api/backend/affiliate/metrics/${metric.id}/verify`, { decision: 'REJECTED', notes: notes[metric.id] }, 'Snapshot ditolak dengan alasan.')" class="min-h-11 rounded-xl border border-red-200 px-3 text-sm font-bold text-red-700">Tolak</button></div></div></div></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500">Semua snapshot telah ditangani.</p></div></section>

    <section><h2 class="text-xl font-extrabold text-slate-900">Flag kualitas data</h2><p class="mt-1 text-sm text-slate-600">Pilih penyelesaian setelah bukti diperiksa. Nilai snapshot asli tidak diubah.</p><div class="mt-4 grid gap-4 lg:grid-cols-2"><template v-if="queue.flags.length"><article v-for="flag in queue.flags" :key="flag.id" class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase text-amber-700">{{ flag.type }}</p><h3 class="mt-1 font-extrabold">{{ flag.affiliateVideo.student.fullName }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ flag.reason }}</p><textarea :value="notes[flag.id] || ''" @input="notes = { ...notes, [flag.id]: ($event.target as HTMLTextAreaElement).value }" placeholder="Hasil pemeriksaan (wajib)" class="mt-4 min-h-20 w-full rounded-xl border p-3 text-sm" /><div class="mt-3 grid grid-cols-3 gap-2"><button @click="void act(flag.id, `/api/backend/affiliate/flags/${flag.id}/resolve`, { status: 'DISMISSED', notes: notes[flag.id] }, 'Flag dinyatakan tidak terbukti.')" class="min-h-11 rounded-xl border px-2 text-xs font-bold">Tidak terbukti</button><button @click="void act(flag.id, `/api/backend/affiliate/flags/${flag.id}/resolve`, { status: 'RESOLVED', notes: notes[flag.id] }, 'Flag selesai ditangani.')" class="min-h-11 rounded-xl bg-slate-900 px-2 text-xs font-bold text-white">Selesai</button><button @click="void act(flag.id, `/api/backend/affiliate/flags/${flag.id}/resolve`, { status: 'CONFIRMED', notes: notes[flag.id] }, 'Flag dikonfirmasi untuk tindak lanjut.')" class="min-h-11 rounded-xl border border-red-200 px-2 text-xs font-bold text-red-700">Dikonfirmasi</button></div></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500 lg:col-span-2">Tidak ada flag terbuka.</p></div></section>

    <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm sm:p-6"><div class="flex items-start gap-3"><FileUp aria-hidden="true" class="mt-1 shrink-0 text-orange-600" /><div><h2 class="text-xl font-extrabold text-slate-900">Impor metrik CSV/XLSX asinkron</h2><p class="mt-1 text-sm leading-6 text-slate-600">Pilih file atau tempel CSV. Baris diproses worker di latar belakang, sehingga halaman tetap responsif dan setiap kesalahan tercatat per baris.</p></div></div><form @submit.prevent="handleImport" class="mt-5 grid gap-4"><label class="text-sm font-bold">Pilih file CSV atau XLSX<input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="void handleImportFile(($event.target as HTMLInputElement).files?.[0])" class="mt-1 block min-h-11 w-full rounded-xl border bg-white px-3 py-2 font-normal" /></label><label class="text-sm font-bold">Nama sumber<input required v-model="sourceName" class="mt-1 min-h-11 w-full rounded-xl border px-3 font-normal" /></label><label class="text-sm font-bold">Preview data CSV<textarea required v-model="csvText" class="mt-1 min-h-44 w-full rounded-xl border p-3 font-mono text-xs font-normal" /></label><button :disabled="busyId === 'import'" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-orange-600 px-5 text-sm font-bold text-white disabled:opacity-50"><FileUp aria-hidden="true" :size="17" />{{ busyId === "import" ? "Mengantrekan..." : "Mulai impor" }}</button></form><div v-if="imports.length > 0" class="mt-6 overflow-x-auto"><table class="min-w-[700px] w-full text-left text-sm"><thead class="bg-orange-50 text-xs uppercase text-slate-600"><tr><th class="p-3">Sumber</th><th class="p-3">Status</th><th class="p-3">Progres</th><th class="p-3">Berhasil</th><th class="p-3">Gagal</th></tr></thead><tbody><tr v-for="item in imports" :key="item.id" class="border-t"><td class="p-3 font-semibold">{{ item.sourceName }}</td><td class="p-3">{{ statusLabels[item.status] || item.status }}</td><td class="p-3">{{ item.processedRows }}/{{ item.totalRows }}</td><td class="p-3 text-emerald-700">{{ item.validRows }}</td><td class="p-3 text-red-700">{{ item.failedRows }}</td></tr></tbody></table></div></section>
  </div></main>
</template>
