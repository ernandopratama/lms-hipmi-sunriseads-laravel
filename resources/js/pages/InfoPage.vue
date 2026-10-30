<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import { ArrowRight, CheckCircle2 } from "@lucide/vue";
import "@/components/landing/landing.css";
import Header from "@/components/landing/Header.vue";
import Footer from "@/components/landing/Footer.vue";
import LeadForm from "@/components/landing/LeadForm.vue";
import { vReveal } from "@/components/landing/reveal";
import { infoPages } from "./info-pages";

// Laravel only serves the slugs listed in infoPages (routes/web.php), so the lookup always succeeds.
const route = useRoute();
const slug = computed(() => String(route.params.slug));
const page = computed(() => infoPages[slug.value]);
const showForm = computed(() => slug.value === "daftar-sekolah" || slug.value === "kontak");
</script>

<template>
  <div class="public-site lp min-h-screen bg-white">
    <Header />
    <main id="main-content">
      <section class="px-5 pb-20 pt-20 sm:px-8 sm:pb-28 sm:pt-28 lg:px-12">
        <div class="mx-auto grid max-w-[1440px] gap-8 lg:grid-cols-[1.18fr_.82fr] lg:items-end lg:gap-20">
          <div v-reveal>
            <h5 class="primary-color mb-[10px] uppercase">{{ page.eyebrow }}</h5>
            <h1 class="max-w-5xl">{{ page.title }}</h1>
          </div>
          <p v-reveal class="max-w-xl sm:!text-lg sm:!leading-8" style="transition-delay: 150ms">{{ page.description }}</p>
        </div>
      </section>
      <section class="px-5 pb-20 sm:px-8 sm:pb-28 lg:px-12">
        <div class="mx-auto grid max-w-[1440px] gap-8 border-t border-[var(--border)] pt-10 md:grid-cols-3 md:gap-12">
          <article v-for="(section, index) in page.sections" :key="section.title" v-reveal :style="{ transitionDelay: `${200 + index * 150}ms` }" class="border-b border-[var(--border)] pb-8 md:border-b-0">
            <p class="font-spartan font-bold text-[var(--primary-color)]">{{ String(index + 1).padStart(2, "0") }}</p>
            <CheckCircle2 aria-hidden="true" class="mt-7 text-[var(--primary-color)]" :size="25" />
            <h3 class="mt-5 max-w-sm">{{ section.title }}</h3>
            <p class="mt-4 max-w-md !text-sm !leading-7">{{ section.body }}</p>
          </article>
        </div>
        <div v-if="!showForm" v-reveal class="mx-auto mt-12 max-w-[1440px]" style="transition-delay: 600ms"><a href="/#register" class="btn-one">Daftarkan sekolah <ArrowRight aria-hidden="true" :size="17" /></a></div>
      </section>
      <LeadForm v-if="showForm" />
    </main>
    <Footer />
  </div>
</template>
