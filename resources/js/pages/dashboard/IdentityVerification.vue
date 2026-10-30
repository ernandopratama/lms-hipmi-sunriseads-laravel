<script setup lang="ts">
import { onMounted, ref } from "vue";
import { ShieldAlert, Check, X, FileText, Loader2, AlertCircle, Download } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const documents = ref<any[]>([]);
const loading = ref(true);
const accessDenied = ref(false);
const canReadDocument = ref(false);
const processingId = ref<string | null>(null);

const notes = ref<{ [key: string]: string }>({});
const reasons = ref<{ [key: string]: string }>({});
const error = ref("");

const fetchPendingDocuments = async () => {
  try {
    const sessionRes = await fetch("/api/session", { cache: "no-store" });
    if (!sessionRes.ok) {
      accessDenied.value = true;
      return;
    }

    const session = await sessionRes.json();
    const canVerify = session?.roles?.includes("super-admin") || session?.permissions?.some((permission: { resource?: string; action?: string }) => permission.resource === "identity" && permission.action === "verify");
    if (!canVerify) {
      accessDenied.value = true;
      return;
    }
    canReadDocument.value = session?.roles?.includes("super-admin") || session?.permissions?.some((permission: { resource?: string; action?: string }) => permission.resource === "identity.document" && permission.action === "read");

    const res = await fetch("/api/backend/identity-documents/pending", { cache: "no-store" });
    if (res.status === 401 || res.status === 403) {
      accessDenied.value = true;
      return;
    }
    if (!res.ok) throw new Error("Gagal mengambil antrean verifikasi");

    const data = await res.json();
    documents.value = Array.isArray(data) ? data : [];
  } catch (err) {
    console.error("Gagal mengambil antrean verifikasi", err);
    error.value = "Antrean verifikasi belum dapat dimuat. Silakan coba lagi.";
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchPendingDocuments();
});

const handleVerify = async (id: string, status: "VALID" | "REJECTED" | "NEEDS_CORRECTION") => {
  if (status !== "VALID" && !notes.value[id]?.trim()) {
    error.value = "Catatan wajib diisi ketika menolak atau meminta perbaikan.";
    return;
  }
  processingId.value = id;
  error.value = "";
  try {
    const res = await csrfFetch(`/api/backend/identity-documents/${id}/verify`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        status,
        reasonCode: status === "VALID" ? undefined : reasons.value[id] || "DOCUMENT_MISMATCH",
        notes: notes.value[id] || "",
      }),
    });

    if (!res.ok) throw new Error("Gagal melakukan verifikasi dokumen");

    // Hapus dari list lokal
    documents.value = documents.value.filter(doc => doc.id !== id);
  } catch (err: any) {
    error.value = err.message;
  } finally {
    processingId.value = null;
  }
};

const handleAccess = async (id: string) => {
  processingId.value = id;
  error.value = "";
  try {
    const response = await csrfFetch(`/api/backend/identity-documents/${id}/access`, { method: "POST" });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || "Dokumen belum dapat diakses");
    window.location.assign(result.url);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Dokumen belum dapat diakses";
  } finally {
    processingId.value = null;
  }
};

const handleNoteChange = (id: string, value: string) => {
  notes.value = { ...notes.value, [id]: value };
};
</script>

