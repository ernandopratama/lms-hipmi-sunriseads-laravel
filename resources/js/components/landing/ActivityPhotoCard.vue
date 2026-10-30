<script setup lang="ts">
import { Expand } from "@lucide/vue";

defineProps<{ photo: { file: string; title: string; alt: string }; index: number; featured?: boolean }>();
defineEmits<{ open: [index: number] }>();
</script>

<template>
  <button
    type="button"
    @click="$emit('open', index)"
    :aria-label="`Perbesar foto: ${photo.title}`"
    aria-haspopup="dialog"
    class="courses__item"
  >
    <div class="courses__image">
      <div class="photo">
        <img
          :src="`/images/activities/${photo.file}.webp`"
          :alt="photo.alt"
          sizes="(max-width: 768px) 85vw, (max-width: 1200px) 45vw, 373px"
          loading="lazy"
        />
      </div>
      <div class="courses-price"><span aria-hidden="true">{{ String(index + 1).padStart(2, "0") }}</span></div>
    </div>
    <div class="courses__content">
      <h3>{{ photo.title }}</h3>
      <div class="courses__meta">
        <span>{{ featured ? "Momen kebersamaan" : "Galeri kegiatan" }}</span>
        <span class="zoom"><Expand aria-hidden="true" :size="15" /> Lihat foto</span>
      </div>
    </div>
  </button>
</template>
