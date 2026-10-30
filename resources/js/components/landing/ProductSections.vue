<script setup lang="ts">
import { ArrowRight, CircleCheck, FileCheck2, LockKeyhole, MessageSquareText, School, ShieldCheck, Store, UserRound, Users } from "@lucide/vue";
import { vReveal } from "./reveal";

const steps = [
  ["01", "Tentukan ruang lingkup", "Sekolah memilih peserta, kelas, peran pengguna, dan target program."],
  ["02", "Siapkan pembelajaran", "Guru menyusun materi, brief, checklist, rubrik, dan tenggat yang jelas."],
  ["03", "Jalankan proyek", "Murid mengerjakan, menyimpan draf, mengirim bukti, lalu menerima review."],
  ["04", "Baca perkembangannya", "Sekolah melihat progres, beban review, dan kebutuhan pendampingan."],
];

const audiences = [
  { icon: UserRound, title: "Murid", body: "Tahu apa yang harus dikerjakan, apa yang belum lengkap, dan bagaimana memperbaikinya." },
  { icon: Users, title: "Guru", body: "Menyusun pekerjaan, mereview dengan rubrik, dan mendampingi tanpa berpindah-pindah alat." },
  { icon: ShieldCheck, title: "Orang tua", body: "Melihat ringkasan perkembangan anak tanpa mendapat akses ke data murid lain." },
  { icon: School, title: "Instansi", body: "Memantau jalannya program lintas kelas melalui data yang sudah diringkas." },
];

const projectSteps = ["Brief dan pilihan platform", "Profil toko dan produk", "Bukti operasional", "Review dan kesempatan revisi"];

const checklist = [
  { label: "URL toko dapat dibuka", status: "Selesai", done: true },
  { label: "Minimal lima produk aktif", status: "Selesai", done: true },
  { label: "Bukti pengiriman pertama", status: "Belum ada", done: false },
];

const protections = [
  "Hak akses mengikuti peran dan sekolah",
  "Data antarorganisasi tetap terpisah",
  "Dokumen identitas disimpan privat",
  "NIK tidak ditampilkan kepada guru",
  "Persetujuan orang tua tercatat",
  "Akses dan perubahan penting diaudit",
];

const tones = ["item-one", "item-two", "item-three", "item-four", "item-five", "item-six"];
</script>

