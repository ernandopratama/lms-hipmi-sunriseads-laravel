<script setup lang="ts">
import { onMounted, ref } from "vue";
import { Loader2, ShieldCheck, AlertCircle, CheckCircle } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type IdentityFormInputs = {
  nik: string;
  nisn: string;
  documentType: "KTP" | "KIA" | "KK";
  document: FileList;
  consent: boolean;
};

type IdentityStatus = {
  document: { id: string; documentType: string; documentStatus: string; verificationStatus: string; verificationNotes?: string | null; scanStatus: string } | null;
  identifiers: Array<{ type: string; masked: string; status: string }>;
};

// Stand-in for the source's react-hook-form rules: same fields, same rules, errors re-checked on change after the first submit.
const fieldOrder = ["nisn", "nik", "documentType", "document", "consent"] as const;
const errors = ref<Partial<Record<keyof IdentityFormInputs, boolean>>>({});
const submitted = ref(false);
const status = ref<IdentityStatus | null>(null);
const loading = ref(true);
const submitting = ref(false);
const error = ref("");
const success = ref(false);

function readForm(form: HTMLFormElement): IdentityFormInputs {
  const field = (name: string) => form.elements.namedItem(name) as HTMLInputElement;
  return { nik: field("nik").value, nisn: field("nisn").value, documentType: field("documentType").value as IdentityFormInputs["documentType"], document: field("document").files as FileList, consent: field("consent").checked };
}

function validate(data: IdentityFormInputs) {
  return {
    nisn: data.nisn !== "" && !/^\d{10}$/.test(data.nisn),
    nik: !data.nik || !/^\d{16}$/.test(data.nik),
    documentType: !data.documentType,
    document: !data.document?.length,
    consent: !data.consent,
  };
}

function revalidate(event: Event) {
  if (submitted.value) errors.value = validate(readForm(event.currentTarget as HTMLFormElement));
}

const fetchIdentityStatus = async () => {
  try {
    const res = await fetch("/api/backend/identity-documents/me", { cache: "no-store" });
    const data = await res.json();
    if (!res.ok) throw new Error("Gagal mengambil status identitas");
    status.value = data;
  } catch {
    error.value = "Status identitas belum dapat dimuat.";
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchIdentityStatus();
});

const onSubmit = async (formData: IdentityFormInputs) => {
  submitting.value = true;
  error.value = "";
  success.value = false;
  try {
    const file = formData.document?.[0];
    if (!file) throw new Error("Pilih dokumen identitas terlebih dahulu");
    const prepare = await csrfFetch("/api/backend/identity-documents/upload-url", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ fileName: file.name, mimeType: file.type, sizeBytes: file.size }),
    });
    const upload = await prepare.json();
    if (!prepare.ok) throw new Error(upload.message || "Tujuan unggah belum dapat dibuat");
    const stored = await fetch(upload.uploadUrl, { method: "PUT", headers: { "Content-Type": file.type }, body: file });
    if (!stored.ok) throw new Error("File gagal masuk ke penyimpanan karantina");
    const res = await csrfFetch("/api/backend/identity-documents", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        nik: formData.nik,
        nisn: formData.nisn || undefined,
        type: formData.documentType,
        storageKey: upload.storageKey,
        fileName: file.name,
        mimeType: file.type,
        sizeBytes: file.size,
        consent: formData.consent,
      }),
    });

    const data = await res.json();
    if (!res.ok) throw new Error(data.message || "Gagal mengunggah identitas");

    success.value = true;
    fetchIdentityStatus();
  } catch (err) {
    error.value = err instanceof Error ? err.message : "Pengajuan identitas gagal";
  } finally {
    submitting.value = false;
  }
};

function handleSubmit(event: Event) {
  const form = event.currentTarget as HTMLFormElement;
  const data = readForm(form);
  submitted.value = true;
  errors.value = validate(data);
  const firstError = fieldOrder.find((name) => errors.value[name]);
  if (firstError) {
    (form.elements.namedItem(firstError) as HTMLElement | null)?.focus();
    return;
  }
  void onSubmit(data);
}
</script>

