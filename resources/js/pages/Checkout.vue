<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { ArrowLeft, ArrowRight, Check, CheckCircle2, Clock3, ReceiptText, ShieldCheck } from "@lucide/vue";
import "@/components/landing/landing.css";
import { vReveal } from "@/components/landing/reveal";
import BrandLogo from "@/components/BrandLogo.vue";
import CopyValueButton from "@/components/CopyValueButton.vue";
import CheckoutState from "@/components/checkout/CheckoutState.vue";
import OrderRow from "@/components/checkout/OrderRow.vue";
import { csrfFetch } from "@/lib/csrf";

type PublicPlan = {
  code: string;
  name: string;
  moduleLimit: number;
  offers: Array<{
    slug: string;
    name: string;
    accessDurationDays: number | null;
    prices: Array<{ currency: string; amount: string; compareAtAmount: string | null }>;
  }>;
};

type OrderResult = {
  id: string;
  orderNumber: string;
  status: string;
  currency: string;
  subtotalAmount: string;
  feeAmount: string;
  totalAmount: string;
  paymentExpiresAt: string | null;
  items: Array<{ plan: { code: string; name: string; moduleLimit: number }; offer: { name: string; accessDurationDays: number | null } }>;
  paymentInstructions: {
    method: "BANK_TRANSFER";
    bankName: string;
    accountNumber: string;
    accountHolder: string;
    baseAmount: string;
    uniqueCode: number;
    transferAmount: string;
    expiresAt: string | null;
  } | null;
  nextAction: { type: string; message: string } | null;
};

function money(amount: string | number, currency = "IDR") {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency, maximumFractionDigits: 0 }).format(Number(amount));
}

function formatDate(value: string | null) {
  if (!value) return "batas waktu yang tertera";
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(value));
}

const offerSlug = String(useRoute().params.offerSlug);
const plans = ref<PublicPlan[]>([]);
const authenticated = ref<boolean | null>(null);
const loading = ref(true);
const submitting = ref(false);
const accepted = ref(false);
const error = ref("");
const order = ref<OrderResult | null>(null);
const idempotencyKey = crypto.randomUUID();

onMounted(() => {
  Promise.all([
    fetch("/api/backend/access/plans", { cache: "no-store" }).then(async (response) => response.ok ? response.json() : []),
    fetch("/api/session", { cache: "no-store" }).then((response) => response.ok),
  ])
    .then(([planData, sessionActive]) => {
      plans.value = Array.isArray(planData) ? planData : [];
      authenticated.value = sessionActive;
    })
    .catch(() => { error.value = "Detail paket belum dapat dimuat. Coba lagi beberapa saat."; })
    .finally(() => { loading.value = false; });
});

const selected = computed(() => {
  for (const plan of plans.value) {
    const offer = plan.offers.find((item) => item.slug === offerSlug);
    if (offer) return { plan, offer, price: offer.prices[0] };
  }
  return null;
});

