<script setup lang="ts">
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";

defineProps<{ text: string; description?: string }>();
</script>

<template>
  <main class="public-site lp grid min-h-screen place-items-center bg-[var(--sub-bg)] px-5">
    <section v-reveal class="contact__item w-full max-w-lg text-center">
      <BrandLogo class="mx-auto text-xl" />
      <h1 class="mt-8 !text-[28px] !leading-[38px]">{{ text }}</h1>
      <p v-if="description" class="mt-3 !text-sm">{{ description }}</p>
      <slot />
    </section>
  </main>
</template>
