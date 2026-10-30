<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { BarChart3, CheckCircle2, ExternalLink, FileVideo2, Link2, Plus, RefreshCw, Send, ShieldCheck, X } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const platformLabels: Record<string, string> = { TIKTOK: "TikTok", SHOPEE: "Shopee Video", INSTAGRAM: "Instagram Reels", YOUTUBE: "YouTube Shorts" };
const statusLabels: Record<string, string> = { DRAFT: "Draf", SUBMITTED: "Menunggu review", RESUBMITTED: "Dikirim ulang", REVISION_REQUIRED: "Perlu revisi", APPROVED: "Disetujui", REJECTED: "Ditolak", UNVERIFIED: "Belum diverifikasi", NEEDS_REVIEW: "Perlu diperiksa", VERIFIED: "Terverifikasi", PENDING_VERIFICATION: "Menunggu verifikasi", INACTIVE: "Tidak aktif" };

const emptyAccount = { platform: "TIKTOK", username: "", displayName: "", profileUrl: "", externalAccountId: "" };
const emptyContent = { affiliateAccountId: "", platform: "TIKTOK", videoUrl: "", publishedAt: "", caption: "", hook: "", productName: "", productExternalId: "", productUrl: "", campaignName: "", evidenceUrl: "", insightsEvidenceUrl: "", studentNote: "", disclosureAccepted: false, ownershipDeclared: false };
const emptyMetric = { views: "", likes: "", comments: "", shares: "", clicks: "", orders: "", unitsSold: "", refunds: "", gmv: "", commission: "", snapshotDate: "", evidenceUrl: "", supersedesId: "" };

function money(value: number | string) {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value || 0));
}

