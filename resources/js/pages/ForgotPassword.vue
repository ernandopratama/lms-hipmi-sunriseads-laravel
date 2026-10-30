<script setup lang="ts">
import { ref } from "vue";
import { csrfFetch } from "@/lib/csrf";
import AccountPageFrame from "@/components/AccountPageFrame.vue";

const email = ref("");
const message = ref("");
const loading = ref(false);

async function submit() {
  loading.value = true;
  const response = await csrfFetch("/api/backend/auth/forgot-password", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ email: email.value }),
  });
  const data = await response.json().catch(() => ({}));
  message.value = data.message || "Jika akun tersedia, petunjuk reset akan dikirim.";
  loading.value = false;
}
</script>

<template>
  <AccountPageFrame eyebrow="Pemulihan akun" title="Kembali ke ruang belajar." description="Atur ulang akses akun dengan aman agar Anda dapat melanjutkan kegiatan belajar dan mengajar.">
    <h2>Lupa kata sandi</h2>
    <p class="mb-8 mt-2 !text-sm">Masukkan email akun. Pesan yang sama akan ditampilkan meskipun email tidak terdaftar.</p>
    <form @submit.prevent="submit">
      <label for="email">Alamat email</label>
      <input id="email" v-model="email" type="email" required autocomplete="email" />
      <button :disabled="loading" class="btn-one w-full justify-center">{{ loading ? "Memproses..." : "Kirim petunjuk reset" }}</button>
    </form>
    <p v-if="message" role="status" class="mt-5 rounded border-l-2 border-[var(--primary-color)] bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-800">{{ message }}</p>
    <a href="/login" class="mt-6 inline-flex text-sm font-semibold text-[var(--primary-color)] hover:underline">Kembali ke login</a>
  </AccountPageFrame>
</template>