async function submit() {
  if (!selected.value || !accepted.value || submitting.value) return;
  submitting.value = true;
  error.value = "";
  try {
    const response = await csrfFetch("/api/backend/commerce/orders", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ offerSlug, idempotencyKey, acceptTerms: accepted.value }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || "Pesanan belum dapat dibuat");
    order.value = data as OrderResult;
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Pesanan belum dapat dibuat";
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <CheckoutState v-if="loading" text="Menyiapkan detail pembelian…" />

  <CheckoutState v-else-if="!selected" text="Paket tidak tersedia." description="Penawaran mungkin sudah berakhir atau belum aktif.">
    <a href="/paket" class="btn-one mt-6"><ArrowLeft aria-hidden="true" :size="16" /> Kembali ke paket</a>
  </CheckoutState>

  <CheckoutState v-else-if="authenticated === false" text="Masuk untuk melanjutkan pembelian." description="Pesanan perlu ditautkan ke akun yang akan menerima akses.">
    <a :href="`/login?offer=${encodeURIComponent(offerSlug)}`" class="btn-one mt-6">Masuk ke akun <ArrowRight aria-hidden="true" :size="16" /></a>
  </CheckoutState>

  <main v-else-if="order" class="public-site lp min-h-screen bg-[var(--sub-bg)] px-4 py-10 sm:px-6 sm:py-16">
    <div class="mx-auto max-w-2xl">
      <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-xl" /></a>
      <section v-reveal class="contact__item mt-10" aria-labelledby="order-success-title">
        <span class="grid size-14 place-items-center rounded-full bg-[var(--primary-color)]/10 text-[var(--primary-color)]"><CheckCircle2 :size="30" aria-hidden="true" /></span>
        <h5 class="primary-color mt-6 uppercase">Pesanan tercatat</h5>
        <h1 id="order-success-title" class="mt-1 !text-[30px] !leading-[40px] sm:!text-[36px] sm:!leading-[46px]">Selesaikan pembayaran melalui transfer bank.</h1>
        <dl class="mt-8 divide-y divide-slate-900/10 border-y border-slate-900/10 text-sm">
          <OrderRow label="Nomor pesanan" :value="order.orderNumber" strong />
          <OrderRow label="Paket" :value="order.items[0]?.plan.name || selected.plan.name" />
          <OrderRow label="Harga paket" :value="money(order.subtotalAmount, order.currency)" />
          <OrderRow label="Kode unik" :value="String(order.paymentInstructions?.uniqueCode ?? Number(order.feeAmount)).padStart(3, '0')" />
          <OrderRow label="Total transfer" :value="money(order.totalAmount, order.currency)" strong />
          <OrderRow label="Status" value="Menunggu pembayaran" />
        </dl>
        <section v-if="order.paymentInstructions" class="mt-7 rounded-xl border border-[var(--primary-color)]/30 bg-[var(--primary-color)]/5 p-5" aria-labelledby="bank-transfer-title">
          <p id="bank-transfer-title" class="font-spartan !text-sm font-bold uppercase text-[var(--primary-color)]">Rekening tujuan</p>
          <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p class="font-spartan !text-xl font-bold text-[var(--heading-color)]">{{ order.paymentInstructions.bankName }} · {{ order.paymentInstructions.accountNumber }}</p>
              <p class="mt-1 !text-sm">a.n. {{ order.paymentInstructions.accountHolder }}</p>
            </div>
            <CopyValueButton :value="order.paymentInstructions.accountNumber" label="Salin rekening" />
          </div>
          <div class="mt-5 border-t border-[var(--primary-color)]/20 pt-5">
            <p class="!text-xs font-medium">Nominal yang harus ditransfer</p>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p class="font-spartan !text-[32px] font-bold !leading-none text-[var(--heading-color)]">{{ money(order.paymentInstructions.transferAmount, order.currency) }}</p>
              <CopyValueButton :value="String(Math.round(Number(order.paymentInstructions.transferAmount)))" label="Salin nominal" />
            </div>
            <p class="mt-3 !text-xs font-semibold !leading-5 text-[#b45309]">Transfer tepat sampai 3 digit terakhir. Jangan membulatkan nominal agar pembayaran mudah dicocokkan.</p>
          </div>
        </section>
        <p class="mt-5 flex items-start gap-2 !text-xs !leading-6"><Clock3 aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--primary-color)]" :size="15" /> Selesaikan pembayaran sebelum {{ formatDate(order.paymentExpiresAt) }}. Akses aktif setelah pembayaran diverifikasi.</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
          <a href="/dashboard/pembelian" class="btn-one flex-1 justify-center">Lihat riwayat pesanan <ArrowRight aria-hidden="true" :size="16" /></a>
          <a href="/kontak" class="btn-outline flex-1 justify-center">Butuh bantuan?</a>
        </div>
      </section>
    </div>
  </main>

  <main v-else class="public-site lp min-h-screen bg-[var(--sub-bg)]">
    <header>
      <div class="mx-auto flex h-20 max-w-[1200px] items-center justify-between px-4 sm:h-24 sm:px-6">
        <a href="/" aria-label="Sunrise Academy — Beranda"><BrandLogo class="text-xl" /></a>
        <a href="/paket" aria-label="Kembali ke paket" class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 font-spartan text-sm font-semibold text-[var(--heading-color)] hover:text-[var(--primary-color)]"><ArrowLeft aria-hidden="true" :size="16" /> <span class="hidden sm:inline">Kembali ke paket</span></a>
      </div>
    </header>

    <div class="mx-auto grid max-w-[1200px] gap-12 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[.85fr_1.15fr] lg:gap-20 lg:py-20">
      <section>
        <h5 v-reveal class="primary-color mb-[10px] uppercase">Ringkasan pilihan</h5>
        <h1 v-reveal style="transition-delay: 100ms">{{ selected.plan.name }}</h1>
        <p v-reveal class="mt-5 max-w-lg" style="transition-delay: 200ms">Akses paket ini berlaku untuk satu siswa, bukan satu sekolah.</p>

        <div v-reveal class="mt-9 border-y border-[var(--border)] py-6" style="transition-delay: 300ms">
          <del v-if="selected.price?.compareAtAmount" class="text-sm font-medium">{{ money(selected.price.compareAtAmount, selected.price.currency) }}</del>
          <p class="mt-2 font-spartan !text-[40px] font-bold !leading-none text-[var(--heading-color)]">{{ selected.price ? money(selected.price.amount, selected.price.currency) : "Harga belum tersedia" }}</p>
          <p class="mt-2 !text-sm">Harga per siswa · akses {{ selected.offer.accessDurationDays || "sesuai penawaran" }} hari</p>
        </div>

        <ul v-reveal class="mt-8 space-y-4 text-sm text-[var(--heading-color)]" style="transition-delay: 400ms">
          <li class="flex gap-3"><Check aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--primary-color)]" :size="18" /> Maksimal {{ selected.plan.moduleLimit }} modul terbit</li>
          <li class="flex gap-3"><ShieldCheck aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--primary-color)]" :size="18" /> Akses hanya aktif setelah pembayaran terverifikasi</li>
          <li class="flex gap-3"><ReceiptText aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--primary-color)]" :size="18" /> Kode unik 3 digit ditambahkan pada nominal transfer</li>
          <li class="flex gap-3"><Clock3 aria-hidden="true" class="mt-0.5 shrink-0 text-[var(--primary-color)]" :size="18" /> Pesanan berlaku 24 jam</li>
        </ul>
      </section>

      <section v-reveal class="contact__item" style="transition-delay: 250ms" aria-labelledby="checkout-title">
        <div class="flex items-center gap-3"><ReceiptText aria-hidden="true" class="text-[var(--primary-color)]" :size="22" /><h5 class="primary-color uppercase">Konfirmasi pesanan</h5></div>
        <h2 id="checkout-title" class="mt-3">Periksa sebelum membuat pesanan.</h2>
        <p class="mt-2 !text-sm !leading-7">Tahap ini belum memotong saldo. Setelah pesanan dibuat, rekening BCA dan total transfer akan langsung ditampilkan.</p>

        <dl class="mt-8 divide-y divide-slate-900/10 border-y border-slate-900/10 text-sm">
          <OrderRow label="Paket" :value="selected.offer.name" />
          <OrderRow label="Penerima" value="1 siswa" />
          <OrderRow label="Durasi" :value="`${selected.offer.accessDurationDays || '-'} hari`" />
          <OrderRow label="Harga paket" :value="selected.price ? money(selected.price.amount, selected.price.currency) : '-'" strong />
          <OrderRow label="Kode unik" value="Ditentukan setelah pesanan dibuat" />
        </dl>

        <p class="mt-4 rounded border-l-2 border-[var(--primary-color)] bg-[var(--primary-color)]/5 px-4 py-3 !text-xs !leading-6">Total transfer adalah harga paket ditambah kode unik 3 digit sebesar Rp1–Rp999.</p>

        <form @submit.prevent="submit" class="mt-7">
          <p v-if="error" role="alert" class="mb-5 border-l-2 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
          <label class="!mb-0 flex cursor-pointer items-start gap-3 rounded border border-[var(--border)] bg-white p-4 text-xs !leading-6 !text-[var(--paragraph)]">
            <input v-model="accepted" type="checkbox" class="mt-1 size-4 accent-[var(--primary-color)]" />
            <span>Saya memahami detail paket dan menyetujui <a href="/syarat-ketentuan" class="font-semibold text-[var(--primary-color)] underline">Syarat &amp; Ketentuan</a> serta kebijakan pembayaran yang berlaku.</span>
          </label>
          <button :disabled="!accepted || submitting || !selected.price" class="btn-one mt-5 w-full justify-center">
            {{ submitting ? "Membuat pesanan…" : "Buat pesanan" }} <ArrowRight v-if="!submitting" aria-hidden="true" :size="17" />
          </button>
        </form>
        <p class="mt-5 flex items-start gap-2 !text-xs !leading-5"><ShieldCheck aria-hidden="true" class="mt-0.5 shrink-0" :size="15" /> Harga disalin ke pesanan sehingga perubahan harga berikutnya tidak mengubah pesanan ini.</p>
      </section>
    </div>
  </main>
</template>
