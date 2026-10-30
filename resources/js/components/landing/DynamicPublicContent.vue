<script setup lang="ts">
import { onMounted, ref } from "vue";
import { Tag } from "@lucide/vue";
import { vReveal } from "./reveal";

type Content = { slug: string; type: string; title: string; excerpt?: string; body?: { text?: string }; imageUrl?: string };

const items = ref<Content[]>([]);
const labels: Record<string, string> = { TESTIMONIAL: "Cerita pengguna", CASE_STUDY: "Studi kasus", PARTNER: "Mitra", PAGE: "Informasi", FAQ: "Pertanyaan umum" };

onMounted(() => {
  fetch("/api/backend/public/content").then((response) => response.ok ? response.json() : []).then((data) => { items.value = data; }).catch(() => undefined);
});
</script>

<template>
  <section v-if="items.length > 0" class="blog-area sub-bg section-pad" aria-labelledby="content-heading">
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[760px] text-center">
        <h5>Catatan dari program</h5>
        <h2 id="content-heading">Cerita, pembelajaran, dan <span>kabar terbaru. <img src="/images/landing/header-shape.png" alt="" /></span></h2>
      </div>
      <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="item in items" :key="`${item.type}-${item.slug}`" v-reveal class="blog__item">
          <div v-if="item.imageUrl" class="blog__image mb-6 overflow-hidden rounded-lg"><img :src="item.imageUrl" alt="" loading="lazy" class="aspect-[16/10] w-full object-cover" /></div>
          <ul class="blog-info !mt-0">
            <li><Tag aria-hidden="true" :size="16" />{{ labels[item.type] || item.type }}</li>
          </ul>
          <h3>{{ item.title }}</h3>
          <p class="mt-3">{{ item.excerpt || item.body?.text }}</p>
        </article>
      </div>
    </div>
  </section>
</template>
