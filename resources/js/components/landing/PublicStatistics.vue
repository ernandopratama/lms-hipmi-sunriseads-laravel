<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { Building2, CalendarDays, CheckCircle2, ShieldCheck } from "@lucide/vue";
import { vReveal } from "./reveal";

type PublicStatistic = {
  id: string;
  title: string;
  periodStart: string;
  periodEnd: string;
  cohortSize: number;
  metrics: {
    participatingStudents?: number;
    averageLearningProgress?: number;
    approvedStores?: number;
    approvedAffiliateContent?: number;
  };
  publishedAt: string;
  school?: { name?: string; city?: string | null; province?: string | null } | null;
};

const number = new Intl.NumberFormat("id-ID");

function formatPeriod(start: string, end: string) {
  const date = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", year: "numeric" });
  return `${date.format(new Date(start))}–${date.format(new Date(end))}`;
}

function dateTag(value: string) {
  const date = new Date(value);
  return { day: date.getDate(), month: new Intl.DateTimeFormat("id-ID", { month: "short" }).format(date) };
}

const statistics = ref<PublicStatistic[]>([]);
const state = ref<"loading" | "ready" | "error">("loading");
const controller = new AbortController();

onMounted(() => {
  fetch("/api/backend/public/statistics", { signal: controller.signal, cache: "no-store" })
    .then(async (response) => {
      if (!response.ok) throw new Error("Statistik belum dapat dimuat");
      return response.json() as Promise<PublicStatistic[]>;
    })
    .then((data) => {
      statistics.value = data;
      state.value = "ready";
    })
    .catch((error: unknown) => {
      if (error instanceof DOMException && error.name === "AbortError") return;
      state.value = "error";
    });
});

onUnmounted(() => controller.abort());
</script>

<template>
  <section aria-labelledby="public-statistics-title" class="blog-area section-pad">
    <div class="container">
      <div v-reveal class="section-header mx-auto mb-[60px] max-w-[780px] text-center">
        <h5>Dampak terverifikasi</h5>
        <h2 id="public-statistics-title">Angka yang boleh dilihat publik, tanpa mengorbankan <span>privasi. <img src="/images/landing/header-shape.png" alt="" /></span></h2>
        <p>Hanya ringkasan yang sudah ditinjau dan memenuhi ambang minimum peserta yang dapat tampil di halaman ini.</p>
      </div>

      <div aria-live="polite">
        <div v-if="state === 'loading'" class="empty-box text-sm font-medium">Memuat statistik terverifikasi…</div>
        <div v-if="state === 'error'" role="status" class="empty-box text-sm">Statistik publik sedang tidak tersedia. Data internal sekolah tetap terlindungi.</div>
        <div v-if="state === 'ready' && statistics.length === 0" class="empty-box">
          <div>
            <CheckCircle2 aria-hidden="true" class="mx-auto text-[#2eb97e]" :size="32" />
            <h3 class="mt-4">Belum ada data yang dipublikasikan</h3>
            <p class="mx-auto mt-2 max-w-lg">Ringkasan baru akan tampil setelah memenuhi ambang privasi dan selesai ditinjau.</p>
          </div>
        </div>
        <div v-if="state === 'ready' && statistics.length > 0" class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="statistic in statistics.slice(0, 4)" :key="statistic.id" v-reveal class="blog__item">
            <div class="blog__head">
              <div class="flex items-start justify-between gap-4">
                <h3>{{ statistic.title }}</h3>
                <CheckCircle2 aria-label="Sudah disetujui" class="mt-1 shrink-0 text-[#2eb97e]" :size="22" />
              </div>
              <div class="blog-tag" aria-hidden="true"><strong>{{ dateTag(statistic.periodStart).day }}</strong><small>{{ dateTag(statistic.periodStart).month }}</small></div>
            </div>
            <ul class="blog-info">
              <li><Building2 aria-hidden="true" :size="16" />{{ statistic.school?.name || "Program Sunrise Academy" }}{{ statistic.school?.city ? ` · ${statistic.school.city}` : "" }}</li>
              <li><CalendarDays aria-hidden="true" :size="16" />{{ formatPeriod(statistic.periodStart, statistic.periodEnd) }}</li>
            </ul>
            <dl class="stat-list">
              <div><dt>Peserta agregat</dt><dd>{{ number.format(statistic.metrics?.participatingStudents ?? statistic.cohortSize) }}</dd></div>
              <div><dt>Progres rata-rata</dt><dd>{{ number.format(statistic.metrics?.averageLearningProgress ?? 0) }}%</dd></div>
              <div><dt>Toko disetujui</dt><dd>{{ number.format(statistic.metrics?.approvedStores ?? 0) }}</dd></div>
              <div><dt>Konten terverifikasi</dt><dd>{{ number.format(statistic.metrics?.approvedAffiliateContent ?? 0) }}</dd></div>
            </dl>
          </article>
        </div>
      </div>

      <div v-reveal class="privacy-note mx-auto max-w-[780px]">
        <ShieldCheck aria-hidden="true" :size="20" />Nama siswa, nilai individual, dokumen identitas, dan kredensial toko tidak pernah menjadi statistik publik.
      </div>
    </div>
  </section>
</template>
