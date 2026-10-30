<script setup lang="ts">
import { onMounted, ref } from "vue";
import { AlertTriangle, CheckCircle2, ExternalLink, FileSearch, Loader2, RefreshCw } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import { apiError } from "@/lib/api";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const queue = ref<any[]>([]);
const selected = ref<any>(null);
const scores = ref<Record<string, number>>({});
const decision = ref("APPROVED");
const feedback = ref("");
const loading = ref(true);
const saving = ref(false);
const error = ref("");
const message = ref("");

async function loadDetail(id: string) {
  loading.value = true; error.value = "";
  try {
    const response = await fetch(`/api/backend/student-store/submissions/${id}`);
    if (!response.ok) throw new Error(await apiError(response, "Detail belum dapat dimuat"));
    const data = await response.json();
    selected.value = data;
    scores.value = Object.fromEntries((data.assignment.rubric?.criteria || []).map((item: any) => [item.id, item.maxScore]));
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Detail belum dapat dimuat"; }
  finally { loading.value = false; }
}

async function loadQueue() {
  loading.value = true;
  try {
    const response = await fetch("/api/backend/student-store/review/queue");
    if (!response.ok) throw new Error(await apiError(response, "Antrean belum dapat dimuat"));
    const data = await response.json();
    queue.value = data;
    if (!selected.value && data[0]) await loadDetail(data[0].id);
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Antrean belum dapat dimuat"; }
  finally { loading.value = false; }
}

onMounted(loadQueue);

async function review() {
  if (!selected.value) return;
  saving.value = true; error.value = ""; message.value = "";
  try {
    const criteria = selected.value.assignment.rubric?.criteria || [];
    const response = await csrfFetch(`/api/backend/student-store/submissions/${selected.value.id}/review`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ decision: decision.value, feedback: feedback.value || undefined, publishGrade: true, scores: criteria.map((item: any) => ({ criterionId: item.id, score: scores.value[item.id] ?? 0 })) }) });
    if (!response.ok) throw new Error(await apiError(response, "Review belum dapat disimpan"));
    message.value = decision.value === "APPROVED" ? "Submission disetujui dan nilai dipublikasikan." : "Keputusan dan feedback berhasil dikirim.";
    feedback.value = ""; selected.value = null;
    await loadQueue();
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Review belum dapat disimpan"; }
  finally { saving.value = false; }
}

async function openFile(id: string) {
  const opened = window.open("", "_blank");
  const response = await csrfFetch(`/api/backend/student-store/files/${id}/access`, { method: "POST" });
  if (!response.ok) { opened?.close(); error.value = await apiError(response, "File belum dapat dibuka"); return; }
  const data = await response.json();
  if (opened) opened.location.replace(data.url); else window.location.assign(data.url);
}

async function resolveFlag(id: string, status: string) {
  const notes = window.prompt("Catatan hasil pemeriksaan manusia:");
  if (!notes) return;
  const response = await csrfFetch(`/api/backend/student-store/fraud-flags/${id}/resolve`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ status, notes }) });
  if (!response.ok) { error.value = await apiError(response, "Flag belum dapat diselesaikan"); return; }
  await loadDetail(selected.value.id);
}

const checklistResponse = (itemId: string) => selected.value.checklistResponses.find((entry: any) => entry.itemId === itemId);
</script>

