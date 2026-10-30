<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { AlertCircle, CheckCircle2, Cloud, CloudOff, ExternalLink, FileUp, Loader2, Save, ShieldAlert, Store } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import { apiError } from "@/lib/api";
import Field from "@/components/dashboard/store/Field.vue";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
type ChecklistItem = {
  id: string;
  label: string;
  description?: string;
  weight: number;
  isRequired: boolean;
};
type StoreAssignment = {
  id: string;
  title: string;
  description?: string;
  endDate: string;
  checklistTemplate: { minimumProducts: number; items: ChecklistItem[] };
  storeSubmissions: Array<{
    id: string;
    status: string;
    rowVersion: number;
    currentVersion: number;
    score?: number;
    feedback?: string;
  }>;
};
type Draft = {
  assignmentId: string;
  platform: string;
  storeName: string;
  storeUsername: string;
  storeExternalId: string;
  storeUrl: string;
  category: string;
  description: string;
  createdDate: string;
  productCount: number;
  productName: string;
  productUrl: string;
  productPrice: string;
  productStock: string;
  studentNotes: string;
  obstacles: string;
  declarationAccepted: boolean;
  checklist: Record<string, boolean>;
};

const platforms = ["SHOPEE", "TIKTOK", "TOKOPEDIA", "LAZADA", "INSTAGRAM_SHOP", "WHATSAPP_BUSINESS", "WEBSITE", "OTHER"];
const initialDraft: Draft = {
  assignmentId: "",
  platform: "SHOPEE",
  storeName: "",
  storeUsername: "",
  storeExternalId: "",
  storeUrl: "",
  category: "",
  description: "",
  createdDate: "",
  productCount: 0,
  productName: "",
  productUrl: "",
  productPrice: "",
  productStock: "",
  studentNotes: "",
  obstacles: "",
  declarationAccepted: false,
  checklist: {},
};
const steps = ["Info toko", "Profil", "Produk", "Operasional", "Bukti", "Preview"];

const assignments = ref<StoreAssignment[]>([]);
const selected = ref<StoreAssignment | null>(null);
const draft = ref<Draft>(initialDraft);
const submission = ref<any>(null);
const step = ref(1);
const pendingFiles = ref<File[]>([]);
const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const message = ref("");
const error = ref("");
const offlineSavedAt = ref<string>("");
const syncStatus = ref<"local" | "syncing" | "synced" | "conflict">("local");
let syncVersion = 0;
const conflictDraft = ref<{ version: number; content: Partial<Draft> } | null>(null);

async function loadSyncedDraft(assignmentId: string, hasLocalDraft: boolean) {
  if (!navigator.onLine) return;
  const response = await fetch(
    `/api/backend/support/sync-drafts/store-${assignmentId}`,
  );
  if (!response.ok) return;
  if (response.status === 204) return;
  const serverDraft = await response.json().catch(() => null);
  if (!serverDraft) return;
  syncVersion = serverDraft.version;
  if (!hasLocalDraft && serverDraft.content) {
    draft.value = {
      ...draft.value,
      ...serverDraft.content,
      assignmentId,
    };
  }
  syncStatus.value = "synced";
}

