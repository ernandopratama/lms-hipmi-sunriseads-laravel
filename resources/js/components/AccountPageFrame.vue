<script setup lang="ts">
import { ArrowLeft, ShieldCheck } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";

defineProps<{ eyebrow: string; title: string; description: string }>();
</script>

<template>
  <main class="public-site lp min-h-screen bg-[var(--sub-bg)]">
    <header>
      <div class="mx-auto flex h-20 max-w-[1440px] items-center justify-between px-5 sm:h-24 sm:px-8 lg:px-12">
        <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-lg sm:text-xl" /></a>
        <a href="/login" aria-label="Kembali ke login" class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 font-spartan text-sm font-semibold text-[var(--heading-color)] hover:text-[var(--primary-color)]"><ArrowLeft aria-hidden="true" :size="16" /> <span class="hidden sm:inline">Kembali ke login</span></a>
      </div>
    </header>
    <div class="mx-auto grid min-h-[calc(100vh-6rem)] max-w-[1440px] lg:grid-cols-[.95fr_1.05fr]">
      <section class="order-2 flex flex-col justify-between px-5 py-14 sm:px-8 sm:py-20 lg:order-1 lg:px-12 lg:py-24 xl:pr-20">
        <div>
          <h5 v-reveal class="primary-color mb-[10px] uppercase" style="transition-delay: 150ms">{{ eyebrow }}</h5>
          <h1 v-reveal class="max-w-2xl" style="transition-delay: 250ms">{{ title }}</h1>
          <p v-reveal class="mt-5 max-w-xl" style="transition-delay: 350ms">{{ description }}</p>
        </div>
        <p v-reveal class="mt-12 flex items-center gap-2 !text-xs font-medium" style="transition-delay: 450ms"><ShieldCheck aria-hidden="true" :size="16" class="text-[var(--primary-color)]" /> Akses dan data mengikuti peran pengguna.</p>
      </section>
      <section class="order-1 flex items-center px-5 py-6 sm:px-8 sm:py-10 lg:order-2 lg:px-12 lg:py-20">
        <div v-reveal class="contact__item mx-auto w-full max-w-[540px]"><slot /></div>
      </section>
    </div>
  </main>
</template>
