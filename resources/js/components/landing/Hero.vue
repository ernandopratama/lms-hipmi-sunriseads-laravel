<script setup lang="ts">
import { ArrowDownRight, ArrowRight, ArrowUpRight } from "@lucide/vue";

const programNotes = [
  { number: "01", title: "Belajar", description: "Materi dan tugas disusun dalam alur yang jelas." },
  { number: "02", title: "Praktik", description: "Murid membangun karya digital dengan pendampingan guru." },
  { number: "03", title: "Bertumbuh", description: "Perkembangan terlihat oleh pihak yang berwenang." },
];
</script>

<template>
  <section class="banner-area">
    <div class="banner__shape1"><img src="/images/landing/banner-shape-left.png" alt="" /></div>
    <div class="banner__shape2"><img class="animation__arryUpDown" src="/images/landing/banner-earth.png" alt="" /></div>
    <div class="banner__shape3"><img class="slide-effect1" src="/images/landing/circle-sm.png" alt="" /></div>
    <div class="banner__shape4"><img class="sway__animationX" src="/images/landing/squiggle.png" alt="" /></div>
    <div class="banner__shape5"><img src="/images/landing/shape-right.png" alt="" /></div>

    <div class="container">
      <div class="banner__content">
        <h5 class="primary-color mb-[10px] uppercase">Program praktik digital untuk sekolah</h5>
        <h1>Belajar bisnis <span class="primary-color">digital,</span> <span class="whitespace-nowrap">benar-benar</span> dipraktikkan.</h1>
        <p class="mt-5">
          Sunrise Academy membantu guru menjalankan pembelajaran berbasis proyek, dari materi dan praktik toko hingga review serta laporan perkembangan murid.
        </p>
        <div class="mt-[50px] flex flex-wrap items-center gap-4">
          <a href="/#register" class="btn-one">Ceritakan kebutuhan sekolah <ArrowRight aria-hidden="true" :size="18" /></a>
          <a href="/#how-it-works" class="btn-outline">Pelajari alurnya <ArrowDownRight aria-hidden="true" :size="18" /></a>
        </div>

        <!-- Below 1200px the hero art is hidden (as in the template); photo and notes stack under the copy instead. -->
        <div class="banner__notes">
          <div class="relative overflow-hidden rounded-2xl border-[6px] border-white shadow-[0_4px_25px_#0000000f]">
            <img src="/images/activities/sesi-digital-marketing.webp" alt="Pemateri dan murid belajar bisnis digital bersama di aula sekolah" class="aspect-[16/10] w-full object-cover" />
            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 to-transparent p-5 text-white">
              <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/80">Cerita dari sekolah</p>
              <p class="font-spartan text-xl font-bold leading-tight">Pengetahuan tumbuh saat dipraktikkan.</p>
            </div>
          </div>
          <div v-for="note in programNotes" :key="note.number" class="banner__info">
            <span class="num">{{ note.number }}</span>
            <div><h5>{{ note.title }}</h5><p>{{ note.description }}</p></div>
          </div>
        </div>
      </div>
    </div>

    <div class="banner__hero">
      <div v-for="(note, index) in programNotes" :key="note.number" :class="['banner__info', `info${index + 1}`]">
        <span class="num">{{ note.number }}</span>
        <div><h5>{{ note.title }}</h5><p>{{ note.description }}</p></div>
      </div>
      <div class="hero-photo">
        <img
          src="/images/activities/sesi-digital-marketing.webp"
          alt="Pemateri dan murid belajar bisnis digital bersama di aula sekolah"
          sizes="470px"
          loading="eager"
        />
        <div class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-4 bg-gradient-to-t from-black/75 to-transparent p-7 pt-24 text-white">
          <div>
            <p class="text-[11px] font-semibold uppercase leading-5 tracking-[0.16em] text-white/80">Cerita dari sekolah</p>
            <p class="mt-1 max-w-[16rem] font-spartan text-2xl font-bold leading-tight">Pengetahuan tumbuh saat dipraktikkan.</p>
          </div>
          <a href="/#galeri" aria-label="Lihat galeri kegiatan" class="grid size-11 shrink-0 place-items-center rounded-full border border-white/70 text-white hover:border-[#2eb97e] hover:bg-[#2eb97e]">
            <ArrowUpRight aria-hidden="true" :size="20" />
          </a>
        </div>
      </div>
      <img class="hero-shape1" src="/images/landing/banner-hero-line.png" alt="" />
      <img class="hero-shape2" src="/images/landing/banner-hero-shape.png" alt="" />
      <img class="hero-shape3" src="/images/landing/ring.png" alt="" />
      <img class="hero-shape4 sway_Y__animationY" src="/images/landing/dots.png" alt="" />
    </div>
  </section>
</template>
