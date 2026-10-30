<script setup lang="ts">
import { BookOpen, ChartNoAxesCombined, ClipboardCheck, FileText, ShieldCheck, Users } from "@lucide/vue";
import { vReveal } from "./reveal";

const capabilities = [
  { icon: BookOpen, title: "Ruang belajar", description: "Modul, materi, tugas, tenggat, dan progres berada dalam urutan yang mudah diikuti." },
  { icon: ClipboardCheck, title: "Pekerjaan murid", description: "Toko digital dan konten affiliate dinilai sebagai proses, bukan satu tangkapan layar." },
  { icon: Users, title: "Review guru", description: "Rubrik, komentar, revisi, dan nilai memiliki riwayat yang jelas untuk kedua pihak." },
  { icon: ChartNoAxesCombined, title: "Pandangan instansi", description: "Sekolah melihat partisipasi, progres kelas, dan kebutuhan pendampingan secara ringkas." },
  { icon: FileText, title: "Laporan yang siap dipakai", description: "Data dapat disaring berdasarkan sekolah, kelas, periode, dan kewenangan pengguna." },
  { icon: ShieldCheck, title: "Akses sesuai peran", description: "Murid, guru, orang tua, dan pengelola hanya melihat informasi yang memang dibutuhkan." },
];

const tones = ["item-one", "item-two", "item-three", "item-four", "item-five", "item-six"];
</script>

<template>
  <section id="features" class="topic-area section-pad">
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[820px] text-center">
        <h5>Yang dikelola</h5>
        <h2>Cukup lengkap untuk program sekolah. Tetap <span>sederhana <img src="/images/landing/header-shape.png" alt="" /></span> untuk dipakai setiap hari.</h2>
        <p>Setiap bagian punya alasan untuk ada. Tidak ada fitur yang sekadar memenuhi dashboard.</p>
      </div>

      <div class="topic-grid cols-3">
        <article v-for="(item, index) in capabilities" :key="item.title" v-reveal :class="['topic__item', tones[index]]">
          <div class="topic__icon"><component :is="item.icon" aria-hidden="true" :size="32" :stroke-width="1.7" /></div>
          <div class="topic__content">
            <h4>{{ item.title }}</h4>
            <p>{{ item.description }}</p>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>
