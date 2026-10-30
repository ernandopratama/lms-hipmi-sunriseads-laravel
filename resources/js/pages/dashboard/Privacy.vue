<script setup lang="ts">
import { onMounted, ref } from "vue";
import { AlertCircle, CheckCircle2, Loader2, Scale, ShieldCheck } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type ConsentDocument = { id: string; type: string; version: string; title: string; content: string; effectiveAt: string };
type Consent = { id: string; type: string; status: string; grantedAt: string; withdrawnAt?: string | null; mayWithdraw: boolean; document: { title: string; version: string }; student?: { id: string; fullName: string } | null };
type DataRequest = { id: string; type: string; status: string; details?: string | null; resolution?: string | null; dueAt?: string | null; createdAt: string };
type ManagedRequest = DataRequest & { requester: { firstName: string; lastName: string; email: string }; student?: { fullName: string; studentNumber: string } | null };
type Subject = { id: string; fullName: string; studentNumber: string };

const nextStatuses: Record<string, string[]> = { SUBMITTED: ["IN_REVIEW", "CANCELLED"], IN_REVIEW: ["APPROVED", "REJECTED", "CANCELLED"], APPROVED: ["COMPLETED", "CANCELLED"] };

const documents = ref<ConsentDocument[]>([]);
const consents = ref<Consent[]>([]);
const requests = ref<DataRequest[]>([]);
const managedRequests = ref<ManagedRequest[]>([]);
const subjects = ref<Subject[]>([]);
const isParent = ref(false);
const canManagePrivacy = ref(false);
const loading = ref(true);
const busy = ref("");
const subjectByDocument = ref<Record<string, string>>({});
const resolutionByRequest = ref<Record<string, string>>({});
const message = ref("");
const error = ref("");

async function load() {
  try {
    const sessionResponse = await fetch("/api/session", { cache: "no-store" });
    if (!sessionResponse.ok) throw new Error("Sesi pengguna tidak tersedia");
    const sessionData = await sessionResponse.json();
    const mayManage = Array.isArray(sessionData.roles) && sessionData.roles.includes("super-admin") || sessionData.permissions?.some((permission: { resource?: string; action?: string }) => permission.resource === "privacy" && permission.action === "manage");
    const responses = await Promise.all([
      fetch("/api/backend/privacy/consent-documents", { cache: "no-store" }),
      fetch("/api/backend/privacy/consents/me", { cache: "no-store" }),
      fetch("/api/backend/privacy/data-requests/me", { cache: "no-store" }),
      fetch("/api/backend/privacy/subjects", { cache: "no-store" }),
    ]);
    if (responses.some((response) => !response.ok)) throw new Error("Data privasi belum dapat dimuat");
    const [documentData, consentData, requestData, subjectData] = await Promise.all(responses.map((response) => response.json()));
    documents.value = documentData;
    consents.value = consentData;
    requests.value = requestData;
    subjects.value = subjectData;
    isParent.value = Array.isArray(sessionData.roles) && sessionData.roles.includes("parent");
    canManagePrivacy.value = Boolean(mayManage);
    if (mayManage) {
      const managedResponse = await fetch("/api/backend/privacy/data-requests", { cache: "no-store" });
      if (!managedResponse.ok) throw new Error("Antrean hak data belum dapat dimuat");
      managedRequests.value = await managedResponse.json();
    } else {
      managedRequests.value = [];
    }
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Data privasi belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

onMounted(load);

async function grant(document: ConsentDocument, studentId?: string) {
  busy.value = document.id;
  error.value = "";
  const response = await csrfFetch("/api/backend/privacy/consents", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ documentId: document.id, type: document.type, studentId: studentId || undefined, method: "AUTHENTICATED_CHECKBOX" }),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) error.value = result.message || "Persetujuan belum dapat dicatat";
  else { message.value = "Persetujuan dan versinya berhasil dicatat."; await load(); }
  busy.value = "";
}

async function withdraw(id: string) {
  busy.value = id;
  const response = await csrfFetch(`/api/backend/privacy/consents/${id}`, {
    method: "DELETE",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ reason: "Ditarik oleh pengguna melalui dashboard" }),
  });
  if (!response.ok) error.value = "Persetujuan belum dapat ditarik";
  else { message.value = "Persetujuan telah ditarik tanpa menghapus riwayat audit."; await load(); }
  busy.value = "";
}

