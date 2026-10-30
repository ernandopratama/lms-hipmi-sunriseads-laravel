<script setup lang="ts">
import { ref } from "vue";
import { ArrowRight, Minus, Plus } from "@lucide/vue";
import { vReveal } from "./reveal";

const faqs = [
  ["Apa fungsi utama Sunrise Academy?", "Platform ini menyatukan pembelajaran, praktik toko digital, submission konten affiliate, review, penilaian, dan monitoring perkembangan siswa dalam satu alur."],
  ["Siapa saja yang dapat menggunakan platform?", "Siswa, guru, orang tua, admin sekolah, kepala sekolah, reviewer, dan pengelola program mendapat tampilan serta akses sesuai kewenangannya."],
  ["Apakah dapat digunakan melalui smartphone?", "Ya. Antarmuka dirancang mobile-first agar materi, draf, checklist, submission, feedback, dan monitoring tetap nyaman digunakan di layar kecil."],
  ["Marketplace apa saja yang dapat didukung?", "Program dapat mengonfigurasi platform seperti Shopee, TikTok Shop, Tokopedia, Lazada, Instagram Shop, WhatsApp Business, atau website toko sendiri."],
  ["Apakah siswa harus memberikan password toko?", "Tidak. Password, PIN, OTP, cookie, token, dan kredensial marketplace tidak boleh diminta atau disimpan oleh platform."],
  ["Bagaimana sekolah memantau perkembangan siswa?", "Sekolah melihat data agregat seperti progres pembelajaran, status submission, antrean review, dan kebutuhan pendampingan sesuai ruang lingkup izinnya."],
  ["Apa yang dapat dilihat orang tua?", "Orang tua hanya melihat ringkasan progres, tugas, feedback yang diizinkan, dan nilai anak yang telah terhubung melalui relasi wali yang sah."],
  ["Bagaimana data siswa dilindungi?", "Akses berbasis peran, isolasi antar sekolah, enkripsi data sensitif, penyimpanan file privat, audit aktivitas, consent, dan redaksi export diterapkan secara berlapis."],
  ["Apakah guru dapat melihat NIK atau dokumen identitas?", "Tidak secara default. Verifikasi identitas dipisahkan dari peran akademik dan memerlukan izin sensitif tersendiri."],
  ["Bagaimana sekolah mendaftar?", "Isi formulir pendaftaran di halaman ini. Data masuk sebagai lead untuk diverifikasi; platform tidak otomatis membuat tenant sebelum proses peninjauan."],
  ["Apakah tugas dapat disesuaikan?", "Ya. Guru dapat mengatur brief, platform, checklist, bukti, target, rubrik, deadline, dan jumlah percobaan sesuai program."],
  ["Apakah laporan bisa diekspor?", "Laporan dapat disaring dan diekspor sesuai peran, tenant, serta kebijakan redaksi data sensitif."],
  ["Apakah siswa dapat memperbaiki tugas?", "Ya. Guru dapat meminta revisi dan setiap pengiriman ulang disimpan sebagai versi baru agar riwayat proses tetap utuh."],
];

const openIndex = ref<number | null>(0);
</script>

<template>
  <section id="faq" class="faq-two-area section-pad">
    <div class="container">
      <div class="faq-grid">
        <div v-reveal class="faq__aside">
          <div class="section-header">
            <h5>Pertanyaan umum</h5>
            <h2>Hal yang biasanya ditanyakan sebelum <span>sekolah <img src="/images/landing/header-shape.png" alt="" /></span> memulai.</h2>
            <p>Belum menemukan jawabannya? Gunakan formulir di atas dan tulis pertanyaan pada bagian pesan.</p>
          </div>
          <a href="/#register" class="btn-one mt-[40px]">Ceritakan kebutuhan <ArrowRight aria-hidden="true" :size="18" /></a>
        </div>
        <div v-reveal class="accordion">
          <div v-for="([question, answer], index) in faqs" :key="question" class="accordion-item">
            <h3 class="!m-0">
              <button type="button" class="accordion-button" @click="openIndex = openIndex === index ? null : index" :aria-expanded="openIndex === index" :aria-controls="`faq-answer-${index}`">
                {{ question }}
                <span class="sign" aria-hidden="true"><Minus v-if="openIndex === index" :size="16" :stroke-width="3" /><Plus v-else :size="16" :stroke-width="3" /></span>
              </button>
            </h3>
            <div v-if="openIndex === index" :id="`faq-answer-${index}`" class="accordion-body"><p>{{ answer }}</p></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
