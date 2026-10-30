<script setup lang="ts">
import { ArrowUpRight, ChevronsRight, Mail } from "@lucide/vue";
import BrandLogo from "@/components/BrandLogo.vue";

const navigation = [
  ["Tentang Program", "/tentang"], ["Fitur", "/fitur"], ["Tim", "/#team"], ["Galeri Kegiatan", "/#galeri"], ["Paket", "/paket"], ["Cara Kerja", "/cara-kerja"], ["Untuk Sekolah", "/untuk-sekolah"], ["Keamanan", "/keamanan"], ["FAQ", "/faq"],
];

const legal = [
  ["Kebijakan Privasi", "/kebijakan-privasi"], ["Syarat & Ketentuan", "/syarat-ketentuan"], ["Kebijakan Data Anak", "/kebijakan-data-anak"], ["Pusat Bantuan", "/kontak"],
];

const year = new Date().getFullYear();
</script>

<template>
  <footer class="footer-area sub-bg">
    <div class="footer__shape-left"><img src="/images/landing/footer-shape-left.png" alt="" /></div>
    <div class="footer__shape-right sway_Y__animationY"><img src="/images/landing/footer-shape-right.png" alt="" /></div>
    <div class="container">
      <div class="footer__wrp">
        <div class="footer__item footer-about">
          <a href="/" aria-label="Sunrise Academy — Beranda" class="mb-[30px] inline-block"><BrandLogo class="text-[22px]" /></a>
          <h3 class="!text-xl">Belajar. Berkarya. Bertumbuh.</h3>
          <p class="mt-3">Platform pembelajaran digital dan kewirausahaan berbasis praktik untuk sekolah Indonesia.</p>
        </div>
        <div class="footer__item item-sm">
          <h3 class="footer-title">Jelajahi</h3>
          <ul><li v-for="[label, href] in navigation" :key="href"><a :href="href"><ChevronsRight aria-hidden="true" :size="16" />{{ label }}</a></li></ul>
        </div>
        <div class="footer__item item-sm">
          <h3 class="footer-title">Informasi</h3>
          <ul><li v-for="[label, href] in legal" :key="href"><a :href="href"><ChevronsRight aria-hidden="true" :size="16" />{{ label }}</a></li></ul>
        </div>
        <div class="footer__item footer-subscribe">
          <h3 class="footer-title">Mari terhubung</h3>
          <p class="mb-5">Punya pertanyaan tentang kesiapan sekolah atau alur program?</p>
          <a href="/#register" class="footer-cta"><Mail aria-hidden="true" :size="16" /> Ceritakan kebutuhan</a>
          <a href="/login" class="footer-cta ghost">Masuk ke platform <ArrowUpRight aria-hidden="true" :size="16" /></a>
        </div>
      </div>
    </div>
    <div class="footer__copyright">
      <div class="container">
        <div class="row-between">
          <p>© {{ year }} <a href="/" class="primary-color">Sunrise Academy</a>. Hak cipta dilindungi.</p>
          <p>Pendidikan inklusif adaptif untuk wirausaha digital.</p>
        </div>
      </div>
    </div>
  </footer>
</template>