async function createRequest(event: Event) {
  const formElement = event.currentTarget as HTMLFormElement;
  const form = new FormData(formElement);
  busy.value = "request";
  const response = await csrfFetch("/api/backend/privacy/data-requests", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ type: form.get("type"), studentId: form.get("studentId") || undefined, details: form.get("details") || undefined }),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) error.value = result.message || "Permintaan belum dapat dibuat";
  else { message.value = "Permintaan hak data berhasil dicatat dan memiliki tenggat penanganan."; formElement.reset(); await load(); }
  busy.value = "";
}

async function updateManagedRequest(id: string, status: string) {
  busy.value = id;
  error.value = "";
  const response = await csrfFetch(`/api/backend/privacy/data-requests/${id}`, {
    method: "PUT",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ status, resolution: ["REJECTED", "COMPLETED"].includes(status) ? resolutionByRequest.value[id] : undefined }),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok) error.value = result.message || "Status permintaan belum dapat diperbarui";
  else { message.value = `Permintaan diperbarui menjadi ${status}.`; await load(); }
  busy.value = "";
}

const subjectFor = (document: ConsentDocument) => subjectByDocument.value[document.id] || subjects.value[0]?.id;
const activeConsent = (document: ConsentDocument) => {
  const subjectId = subjectFor(document);
  return consents.value.find((consent) => consent.type === document.type && consent.status === "ACTIVE" && (subjectId ? consent.student?.id === subjectId : !consent.student));
};
</script>

