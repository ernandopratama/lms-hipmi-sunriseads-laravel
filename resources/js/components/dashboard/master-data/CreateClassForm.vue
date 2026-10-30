<script setup lang="ts">
import { ref } from "vue";
import { csrfFetch } from "@/lib/csrf";

const emit = defineEmits<{ created: [] }>();
const message = ref("");

async function submit(event: Event) {
  const formElement = event.currentTarget as HTMLFormElement;
  const form = new FormData(formElement);
  const response = await csrfFetch("/api/backend/classes", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ code: form.get("code"), name: form.get("name"), grade: Number(form.get("grade")) || undefined, capacity: Number(form.get("capacity")) || undefined }) });
  const result = await response.json().catch(() => ({}));
  message.value = response.ok ? "Kelas berhasil ditambahkan." : result.message || "Kelas gagal ditambahkan.";
  if (response.ok) { formElement.reset(); emit("created"); }
}
</script>

<template>
  <form @submit.prevent="submit" class="mb-4 grid gap-3 rounded-xl border border-orange-100 bg-white p-4 sm:grid-cols-5"><input name="code" required placeholder="Kode kelas" aria-label="Kode kelas" class="min-h-11 rounded-xl border border-slate-300 px-3" /><input name="name" required placeholder="Nama kelas" aria-label="Nama kelas" class="min-h-11 rounded-xl border border-slate-300 px-3" /><input name="grade" type="number" min="1" max="13" placeholder="Tingkat" aria-label="Tingkat" class="min-h-11 rounded-xl border border-slate-300 px-3" /><input name="capacity" type="number" min="1" placeholder="Kapasitas" aria-label="Kapasitas" class="min-h-11 rounded-xl border border-slate-300 px-3" /><button class="bg-sunrise min-h-11 rounded-xl px-3 text-sm font-extrabold text-white">Tambah kelas</button><p v-if="message" role="status" class="text-sm text-slate-600 sm:col-span-5">{{ message }}</p></form>
</template>