<template>
  <div v-if="loading" class="p-8 bg-[#FFFFFF] min-h-screen">
    <div class="text-[#377C78] font-semibold">Memuat status identitas...</div>
  </div>
  <div v-else class="p-8 bg-[#FFFFFF] min-h-screen max-w-2xl">
    <h2 class="text-2xl font-bold text-[#1F2937] mb-2 flex items-center gap-2"><ShieldCheck aria-hidden="true" class="text-[#377C78]" /> Verifikasi Identitas</h2>
    <p class="text-[#64748B] mb-8">NIK dan NISN dienkripsi, sedangkan dokumen masuk karantina dan dipindai sebelum dapat ditinjau verifier.</p>

    <!-- Status Box -->
    <div v-if="status?.document" class="rounded-xl border border-[#DDEBE8] bg-white p-6 shadow-sm mb-6">
      <h3 class="text-lg font-bold text-[#1F2937] mb-3">Status Dokumen</h3>
      <div class="space-y-2">
        <p class="text-sm">Tipe Dokumen: <span class="font-semibold">{{ status.document.documentType }}</span></p>
        <p class="text-sm">Pemindaian file: <span class="font-semibold">{{ status.document.scanStatus }}</span></p>
        <p class="text-sm">Status Verifikasi: <span class="font-semibold text-[#377C78]">{{ status.document.verificationStatus }}</span></p>
        <p v-for="identifier in status.identifiers" :key="identifier.type" class="text-sm">{{ identifier.type }}: <span class="font-mono font-semibold">{{ identifier.masked }}</span></p>
        <p v-if="status.document.verificationNotes" class="text-sm text-red-600 bg-red-50 p-2 rounded">Catatan: {{ status.document.verificationNotes }}</p>
      </div>
    </div>
    <div v-else class="rounded-xl border border-dashed border-[#FBBF24] bg-yellow-50/50 p-6 text-center mb-6">
      <AlertCircle aria-hidden="true" class="mx-auto text-[#286864] mb-2" :size="32" />
      <p class="font-semibold text-[#1F2937]">Dokumen Belum Dilengkapi</p>
      <p class="text-sm text-[#64748B] mt-1">Harap lengkapi formulir di bawah ini untuk proses verifikasi.</p>
    </div>

    <!-- Form Upload -->
    <div v-if="!status?.document || ['REJECTED', 'NEEDS_CORRECTION'].includes(status.document.documentStatus) || status.document.scanStatus === 'FAILED'" class="rounded-xl border border-[#DDEBE8] bg-white p-6 shadow-sm">
      <div v-if="success" class="p-3 bg-green-50 rounded-lg flex items-center gap-2 text-sm text-green-600 mb-4">
        <CheckCircle aria-hidden="true" :size="18" />
        <span>Dokumen berhasil diajukan untuk verifikasi!</span>
      </div>
      <div v-if="error" class="p-3 bg-red-50 rounded-lg flex items-center gap-2 text-sm text-red-600 mb-4">
        <AlertCircle aria-hidden="true" :size="18" />
        <span>{{ error }}</span>
      </div>

      <form @submit.prevent="handleSubmit" @input="revalidate" @change="revalidate" class="space-y-4">
        <div>
          <label for="identity-nisn" class="block text-sm font-medium text-[#1F2937] mb-1">NISN (Opsional)</label>
          <input id="identity-nisn" inputmode="numeric" maxlength="10" name="nisn" class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none" placeholder="10 digit NISN" />
          <span v-if="errors.nisn" class="text-red-500 text-xs">NISN harus berisi tepat 10 digit.</span>
        </div>

        <div>
          <label for="identity-nik" class="block text-sm font-medium text-[#1F2937] mb-1">NIK (Nomor Induk Kependudukan)</label>
          <input
            id="identity-nik"
            type="text"
            maxlength="16"
            name="nik"
            class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
            placeholder="16 digit angka NIK"
          />
          <span v-if="errors.nik" class="text-red-500 text-xs">NIK harus berisi tepat 16 digit angka.</span>
        </div>

        <div>
          <label for="identity-document-type" class="block text-sm font-medium text-[#1F2937] mb-1">Tipe Dokumen</label>
          <select
            id="identity-document-type"
            name="documentType"
            class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
          >
            <option value="KTP">KTP (Siswa &gt;= 17 tahun)</option>
            <option value="KIA">KIA (Siswa &lt; 17 tahun)</option>
            <option value="KK">Kartu Keluarga (KK)</option>
          </select>
        </div>

        <div>
          <label for="identity-document" class="block text-sm font-medium text-[#1F2937] mb-1">Dokumen identitas</label>
          <input
            id="identity-document"
            type="file"
            accept="image/jpeg,image/png,application/pdf"
            name="document"
            class="w-full rounded-lg border border-[#64748B]/30 px-3 py-2 text-sm focus:border-[#377C78] focus:outline-none"
          />
          <p class="mt-1 text-xs text-slate-500">JPG, PNG, atau PDF; maksimal 8 MB. File tidak langsung dapat dibuka sebelum lolos pemindaian.</p>
        </div>

        <div class="flex items-start gap-2 bg-[#FFFFFF] p-3 rounded-lg border border-[#DDEBE8] text-xs text-[#64748B]">
          <input
            id="identity-consent"
            type="checkbox"
            required
            name="consent"
            class="mt-0.5"
          />
          <label for="identity-consent">Saya menyetujui pemrosesan terbatas NIK, NISN, dan dokumen ini untuk verifikasi kepesertaan sesuai versi persetujuan yang tercatat. Persetujuan dapat ditarik melalui permintaan hak data.</label>
        </div>

        <div class="flex justify-end pt-4 border-t border-gray-100">
          <button
            type="submit"
            :disabled="submitting"
            class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-[#FBBF24] to-[#377C78] text-white rounded-lg text-sm font-semibold hover:opacity-90 disabled:opacity-50"
          ><Loader2 v-if="submitting" aria-hidden="true" :size="16" class="animate-spin" />Ajukan Verifikasi</button>
        </div>
      </form>
    </div>
  </div>
</template>
