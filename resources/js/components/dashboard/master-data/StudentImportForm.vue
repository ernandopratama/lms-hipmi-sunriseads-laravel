<script setup lang="ts">
import { ref } from "vue";
import { csrfFetch } from "@/lib/csrf";

const emit = defineEmits<{ completed: [] }>();
const file = ref<File | null>(null);
const status = ref("");
const loading = ref(false);

function setStatus(message: string) {
  status.value = message;
}

function chooseFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] || null;
  status.value = "";
}

async function submit() {
  const selected = file.value;
  if (!selected) return;
  if (selected.size > 2 * 1024 * 1024) return setStatus("File maksimal 2 MB.");
  loading.value = true;
  try {
    const parsed = parseCsv(await selected.text());
    if (parsed.length < 2) throw new Error("CSV belum memiliki baris data");
    const headers = parsed[0].map((value) => value.trim());
    const required = ["email", "studentNumber", "fullName"];
    if (required.some((name) => !headers.includes(name))) throw new Error(`Header wajib: ${required.join(", ")}`);
    const rows = parsed.slice(1).filter((row) => row.some(Boolean)).map((row) => Object.fromEntries(headers.map((header, index) => [header, row[index]?.trim() || undefined])));
    const response = await csrfFetch("/api/backend/imports/students", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ sourceName: selected.name, rows }),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(Array.isArray(result.message) ? result.message.join(", ") : result.message || "Import gagal dibuat");
    setStatus(`Import ${result.totalRows} baris masuk antrean.`);
    await monitorImport(result.id, setStatus);
    emit("completed");
  } catch (caught) {
    setStatus(caught instanceof Error ? caught.message : "File belum dapat diproses");
  } finally {
    loading.value = false;
  }
}

async function monitorImport(id: string, setStatus: (message: string) => void) {
  for (let attempt = 0; attempt < 60; attempt += 1) {
    const response = await fetch(`/api/backend/imports/${id}`);
    if (!response.ok) throw new Error("Status import belum dapat dibaca");
    const job = await response.json();
    setStatus(`${job.status}: ${job.processedRows}/${job.totalRows} diproses, ${job.failedRows} gagal.`);
    if (["COMPLETED", "COMPLETED_WITH_ERRORS", "FAILED", "CANCELLED"].includes(job.status)) return;
    await new Promise((resolve) => window.setTimeout(resolve, 500));
  }
  throw new Error("Import masih berjalan. Periksa kembali daftar import nanti.");
}

function parseCsv(text: string) {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = "";
  let quoted = false;
  for (let index = 0; index < text.length; index += 1) {
    const char = text[index];
    if (char === '"' && quoted && text[index + 1] === '"') { cell += '"'; index += 1; }
    else if (char === '"') quoted = !quoted;
    else if (char === "," && !quoted) { row.push(cell); cell = ""; }
    else if ((char === "\n" || char === "\r") && !quoted) {
      if (char === "\r" && text[index + 1] === "\n") index += 1;
      row.push(cell); rows.push(row); row = []; cell = "";
    } else cell += char;
  }
  if (cell || row.length) { row.push(cell); rows.push(row); }
  return rows;
}
</script>

<template>
  <form @submit.prevent="submit" class="mb-4 rounded-xl border border-orange-100 bg-white p-4"><div class="flex flex-wrap items-end gap-3"><div class="min-w-64 flex-1"><label for="student-import" class="text-sm font-bold text-slate-700">Import siswa CSV</label><input id="student-import" type="file" accept=".csv,text/csv" required @change="chooseFile" class="mt-1 block min-h-11 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" /></div><button :disabled="loading || !file" class="bg-sunrise min-h-11 rounded-xl px-4 text-sm font-extrabold text-white disabled:opacity-60">{{ loading ? "Memproses antrean..." : "Import asynchronous" }}</button><a download="template-import-siswa.csv" href="data:text/csv;charset=utf-8,email%2CstudentNumber%2CfullName%2CclassCode%2Cnisn%0A" class="inline-flex min-h-11 items-center rounded-xl border border-orange-300 px-4 text-sm font-bold text-orange-700">Unduh template</a></div><p v-if="status" role="status" class="mt-3 text-sm text-slate-600">{{ status }}</p></form>
</template>
