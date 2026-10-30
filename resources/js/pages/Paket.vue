<script setup lang="ts">
import { onMounted, ref } from "vue";
import { ArrowLeft, ArrowRight, Award, BadgeCheck, BookOpen, CalendarClock, Check, ClipboardCheck, Info, ListChecks, LockKeyhole, Minus, Plus, Send, Star, Users } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import Header from "@/components/landing/Header.vue";
import Footer from "@/components/landing/Footer.vue";

type PublicPlan = {
  code: "FREE" | "MEDIUM" | "ADVANCED";
  name: string;
  rank: number;
  moduleLimit: number;
  entitlements: Record<string, boolean | number | string>;
  offers: Array<{
    code: string;
    slug: string;
    name: string;
    billingModel: string;
    accessDurationDays: number | null;
    prices: Array<{ currency: string; amount: string; compareAtAmount: string | null }>;
  }>;
};

const featureLabels: Array<[string, string]> = [
  ["learning.materials.read", "Materi belajar digital"],
  ["assignments.standard.submit", "Tugas, penilaian, dan feedback"],
  ["student_store.submit", "Praktik pembuatan toko"],
  ["affiliate.content.submit", "Submission konten affiliate"],
  ["affiliate.metrics.manage", "Snapshot performa historis"],
  ["dashboards.advanced.read", "Dashboard lanjutan semua peran"],
  ["early_warnings.read", "Early warning dan pendampingan"],
  ["reports.export", "Reporting dan export"],
];

const promotionalPriceFallbacks: Partial<Record<PublicPlan["code"], { amount: number; compareAtAmount: number }>> = {
  MEDIUM: { amount: 25_000, compareAtAmount: 499_000 },
  ADVANCED: { amount: 50_000, compareAtAmount: 899_000 },
};

function formatCurrency(amount: number, currency = "IDR") {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency, maximumFractionDigits: 0 }).format(amount);
}

function getPricing(plan: PublicPlan) {
  const price = plan.offers[0]?.prices[0];
  const fallback = promotionalPriceFallbacks[plan.code];
  const hasConfiguredComparison = Boolean(price?.compareAtAmount);
  const amount = hasConfiguredComparison ? Number(price?.amount ?? 0) : (fallback?.amount ?? Number(price?.amount ?? 0));
  if (!price || amount === 0) return { current: "Rp 0", previous: null, saving: null };

  const compareAtAmount = hasConfiguredComparison ? Number(price.compareAtAmount) : (fallback?.compareAtAmount ?? amount);
  const saving = Math.max(compareAtAmount - amount, 0);

  return {
    current: formatCurrency(amount, price.currency),
    previous: saving > 0 ? formatCurrency(compareAtAmount, price.currency) : null,
    saving: saving > 0 ? formatCurrency(saving, price.currency) : null,
  };
}

const planCopy: Record<PublicPlan["code"], { tag: string; description: string }> = {
  FREE: { tag: "Akses Dasar Sekolah", description: "Otomatis untuk sekolah dan seluruh siswa tanpa syarat minimal." },
  MEDIUM: { tag: "Vokasi Menengah", description: "Disesuaikan untuk kelas industri dan pembekalan portofolio aktif siswa SMK/SMA." },
  ADVANCED: { tag: "Akses Lengkap & Pendampingan", description: "Paket paripurna untuk sertifikasi kompetensi dan audit akreditasi." },
};

const faqs = [
  { q: "Bagaimana jika siswa pindah rombel atau mutasi sekolah?", a: "Lisensi Medium dan Advanced terikat pada slot akun yang dikelola Operator Sekolah. Slot dapat dialihkan ke siswa pengganti melalui menu Administrasi Sekolah selama masa akses masih berlaku." },
  { q: "Apakah ada biaya berlangganan untuk akun guru?", a: "Tidak ada. Akses pendidik, fasilitator, dan kepala sekolah tidak dikenakan biaya lisensi terpisah jika sekolah telah mengaktifkan minimal paket Gratis untuk siswanya." },
  { q: "Dapatkah pembayaran diproses melalui SIPLah?", a: "Hubungi tim program untuk memastikan skema pembayaran yang sesuai dengan sekolah Anda." },
  { q: "Apakah data praktik siswa aman dan terisolasi?", a: "Data siswa hanya dapat diakses oleh sekolah bersangkutan, sesuai pembatasan peran, tenant, consent, dan keamanan data." },
];