function localDateTime(value?: string) {
  const date = value ? new Date(value) : new Date();
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

const workspace = ref<any>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref("");
const message = ref("");
const showAccountForm = ref(false);
const accountForm = ref({ ...emptyAccount });
const selectedAssignment = ref<any>(null);
const contentForm = ref({ ...emptyContent, publishedAt: localDateTime() });
const metricVideo = ref<any>(null);
const metricForm = ref({ ...emptyMetric, snapshotDate: localDateTime() });

async function loadWorkspace() {
  loading.value = true;
  error.value = "";
  try {
    const response = await fetch("/api/backend/affiliate/workspace", { cache: "no-store" });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || "Ruang kerja affiliate belum dapat dimuat");
    workspace.value = data;
    const requestedId = typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("assignmentId") : null;
    if (requestedId) selectedAssignment.value = data.assignments?.find((item: any) => item.id === requestedId) || null;
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : "Ruang kerja affiliate belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void loadWorkspace(); });

const performance = computed(() => workspace.value?.performance || {});
const videos = computed(() => performance.value.videos || []);
const selectedExistingVideo = computed(() => selectedAssignment.value?.submissions?.[0]?.affiliateVideos?.[0]);

watch([selectedAssignment, () => workspace.value?.accounts], () => {
  if (!selectedAssignment.value) return;
  const existing = selectedAssignment.value.submissions?.[0]?.affiliateVideos?.[0];
  const account = workspace.value?.accounts?.find((item: any) => item.id === existing?.affiliateAccountId) || workspace.value?.accounts?.[0];
  contentForm.value = {
    affiliateAccountId: account?.id || "",
    platform: existing?.platform || account?.platform || "TIKTOK",
    videoUrl: existing?.videoUrl || "",
    publishedAt: localDateTime(existing?.publishedAt),
    caption: existing?.caption || "",
    hook: existing?.hook || "",
    productName: existing?.productName || "",
    productExternalId: existing?.productExternalId || "",
    productUrl: existing?.productUrl || "",
    campaignName: existing?.campaignName || "",
    evidenceUrl: existing?.evidenceUrl || "",
    insightsEvidenceUrl: existing?.insightsEvidenceUrl || "",
    studentNote: existing?.studentNote || "",
    disclosureAccepted: Boolean(existing?.disclosureAccepted),
    ownershipDeclared: Boolean(existing?.ownershipDeclared),
  };
}, { immediate: true });

const canEditSelected = computed(() => !selectedAssignment.value?.submissions?.[0] || ["DRAFT", "REVISION_REQUIRED"].includes(selectedAssignment.value.submissions[0].status));

const handleCreateAccount = async () => {
  busy.value = true; error.value = ""; message.value = "";
  try {
    const response = await csrfFetch("/api/backend/affiliate/accounts", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(accountForm.value) });
    const data = await response.json();
    if (!response.ok) throw new Error(Array.isArray(data.message) ? data.message.join(", ") : data.message || "Akun belum dapat disimpan");
    message.value = "Akun affiliate tersimpan dan masuk antrean verifikasi.";
    accountForm.value = { ...emptyAccount }; showAccountForm.value = false;
    await loadWorkspace();
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Akun belum dapat disimpan"; }
  finally { busy.value = false; }
};

const handleSaveContent = async (submitForReview: boolean) => {
  if (!selectedAssignment.value) return;
  busy.value = true; error.value = ""; message.value = "";
  try {
    const response = await csrfFetch("/api/backend/affiliate/content", {
      method: "POST", headers: { "content-type": "application/json" },
      body: JSON.stringify({ ...contentForm.value, assignmentId: selectedAssignment.value.id, publishedAt: new Date(contentForm.value.publishedAt).toISOString() }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(Array.isArray(data.message) ? data.message.join(", ") : data.message || "Konten belum dapat disimpan");
    if (submitForReview) {
      const finalResponse = await csrfFetch(`/api/backend/submissions/${data.submission.id}/submit`, { method: "POST" });
      const finalData = await finalResponse.json();
      if (!finalResponse.ok) throw new Error(finalData.message || "Konten tersimpan sebagai draf, tetapi belum dapat dikirim");
    }
    message.value = submitForReview ? "Konten berhasil dikirim untuk review guru." : "Draf konten dan versinya berhasil disimpan.";
    selectedAssignment.value = null;
    await loadWorkspace();
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Konten belum dapat diproses"; }
  finally { busy.value = false; }
};

const handleSaveMetric = async () => {
  if (!metricVideo.value) return;
  busy.value = true; error.value = ""; message.value = "";
  try {
    const metric = metricForm.value;
    const payload = {
      views: Number(metric.views || 0), likes: Number(metric.likes || 0), comments: Number(metric.comments || 0), shares: Number(metric.shares || 0),
      clicks: Number(metric.clicks || 0), orders: Number(metric.orders || 0), unitsSold: Number(metric.unitsSold || 0), refunds: Number(metric.refunds || 0),
      gmv: Number(metric.gmv || 0), commission: Number(metric.commission || 0), snapshotDate: new Date(metric.snapshotDate).toISOString(), evidenceUrl: metric.evidenceUrl || undefined, supersedesId: metric.supersedesId || undefined,
    };
    const response = await csrfFetch(`/api/backend/affiliate/videos/${metricVideo.value.id}/snapshots`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(payload) });
    const data = await response.json();
    if (!response.ok) throw new Error(Array.isArray(data.message) ? data.message.join(", ") : data.message || "Metrik belum dapat disimpan");
    message.value = data.verification === "NEEDS_REVIEW" ? "Snapshot tersimpan dan ditandai untuk pemeriksaan guru; data asli tetap aman." : "Snapshot baru tersimpan tanpa menimpa riwayat lama.";
    metricVideo.value = null; metricForm.value = { ...emptyMetric, snapshotDate: localDateTime() };
    await loadWorkspace();
  } catch (cause) { error.value = cause instanceof Error ? cause.message : "Metrik belum dapat disimpan"; }
  finally { busy.value = false; }
};

const openMetricForm = (video: any, correction?: any) => {
  metricVideo.value = video;
  metricForm.value = correction ? {
    views: String(correction.views), likes: String(correction.likes), comments: String(correction.comments), shares: String(correction.shares),
    clicks: String(correction.clicks), orders: String(correction.orders), unitsSold: String(correction.unitsSold), refunds: String(correction.refunds),
    gmv: String(correction.gmv), commission: String(correction.commission), snapshotDate: localDateTime(correction.snapshotDate), evidenceUrl: correction.evidenceUrl || "", supersedesId: correction.id,
  } : { ...emptyMetric, snapshotDate: localDateTime() };
};

const onAccountChange = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value;
  const account = workspace.value.accounts.find((item: any) => item.id === value);
  contentForm.value = { ...contentForm.value, affiliateAccountId: value, platform: account?.platform || contentForm.value.platform };
};

const kpis = computed<any[]>(() => [
  ["Konten", performance.value.totalVideos || 0, FileVideo2], ["Tayangan", Number(performance.value.totalViews || 0).toLocaleString("id-ID"), BarChart3],
  ["CTR", `${Number(performance.value.ctr || 0).toFixed(2)}%`, Link2], ["Pesanan", Number(performance.value.totalOrders || 0).toLocaleString("id-ID"), CheckCircle2],
  ["GMV", money(performance.value.totalGmv || 0), BarChart3], ["Komisi", money(performance.value.totalCommission || 0), CheckCircle2],
]);

const assignmentCards = computed(() => (workspace.value?.assignments || []).map((assignment: any) => {
  const submission = assignment.submissions?.[0];
  const video = submission?.affiliateVideos?.[0];
  const canEdit = !submission || ["DRAFT", "REVISION_REQUIRED"].includes(submission.status);
  return { assignment, submission, video, canEdit };
}));

const metricFields = [["views", "Views"], ["clicks", "Klik"], ["orders", "Pesanan"], ["unitsSold", "Unit terjual"], ["likes", "Likes"], ["comments", "Komentar"], ["shares", "Dibagikan"], ["refunds", "Refund / batal"], ["gmv", "GMV (Rp)"], ["commission", "Komisi (Rp)"]] as const;
const requiredMetricFields: string[] = ["views", "clicks", "orders", "unitsSold", "gmv", "commission"];
</script>

<template>
  <main v-if="loading" class="min-h-screen bg-[#FFFFFF] p-6 sm:p-8"><p class="font-semibold text-orange-600">Memuat ruang kerja affiliate...</p></main>
  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-7xl space-y-8">
      <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div><p class="text-xs font-extrabold uppercase tracking-[0.18em] text-orange-600">Fase 5 · Affiliate</p><h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Affiliate Saya</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Kelola akun publik, kirim konten, lalu catat performa sebagai snapshot historis. Sistem tidak pernah meminta password, OTP, cookie, atau token marketplace.</p></div>
        <button @click="loadWorkspace" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-orange-200 bg-white px-4 text-sm font-bold text-orange-700"><RefreshCw aria-hidden="true" :size="17" /> Muat ulang</button>
      </header>

      <div v-if="error || message" role="status" :class="['rounded-xl border p-4 text-sm font-semibold', error ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700']">{{ error || message }}</div>

      <p class="-mb-5 text-xs font-semibold text-slate-500">Ringkasan finansial hanya menggunakan snapshot terbaru yang sudah diverifikasi.</p>
      <section aria-labelledby="ringkasan-affiliate" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <h2 id="ringkasan-affiliate" class="sr-only">Ringkasan performa</h2>
        <article v-for="[label, value, Icon] in kpis" :key="label" class="rounded-2xl border border-orange-100 bg-white p-4 shadow-sm"><component :is="Icon" aria-hidden="true" class="mb-3 text-orange-600" :size="20" /><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ label }}</p><p class="mt-1 break-words text-xl font-extrabold text-slate-900">{{ value }}</p></article>
      </section>

      <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><h2 class="text-xl font-extrabold text-slate-900">Akun affiliate publik</h2><p class="mt-1 text-sm text-slate-600">Tambahkan profil publik saja. Verifikasi dilakukan guru secara terpisah.</p></div><button @click="showAccountForm = !showAccountForm" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white"><Plus aria-hidden="true" :size="17" />{{ showAccountForm ? "Tutup form" : "Tambah akun" }}</button></div>
        <form v-if="showAccountForm" @submit.prevent="handleCreateAccount" class="mt-5 grid gap-4 rounded-xl bg-orange-50/60 p-4 sm:grid-cols-2">
          <label class="text-sm font-bold text-slate-700">Platform<select v-model="accountForm.platform" class="mt-1 min-h-11 w-full rounded-lg border bg-white px-3 font-normal"><option value="TIKTOK">TikTok</option><option value="SHOPEE">Shopee</option><option value="INSTAGRAM">Instagram</option><option value="YOUTUBE">YouTube</option></select></label>
          <label class="text-sm font-bold text-slate-700">Username<input required v-model="accountForm.username" placeholder="contoh: andi.kreator" class="mt-1 min-h-11 w-full rounded-lg border bg-white px-3 font-normal" /></label>
          <label class="text-sm font-bold text-slate-700">Nama tampilan<input v-model="accountForm.displayName" class="mt-1 min-h-11 w-full rounded-lg border bg-white px-3 font-normal" /></label>
          <label class="text-sm font-bold text-slate-700">URL profil publik<input required type="url" v-model="accountForm.profileUrl" placeholder="https://www.tiktok.com/@username" class="mt-1 min-h-11 w-full rounded-lg border bg-white px-3 font-normal" /></label>
          <button :disabled="busy" class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-bold text-white disabled:opacity-50 sm:col-span-2">{{ busy ? "Menyimpan..." : "Simpan akun" }}</button>
        </form>
        <div class="mt-5 grid gap-3 md:grid-cols-2"><template v-if="workspace?.accounts?.length"><article v-for="account in workspace.accounts" :key="account.id" class="flex items-start justify-between gap-4 rounded-xl border p-4"><div><p class="text-xs font-bold uppercase text-orange-600">{{ platformLabels[account.platform] || account.platform }}</p><p class="mt-1 font-extrabold text-slate-900">@{{ account.username }}</p><a :href="account.profileUrl" target="_blank" rel="noreferrer" class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-orange-700">Lihat profil <ExternalLink aria-hidden="true" :size="13" /></a></div><span :class="['rounded-full px-3 py-1 text-xs font-bold', account.status === 'VERIFIED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700']">{{ statusLabels[account.status] || account.status }}</span></article></template><p v-else class="rounded-xl border border-dashed p-5 text-sm text-slate-500 md:col-span-2">Belum ada akun. Tambahkan akun sebelum mengumpulkan konten.</p></div>
      </section>

      <section><h2 class="text-xl font-extrabold text-slate-900">Tugas konten affiliate</h2><p class="mt-1 text-sm text-slate-600">Setiap perubahan tersimpan sebagai versi baru sehingga versi lama tetap dapat ditinjau.</p><div class="mt-4 grid gap-4 lg:grid-cols-2"><template v-if="workspace?.assignments?.length"><article v-for="{ assignment, submission, video, canEdit } in assignmentCards" :key="assignment.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase text-orange-600">{{ assignment.course?.code }} · berakhir {{ new Date(assignment.endDate).toLocaleDateString("id-ID") }}</p><h3 class="mt-2 text-lg font-extrabold text-slate-900">{{ assignment.title }}</h3></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ statusLabels[submission?.status || "DRAFT"] }}</span></div><p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">{{ assignment.description }}</p><p v-if="submission?.feedback" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800"><strong>Feedback:</strong> {{ submission.feedback }}</p><div class="mt-4 flex flex-wrap gap-2"><button @click="selectedAssignment = assignment" class="min-h-11 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white">{{ video ? (canEdit ? "Perbarui konten" : "Lihat konten") : "Mulai tugas" }}</button><button v-if="video" @click="openMetricForm(video)" class="min-h-11 rounded-xl border border-orange-200 px-4 text-sm font-bold text-orange-700">Tambah metrik</button></div><p v-if="video" class="mt-3 text-xs text-slate-500">Versi konten {{ video.currentVersion }} · {{ video.metricSnapshots?.length || 0 }} snapshot tersimpan</p></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500 lg:col-span-2">Belum ada tugas affiliate aktif.</p></div></section>

      <section><h2 class="text-xl font-extrabold text-slate-900">Riwayat performa</h2><div class="mt-4 space-y-4"><template v-if="videos.length"><article v-for="video in videos" :key="video.id" class="overflow-hidden rounded-2xl border border-orange-100 bg-white shadow-sm"><div class="flex flex-col justify-between gap-3 border-b p-5 sm:flex-row sm:items-center"><div><p class="text-xs font-bold uppercase text-orange-600">{{ platformLabels[video.platform] || video.platform }}</p><h3 class="mt-1 font-extrabold text-slate-900">{{ video.productName || "Konten affiliate" }}</h3><a :href="video.videoUrl" target="_blank" rel="noreferrer" class="mt-1 inline-flex items-center gap-1 text-xs font-bold text-orange-700">Buka konten <ExternalLink aria-hidden="true" :size="13" /></a></div><button @click="openMetricForm(video)" class="min-h-11 rounded-xl bg-slate-900 px-4 text-sm font-bold text-white">Catat snapshot baru</button></div><div class="overflow-x-auto"><table class="min-w-[850px] w-full text-left text-sm"><thead class="bg-orange-50 text-xs uppercase text-slate-600"><tr><th v-for="item in ['Waktu', 'Views', 'Klik', 'CTR', 'Pesanan', 'GMV', 'Komisi', 'Status', 'Aksi']" :key="item" class="px-4 py-3">{{ item }}</th></tr></thead><tbody><template v-if="video.metricSnapshots?.length"><tr v-for="metric in video.metricSnapshots" :key="metric.id" class="border-t"><td class="px-4 py-3">{{ new Date(metric.snapshotDate).toLocaleString("id-ID") }}</td><td class="px-4 py-3">{{ metric.views.toLocaleString("id-ID") }}</td><td class="px-4 py-3">{{ metric.clicks.toLocaleString("id-ID") }}</td><td class="px-4 py-3">{{ Number(metric.ctr).toFixed(2) }}%</td><td class="px-4 py-3">{{ metric.orders }}</td><td class="px-4 py-3">{{ money(metric.gmv) }}</td><td class="px-4 py-3">{{ money(metric.commission) }}</td><td class="px-4 py-3"><span :class="['rounded-full px-2 py-1 text-xs font-bold', metric.verification === 'VERIFIED' ? 'bg-emerald-100 text-emerald-700' : metric.verification === 'NEEDS_REVIEW' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600']">{{ statusLabels[metric.verification] }}</span></td><td class="px-4 py-3"><button @click="openMetricForm(video, metric)" class="rounded-lg border px-3 py-2 text-xs font-bold text-orange-700">Koreksi</button></td></tr></template><tr v-else><td :colspan="9" class="p-5 text-center text-slate-500">Belum ada snapshot.</td></tr></tbody></table></div></article></template><p v-else class="rounded-xl border border-dashed bg-white p-6 text-sm text-slate-500">Riwayat akan muncul setelah konten affiliate disimpan.</p></div></section>
    </div>

    <div v-if="selectedAssignment" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-3 sm:p-8"><div class="mx-auto max-w-3xl rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase text-orange-600">Form konten affiliate</p><h2 class="mt-1 text-xl font-extrabold text-slate-900">{{ selectedAssignment.title }}</h2></div><button aria-label="Tutup" @click="selectedAssignment = null" class="grid size-11 place-items-center rounded-xl hover:bg-slate-100"><X aria-hidden="true" /></button></div><p v-if="!canEditSelected" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Konten telah dikirim dan terkunci sampai guru meminta revisi.</p><fieldset :disabled="!canEditSelected || busy" class="mt-5 grid gap-4 sm:grid-cols-2 disabled:opacity-70">
      <label class="text-sm font-bold">Akun affiliate<select required :value="contentForm.affiliateAccountId" @change="onAccountChange" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal"><option value="">Pilih akun</option><option v-for="account in workspace.accounts" :key="account.id" :value="account.id">{{ platformLabels[account.platform] }} · @{{ account.username }}</option></select></label>
      <label class="text-sm font-bold">Tanggal publikasi<input type="datetime-local" required v-model="contentForm.publishedAt" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold sm:col-span-2">URL konten publik<input type="url" required v-model="contentForm.videoUrl" placeholder="https://www.tiktok.com/@akun/video/..." class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold">Nama produk<input required v-model="contentForm.productName" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold">URL produk<input type="url" required v-model="contentForm.productUrl" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold">Campaign / tag<input v-model="contentForm.campaignName" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold">Hook pembuka<input v-model="contentForm.hook" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold sm:col-span-2">Caption<textarea v-model="contentForm.caption" class="mt-1 min-h-24 w-full rounded-lg border p-3 font-normal" /></label>
      <label class="text-sm font-bold">URL bukti publikasi<input type="url" required v-model="contentForm.evidenceUrl" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold">URL screenshot insight (opsional)<input type="url" v-model="contentForm.insightsEvidenceUrl" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label>
      <label class="text-sm font-bold sm:col-span-2">Catatan siswa<textarea v-model="contentForm.studentNote" class="mt-1 min-h-20 w-full rounded-lg border p-3 font-normal" /></label>
      <label class="flex gap-3 rounded-xl bg-orange-50 p-4 text-sm sm:col-span-2"><input type="checkbox" required v-model="contentForm.ownershipDeclared" class="mt-1 size-4" /><span><strong>Saya menyatakan akun dan konten ini milik saya.</strong><br /><span class="text-slate-600">Saya tidak membagikan password, OTP, cookie, atau token marketplace.</span></span></label>
      <label class="flex gap-3 rounded-xl bg-orange-50 p-4 text-sm sm:col-span-2"><input type="checkbox" required v-model="contentForm.disclosureAccepted" class="mt-1 size-4" /><span><strong>Saya mengikuti ketentuan platform dan disclosure iklan.</strong><br /><span class="text-slate-600">Konten akan diperiksa manusia sebelum dinilai.</span></span></label>
    </fieldset><div v-if="canEditSelected" class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><button @click="handleSaveContent(false)" :disabled="busy" class="min-h-11 rounded-xl border px-5 text-sm font-bold">Simpan draf</button><button @click="handleSaveContent(true)" :disabled="busy" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-orange-600 px-5 text-sm font-bold text-white"><Send aria-hidden="true" :size="17" />{{ busy ? "Memproses..." : "Kirim untuk review" }}</button></div><p v-if="selectedExistingVideo?.versions?.length > 1" class="mt-4 text-xs text-slate-500">{{ selectedExistingVideo.versions.length }} versi konten tersimpan.</p></div></div>

    <div v-if="metricVideo" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-3 sm:p-8"><form @submit.prevent="handleSaveMetric" class="mx-auto max-w-2xl rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><div class="flex items-start justify-between"><div><p class="text-xs font-bold uppercase text-orange-600">Snapshot historis</p><h2 class="mt-1 text-xl font-extrabold">Catat performa baru</h2><p class="mt-1 text-sm text-slate-600">{{ metricVideo.productName || metricVideo.videoUrl }}</p></div><button type="button" aria-label="Tutup" @click="metricVideo = null" class="grid size-11 place-items-center rounded-xl hover:bg-slate-100"><X aria-hidden="true" /></button></div><div class="mt-5 grid gap-4 sm:grid-cols-2"><label v-for="[key, label] in metricFields" :key="key" class="text-sm font-bold">{{ label }}<input type="number" min="0" :step="key === 'gmv' || key === 'commission' ? '0.01' : '1'" :required="requiredMetricFields.includes(key)" :value="metricForm[key]" @input="metricForm = { ...metricForm, [key]: ($event.target as HTMLInputElement).value }" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label><label class="text-sm font-bold">Waktu pengambilan<input type="datetime-local" required v-model="metricForm.snapshotDate" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label><label class="text-sm font-bold">URL bukti insight<input type="url" v-model="metricForm.evidenceUrl" class="mt-1 min-h-11 w-full rounded-lg border px-3 font-normal" /></label></div><div class="mt-5 flex gap-3 rounded-xl bg-blue-50 p-4 text-sm text-blue-800"><ShieldCheck aria-hidden="true" class="shrink-0" :size="20" /><p>CTR, conversion rate, nilai pesanan rata-rata, dan rate komisi dihitung otomatis. Angka lama tidak ditimpa dan flag hanya mengirim data ke pemeriksaan manusia.</p></div><button :disabled="busy" class="mt-5 min-h-11 w-full rounded-xl bg-slate-900 px-5 text-sm font-bold text-white disabled:opacity-50">{{ busy ? "Menyimpan..." : "Simpan snapshot" }}</button></form></div>
  </main>
</template>
