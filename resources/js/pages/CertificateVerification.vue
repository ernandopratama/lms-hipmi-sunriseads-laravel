<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { ArrowLeft, BadgeCheck, CircleX, Loader2 } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";


type Result = { valid: boolean; status: string; verificationCode?: string; title?: string; school?: string; issuedAt?: string; studentName?: string; city?: string };

const route = useRoute();
const result = ref<Result | null>(null);

onMounted(() => {
  fetch(`/api/backend/public/certificates/${encodeURIComponent(String(route.params.code))}`)
    .then((response) => response.json())
    .then((data) => { result.value = data; })
    .catch(() => { result.value = { valid: false, status: "ERROR" }; });
});
</script>

<template>
  <main class="public-site lp flex min-h-screen flex-col bg-[var(--sub-bg)]">
    <header>
      <div class="mx-auto flex h-20 max-w-[1440px] items-center justify-between px-5 sm:h-24 sm:px-8 lg:px-12">
        <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-lg sm:text-xl" /></a>
        <a href="/" aria-label="Kembali ke beranda" class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 font-spartan text-sm font-semibold text-[var(--heading-color)] hover:text-[var(--primary-color)]"><ArrowLeft aria-hidden="true" :size="16" /> <span class="hidden sm:inline">Kembali ke beranda</span></a>
      </div>
    </header>
    <div class="flex flex-1 items-center justify-center px-5 pb-16 pt-4 sm:px-8">
      <p v-if="!result" role="status" class="flex items-center gap-2 font-medium text-[var(--primary-color)]"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memeriksa sertifikat...</p>
      <article v-else v-reveal class="contact__item w-full max-w-xl text-center">
        <span :class="['mx-auto grid size-20 place-items-center rounded-full', result.valid ? 'bg-[var(--primary-color)]/10 text-[var(--primary-color)]' : 'bg-red-50 text-red-600']">
          <BadgeCheck v-if="result.valid" aria-hidden="true" :size="44" />
          <CircleX v-else aria-hidden="true" :size="44" />
        </span>
        <h5 class="primary-color mt-6 uppercase">Verifikasi publik</h5>
        <h1 class="mt-1 !text-[30px] !leading-[40px]">{{ result.valid ? "Sertifikat valid" : "Sertifikat tidak valid" }}</h1>
        <dl v-if="result.valid" class="mt-6 divide-y divide-[var(--border)] rounded-xl bg-[var(--sub-bg)] px-5 text-left text-sm">
          <div class="flex justify-between gap-4 py-3"><dt>Judul</dt><dd class="text-right font-medium text-[var(--heading-color)]">{{ result.title }}</dd></div>
          <div v-if="result.studentName" class="flex justify-between gap-4 py-3"><dt>Penerima</dt><dd class="text-right font-medium text-[var(--heading-color)]">{{ result.studentName }}</dd></div>
          <div class="flex justify-between gap-4 py-3"><dt>Sekolah</dt><dd class="text-right font-medium text-[var(--heading-color)]">{{ result.school }}{{ result.city ? `, ${result.city}` : "" }}</dd></div>
          <div class="flex justify-between gap-4 py-3"><dt>Terbit</dt><dd class="text-right font-medium text-[var(--heading-color)]">{{ result.issuedAt ? new Date(result.issuedAt).toLocaleDateString("id-ID") : "-" }}</dd></div>
          <div class="flex justify-between gap-4 py-3"><dt>Kode</dt><dd class="text-right font-medium text-[var(--heading-color)]">{{ result.verificationCode }}</dd></div>
        </dl>
        <p class="mt-5 !text-xs !leading-5">Identitas penerima hanya ditampilkan bila pemilik memiliki persetujuan publikasi aktif.</p>
        <a href="/" class="btn-one mt-7">Kembali ke beranda</a>
      </article>
    </div>
  </main>
</template>
