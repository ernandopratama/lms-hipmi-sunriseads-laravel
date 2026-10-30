<script setup lang="ts">
import { WifiOff } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";

</script>

<template>
  <main class="public-site lp flex min-h-screen flex-col bg-[var(--sub-bg)]">
    <header class="mx-auto flex h-20 w-full max-w-[1440px] items-center px-5 sm:h-24 sm:px-8 lg:px-12">
      <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-lg sm:text-xl" /></a>
    </header>
    <div class="flex flex-1 items-center justify-center px-5 pb-16 pt-4 sm:px-8">
      <article v-reveal class="contact__item w-full max-w-md text-center">
        <span class="mx-auto grid size-20 place-items-center rounded-full bg-[var(--primary-color)]/10 text-[var(--primary-color)]"><WifiOff aria-hidden="true" :size="38" /></span>
        <h1 class="mt-6 !text-[30px] !leading-[40px]">Anda sedang offline</h1>
        <p class="mt-3 !text-sm !leading-6">Halaman publik yang pernah dibuka tetap tersedia. Data akun, dashboard, dan API tidak pernah disimpan di cache publik. Draf lokal akan disinkronkan saat koneksi kembali.</p>
        <a href="/" class="btn-one mt-7">Kembali ke beranda</a>
      </article>
    </div>
  </main>
</template>