function planHref(plan: PublicPlan) {
  if (plan.code === "FREE") return "/login";
  return plan.offers[0] ? `/checkout/${encodeURIComponent(plan.offers[0].slug)}` : "/kontak";
}

function planCta(plan: PublicPlan) {
  if (plan.code === "FREE") return "Mulai dengan Gratis";
  return plan.offers[0] ? `Pilih ${plan.name}` : "Minta informasi";
}

// Medium first (its badge pops in right after), then Gratis, then Advanced.
const planRevealDelay: Record<PublicPlan["code"], number> = { MEDIUM: 0, FREE: 1100, ADVANCED: 1500 };

const openFaq = ref(0);

const plans = ref<PublicPlan[] | null>(null);

onMounted(async () => {
  try {
    const response = await fetch("/api/backend/access/plans", { cache: "no-store" });
    plans.value = response.ok ? await response.json() as PublicPlan[] : [];
  } catch {
    plans.value = [];
  }
});
</script>

<template>
  <main v-if="plans === null" class="min-h-screen bg-[#FFFFFF] px-4 py-20" aria-busy="true" aria-label="Memuat pilihan paket">
    <div class="mx-auto max-w-7xl animate-pulse">
      <div class="mx-auto h-10 max-w-xl rounded-xl bg-amber-100" />
      <div class="mx-auto mt-5 h-5 max-w-2xl rounded bg-amber-50" />
      <div class="mt-14 grid gap-6 lg:grid-cols-3">
        <div v-for="item in 3" :key="item" class="h-[520px] rounded-3xl border border-amber-100 bg-white" />
      </div>
    </div>
  </main>
  <div v-else class="public-site lp min-h-screen bg-white">
    <Header />
    <main id="main-content" class="relative pb-24" style="background-image: linear-gradient(to right, rgba(213,224,221,.45) 1px, transparent 1px), linear-gradient(to bottom, rgba(213,224,221,.45) 1px, transparent 1px); background-size: 24px 24px; background-color: #F5F8F7">
      <div class="mx-auto max-w-[1200px] px-4 pt-8 sm:px-8 md:pt-12 lg:px-12">
        <div v-reveal class="mb-8 flex flex-wrap items-center justify-between gap-4 border-b border-[#D5E0DD] pb-4">
          <a href="/" class="inline-flex items-center gap-2 text-sm font-semibold text-[#5A6B69] hover:text-[#17302D]"><ArrowLeft aria-hidden="true" :size="18" /> Kembali ke beranda</a>
          <span class="inline-flex items-center gap-1.5 border border-[#D5E0DD] bg-white px-2.5 py-1 text-[13px] font-medium text-[#5A6B69]"><span class="inline-block size-2 bg-[#2E7D4F]" /> Registri tahun ajaran 2025/2026</span>
        </div>

        <section class="mb-12 grid items-start gap-8 lg:grid-cols-12">
          <div v-reveal class="flex flex-col gap-3 lg:col-span-8" style="transition-delay: 100ms">
            <span class="inline-flex items-center gap-2 text-[13px] font-semibold uppercase tracking-widest text-[#2F6F6A]"><ClipboardCheck aria-hidden="true" :size="18" class="text-[#E9A23B]" /> Pilihan akses program</span>
            <h1 class="text-[1.875rem] font-bold leading-[2.125rem] tracking-tight text-[#17302D] md:text-[2.5rem] md:leading-[2.875rem]">Pilih akses belajar sesuai kebutuhan.</h1>
            <p class="max-w-2xl text-[1.0625rem] leading-7 text-[#5A6B69] md:text-[1.1875rem] md:leading-[1.875rem]">Paket berbayar dihitung untuk satu siswa. Paket Gratis otomatis menjadi akses dasar sekolah dan seluruh siswanya.</p>
          </div>
          <div v-reveal class="flex flex-col justify-start pt-2 sm:items-end lg:col-span-4" style="transition-delay: 250ms">
            <div class="w-full border border-l-4 border-[#D5E0DD] border-l-[#E9A23B] bg-white p-4 sm:w-auto">
              <div class="mb-1 flex items-center gap-2 text-[13px] font-bold text-[#17302D]"><CalendarClock aria-hidden="true" :size="16" class="text-[#E9A23B]" /> Masa aktif lisensi praktik</div>
              <p class="text-sm leading-[1.375rem] text-[#5A6B69]">Akses berbayar aktif 365 hari sejak pembayaran disetujui.</p>
            </div>
          </div>
        </section>

        <div v-reveal class="mb-8 flex flex-wrap items-center justify-between gap-3 border border-[#D5E0DD] bg-white px-5 py-3 text-sm text-[#5A6B69]">
          <div class="flex items-start gap-2"><ListChecks aria-hidden="true" :size="18" class="mt-0.5 shrink-0 text-[#2F6F6A]" /><p class="!text-sm !leading-6"><span class="font-semibold text-[#17302D]">Spesifikasi penugasan mandiri:</span> <span>Dapat dialihkan antarsiswa dengan persetujuan Operator Sekolah</span></p></div>
          <div class="flex items-start gap-1.5 text-[13px] font-semibold text-[#2F6F6A]"><BadgeCheck aria-hidden="true" :size="16" class="mt-0.5 shrink-0" /> Sesuai standar Kurikulum Merdeka &amp; Vokasi</div>
        </div>

        <section aria-labelledby="package-list-title" class="mb-16 pt-4">
          <h2 id="package-list-title" class="sr-only">Daftar paket belajar</h2>
          <div v-if="plans.length === 0" class="mx-auto max-w-2xl border border-[#D5E0DD] bg-white p-8 text-center">
            <LockKeyhole class="mx-auto text-[#2F6F6A]" aria-hidden="true" />
            <h3 class="mt-4 text-xl font-bold text-[#17302D]">Informasi paket sedang diperbarui</h3>
            <p class="mt-2 leading-7 text-[#5A6B69]">Harga dan ketersediaan tidak ditampilkan sampai data terverifikasi tersedia dari sistem.</p>
            <a href="/kontak" class="btn-one mt-6">Hubungi tim program</a>
          </div>
          <div v-else class="mx-auto grid max-w-xl gap-6 lg:max-w-none lg:grid-cols-3 lg:gap-8">
            <article
              v-for="plan in plans"
              :key="plan.code"
              v-reveal
              :style="{ transitionDelay: `${planRevealDelay[plan.code]}ms` }"
              :class="['relative flex flex-col justify-between bg-white', plan.code === 'MEDIUM' ? 'z-10 rounded-[30px] border-2 border-[#2F6F6A] shadow-[0_24px_54px_-12px_rgba(23,48,45,.22)] lg:-my-5' : 'overflow-hidden rounded-[26px] border border-[#D5E0DD] shadow-[0_10px_30px_-10px_rgba(23,48,45,.06)] ']"
            >
              <div>
                <span v-if="plan.code === 'MEDIUM'" class="badge-pop absolute left-1/2 top-0 z-20 inline-flex -translate-x-1/2 -translate-y-1/2 items-center gap-1.5 whitespace-nowrap rounded-full bg-[#E9A23B] px-4 py-1.5 text-[11px] font-bold uppercase tracking-wider text-[#17302D] shadow-md"><Star aria-hidden="true" :size="12" fill="currentColor" /> Paling banyak dipilih</span>
                <div v-if="plan.code === 'MEDIUM'" class="flex items-center gap-2 rounded-t-[28px] bg-gradient-to-r from-[#235450] via-[#2F6F6A] to-[#235450] px-6 py-3 pt-5 text-white">
                  <span class="size-2 animate-pulse rounded-full bg-[#E9A23B]" />
                  <span class="text-[11px] font-bold uppercase tracking-wider text-[#baece6]">Rekomendasi kurikulum</span>
                </div>

                <div :class="['flex flex-col gap-4 border-b border-[#D5E0DD]/70 p-7', plan.code === 'MEDIUM' ? 'bg-[#ddf9f4]/40 p-8' : 'bg-[#F5F8F7]/40']">
                  <span :class="['text-[13px]', plan.code === 'MEDIUM' ? 'font-bold uppercase tracking-wide text-[#2F6F6A]' : 'font-medium text-[#5A6B69]']">{{ planCopy[plan.code].tag }}</span>
                  <div>
                    <h3 :class="['font-bold tracking-tight text-[#17302D]', plan.code === 'MEDIUM' ? 'text-[2.5rem] leading-[2.875rem]' : 'text-[1.375rem] leading-7']">{{ plan.name }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-[#5A6B69]">{{ planCopy[plan.code].description }}</p>
                  </div>
                  <div class="pt-2">
                    <div v-if="getPricing(plan).previous" class="mb-1.5 flex flex-wrap items-center gap-3">
                      <del class="text-sm text-[#5A6B69]/80">{{ getPricing(plan).previous }}</del>
                      <span class="rounded-full border border-[#2E7D4F]/30 bg-[#2E7D4F]/15 px-2.5 py-0.5 text-[13px] font-bold text-[#2E7D4F]">Hemat {{ getPricing(plan).saving }}</span>
                    </div>
                    <p :class="['font-spartan font-bold leading-none tracking-tight text-[#17302D]', plan.code === 'MEDIUM' ? 'text-[2.75rem]' : 'text-[2.25rem]']">{{ getPricing(plan).current }}</p>
                    <p class="mt-2 text-sm font-medium text-[#5A6B69]">
                      {{ plan.offers[0]?.accessDurationDays ? `1 siswa · akses ${plan.offers[0].accessDurationDays} hari` : plan.code === "FREE" ? "Otomatis untuk sekolah & seluruh siswa" : "Durasi mengikuti penawaran" }}
                    </p>
                  </div>
                </div>

                <div :class="['mx-6 my-5 rounded-2xl border border-l-4 p-4', plan.code === 'ADVANCED' ? 'border-[#E9A23B]/30 border-l-[#E9A23B] bg-[#ffddb6]/20' : plan.code === 'MEDIUM' ? 'border-[#2F6F6A]/30 border-l-[#2F6F6A] bg-[#ddf9f4]' : 'border-[#D5E0DD] border-l-[#5A6B69] bg-[#F5F8F7]/80']">
                  <div class="flex items-start gap-3">
                    <div :class="['grid size-9 shrink-0 place-items-center rounded-xl', plan.code === 'MEDIUM' ? 'bg-[#2F6F6A] text-white' : 'border border-[#D5E0DD] bg-white text-[#2F6F6A]']">
                      <Award v-if="plan.code === 'ADVANCED'" aria-hidden="true" :size="18" />
                      <BookOpen v-else aria-hidden="true" :size="18" />
                    </div>
                    <div>
                      <div class="text-sm font-bold text-[#17302D]">Maksimal {{ plan.moduleLimit }} modul terbit</div>
                      <div class="mt-0.5 text-[13px] leading-normal text-[#5A6B69]">{{ plan.code === "FREE" ? "Langsung aktif tanpa pembayaran atau approval." : "Aktif setelah pembayaran disetujui untuk satu siswa." }}</div>
                    </div>
                  </div>
                </div>

                <div class="px-7 py-2">
                  <span class="mb-3.5 block text-[11px] font-bold uppercase tracking-widest text-[#5A6B69]/80">Item pengujian &amp; fasilitas:</span>
                  <ul>
                    <li v-for="[key, label] in featureLabels" :key="key" :class="['flex items-start gap-3 border-b border-[#D5E0DD]/60 py-2.5 text-sm last:border-b-0', plan.entitlements[key] ? 'text-[#17302D]' : 'text-[#5A6B69]/60']">
                      <span :class="['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', plan.entitlements[key] ? 'bg-[#2E7D4F]/10 text-[#2E7D4F]' : 'bg-[#F5F8F7] text-[#5A6B69]/50']">
                        <Check v-if="plan.entitlements[key]" aria-hidden="true" :size="14" />
                        <LockKeyhole v-else aria-hidden="true" :size="13" />
                      </span>
                      <span>{{ label }}</span>
                    </li>
                  </ul>
                </div>
              </div>

              <div :class="['mt-4 border-t border-[#D5E0DD]/70 bg-[#F5F8F7]/30 p-7 pt-5', plan.code === 'MEDIUM' && 'rounded-b-[28px]']">
                <a
                  :href="planHref(plan)"
                  :class="['flex h-12 w-full items-center justify-center gap-2 rounded-xl font-semibold transition active:scale-[0.98]', plan.code === 'MEDIUM' ? 'bg-[#2F6F6A] text-white shadow-md hover:bg-[#235450]' : plan.code === 'ADVANCED' ? 'bg-[#17302D] text-white hover:bg-[#235450]' : 'border-2 border-[#17302D]/20 bg-white text-[#17302D] hover:border-[#17302D]']"
                >{{ planCta(plan) }} <ArrowRight v-if="plan.code === 'MEDIUM'" aria-hidden="true" :size="18" /></a>
              </div>
            </article>
          </div>
        </section>

        <div v-reveal class="mb-12 flex items-start gap-4 border border-[#D5E0DD] bg-white p-5">
          <Info aria-hidden="true" :size="24" class="mt-0.5 shrink-0 text-[#2F6F6A]" />
          <div class="text-sm leading-relaxed text-[#5A6B69]">
            <p class="mb-1 font-semibold text-[#17302D]">Ketentuan regulasi &amp; tata kelola lisensi:</p>
            <p>Harga Medium dan Advanced berlaku per siswa, bukan per sekolah. Paket Advanced tetap mengikuti pembatasan peran, tenant, consent, dan keamanan data.</p>
          </div>
        </div>

        <section v-reveal class="mb-16 border border-[#D5E0DD]/40 bg-[#17302D] p-6 text-[#F5F8F7] sm:p-8 md:p-10">
          <div class="grid items-center gap-8 lg:grid-cols-12">
            <div class="flex flex-col gap-2 lg:col-span-8">
              <div class="flex items-start gap-2 text-[13px] font-semibold uppercase tracking-wider text-[#E9A23B]"><Users aria-hidden="true" :size="18" class="shrink-0" /> Pengadaan satuan pendidikan &amp; dinas</div>
              <h3 class="text-[1.375rem] font-bold leading-7 text-white">Butuh pengadaan kolektif untuk 1 angkatan atau seluruh sekolah?</h3>
              <p class="max-w-2xl text-[1.0625rem] leading-7 text-[#c4e0db]">Hubungi tim kami untuk membahas skema kemitraan, kontrak kerja sama instansi, dan pendampingan untuk guru pembina.</p>
            </div>
            <div class="flex flex-col justify-end gap-3 sm:flex-row lg:col-span-4 lg:flex-col">
              <a href="/#register" class="btn-push"><span class="btn-push__top">Konsultasikan kebutuhan sekolah</span></a>
              <a href="/kontak" class="btn-push btn-push--light btn-push--fly">
                <span class="btn-push__top"><span class="btn-push__icon"><Send aria-hidden="true" :size="20" /></span><span class="btn-push__label">Hubungi tim program</span></span>
              </a>
            </div>
          </div>
        </section>

        <section v-if="plans.length" v-reveal class="mb-16 border border-[#D5E0DD] bg-white">
          <div class="border-b border-[#D5E0DD] p-6">
            <span class="block text-[13px] font-semibold uppercase tracking-wider text-[#2F6F6A]">Rubrik verifikasi</span>
            <h3 class="text-[1.375rem] font-bold leading-7 text-[#17302D]">Perbandingan matriks kapabilitas siswa</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-xs sm:text-sm">
              <thead>
                <tr class="border-b border-[#D5E0DD] bg-[#F5F8F7] text-[10px] text-[#5A6B69] sm:text-[13px]">
                  <th scope="col" class="p-3 pl-3 font-semibold uppercase sm:p-4 sm:pl-6">Komponen praktik niaga</th>
                  <th v-for="plan in plans" :key="plan.code" scope="col" :class="['px-1 py-3 text-center font-semibold uppercase sm:w-36 sm:p-4', plan.code === 'MEDIUM' && 'bg-[#ddf9f4]/60 text-[#2F6F6A]']">{{ plan.name }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[#D5E0DD]">
                <tr class="hover:bg-[#F5F8F7]/40">
                  <td class="p-3 pl-3 font-medium text-[#17302D] sm:p-4 sm:pl-6">Jumlah modul praktik terbit</td>
                  <td v-for="plan in plans" :key="plan.code" :class="['px-1 py-3 text-center font-semibold text-[#17302D] sm:p-4', plan.code === 'MEDIUM' && 'bg-[#ddf9f4]/30']">{{ plan.moduleLimit }} modul</td>
                </tr>
                <tr v-for="[key, label] in featureLabels" :key="key" class="hover:bg-[#F5F8F7]/40">
                  <td class="p-3 pl-3 font-medium text-[#17302D] sm:p-4 sm:pl-6">{{ label }}</td>
                  <td v-for="plan in plans" :key="plan.code" :class="['px-1 py-3 text-center sm:p-4', plan.code === 'MEDIUM' && 'bg-[#ddf9f4]/30']">
                    <Check v-if="plan.entitlements[key]" aria-label="Termasuk" :size="20" class="mx-auto text-[#2E7D4F]" />
                    <Minus v-else aria-label="Tidak termasuk" :size="18" class="mx-auto text-[#5A6B69]/60" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="mb-4">
          <div v-reveal class="mb-8">
            <span class="text-[13px] font-semibold uppercase tracking-wider text-[#2F6F6A]">Tanya jawab operasional</span>
            <h3 class="mt-1 text-[1.375rem] font-bold leading-7 text-[#17302D]">Pertanyaan seputar lisensi sekolah</h3>
          </div>
          <div class="grid items-start gap-4 md:grid-cols-2">
            <div v-for="(item, index) in faqs" :key="item.q" v-reveal :style="{ transitionDelay: `${index * 100}ms` }">
            <div
              :class="['border bg-white transition-[border-color,box-shadow] duration-300', openFaq === index ? 'border-[#2F6F6A] shadow-[0_12px_30px_-14px_rgba(23,48,45,.3)]' : 'border-[#D5E0DD] hover:border-[#2F6F6A]/50']"
            >
              <h4>
                <button
                  type="button"
                  :id="`faq-btn-${index}`"
                  :aria-expanded="openFaq === index"
                  :aria-controls="`faq-panel-${index}`"
                  class="flex w-full items-center justify-between gap-4 p-6 text-left text-sm font-bold leading-6 text-[#17302D]"
                  @click="openFaq = openFaq === index ? -1 : index"
                >
                  {{ item.q }}
                  <span :class="['grid size-8 shrink-0 place-items-center rounded-full transition-all duration-300', openFaq === index ? 'rotate-45 bg-[#2F6F6A] text-white' : 'bg-[#F5F8F7] text-[#2F6F6A]']"><Plus aria-hidden="true" :size="16" /></span>
                </button>
              </h4>
              <div
                :id="`faq-panel-${index}`"
                role="region"
                :aria-labelledby="`faq-btn-${index}`"
                :class="['grid transition-[grid-template-rows,opacity] duration-300 ease-out', openFaq === index ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0']"
              >
                <div class="overflow-hidden">
                  <p class="px-6 pb-6 text-sm leading-relaxed text-[#5A6B69]">{{ item.a }}</p>
                </div>
              </div>
            </div>
            </div>
          </div>
        </section>
      </div>
    </main>
    <Footer />
  </div>
</template>
