<script setup lang="ts">
import { ref, watch } from "vue";
import { AlertTriangle, CheckCircle2, Filter, LoaderCircle, MessageSquareText, RefreshCw } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type WarningItem = {
  id: string;
  severity: string;
  status: string;
  title: string;
  message: string;
  triggeredAt: string;
  actionNote: string | null;
  resolution: string | null;
  student: { id: string; fullName: string; studentNumber: string; class: { name: string } | null };
  rule: { code: string; name: string; description: string };
  assignedTo: { firstName: string; lastName: string } | null;
  actions: Array<{ id: string; fromStatus: string; toStatus: string; note: string | null; createdAt: string; actor: { firstName: string; lastName: string } }>;
};

const severityClass: Record<string, string> = {
  INFO: "bg-blue-100 text-blue-800",
  ATTENTION: "bg-amber-100 text-amber-800",
  HIGH: "bg-orange-100 text-orange-800",
  CRITICAL: "bg-red-100 text-red-800",
};

const items = ref<WarningItem[]>([]);
const status = ref("OPEN");
const loading = ref(true);
const submittingId = ref("");
const notes = ref<Record<string, string>>({});
const error = ref("");
const notice = ref("");

async function load() {
  loading.value = true; error.value = "";
  try {
    const response = await fetch(`/api/backend/monitoring/early-warnings?status=${status.value}&periodDays=30`, { cache: "no-store" });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "Early warning belum dapat dimuat");
    items.value = body;
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "Early warning belum dapat dimuat"; }
  finally { loading.value = false; }
}

watch(status, load, { immediate: true });

async function update(item: WarningItem, nextStatus: "ACKNOWLEDGED" | "RESOLVED" | "DISMISSED") {
  const note = notes.value[item.id]?.trim();
  if (!note) { error.value = "Tambahkan catatan tindakan atau alasan sebelum memperbarui status."; return; }
  submittingId.value = item.id; error.value = ""; notice.value = "";
  try {
    const response = await csrfFetch(`/api/backend/monitoring/early-warnings/${item.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ status: nextStatus, note }) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || "Tindak lanjut belum dapat disimpan");
    notice.value = `Tindak lanjut untuk ${item.student.fullName} berhasil disimpan.`;
    notes.value = { ...notes.value, [item.id]: "" };
    await load();
  } catch (reason) { error.value = reason instanceof Error ? reason.message : "Tindak lanjut belum dapat disimpan"; }
  finally { submittingId.value = ""; }
}

const formatDate = (value: string) => new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeZone: "Asia/Jakarta" }).format(new Date(value));
</script>

<template>
  <main class="p-5 sm:p-6 lg:p-8">
    <header class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
      <div><p class="text-xs font-bold uppercase tracking-[0.2em] text-orange-600">Early warning</p><h2 class="mt-2 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Pendampingan siswa</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Sinyal berbasis aturan membantu menentukan tindak lanjut manusia. Sinyal tidak menjadi hukuman atau keputusan otomatis.</p></div>
      <div class="flex items-center gap-2"><Filter aria-hidden="true" class="h-4 w-4 text-slate-500" /><select v-model="status" class="min-h-11 rounded-xl border border-orange-200 bg-white px-3 text-sm font-semibold text-slate-700"><option value="OPEN">Terbuka</option><option value="ACKNOWLEDGED">Ditindaklanjuti</option><option value="RESOLVED">Terselesaikan</option><option value="DISMISSED">Ditutup tanpa tindakan</option></select><button @click="load" class="min-h-11 rounded-xl border border-orange-200 p-3 text-orange-700" aria-label="Perbarui early warning"><RefreshCw aria-hidden="true" :class="['h-4 w-4', loading && 'animate-spin']" /></button></div>
    </header>

    <div v-if="notice" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ notice }}</div>
    <div v-if="error" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }}</div>

    <section class="mt-6 grid gap-4 xl:grid-cols-2">
      <div v-if="loading" class="rounded-2xl border border-orange-100 bg-white p-8 text-sm text-slate-500"><LoaderCircle aria-hidden="true" class="mr-2 inline h-4 w-4 animate-spin" />Memuat sinyal…</div>
      <div v-else-if="items.length === 0" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-8 text-sm text-emerald-800"><CheckCircle2 aria-hidden="true" class="mb-2 h-6 w-6" />Tidak ada sinyal dengan status ini.</div>
      <template v-else>
        <article v-for="item in items" :key="item.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <div class="flex items-start justify-between gap-4"><div class="flex gap-3"><span class="rounded-xl bg-amber-50 p-2.5 text-amber-700"><AlertTriangle aria-hidden="true" class="h-5 w-5" /></span><div><h3 class="font-bold text-slate-900">{{ item.title }}</h3><p class="mt-1 text-sm font-medium text-slate-700">{{ item.student.fullName }} · {{ item.student.class?.name || "Belum ada kelas" }}</p><p class="text-xs text-slate-500">{{ item.student.studentNumber }} · {{ formatDate(item.triggeredAt) }}</p></div></div><span :class="['rounded-full px-2.5 py-1 text-[10px] font-bold', severityClass[item.severity] || 'bg-slate-100']">{{ item.severity }}</span></div>
          <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ item.message }}</p>
          <p class="mt-3 text-xs text-slate-500"><strong class="text-slate-700">Aturan:</strong> {{ item.rule.name }} — {{ item.rule.description }}</p>
          <div v-if="item.actions.length > 0" class="mt-4 rounded-xl border border-slate-100 p-3"><p class="flex items-center gap-2 text-xs font-bold text-slate-700"><MessageSquareText aria-hidden="true" class="h-4 w-4" /> Tindak lanjut terakhir</p><p class="mt-2 text-xs leading-5 text-slate-600">{{ item.actions[0].note || "Tanpa catatan" }}</p></div>
          <div v-if="['OPEN', 'ACKNOWLEDGED'].includes(item.status)" class="mt-4"><label class="text-xs font-bold text-slate-700">Catatan pendampingan<textarea :value="notes[item.id] || ''" @input="notes = { ...notes, [item.id]: ($event.target as HTMLTextAreaElement).value }" rows="3" placeholder="Contoh: sudah menghubungi siswa dan menjadwalkan pendampingan…" class="mt-2 w-full rounded-xl border border-slate-200 p-3 text-sm font-normal outline-none focus:ring-2 focus:ring-orange-400" /></label><div class="mt-3 flex flex-wrap gap-2"><button v-if="item.status === 'OPEN'" @click="update(item, 'ACKNOWLEDGED')" :disabled="submittingId === item.id" class="rounded-lg bg-blue-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-60">Tandai ditindaklanjuti</button><button @click="update(item, 'RESOLVED')" :disabled="submittingId === item.id" class="rounded-lg bg-emerald-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-60">Selesaikan</button><button @click="update(item, 'DISMISSED')" :disabled="submittingId === item.id" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-60">Tutup sinyal</button></div></div>
        </article>
      </template>
    </section>
  </main>
</template>