<template>
  <div v-if="loading" class="flex min-h-[60vh] items-center justify-center gap-2 text-orange-600"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat pusat privasi...</div>
  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8"><div class="mx-auto max-w-5xl">
    <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Privacy by design</p>
    <h2 class="mt-2 flex items-center gap-2 text-2xl font-extrabold text-slate-900"><Scale aria-hidden="true" class="text-orange-600" /> Privasi &amp; Persetujuan</h2>
    <p class="mt-2 text-sm text-slate-600">Lihat versi persetujuan, tarik pilihan opsional, atau ajukan akses, koreksi, pembatasan, export, dan penghapusan sesuai kebijakan retensi.</p>
    <p v-if="error" role="alert" class="mt-5 flex gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error }}</p>
    <p v-if="message" role="status" class="mt-5 flex gap-2 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800"><CheckCircle2 aria-hidden="true" :size="18" />{{ message }}</p>

    <section class="mt-7"><h3 class="text-lg font-bold text-slate-900">Dokumen persetujuan aktif</h3><div class="mt-3 grid gap-4 md:grid-cols-2">
      <article v-for="document in documents" :key="document.id" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold text-orange-600">{{ document.type }} · v{{ document.version }}</p><h4 class="mt-1 font-bold text-slate-900">{{ document.title }}</h4></div><ShieldCheck aria-hidden="true" class="text-emerald-600" :size="20" /></div><p class="mt-3 text-sm leading-6 text-slate-600">{{ document.content }}</p><label v-if="isParent && subjects.length > 1 && document.type !== 'IDENTITY'" class="mt-3 block text-xs font-bold text-slate-700">Subjek siswa<select :value="subjectFor(document)" @change="subjectByDocument = { ...subjectByDocument, [document.id]: ($event.target as HTMLSelectElement).value }" class="mt-1 min-h-10 w-full rounded-xl border border-slate-300 px-3"><option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.fullName }}</option></select></label><div class="mt-4">
        <template v-if="activeConsent(document)"><button v-if="activeConsent(document)!.mayWithdraw" type="button" :disabled="busy === activeConsent(document)!.id" @click="withdraw(activeConsent(document)!.id)" class="min-h-11 rounded-xl border border-red-200 px-4 text-sm font-bold text-red-700 disabled:opacity-50">Tarik persetujuan</button><p v-else class="text-xs font-semibold text-emerald-700">Persetujuan aktif; hanya pemberi atau subjek yang dapat menariknya.</p></template>
        <p v-else-if="document.type === 'IDENTITY'" class="text-xs font-semibold text-slate-500">Persetujuan ini dicatat bersama pengajuan identitas dan tidak dapat dibuat terpisah.</p>
        <button v-else type="button" :disabled="busy === document.id || (document.type === 'GUARDIAN' && (!subjectFor(document) || !isParent))" @click="grant(document, subjectFor(document))" class="bg-sunrise min-h-11 rounded-xl px-4 text-sm font-bold text-white disabled:opacity-50">Berikan persetujuan</button>
      </div></article>
    </div></section>

    <section class="mt-8 grid gap-5 lg:grid-cols-2"><form @submit.prevent="createRequest" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Ajukan permintaan hak data</h3><label class="mt-4 block text-sm font-bold text-slate-700">Jenis<select name="type" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option value="ACCESS">Akses data</option><option value="CORRECTION">Koreksi</option><option value="DELETION">Penghapusan sesuai retensi</option><option value="RESTRICTION">Pembatasan pemrosesan</option><option value="EXPORT">Export aman</option><option value="CONSENT_WITHDRAWAL">Penarikan persetujuan</option></select></label><label v-if="subjects.length > 0" class="mt-3 block text-sm font-bold text-slate-700">Subjek<select name="studentId" class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3"><option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.fullName }} ({{ subject.studentNumber }})</option></select></label><label class="mt-3 block text-sm font-bold text-slate-700">Keterangan<textarea name="details" maxlength="2000" class="mt-1 min-h-28 w-full rounded-xl border border-slate-300 p-3" /></label><button :disabled="busy === 'request'" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white disabled:opacity-50">Kirim permintaan</button></form>
    <div class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Riwayat permintaan</h3><div class="mt-4 space-y-3"><p v-if="requests.length === 0" class="text-sm text-slate-500">Belum ada permintaan.</p><template v-else><div v-for="request in requests" :key="request.id" class="rounded-xl bg-slate-50 p-4"><div class="flex justify-between gap-3"><p class="text-sm font-bold text-slate-900">{{ request.type }}</p><span class="text-xs font-bold text-orange-700">{{ request.status }}</span></div><p class="mt-1 text-xs text-slate-500">Dibuat {{ new Date(request.createdAt).toLocaleDateString("id-ID") }}{{ request.dueAt ? ` · Tenggat ${new Date(request.dueAt).toLocaleDateString("id-ID")}` : "" }}</p><p v-if="request.resolution" class="mt-2 text-sm text-slate-600">{{ request.resolution }}</p></div></template></div></div></section>
    <section v-if="canManagePrivacy" class="mt-8 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Antrean penanganan hak data</h3><p class="mt-1 text-sm text-slate-600">Keputusan final memerlukan resolusi tertulis dan tidak dapat dibuka ulang.</p><div class="mt-4 space-y-4"><p v-if="managedRequests.length === 0" class="text-sm text-slate-500">Tidak ada permintaan dalam antrean.</p><template v-else><article v-for="request in managedRequests" :key="request.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-900">{{ request.type }} · {{ request.requester.firstName }} {{ request.requester.lastName }}</p><p class="text-xs text-slate-500">{{ request.student ? `${request.student.fullName} (${request.student.studentNumber})` : request.requester.email }}</p></div><span class="text-xs font-bold text-orange-700">{{ request.status }}</span></div><p v-if="request.details" class="mt-3 text-sm text-slate-600">{{ request.details }}</p><label v-if="(nextStatuses[request.status] || []).some((action) => ['REJECTED', 'COMPLETED'].includes(action))" class="mt-3 block text-xs font-bold text-slate-700">Resolusi<textarea :value="resolutionByRequest[request.id] || ''" @input="resolutionByRequest = { ...resolutionByRequest, [request.id]: ($event.target as HTMLTextAreaElement).value }" maxlength="4000" class="mt-1 min-h-20 w-full rounded-xl border border-slate-300 bg-white p-3 text-sm" /></label><div class="mt-3 flex flex-wrap gap-2"><button v-for="action in nextStatuses[request.status] || []" :key="action" type="button" :disabled="busy === request.id || (['REJECTED', 'COMPLETED'].includes(action) && !resolutionByRequest[request.id]?.trim())" @click="updateManagedRequest(request.id, action)" class="min-h-10 rounded-xl border border-orange-300 bg-white px-3 text-xs font-bold text-orange-700 disabled:opacity-50">{{ action }}</button></div></article></template></div></section>
  </div></main>
</template>