async function loadSubmission(id: string) {
  const response = await fetch(
    `/api/backend/student-store/submissions/${id}`,
  );
  if (!response.ok) return;
  const data = await response.json().catch(() => null);
  if (!data) return;
  submission.value = data;
  const offline = data.draftData || {};
  const current = draft.value;
  draft.value = {
    ...current,
    platform: data.studentStore?.platform || current.platform,
    storeName: data.studentStore?.storeName || "",
    storeUsername: data.studentStore?.storeUsername || "",
    storeExternalId: data.studentStore?.storeExternalId || "",
    storeUrl: data.studentStore?.storeUrl || "",
    category: data.studentStore?.category || "",
    description: data.studentStore?.description || "",
    createdDate: data.studentStore?.createdDate?.slice(0, 10) || "",
    productCount: data.studentStore?.productCount || 0,
    productName: offline.products?.[0]?.name || "",
    productUrl: offline.products?.[0]?.url || "",
    productPrice: offline.products?.[0]?.price || "",
    productStock: offline.products?.[0]?.stock || "",
    studentNotes: offline.studentNotes || "",
    obstacles: offline.obstacles || "",
    declarationAccepted: Boolean(offline.declarationAccepted),
    checklist: Object.fromEntries(
      data.checklistResponses.map((item: any) => [
        item.itemId,
        item.completed,
      ]),
    ),
  };
}

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const response = await fetch("/api/backend/student-store/assignments");
    if (!response.ok)
      throw new Error(
        await apiError(response, "Tugas toko belum dapat dimuat"),
      );
    const data: StoreAssignment[] = await response.json();
    assignments.value = data;
    const requestedId =
      typeof window !== "undefined"
        ? new URLSearchParams(window.location.search).get("assignmentId")
        : null;
    const target =
      data.find((item) => item.id === requestedId) || data[0] || null;
    selected.value = target;
    if (target) {
      const local = localStorage.getItem(`store-draft:${target.id}`);
      draft.value = local
        ? { ...initialDraft, ...JSON.parse(local), assignmentId: target.id }
        : { ...initialDraft, assignmentId: target.id };
      await loadSyncedDraft(target.id, Boolean(local));
      if (target.storeSubmissions[0])
        await loadSubmission(target.storeSubmissions[0].id);
    }
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Data belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  void load();
});
watch(draft, (current, _previous, onCleanup) => {
  if (!current.assignmentId) return;
  const timer = window.setTimeout(async () => {
    localStorage.setItem(
      `store-draft:${current.assignmentId}`,
      JSON.stringify(current),
    );
    offlineSavedAt.value = new Date().toLocaleTimeString("id-ID", {
      hour: "2-digit",
      minute: "2-digit",
    });
    syncStatus.value = "local";
    if (!navigator.onLine) return;
    syncStatus.value = "syncing";
    const response = await csrfFetch(
      `/api/backend/support/sync-drafts/store-${current.assignmentId}`,
      {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ content: current, version: syncVersion }),
      },
    );
    if (response.status === 409) {
      const payload = await response.json().catch(() => null);
      const conflict = payload?.message && typeof payload.message === "object" ? payload.message : payload;
      conflictDraft.value = conflict ? { version: Number(conflict.serverVersion || 0), content: conflict.serverDraft || {} } : null;
      syncStatus.value = "conflict";
      return;
    }
    if (!response.ok) {
      syncStatus.value = "local";
      return;
    }
    const synced = await response.json();
    syncVersion = synced.version;
    syncStatus.value = "synced";
  }, 900);
  onCleanup(() => window.clearTimeout(timer));
}, { deep: true });

const useServerDraft = () => {
  if (!conflictDraft.value || !draft.value.assignmentId) return;
  syncVersion = conflictDraft.value.version;
  draft.value = { ...initialDraft, ...conflictDraft.value.content, assignmentId: draft.value.assignmentId };
  conflictDraft.value = null;
  syncStatus.value = "synced";
  message.value = "Versi draf dari akun dipakai pada perangkat ini.";
};

const overwriteServerDraft = async () => {
  if (!conflictDraft.value || !draft.value.assignmentId) return;
  syncStatus.value = "syncing";
  const response = await csrfFetch(`/api/backend/support/sync-drafts/store-${draft.value.assignmentId}`, { method: "PUT", headers: { "content-type": "application/json" }, body: JSON.stringify({ content: draft.value, version: conflictDraft.value.version }) });
  if (!response.ok) { syncStatus.value = "conflict"; return; }
  const synced = await response.json();
  syncVersion = synced.version;
  conflictDraft.value = null;
  syncStatus.value = "synced";
  message.value = "Draf perangkat ini menggantikan versi akun.";
};

const clearLocalDraft = () => {
  if (!draft.value.assignmentId) return;
  localStorage.removeItem(`store-draft:${draft.value.assignmentId}`);
  offlineSavedAt.value = "";
  message.value = "Salinan draf di perangkat ini telah dihapus.";
};

