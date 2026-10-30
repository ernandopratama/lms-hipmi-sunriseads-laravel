<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from "vue";
import { ArrowRight, BookOpen, Images, Info, LayoutDashboard, LayoutGrid, LifeBuoy, Mail, Package, Route, School, ShieldCheck, Users, X } from "@lucide/vue";
import BrandLogo from "@/components/BrandLogo.vue";

defineProps<{ scrolled?: boolean }>();

const navLinks = [
  { href: "/#about", label: "Tentang program", icon: Info },
  { href: "/#galeri", label: "Cerita kegiatan", icon: Images },
  { href: "/#features", label: "Produk & fitur", icon: LayoutGrid },
  { href: "/#how-it-works", label: "Alur belajar", icon: Route },
  { href: "/#security", label: "Keamanan", icon: ShieldCheck },
  { href: "/#team", label: "Tim kami", icon: Users },
  { href: "/paket", label: "Paket belajar", icon: Package },
];

type SessionProfile = { email?: string; roles?: string[]; schoolId?: string | null };

const isOpen = ref(false);
const session = ref<SessionProfile | null>(null);
const sessionLoading = ref(true);
let active = true;

async function loadSession() {
  try {
    const response = await fetch("/api/session", { cache: "no-store" });
    const data = response.ok ? await response.json() as SessionProfile : null;
    if (active) session.value = data;
  } catch {
    if (active) session.value = null;
  } finally {
    if (active) sessionLoading.value = false;
  }
}

function refresh() {
  if (document.visibilityState === "visible") void loadSession();
}

function closeOnEscape(event: KeyboardEvent) {
  if (event.key === "Escape") isOpen.value = false;
}

watch(isOpen, (open) => {
  document.body.style.overflow = open ? "hidden" : "";
  if (open) window.addEventListener("keydown", closeOnEscape);
  else window.removeEventListener("keydown", closeOnEscape);
});

onMounted(() => {
  void loadSession();
  window.addEventListener("focus", refresh);
  document.addEventListener("visibilitychange", refresh);
});

onUnmounted(() => {
  active = false;
  document.body.style.overflow = "";
  window.removeEventListener("focus", refresh);
  document.removeEventListener("visibilitychange", refresh);
  window.removeEventListener("keydown", closeOnEscape);
});
</script>

<template>
  <div class="header-top-area hidden lg:block">
    <div class="header__container">
      <div class="header-top__wrp">
        <ul class="info">
          <li class="max-[1399px]:!hidden"><School aria-hidden="true" :size="15" /><span>Platform pembelajaran digital dan kewirausahaan berbasis praktik untuk sekolah Indonesia.</span></li>
          <li aria-hidden="true" class="max-[1399px]:!hidden"><span class="divider" /></li>
          <li><Mail aria-hidden="true" :size="15" /><a href="/#register">Ceritakan kebutuhan sekolah</a></li>
        </ul>
        <ul class="top-links">
          <li><a href="/kontak" class="inline-flex items-center gap-2"><LifeBuoy aria-hidden="true" :size="15" /> Pusat Bantuan</a></li>
          <li><a href="/faq">FAQ</a></li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Holds the header's height while it is pinned, so the page does not jump. -->
  <div :class="scrolled ? 'h-[88px] max-[1199px]:h-[76px]' : ''">
    <header :class="['header-area', scrolled && 'menu-fixed']">
      <div class="header__container">
        <div class="header__main">
          <a href="/" aria-label="Sunrise Academy — Beranda" @click="isOpen = false">
            <BrandLogo class="text-[22px]" />
          </a>
          <nav class="main-menu" aria-label="Navigasi utama">
            <ul>
              <li v-for="link in navLinks" :key="link.href"><a :href="link.href">{{ link.label }}</a></li>
            </ul>
          </nav>
          <div class="flex items-center gap-6">
            <div class="menu-btns">
              <a href="/#register">Hubungi tim kami</a>
              <a v-if="!sessionLoading && session" class="active" href="/dashboard">Dashboard <LayoutDashboard aria-hidden="true" :size="16" /></a>
              <a v-else class="active" href="/login">Masuk ke platform</a>
            </div>
            <button
              type="button"
              class="menubars"
              @click="isOpen = true"
              :aria-expanded="isOpen"
              aria-controls="site-navigation"
              aria-label="Buka menu"
            >
              <span /><span /><span />
            </button>
          </div>
        </div>
      </div>
    </header>
  </div>

  <template v-if="isOpen">
    <button type="button" aria-label="Tutup menu" class="sidebar-backdrop" @click="isOpen = false" />
    <div id="site-navigation" class="sidebar-area" role="dialog" aria-modal="true" aria-label="Menu Sunrise Academy">
      <div class="sidebar__head">
        <a href="/" aria-label="Sunrise Academy — Beranda" @click="isOpen = false"><BrandLogo class="text-base !text-white" /></a>
        <button type="button" class="sidebar__close" aria-label="Tutup menu" @click="isOpen = false"><X aria-hidden="true" :size="16" /></button>
      </div>
      <nav class="sidebar__nav" aria-label="Navigasi utama">
        <p class="sidebar__label"><BookOpen aria-hidden="true" :size="12" /> Jelajahi</p>
        <a v-for="link in navLinks" :key="link.href" :href="link.href" @click="isOpen = false">
          <span class="ico"><component :is="link.icon" aria-hidden="true" :size="16" /></span>
          <span class="grow">{{ link.label }}</span>
          <ArrowRight aria-hidden="true" class="go" :size="14" />
        </a>
      </nav>
      <div class="sidebar__btns">
        <a :href="session ? '/dashboard' : '/login'" @click="isOpen = false">{{ session ? "Buka dashboard" : "Masuk ke platform" }} <ArrowRight aria-hidden="true" :size="15" /></a>
        <a href="/#register" class="ghost" @click="isOpen = false">Hubungi tim kami</a>
        <p v-if="session" class="text-center text-xs text-white/50">Masuk sebagai {{ session.email?.split("@")[0] || "pengguna" }}</p>
      </div>
    </div>
  </template>
</template>
