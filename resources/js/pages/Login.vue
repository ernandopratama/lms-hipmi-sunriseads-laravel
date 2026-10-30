<script setup lang="ts">
import { ref } from "vue";
import { ArrowLeft, ArrowRight, Eye, EyeOff, ShieldCheck } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";

const accessNotes = [
  { number: "01", title: "Murid", note: "Melanjutkan materi, tugas, dan revisi." },
  { number: "02", title: "Guru", note: "Mereview pekerjaan dan memberi arahan." },
  { number: "03", title: "Instansi", note: "Memantau program sesuai kewenangan." },
];

const email = ref("");
const password = ref("");
const error = ref("");
const loading = ref(false);
const showPassword = ref(false);
const otp = ref("");
const otpRequired = ref(false);

async function handleSubmit() {
  error.value = "";
  loading.value = true;

  try {
    const res = await csrfFetch("/api/session", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: email.value, password: password.value, ...(otpRequired.value ? { otp: otp.value } : {}) }),
    });

    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      const message = typeof data?.message === "string" ? data.message : "Email atau kata sandi belum sesuai";
      if (message.includes("Kode verifikasi")) {
        otpRequired.value = true;
        throw new Error("Masukkan kode 6 digit dari aplikasi autentikator Anda.");
      }
      throw new Error(message);
    }

    const selectedOffer = new URLSearchParams(window.location.search).get("offer");
    window.location.replace(selectedOffer ? `/checkout/${encodeURIComponent(selectedOffer)}` : "/dashboard");
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : "Email atau kata sandi belum sesuai";
  } finally {
    loading.value = false;
  }
}

function onOtpInput(event: Event) {
  otp.value = (event.target as HTMLInputElement).value.replace(/\D/g, "").slice(0, 6);
  (event.target as HTMLInputElement).value = otp.value;
}

// Matches the autoFocus the OTP field had in the React version.
const vFocus = { mounted: (el: HTMLElement) => el.focus() };
</script>

<template>
  <main class="public-site lp min-h-screen bg-[var(--sub-bg)]">
    <header v-reveal>
      <div class="mx-auto flex h-20 max-w-[1440px] items-center justify-between px-5 sm:h-24 sm:px-8 lg:px-12">
        <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-xl" /></a>
        <a href="/" aria-label="Kembali ke beranda" class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 font-spartan text-sm font-semibold text-[var(--heading-color)] hover:text-[var(--primary-color)]"><ArrowLeft aria-hidden="true" :size="16" /> <span class="hidden sm:inline">Kembali ke beranda</span></a>
      </div>
    </header>

    <div class="mx-auto grid min-h-[calc(100vh-6rem)] max-w-[1440px] lg:grid-cols-[.95fr_1.05fr]">
      <section class="order-2 flex flex-col justify-between px-5 py-14 sm:px-8 sm:py-20 lg:order-1 lg:px-12 lg:py-24 xl:pr-20">
        <div>
          <h5 v-reveal class="primary-color mb-[10px] uppercase" style="transition-delay: 200ms">Area pengguna</h5>
          <h1 v-reveal class="max-w-2xl" style="transition-delay: 300ms">
            Kembali ke pekerjaan yang <span class="primary-color">sedang berjalan.</span>
          </h1>
          <p v-reveal class="mt-5 max-w-xl" style="transition-delay: 400ms">
            Satu pintu masuk untuk belajar, memberi umpan balik, dan membaca perkembangan program sekolah.
          </p>

          <div class="mt-12 max-w-xl border-t border-[var(--border)]">
            <div v-for="(item, index) in accessNotes" :key="item.number" v-reveal :style="{ transitionDelay: `${500 + index * 120}ms` }" class="grid grid-cols-[34px_82px_1fr] gap-3 border-b border-[var(--border)] py-4 text-sm">
              <span class="font-spartan font-bold text-[var(--primary-color)]">{{ item.number }}</span>
              <strong class="font-spartan text-base font-semibold text-[var(--heading-color)]">{{ item.title }}</strong>
              <span class="leading-6">{{ item.note }}</span>
            </div>
          </div>
        </div>

        <p class="mt-12 flex items-center gap-2 !text-xs font-medium"><ShieldCheck aria-hidden="true" :size="16" class="text-[var(--primary-color)]" /> Akses dan data mengikuti peran pengguna.</p>
      </section>

      <section class="order-1 flex items-center px-5 py-6 sm:px-8 sm:py-10 lg:order-2 lg:px-12 lg:py-20">
        <div class="mx-auto w-full max-w-[540px]">
          <div v-reveal class="contact__item" style="transition-delay: 150ms">
            <h5 class="primary-color uppercase">Masuk ke platform</h5>
            <h2 class="mt-1">Selamat datang kembali.</h2>
            <p class="mb-8 mt-2 !text-sm">Masukkan detail akun sekolah Anda untuk melanjutkan ke ruang kerja.</p>

            <form @submit.prevent="handleSubmit">
              <div v-if="error" role="alert" class="mb-6 rounded border-l-2 border-red-600 bg-red-50 px-4 py-3 text-sm leading-6 text-red-700">{{ error }}</div>

              <label for="login-email">Alamat email</label>
              <input id="login-email" v-model="email" type="email" required autocomplete="email" placeholder="nama@sekolah.sch.id" />

              <template v-if="otpRequired">
                <label for="login-otp">Kode verifikasi</label>
                <input
                  id="login-otp"
                  v-focus
                  :value="otp"
                  @input="onOtpInput"
                  type="text"
                  inputmode="numeric"
                  autocomplete="one-time-code"
                  required
                  minlength="6"
                  maxlength="6"
                  pattern="[0-9]{6}"
                  class="tracking-[0.35em]"
                  placeholder="000000"
                />
              </template>

              <div class="mb-[10px] flex items-center justify-between gap-3">
                <label for="login-password" class="!mb-0">Kata sandi</label>
                <a href="/lupa-password" class="text-sm font-medium text-[var(--primary-color)] hover:underline">Lupa kata sandi?</a>
              </div>
              <div class="relative">
                <input id="login-password" v-model="password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password" class="!pr-12" placeholder="Masukkan kata sandi" />
                <button
                  type="button"
                  @click="showPassword = !showPassword"
                  class="absolute right-0 top-0 grid h-[54px] w-12 place-items-center text-[var(--paragraph)] hover:text-[var(--heading-color)]"
                  :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                >
                  <EyeOff v-if="showPassword" aria-hidden="true" :size="18" />
                  <Eye v-else aria-hidden="true" :size="18" />
                </button>
              </div>

              <button type="submit" :disabled="loading" class="btn-one w-full justify-center">
                {{ loading ? "Memproses..." : otpRequired ? "Verifikasi & masuk" : "Masuk ke platform" }}
                <ArrowRight v-if="!loading" aria-hidden="true" :size="17" />
              </button>
            </form>

            <p class="mt-7 border-t border-[var(--border)] pt-5 !text-xs !leading-6">
              Belum memiliki akses? Hubungi admin sekolah. Untuk institusi baru, <a href="/#register" class="font-semibold text-[var(--primary-color)] hover:underline">ceritakan kebutuhan sekolah</a>.
            </p>
          </div>

          <p v-reveal class="mt-6 !text-xs !leading-5" style="transition-delay: 450ms">Sunrise Academy tidak pernah meminta kata sandi toko atau marketplace Anda.</p>
        </div>
      </section>
    </div>
  </main>
</template>