const checklistItems = computed(
  () => selected.value?.checklistTemplate?.items || [],
);
const completed = computed(() => checklistItems.value.filter(
  (item) => draft.value.checklist[item.id],
).length);
const isLocked = computed(() => [
  "APPROVED",
  "SUBMITTED",
  "RESUBMITTED",
  "UNDER_REVIEW",
  "REJECTED",
].includes(submission.value?.status));
const publicStoreUrl = computed(() =>
  submission.value?.studentStore?.normalizedStoreUrl || draft.value.storeUrl);
const completeness = computed(() => {
  const required = [
    draft.value.storeName.length >= 2,
    /^https?:\/\//.test(draft.value.storeUrl),
    draft.value.productCount >= (selected.value?.checklistTemplate.minimumProducts || 1),
    checklistItems.value.every(
      (item) => !item.isRequired || draft.value.checklist[item.id],
    ),
    Boolean(
      submission.value?.files?.some((file: any) => file.scanStatus === "CLEAN"),
    ),
    draft.value.declarationAccepted,
  ];
  return Math.round(
    (required.filter(Boolean).length / required.length) * 100,
  );
});

const selectAssignment = async (assignment: StoreAssignment) => {
  selected.value = assignment;
  step.value = 1;
  error.value = "";
  message.value = "";
  const local = localStorage.getItem(`store-draft:${assignment.id}`);
  draft.value = local
    ? { ...initialDraft, ...JSON.parse(local), assignmentId: assignment.id }
    : { ...initialDraft, assignmentId: assignment.id };
  syncVersion = 0;
  syncStatus.value = "local";
  await loadSyncedDraft(assignment.id, Boolean(local));
  submission.value = null;
  if (assignment.storeSubmissions[0])
    await loadSubmission(assignment.storeSubmissions[0].id);
};

const saveDraft = async () => {
  if (!selected.value) return null;
  saving.value = true;
  error.value = "";
  message.value = "";
  try {
    const response = await csrfFetch(
      "/api/backend/student-store/submissions/draft",
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          assignmentId: selected.value.id,
          rowVersion: submission.value?.rowVersion,
          platform: draft.value.platform,
          storeName: draft.value.storeName,
          storeUsername: draft.value.storeUsername || undefined,
          storeExternalId: draft.value.storeExternalId || undefined,
          storeUrl: draft.value.storeUrl,
          category: draft.value.category || undefined,
          description: draft.value.description || undefined,
          createdDate: draft.value.createdDate || undefined,
          productCount: draft.value.productCount,
          products: draft.value.productName
            ? [
                {
                  name: draft.value.productName,
                  url: draft.value.productUrl,
                  price: draft.value.productPrice,
                  stock: draft.value.productStock,
                },
              ]
            : [],
          studentNotes: draft.value.studentNotes || undefined,
          obstacles: draft.value.obstacles || undefined,
          declarationAccepted: draft.value.declarationAccepted,
          checklist: checklistItems.value.map((item) => ({
            itemId: item.id,
            completed: Boolean(draft.value.checklist[item.id]),
          })),
        }),
      },
    );
    if (!response.ok)
      throw new Error(await apiError(response, "Draf belum dapat disimpan"));
    const saved = await response.json();
    message.value = "Draf aman tersimpan di server.";
    await loadSubmission(saved.id);
    return saved.id as string;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Draf belum dapat disimpan";
    return null;
  } finally {
    saving.value = false;
  }
};