<template>
  <section id="how-it-works" class="sub-bg section-pad">
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[760px] text-center">
        <h5>Alur program</h5>
        <h2>Teknologi mengikuti cara <span>sekolah <img src="/images/landing/header-shape.png" alt="" /></span> bekerja.</h2>
        <p>Mulai kecil dari satu kelas atau langsung lintas sekolah. Struktur programnya tetap terbaca oleh semua pihak.</p>
      </div>

      <ol class="topic-grid cols-4">
        <li v-for="([number, title, description], index) in steps" :key="number" v-reveal :class="['topic__item stacked', tones[index]]">
          <span class="topic__icon" aria-hidden="true">{{ number }}</span>
          <div class="topic__content">
            <h4>{{ title }}</h4>
            <p>{{ description }}</p>
          </div>
        </li>
      </ol>
    </div>
  </section>

  <section class="section-pad">
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[760px] text-center">
        <h5>Satu program, pandangan berbeda</h5>
        <h2>Setiap orang melihat bagian yang memang menjadi <span>tanggung jawabnya. <img src="/images/landing/header-shape.png" alt="" /></span></h2>
      </div>

      <div class="topic-grid">
        <article v-for="(item, index) in audiences" :key="item.title" v-reveal :class="['topic__item', tones[index + 2]]">
          <div class="topic__icon"><component :is="item.icon" aria-hidden="true" :size="32" :stroke-width="1.7" /></div>
          <div class="topic__content">
            <h4>{{ item.title }}</h4>
            <p>{{ item.body }}</p>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="sub-bg section-pad">
    <div class="container">
      <div class="split-grid">
        <div v-reveal class="split-visual">
          <div class="disc" aria-hidden="true" />
          <img class="testimonial__line sway_Y__animation" src="/images/landing/line-fat.png" alt="" />
          <img class="testimonial__shape slide-effect1" src="/images/landing/circle-sm.png" alt="" />
          <div class="mock-card">
            <div class="mock-card__head">
              <div class="flex items-center gap-3">
                <span class="grid size-11 place-items-center rounded-lg bg-[#2eb97e1f] text-[#2eb97e]"><Store aria-hidden="true" :size="20" /></span>
                <div class="leading-tight">
                  <p class="!text-xs !leading-4 uppercase tracking-wider">Tugas praktik</p>
                  <h3 class="!text-lg !leading-6">Toko produk lokal</h3>
                </div>
              </div>
              <span class="tag">Draf</span>
            </div>
            <div class="mock-card__body">
              <div class="flex items-end justify-between gap-4">
                <div>
                  <p class="!text-xs !leading-4">Kelengkapan</p>
                  <p class="font-spartan mt-1 !text-[32px] !leading-none font-bold text-[#181818]">8 dari 10</p>
                </div>
                <p class="max-w-36 text-right !text-xs !leading-4">Dua bagian perlu dilengkapi sebelum dikirim.</p>
              </div>
              <div class="progress" aria-hidden="true"><span /></div>
              <div class="mt-5">
                <div v-for="row in checklist" :key="row.label" class="check-row">
                  <span class="flex items-center gap-3 font-medium text-[#181818]">
                    <CircleCheck v-if="row.done" aria-hidden="true" class="text-[#2eb97e]" :size="18" />
                    <span v-else aria-hidden="true" class="grid size-[18px] place-items-center rounded-full border border-[#cfcfcf] text-[10px] leading-none">·</span>
                    {{ row.label }}
                  </span>
                  <span :class="row.done ? 'font-semibold text-[#2eb97e]' : 'font-semibold text-[#d97706]'">{{ row.status }}</span>
                </div>
              </div>
              <div class="note">
                <MessageSquareText aria-hidden="true" class="mt-0.5 shrink-0 text-[#2eb97e]" :size="18" />
                <p class="!text-sm !leading-6"><strong class="text-[#181818]">Catatan guru:</strong> foto produk sudah konsisten. Tambahkan kebijakan pengiriman sebelum mengirim versi final.</p>
              </div>
            </div>
          </div>
        </div>

        <div v-reveal class="lg:pl-10">
          <div class="section-header mb-8">
            <h5>Contoh proyek</h5>
            <h2>Membangun <span>toko digital, <img src="/images/landing/header-shape.png" alt="" /></span> langkah demi langkah.</h2>
            <p>Murid tidak diminta sekadar mengirim foto hasil akhir. Guru dapat melihat bagaimana profil toko, produk, operasional, dan bukti kerjanya disusun.</p>
          </div>
          <ol class="step-list">
            <li v-for="(item, index) in projectSteps" :key="item"><span class="n">0{{ index + 1 }}</span>{{ item }}</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section id="security" class="section-pad">
    <div class="container">
      <div class="split-grid !items-start">
        <div v-reveal class="section-header">
          <span class="mb-6 grid size-[70px] place-items-center rounded-lg bg-[#2eb97e26] text-[#2eb97e]"><LockKeyhole aria-hidden="true" :size="30" /></span>
          <h5>Data seperlunya</h5>
          <h2>Aman bukan berarti <span>rumit <img src="/images/landing/header-shape.png" alt="" /></span> bagi pengguna.</h2>
          <p>Kontrol keamanan berjalan di belakang layar, sementara setiap peran tetap mendapat pengalaman yang sederhana.</p>
        </div>
        <ul v-reveal class="grid gap-4 sm:grid-cols-2 lg:mt-4">
          <li v-for="item in protections" :key="item" class="flex items-start gap-3 rounded-[10px] border border-[#e5e5e5] bg-[#fff] p-5 font-medium leading-6 text-[#181818] shadow-[0_4px_25px_#0000000f]">
            <FileCheck2 aria-hidden="true" class="mt-0.5 shrink-0 text-[#2eb97e]" :size="20" />{{ item }}
          </li>
        </ul>
      </div>
    </div>
  </section>

  <section class="trial-area section-pad">
    <div class="container">
      <div class="trial__wrp">
        <div v-reveal>
          <h5>Mulai dari kebutuhan nyata</h5>
          <h2>Ceritakan program yang ingin sekolah Anda jalankan.</h2>
        </div>
        <div v-reveal class="flex flex-wrap items-center gap-6">
          <a href="/#register" class="btn-one-light">Hubungi tim kami <ArrowRight aria-hidden="true" :size="18" /></a>
          <a href="/login" class="text-link">Sudah punya akun? Masuk</a>
        </div>
      </div>
    </div>
  </section>
</template>
