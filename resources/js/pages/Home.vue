<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { ArrowUp } from "@lucide/vue";
import "@/components/landing/landing.css";
import Header from "@/components/landing/Header.vue";
import Hero from "@/components/landing/Hero.vue";
import About from "@/components/landing/About.vue";
import Features from "@/components/landing/Features.vue";
import LeadForm from "@/components/landing/LeadForm.vue";
import Faq from "@/components/landing/Faq.vue";
import Footer from "@/components/landing/Footer.vue";
import ProductSections from "@/components/landing/ProductSections.vue";
import PublicStatistics from "@/components/landing/PublicStatistics.vue";
import DynamicPublicContent from "@/components/landing/DynamicPublicContent.vue";
import AnalyticsConsent from "@/components/landing/AnalyticsConsent.vue";
import ActivityGallery from "@/components/landing/ActivityGallery.vue";
import Team from "@/components/landing/Team.vue";

const structuredData = JSON.stringify({
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  name: "Sunrise Academy",
  applicationCategory: "EducationalApplication",
  operatingSystem: "Web",
  inLanguage: "id-ID",
  description: "Platform pembelajaran digital, praktik toko, dan affiliate multi-sekolah.",
}).replace(/</g, "\\u003c");

const scrolled = ref(false);
const scrollProgress = ref(0);

function onScroll() {
  const max = document.documentElement.scrollHeight - window.innerHeight;
  scrolled.value = window.scrollY > 50;
  scrollProgress.value = max > 0 ? Math.min(window.scrollY / max, 1) : 0;
}

onMounted(() => {
  onScroll();
  window.addEventListener("scroll", onScroll, { passive: true });
});

onUnmounted(() => window.removeEventListener("scroll", onScroll));

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: "smooth" });
}

const circumference = 2 * Math.PI * 24;
</script>

<template>
  <div class="public-site lp min-h-screen">
    <!-- Vue templates drop literal <script> tags, so the JSON-LD block is rendered through <component>. -->
    <component is="script" type="application/ld+json" v-html="structuredData" />
    <a href="#main-content" class="sr-only z-[1100] rounded-lg bg-white px-4 py-3 font-bold text-[#2eb97e] focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Lewati ke konten utama</a>
    <Header :scrolled="scrolled" />
    <main id="main-content">
      <Hero />
      <About />
      <ActivityGallery />
      <Features />
      <ProductSections />
      <Team />
      <PublicStatistics />
      <DynamicPublicContent />
      <LeadForm />
      <Faq />
    </main>
    <Footer />
    <button type="button" aria-label="Kembali ke atas" :class="['scroll-up', scrolled && 'active-scroll']" @click="scrollToTop">
      <svg class="scroll-circle" width="50" height="50" viewBox="0 0 50 50" aria-hidden="true">
        <circle cx="25" cy="25" r="24" :stroke-dasharray="circumference" :stroke-dashoffset="circumference * (1 - scrollProgress)" />
      </svg>
      <ArrowUp aria-hidden="true" :size="18" :stroke-width="2.5" />
    </button>
    <AnalyticsConsent />
  </div>
</template>
