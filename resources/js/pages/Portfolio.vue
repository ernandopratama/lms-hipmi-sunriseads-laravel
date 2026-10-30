<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { ArrowLeft, BriefcaseBusiness, Loader2 } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";


type Portfolio = { title: string; description?: string; sourceType: string; studentName: string; school: string; city?: string; publishedAt?: string };

const route = useRoute();
const item = ref<Portfolio | null | undefined>(undefined);

onMounted(() => {
  fetch(`/api/backend/public/portfolio/${encodeURIComponent(String(route.params.slug))}`)
    .then((response) => response.ok ? response.json() : null)
    .then((data) => { item.value = data; })
    .catch(() => { item.value = null; });
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
      <p v-if="item === undefined" role="status" class="flex items-center gap-2 font-medium text-[var(--primary-color)]"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat karya...</p>
      <article v-else v-reveal class="contact__item w-full max-w-2xl">
        <span class="grid size-16 place-items-center rounded-2xl bg-[var(--primary-color)]/10 text-[var(--primary-color)]"><BriefcaseBusiness aria-hidden="true" :size="32" /></span>
        <template v-if="item">
          <h5 class="primary-color mt-6 uppercase">{{ item.sourceType }}</h5>
          <h1 class="mt-1 !text-[36px] !leading-[46px]">{{ item.title }}</h1>
          <p class="mt-4">{{ item.description || "Karya terpilih dalam portofolio siswa." }}</p>
          <div class="mt-7 rounded-xl bg-[var(--sub-bg)] p-5 !text-sm">
            <p class="font-spartan !text-base font-semibold text-[var(--heading-color)]">{{ item.studentName }}</p>
            <p class="!text-sm">{{ item.school }}{{ item.city ? ` · ${item.city}` : "" }}</p>
          </div>
        </template>
        <template v-else>
          <h1 class="mt-6 !text-[30px] !leading-[40px]">Karya tidak tersedia</h1>
          <p class="mt-3">Karya mungkin privat atau persetujuan publikasinya sudah tidak aktif.</p>
        </template>
        <a href="/" class="btn-one mt-8">Kembali ke beranda</a>
      </article>
    </div>
  </main>
</template>