const uploadFiles = async () => {
  if (!pendingFiles.value.length) return;
  uploading.value = true;
  error.value = "";
  try {
    const submissionId = submission.value?.id || (await saveDraft());
    if (!submissionId) return;
    for (const [position, file] of pendingFiles.value.entries()) {
      const prepare = await csrfFetch(
        `/api/backend/student-store/submissions/${submissionId}/files/upload-url`,
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({
            fileName: file.name,
            mimeType: file.type,
            sizeBytes: file.size,
            kind: "EVIDENCE",
          }),
        },
      );
      if (!prepare.ok)
        throw new Error(
          await apiError(
            prepare,
            `Unggahan ${file.name} belum dapat disiapkan`,
          ),
        );
      const signed = await prepare.json();
      const upload = await fetch(signed.uploadUrl, {
        method: "PUT",
        headers: { "content-type": file.type },
        body: file,
      });
      if (!upload.ok) throw new Error(`Unggahan ${file.name} gagal`);
      const register = await csrfFetch(
        `/api/backend/student-store/submissions/${submissionId}/files`,
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({
            storageKey: signed.storageKey,
            fileName: file.name,
            mimeType: file.type,
            sizeBytes: file.size,
            kind: "EVIDENCE",
            position,
          }),
        },
      );
      if (!register.ok)
        throw new Error(
          await apiError(register, `File ${file.name} belum dapat dicatat`),
        );
    }
    pendingFiles.value = [];
    message.value = "Bukti diunggah dan sedang dipindai. Muat ulang beberapa saat lagi.";
    await loadSubmission(submissionId);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Bukti belum dapat diunggah";
  } finally {
    uploading.value = false;
  }
};

const removeFile = async (fileId: string) => {
  if (!submission.value?.id) return;
  saving.value = true;
  error.value = "";
  try {
    const response = await csrfFetch(
      `/api/backend/student-store/files/${fileId}`,
      { method: "DELETE" },
    );
    if (!response.ok)
      throw new Error(await apiError(response, "Bukti belum dapat dihapus"));
    message.value = "Bukti dihapus. Anda dapat mengunggah penggantinya.";
    await loadSubmission(submission.value.id);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Bukti belum dapat dihapus";
  } finally {
    saving.value = false;
  }
};

const submit = async () => {
  const submissionId = submission.value?.id || (await saveDraft());
  if (!submissionId) return;
  saving.value = true;
  error.value = "";
  try {
    const response = await csrfFetch(
      `/api/backend/student-store/submissions/${submissionId}/submit`,
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          declarationAccepted: draft.value.declarationAccepted,
        }),
      },
    );
    if (!response.ok)
      throw new Error(
        await apiError(response, "Submission belum dapat dikirim"),
      );
    message.value = "Tugas toko berhasil dikirim dan versi tersimpan.";
    localStorage.removeItem(`store-draft:${draft.value.assignmentId}`);
    await csrfFetch(
      `/api/backend/support/sync-drafts/store-${draft.value.assignmentId}`,
      { method: "DELETE" },
    );
    syncVersion = 0;
    syncStatus.value = "synced";
    await loadSubmission(submissionId);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Submission belum dapat dikirim";
  } finally {
    saving.value = false;
  }
};

const inputValue = (event: Event) => (event.target as HTMLInputElement).value;
</script>

