<script setup lang="ts">
import { ref } from "vue";
import { csrfFetch } from "@/lib/csrf";
import AccountPageFrame from "@/components/AccountPageFrame.vue";

const token = new URLSearchParams(window.location.search).get("token") || "";
const password = ref("");
const message = ref("");
const error = ref("");
const loading = ref(false);

async function submit() {
  loading.value = true;
  error.value = "";
  const response = await csrfFetch("/api/backend/auth/reset-password", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ token, password: password.value }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) error.value = Array.isArray(data.message) ? data.message.join(", ") : data.message || "Token tidak valid";
  else message.value = data.message;
  loading.value = false;
}
</script>

<template>
  <AccountPageFrame eyebrow="Keamanan akun" title="Mulai lagi dengan aman." description="Buat kata sandi baru untuk menjaga akses ke materi, karya, dan perkembangan belajar Anda.">
    <h2>Buat kata sandi baru</h2>
    <p class="mb-8 mt-2 !text-sm">Gunakan minimal delapan karakter dan jangan memakai kata sandi akun lain.</p>
    <form @submit.prevent="submit">
      <label for="password">Kata sandi baru</label>
      <input id="password" v-model="password" type="password" minlength="8" required autocomplete="new-password" />
      <button :disabled="loading || !token" class="btn-one w-full justify-center">{{ loading ? "Memproses..." : "Simpan kata sandi" }}</button>
    </form>
    <p v-if="error" role="alert" class="mt-5 rounded border-l-2 border-red-600 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700">{{ error }}</p>
    <p v-if="message" role="status" class="mt-5 rounded border-l-2 border-[var(--primary-color)] bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{{ message }}</p>
    <a href="/login" class="mt-6 inline-flex text-sm font-semibold text-[var(--primary-color)] hover:underline">Kembali ke login</a>
  </AccountPageFrame>
</template>