<template>
  <div class="p-8 bg-[#FFFFFF] min-h-screen">
    <h2 class="text-2xl font-bold text-[#1F2937] mb-2 flex items-center gap-2"><ShieldAlert aria-hidden="true" class="text-[#377C78]" /> Antrean Verifikasi Identitas</h2>
    <p class="text-[#64748B] mb-8">Hanya verifier berwenang yang dapat membuka file melalui tautan singkat. Setiap akses dan keputusan dicatat dalam audit.</p>

    <div v-if="accessDenied" class="p-6 bg-red-50 rounded-xl border border-red-200 text-red-700 max-w-3xl" role="alert">
      <h3 class="font-bold mb-1">Akses ditolak</h3>
      <p class="text-sm">Halaman ini hanya dapat dibuka oleh administrator yang berwenang.</p>
    </div>
    <div v-else-if="error" class="p-3 bg-red-50 rounded-lg flex items-center gap-2 text-sm text-red-600 mb-6 max-w-3xl">
      <AlertCircle aria-hidden="true" :size="18" />
      <span>{{ error }}</span>
    </div>

    <template v-if="!accessDenied">
      <div v-if="loading" class="text-[#377C78] font-semibold">Memuat dokumen pending...</div>
      <div v-else class="space-y-6 max-w-3xl">
        <div v-if="documents.length === 0" class="p-8 bg-white border border-[#DDEBE8] rounded-xl text-center text-[#64748B]">Tidak ada dokumen yang menunggu verifikasi saat ini.</div>
        <template v-else><div v-for="doc in documents" :key="doc.id" class="p-6 bg-white rounded-xl border border-[#DDEBE8] shadow-sm space-y-4">
          <div class="flex justify-between items-start">
            <div>
              <h3 class="font-bold text-lg text-[#1F2937]">{{ doc.student.fullName }}</h3>
              <p class="text-sm text-[#64748B]">No. Induk: {{ doc.student.studentNumber }} | Sekolah: {{ doc.student.school.name }}</p>
            </div>
            <span class="px-3 py-1 bg-yellow-50 text-[#286864] text-xs font-semibold rounded-full border border-yellow-200">{{ doc.documentType }}</span>
          </div>

          <div class="bg-[#FFFFFF] p-4 rounded-lg border border-[#DDEBE8] text-sm space-y-2">
            <p v-for="identifier in (doc.student.identifiers as { type: string; masked: string; status: string }[])" :key="identifier.type">{{ identifier.type }}: <span class="font-mono bg-gray-100 px-2 py-0.5 rounded text-gray-700 font-bold">{{ identifier.masked }}</span></p>
            <p class="flex items-center gap-1.5 text-xs text-emerald-700 mt-2 font-semibold"><FileText aria-hidden="true" :size="14" /> File telah lolos pemeriksaan karantina</p>
            <button v-if="canReadDocument" type="button" @click="handleAccess(doc.id)" :disabled="processingId === doc.id" class="mt-2 inline-flex min-h-10 items-center gap-2 rounded-lg border border-orange-300 px-3 font-bold text-orange-700 disabled:opacity-50"><Download aria-hidden="true" :size="15" /> Buka dokumen (90 detik)</button><p v-else class="mt-2 text-xs font-semibold text-slate-500">Izin verifikasi tidak otomatis memberikan izin membuka file identitas.</p>
          </div>

          <!-- Notes Input -->
          <div>
            <label :for="`verification-notes-${doc.id}`" class="block text-xs font-semibold text-[#1F2937] mb-1">Catatan Verifikasi (Opsional jika valid, wajib jika tolak/revisi)</label>
            <input
              :id="`verification-notes-${doc.id}`"
              type="text"
              placeholder="Contoh: NIK tidak sesuai dengan KTP, Foto dokumen buram..."
              class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
              :value="notes[doc.id] || ''"
              @input="handleNoteChange(doc.id, ($event.target as HTMLInputElement).value)"
            />
          </div>

          <div>
            <label :for="`verification-reason-${doc.id}`" class="block text-xs font-semibold text-[#1F2937] mb-1">Kode alasan untuk tolak/perbaikan</label>
            <select :id="`verification-reason-${doc.id}`" :value="reasons[doc.id] || 'DOCUMENT_MISMATCH'" @change="reasons = { ...reasons, [doc.id]: ($event.target as HTMLSelectElement).value }" class="min-h-10 w-full rounded-lg border border-[#64748B]/30 px-3 text-sm"><option value="DOCUMENT_MISMATCH">Data tidak cocok</option><option value="FILE_UNREADABLE">Dokumen tidak terbaca</option><option value="DOCUMENT_EXPIRED">Dokumen kedaluwarsa</option><option value="OTHER">Lainnya</option></select>
          </div>

          <!-- Actions Button -->
          <div class="flex gap-3 justify-end pt-2">
            <button
              :disabled="processingId === doc.id"
              @click="handleVerify(doc.id, 'REJECTED')"
              class="px-4 py-2 border border-red-200 text-red-600 rounded-lg text-xs font-bold hover:bg-red-50 flex items-center gap-1 disabled:opacity-50"
            ><X aria-hidden="true" :size="14" /> Tolak Dokumen</button>
            <button
              :disabled="processingId === doc.id"
              @click="handleVerify(doc.id, 'NEEDS_CORRECTION')"
              class="px-4 py-2 border border-yellow-300 text-yellow-700 rounded-lg text-xs font-bold hover:bg-yellow-50 flex items-center gap-1 disabled:opacity-50"
            ><AlertCircle aria-hidden="true" :size="14" /> Minta Perbaikan</button>
            <button
              :disabled="processingId === doc.id"
              @click="handleVerify(doc.id, 'VALID')"
              class="px-4 py-2 bg-gradient-to-r from-[#FBBF24] to-[#377C78] text-white rounded-lg text-xs font-bold hover:opacity-90 flex items-center gap-1 disabled:opacity-50"
            ><Loader2 v-if="processingId === doc.id" aria-hidden="true" :size="14" class="animate-spin" /><Check v-else aria-hidden="true" :size="14" />Setujui Identitas</button>
          </div>
        </div></template>
      </div>
    </template>
  </div>
</template>
