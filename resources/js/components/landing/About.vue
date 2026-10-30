<script setup lang="ts">
import { ArrowRight } from "@lucide/vue";
import { vReveal } from "./reveal";

const phases = [
  { number: "01", title: "Pahami dasarnya", description: "Materi singkat dan contoh yang dekat dengan keseharian murid. Tidak perlu menebak harus mulai dari mana." },
  { number: "02", title: "Kerjakan proyeknya", description: "Murid membuat toko, produk, atau konten. Draf dan bukti kerja tersimpan sepanjang proses." },
  { number: "03", title: "Perbaiki dengan arahan", description: "Guru memberi catatan berbasis rubrik. Murid tahu apa yang sudah baik dan apa yang perlu diulang." },
];
</script>

<template>
  <section id="about" class="about-area section-pad">
    <div class="container">
      <div class="about__grid">
        <div v-reveal class="about__left-part">
          <div class="about__image">
            <img class="about-dots sway__animation" src="/images/landing/dots.png" alt="" />
            <img class="about-circle" src="/images/landing/ring.png" alt="" />
            <img class="main-image" src="/images/activities/turen-kelas-inspirasi.webp" alt="Suasana kelas dengan pemateri dan layar bertuliskan Dari Hobi Digital Jadi Skill Masa Depan" loading="lazy" />
            <img class="sm-image" src="/images/activities/turen-peserta-menyimak.webp" alt="Para murid menyimak sesi dengan buku catatan di meja masing-masing" loading="lazy" />
            <div class="count-info">
              <h5>3</h5>
              <span>Tiga tahap yang terus berulang</span>
            </div>
          </div>
        </div>

        <div v-reveal class="about__right-wrp">
          <div class="section-header">
            <h5>Pendekatan kami</h5>
            <h2>
              Keterampilan tumbuh dari pekerjaan yang
              <span>selesai, <img src="/images/landing/header-shape.png" alt="" /></span>
              bukan materi yang lewat.
            </h2>
            <p>
              Program digital sering berhenti di webinar dan sertifikat. Sunrise Academy mengubahnya menjadi rutinitas belajar: ada brief, hasil kerja, review, revisi, dan perkembangan yang bisa dibaca bersama.
            </p>
          </div>
          <div class="about__right-part">
            <article v-for="(phase, index) in phases" :key="phase.number" class="about__info">
              <div :class="['icon', `bg__${index + 1}`]">{{ phase.number }}</div>
              <div>
                <h4>{{ phase.title }}</h4>
                <p>{{ phase.description }}</p>
              </div>
            </article>
          </div>
          <a href="/#how-it-works" class="btn-one mt-[50px]">Pelajari alurnya <ArrowRight aria-hidden="true" :size="18" /></a>
        </div>
      </div>
    </div>
  </section>
</template>