<template>
  <main class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8"><div class="mx-auto max-w-7xl">
    <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Review Guru</p><h2 class="mt-2 text-2xl font-extrabold text-slate-800">Antrean Student Store</h2><p class="mt-2 text-sm text-slate-500">Flag hanya menjadi petunjuk pemeriksaan manusia dan tidak otomatis mengurangi nilai.</p></div><button @click="loadQueue" class="flex min-h-11 items-center gap-2 rounded-xl border bg-white px-4 text-sm font-bold text-slate-700"><RefreshCw aria-hidden="true" :size="17" />Muat ulang</button></div>
    <div v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ error }}</div><div v-if="message" role="status" class="mt-5 flex gap-2 rounded-xl bg-green-50 p-4 text-sm text-green-700"><CheckCircle2 aria-hidden="true" :size="18" />{{ message }}</div>
    <div class="mt-6 grid gap-6 lg:grid-cols-[320px_1fr]">
      <aside class="space-y-3">
        <div v-if="queue.length === 0 && !loading" class="rounded-xl bg-white p-6 text-sm text-slate-500">Antrean review kosong.</div>
        <template v-else><button v-for="item in queue" :key="item.id" @click="loadDetail(item.id)" :class="['w-full rounded-xl border p-4 text-left', selected?.id === item.id ? 'border-orange-400 bg-orange-50' : 'bg-white']"><span class="text-xs font-bold uppercase text-orange-700">{{ item.studentStore?.platform }} · {{ item.status }}</span><strong class="mt-1 block text-slate-800">{{ item.student.fullName }}</strong><span class="block text-sm text-slate-500">{{ item.assignment.title }}</span><span class="mt-2 block text-xs text-slate-500">{{ item._count.files }} file · {{ item._count.fraudFlags }} flag · versi {{ item.currentVersion }}</span></button></template>
      </aside>
      <section class="min-w-0">
        <p v-if="loading && !selected" class="font-semibold text-orange-600">Memuat review...</p>
        <div v-else-if="!selected" class="rounded-xl bg-white p-8 text-center text-slate-500">Pilih submission.</div>
        <div v-else class="space-y-5">
          <article class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase text-orange-700">{{ selected.student.studentNumber }}</p><h3 class="text-xl font-bold text-slate-800">{{ selected.student.fullName }}</h3><p class="text-sm text-slate-500">{{ selected.assignment.title }}</p></div><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ selected.status }}</span></div><dl class="mt-5 grid gap-3 text-sm sm:grid-cols-3"><div v-for="[label, value] in [['Toko', selected.studentStore?.storeName], ['Platform', selected.studentStore?.platform], ['Produk', String(selected.studentStore?.productCount || 0)]]" :key="label"><dt class="text-slate-500">{{ label }}</dt><dd class="font-bold text-slate-800">{{ value || "—" }}</dd></div></dl><a v-if="selected.studentStore?.normalizedStoreUrl" :href="selected.studentStore.normalizedStoreUrl" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-orange-600">Buka URL publik dengan tab baru <ExternalLink aria-hidden="true" :size="16" /></a></article>
          <article class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-800">Checklist &amp; bukti</h3><div class="mt-3 space-y-2"><div v-for="item in selected.assignment.checklistTemplate?.items" :key="item.id" class="flex items-start gap-3 rounded-lg bg-slate-50 p-3 text-sm"><span :class="checklistResponse(item.id)?.completed ? 'text-green-600' : 'text-red-500'">{{ checklistResponse(item.id)?.completed ? "✓" : "○" }}</span><span>{{ item.label }}<span class="ml-2 text-xs text-slate-400">{{ item.weight }}%</span></span></div></div><div class="mt-4 grid gap-2 sm:grid-cols-2"><button v-for="file in selected.files" :key="file.id" @click="openFile(file.id)" :disabled="file.scanStatus !== 'CLEAN'" class="flex items-center justify-between rounded-xl border p-3 text-left text-sm disabled:opacity-50"><span class="flex items-center gap-2"><FileSearch aria-hidden="true" :size="17" />{{ file.originalFileName }}</span><span class="text-xs font-bold">{{ file.scanStatus }}</span></button></div></article>
          <article v-if="selected.fraudFlags.length > 0" class="rounded-2xl border border-amber-300 bg-amber-50 p-5"><h3 class="flex items-center gap-2 font-bold text-amber-900"><AlertTriangle aria-hidden="true" :size="19" />Pemeriksaan flag</h3><div class="mt-3 space-y-3"><div v-for="flag in selected.fraudFlags" :key="flag.id" class="rounded-xl bg-white p-4 text-sm"><div class="flex flex-wrap justify-between gap-2"><strong>{{ flag.type }}</strong><span class="text-xs font-bold">{{ flag.status }} · keyakinan {{ flag.confidence || "—" }}</span></div><p class="mt-1 text-slate-600">{{ flag.reason }}</p><div v-if="flag.status === 'OPEN'" class="mt-3 flex gap-2"><button @click="resolveFlag(flag.id, 'DISMISSED')" class="rounded-lg border px-3 py-2 text-xs font-bold">Tidak terbukti</button><button @click="resolveFlag(flag.id, 'CONFIRMED')" class="rounded-lg bg-amber-700 px-3 py-2 text-xs font-bold text-white">Terkonfirmasi</button></div><p v-if="flag.resolutionNotes" class="mt-2 text-xs text-slate-500">Catatan: {{ flag.resolutionNotes }}</p></div></div></article>
          <article class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-800">Riwayat versi</h3><div class="mt-3 grid gap-3 sm:grid-cols-2"><details v-for="version in selected.versions" :key="version.id" class="rounded-xl border p-3"><summary class="cursor-pointer text-sm font-bold">Versi {{ version.versionNumber }} · {{ new Date(version.createdAt).toLocaleString("id-ID") }}</summary><p class="mt-2 text-xs text-slate-500">Deklarasi: {{ version.declarationAccepted ? "diterima" : "belum" }}</p><p class="mt-1 text-xs text-slate-600">Catatan: {{ version.studentNotes || "—" }}</p></details></div></article>
          <article class="rounded-2xl border border-amber-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-800">Nilai &amp; keputusan</h3><div v-if="selected.assignment.rubric?.criteria?.length" class="mt-4 space-y-3"><label v-for="criterion in selected.assignment.rubric.criteria" :key="criterion.id" class="grid gap-2 rounded-xl bg-slate-50 p-3 text-sm sm:grid-cols-[1fr_120px]"><span><strong>{{ criterion.name }}</strong><span class="block text-xs text-slate-500">Bobot {{ criterion.weight }}% · maksimum {{ criterion.maxScore }}</span></span><input :aria-label="`Nilai ${criterion.name}`" type="number" :min="0" :max="criterion.maxScore" :value="scores[criterion.id] ?? 0" @input="scores = { ...scores, [criterion.id]: Number(($event.target as HTMLInputElement).value) }" class="form-control" /></label></div><p v-else class="mt-3 text-sm text-slate-500">Tugas ini tidak memakai rubrik.</p><label class="mt-4 block text-xs font-bold text-slate-700">Keputusan<select v-model="decision" class="form-control mt-1"><option value="APPROVED">Setujui</option><option value="REVISION_REQUIRED">Minta revisi</option><option value="REJECTED">Tolak</option></select></label><label class="mt-4 block text-xs font-bold text-slate-700">Feedback<textarea v-model="feedback" class="form-control mt-1 min-h-28" placeholder="Jelaskan bagian yang baik dan yang perlu diperbaiki..." /></label><button @click="review" :disabled="saving || (decision !== 'APPROVED' && feedback.trim().length < 3)" class="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-orange-600 font-bold text-white disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" class="animate-spin" :size="17" />Kirim keputusan &amp; publikasikan nilai</button></article>
        </div>
      </section>
    </div>
  </div></main>
</template>