<template>
  <main v-if="loading" class="min-h-screen bg-[#FFFFFF] p-8 font-semibold text-orange-600">Memuat Student Store...</main>
  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-5xl">
      <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Student Store</p>
      <h2 class="mt-2 text-2xl font-extrabold text-slate-800">Pengumpulan Toko Digital</h2>
      <div class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <ShieldAlert aria-hidden="true" class="mt-0.5 shrink-0" :size="19" />
        <p><strong>Keamanan:</strong> unggah hanya bukti yang sudah disanitasi. Jangan pernah memasukkan password, PIN, OTP, cookie, recovery code, API secret, atau data pembayaran.</p>
      </div>
      <div v-if="error" role="alert" class="mt-4 flex gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-700"><AlertCircle aria-hidden="true" :size="18" />{{ error }}</div>
      <div v-if="message" role="status" class="mt-4 flex gap-2 rounded-xl bg-green-50 p-4 text-sm text-green-700"><CheckCircle2 aria-hidden="true" :size="18" />{{ message }}</div>
      <div v-if="assignments.length === 0" class="mt-6 rounded-xl bg-white p-8 text-center text-slate-500">Belum ada tugas Student Store untuk course Anda.</div>
      <template v-else>
        <div class="mt-6 flex flex-wrap gap-2">
          <button v-for="assignment in assignments" :key="assignment.id" @click="void selectAssignment(assignment)" :class="['rounded-full px-4 py-2 text-sm font-bold', selected?.id === assignment.id ? 'bg-orange-600 text-white' : 'bg-white text-slate-600']">{{ assignment.title }}</button>
        </div>
        <section class="mt-5 rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h3 class="font-bold text-slate-800">{{ selected?.title }}</h3>
              <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ selected?.description }}</p>
              <p class="mt-2 text-xs font-semibold text-orange-700">Deadline {{ selected && new Date(selected.endDate).toLocaleString("id-ID") }}</p>
            </div>
            <div class="min-w-44">
              <div class="flex justify-between text-xs font-bold text-slate-600"><span>Kelengkapan</span><span>{{ completeness }}%</span></div>
              <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-orange-500" :style="{ width: `${completeness}%` }" /></div>
              <p class="mt-2 text-xs text-slate-500">Status: {{ submission?.status || "DRAFT" }} · Versi {{ submission?.currentVersion || 0 }}</p>
            </div>
          </div>
        </section>
        <nav aria-label="Tahap pengumpulan toko" class="mt-5 grid grid-cols-3 gap-2 sm:grid-cols-6">
          <button v-for="(label, index) in steps" :key="label" @click="step = index + 1" :class="['min-h-12 rounded-xl px-2 text-xs font-bold', step === index + 1 ? 'bg-orange-600 text-white' : 'border bg-white text-slate-600']"><span class="block">{{ index + 1 }}</span>{{ label }}</button>
        </nav>
        <section class="mt-5 rounded-2xl border border-amber-100 bg-white p-5 shadow-sm">
          <div v-if="isLocked" class="mb-4 rounded-xl bg-blue-50 p-4 text-sm text-blue-800">Submission sedang atau sudah direview dan terkunci. Penyuntingan server tersedia kembali jika guru meminta revisi.</div>
          <div v-if="step === 1" class="grid gap-4 sm:grid-cols-2">
            <Field label="Platform"><select :value="draft.platform" @change="draft = { ...draft, platform: ($event.target as HTMLSelectElement).value }" class="form-control"><option v-for="item in platforms" :key="item">{{ item.replaceAll("_", " ") }}</option></select></Field>
            <Field label="Nama toko"><input :value="draft.storeName" @input="draft = { ...draft, storeName: inputValue($event) }" class="form-control" placeholder="Nama toko publik" /></Field>
            <Field label="URL publik toko"><input type="url" :value="draft.storeUrl" @input="draft = { ...draft, storeUrl: inputValue($event) }" class="form-control" placeholder="https://..." /></Field>
            <Field label="Username publik"><input :value="draft.storeUsername" @input="draft = { ...draft, storeUsername: inputValue($event) }" class="form-control" /></Field>
            <Field label="ID toko publik (opsional)"><input :value="draft.storeExternalId" @input="draft = { ...draft, storeExternalId: inputValue($event) }" class="form-control" /></Field>
            <Field label="Tanggal dibuat"><input type="date" :value="draft.createdDate" @input="draft = { ...draft, createdDate: inputValue($event) }" class="form-control" /></Field>
          </div>
          <div v-if="step === 2" class="grid gap-4 sm:grid-cols-2">
            <Field label="Kategori"><input :value="draft.category" @input="draft = { ...draft, category: inputValue($event) }" class="form-control" /></Field>
            <Field label="Deskripsi toko"><textarea :value="draft.description" @input="draft = { ...draft, description: inputValue($event) }" class="form-control min-h-28" /></Field>
            <div class="rounded-xl bg-orange-50 p-4 text-sm text-orange-900 sm:col-span-2"><Store aria-hidden="true" class="mb-2" />Logo dan banner dapat dipilih bersama bukti pada langkah 5. Semua file disimpan privat dan dipindai.</div>
          </div>
          <div v-if="step === 3" class="grid gap-4 sm:grid-cols-2">
            <Field :label="`Jumlah produk (minimum ${selected?.checklistTemplate.minimumProducts})`"><input type="number" :min="0" :value="draft.productCount" @input="draft = { ...draft, productCount: Number(inputValue($event)) }" class="form-control" /></Field>
            <Field label="Contoh nama produk utama"><input :value="draft.productName" @input="draft = { ...draft, productName: inputValue($event) }" class="form-control" /></Field>
            <Field label="URL produk"><input type="url" :value="draft.productUrl" @input="draft = { ...draft, productUrl: inputValue($event) }" class="form-control" /></Field>
            <Field label="Harga"><input :value="draft.productPrice" @input="draft = { ...draft, productPrice: inputValue($event) }" class="form-control" placeholder="Contoh: 75000" /></Field>
            <Field label="Stok"><input :value="draft.productStock" @input="draft = { ...draft, productStock: inputValue($event) }" class="form-control" /></Field>
          </div>
          <div v-if="step === 4">
            <h3 class="font-bold text-slate-800">Checklist kelengkapan</h3>
            <p class="mt-1 text-sm text-slate-500">{{ completed }} dari {{ checklistItems.length }} item selesai.</p>
            <div class="mt-4 space-y-3">
              <label v-for="item in checklistItems" :key="item.id" class="flex items-start gap-3 rounded-xl border p-4"><input type="checkbox" :checked="Boolean(draft.checklist[item.id])" @change="draft = { ...draft, checklist: { ...draft.checklist, [item.id]: ($event.target as HTMLInputElement).checked } }" class="mt-1 size-4" /><span class="text-sm text-slate-700"><strong>{{ item.label }}</strong> <span class="text-orange-700">· {{ item.weight }}%{{ item.isRequired ? " · wajib" : "" }}</span><span v-if="item.description" class="mt-1 block text-slate-500">{{ item.description }}</span></span></label>
            </div>
            <Field label="Catatan proses"><textarea :value="draft.studentNotes" @input="draft = { ...draft, studentNotes: inputValue($event) }" class="form-control mt-1 min-h-24" /></Field>
            <Field label="Kendala yang ditemui"><textarea :value="draft.obstacles" @input="draft = { ...draft, obstacles: inputValue($event) }" class="form-control mt-1 min-h-20" /></Field>
          </div>
          <div v-if="step === 5">
            <h3 class="font-bold text-slate-800">Bukti privat</h3>
            <p class="mt-1 text-sm text-slate-500">JPG, PNG, WebP, PDF, atau MP4. Maksimal 20 MB per file.</p>
            <input type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4" @change="pendingFiles = Array.from(($event.target as HTMLInputElement).files || [])" class="mt-4 block w-full rounded-xl border p-3 text-sm" />
            <button type="button" @click="void uploadFiles()" :disabled="!pendingFiles.length || uploading || isLocked" class="mt-3 flex min-h-11 items-center gap-2 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white disabled:opacity-50"><Loader2 v-if="uploading" aria-hidden="true" class="animate-spin" :size="17" /><FileUp v-else aria-hidden="true" :size="17" />Unggah {{ pendingFiles.length || "" }} bukti</button>
            <div class="mt-4 grid gap-2">
              <div v-for="file in submission?.files" :key="file.id" class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 text-sm"><span class="min-w-0 truncate">{{ file.originalFileName }}</span><span :class="['font-bold', file.scanStatus === 'CLEAN' ? 'text-green-700' : file.scanStatus === 'INFECTED' || file.scanStatus === 'FAILED' ? 'text-red-600' : 'text-amber-700']">{{ file.scanStatus }}</span><button v-if="!isLocked" type="button" @click="void removeFile(file.id)" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-600">Hapus/ganti</button></div>
            </div>
          </div>
          <div v-if="step === 6">
            <h3 class="font-bold text-slate-800">Preview &amp; konfirmasi</h3>
            <dl class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 text-sm sm:grid-cols-2">
              <div><dt class="text-slate-500">Toko</dt><dd class="font-bold">{{ draft.storeName || "—" }}</dd></div>
              <div><dt class="text-slate-500">Platform</dt><dd class="font-bold">{{ draft.platform }}</dd></div>
              <div><dt class="text-slate-500">Produk</dt><dd class="font-bold">{{ draft.productCount }}</dd></div>
              <div><dt class="text-slate-500">Checklist</dt><dd class="font-bold">{{ completed }}/{{ checklistItems.length }}</dd></div>
            </dl>
            <a v-if="publicStoreUrl" :href="publicStoreUrl" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-orange-600">Buka URL publik <ExternalLink aria-hidden="true" :size="16" /></a>
            <label class="mt-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><input type="checkbox" :checked="draft.declarationAccepted" @change="draft = { ...draft, declarationAccepted: ($event.target as HTMLInputElement).checked }" class="mt-1 size-4" /><span>Saya menyatakan toko dan bukti ini milik saya/kelompok saya, serta tidak memuat kredensial atau data pembayaran sensitif.</span></label>
            <div v-if="submission?.versions?.length > 0" class="mt-5">
              <h4 class="font-bold">Riwayat versi</h4>
              <div class="mt-2 flex flex-wrap gap-2"><span v-for="version in submission.versions" :key="version.id" class="rounded-full bg-slate-100 px-3 py-1 text-xs">Versi {{ version.versionNumber }} · {{ new Date(version.createdAt).toLocaleString("id-ID") }}</span></div>
            </div>
            <div v-if="submission?.feedback" class="mt-4 rounded-xl bg-blue-50 p-4 text-sm text-blue-800"><strong>Feedback:</strong> {{ submission.feedback }}<span v-if="submission.score != null" class="ml-2 font-bold">Nilai {{ submission.score }}</span></div>
            <button type="button" @click="void submit()" :disabled="saving || !draft.declarationAccepted || isLocked" class="mt-5 min-h-12 w-full rounded-xl bg-green-600 font-bold text-white disabled:opacity-50">Kumpulkan dan simpan versi</button>
          </div>
          <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
            <span :class="['flex items-center gap-2 text-xs', syncStatus === 'conflict' ? 'font-bold text-red-600' : 'text-slate-500']"><CloudOff v-if="syncStatus === 'conflict'" aria-hidden="true" :size="15" /><Cloud v-else aria-hidden="true" :size="15" />{{ syncStatus === "syncing" ? "Menyinkronkan draf..." : syncStatus === "synced" ? "Draf tersinkron ke akun" : syncStatus === "conflict" ? "Konflik draf: muat ulang sebelum melanjutkan" : `Draf lokal otomatis ${offlineSavedAt ? `pukul ${offlineSavedAt}` : ""}` }}</span>
            <div v-if="syncStatus === 'conflict' && conflictDraft" class="flex w-full flex-wrap gap-2 rounded-xl bg-red-50 p-3 text-xs">
              <button type="button" @click="useServerDraft" class="min-h-10 rounded-lg bg-white px-3 font-bold text-red-700">Pakai versi akun</button>
              <button type="button" @click="void overwriteServerDraft()" class="min-h-10 rounded-lg border border-red-200 px-3 font-bold text-red-700">Pakai versi perangkat</button>
            </div>
            <div class="flex gap-2">
              <button type="button" @click="clearLocalDraft" class="min-h-11 rounded-xl border px-4 text-sm font-bold text-slate-600">Hapus draf lokal</button>
              <button type="button" @click="step = Math.max(1, step - 1)" :disabled="step === 1" class="min-h-11 rounded-xl border px-4 text-sm font-bold disabled:opacity-40">Kembali</button>
              <button type="button" @click="void saveDraft()" :disabled="saving || isLocked" class="flex min-h-11 items-center gap-2 rounded-xl border border-orange-300 px-4 text-sm font-bold text-orange-700 disabled:opacity-50"><Loader2 v-if="saving" aria-hidden="true" class="animate-spin" :size="16" /><Save v-else aria-hidden="true" :size="16" />Simpan server</button>
              <button type="button" @click="step = Math.min(6, step + 1)" :disabled="step === 6" class="min-h-11 rounded-xl bg-orange-600 px-4 text-sm font-bold text-white disabled:opacity-40">Lanjut</button>
            </div>
          </div>
        </section>
      </template>
    </div>
  </main>
</template>
