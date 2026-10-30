<script setup lang="ts">
import { computed, type Component } from "vue";
import { AlertTriangle, ArrowRight, BookOpenCheck, CheckCircle2, Database, Eye, FileSpreadsheet, GraduationCap, MousePointerClick, ShoppingBag } from "@lucide/vue";
import DashLink from "@/components/dashboard/DashLink.vue";
import type { Overview } from "./shared";

const props = defineProps<{ audience: Overview["audience"] }>();

const actionsByAudience: Record<Overview["audience"], Array<{ href: string; label: string; helper: string; icon: Component; tone: string }>> = {
  STUDENT: [
    { href: "/dashboard/tugas", label: "Lanjut belajar", helper: "Buka materi dan tugas", icon: BookOpenCheck, tone: "bg-blue-50 text-blue-700" },
    { href: "/dashboard/toko", label: "Kelola toko", helper: "Lengkapi praktik tokomu", icon: ShoppingBag, tone: "bg-orange-50 text-orange-700" },
    { href: "/dashboard/affiliate", label: "Cek affiliate", helper: "Lihat konten dan performa", icon: MousePointerClick, tone: "bg-emerald-50 text-emerald-700" },
  ],
  PARENT: [
    { href: "/dashboard/dukungan", label: "Pusat bantuan", helper: "Cari panduan pendampingan", icon: CheckCircle2, tone: "bg-blue-50 text-blue-700" },
    { href: "/dashboard/privasi", label: "Privasi anak", helper: "Tinjau persetujuan data", icon: Eye, tone: "bg-orange-50 text-orange-700" },
  ],
  TEACHER: [
    { href: "/dashboard/review", label: "Review tugas", helper: "Buka antrean penilaian", icon: CheckCircle2, tone: "bg-emerald-50 text-emerald-700" },
    { href: "/dashboard/tugas", label: "Kelola materi", helper: "Perbarui materi dan tugas", icon: BookOpenCheck, tone: "bg-blue-50 text-blue-700" },
    { href: "/dashboard/kurikulum", label: "Atur rubrik", helper: "Kelola struktur penilaian", icon: GraduationCap, tone: "bg-orange-50 text-orange-700" },
  ],
  SCHOOL: [
    { href: "/dashboard/master-data", label: "Data sekolah", helper: "Kelola murid, guru, dan kelas", icon: Database, tone: "bg-blue-50 text-blue-700" },
    { href: "/dashboard/monitoring", label: "Pendampingan", helper: "Tinjau murid yang perlu dukungan", icon: AlertTriangle, tone: "bg-amber-50 text-amber-700" },
    { href: "/dashboard/laporan", label: "Unduh laporan", helper: "Siapkan data untuk instansi", icon: FileSpreadsheet, tone: "bg-emerald-50 text-emerald-700" },
  ],
  PROGRAM: [
    { href: "/dashboard/master-data", label: "Data sekolah", helper: "Kelola data lintas sekolah", icon: Database, tone: "bg-blue-50 text-blue-700" },
    { href: "/dashboard/monitoring", label: "Monitoring program", helper: "Tinjau sinyal pendampingan", icon: AlertTriangle, tone: "bg-amber-50 text-amber-700" },
    { href: "/dashboard/laporan", label: "Laporan program", helper: "Ekspor ringkasan instansi", icon: FileSpreadsheet, tone: "bg-emerald-50 text-emerald-700" },
  ],
};

const actions = computed(() => actionsByAudience[props.audience]);
</script>

<template>
  <section class="mt-6" aria-labelledby="quick-actions-heading">
    <div class="mb-3 flex items-end justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-orange-700">Akses cepat</p>
        <h2 id="quick-actions-heading" class="mt-1 text-lg font-extrabold text-slate-950">Mau mengerjakan apa?</h2>
      </div>
      <p class="hidden text-xs text-slate-500 sm:block">Pilih tujuan untuk langsung mulai</p>
    </div>
    <div :class="['grid gap-3', actions.length === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3']">
      <DashLink v-for="action in actions" :key="action.href" :to="action.href" class="group flex min-h-24 items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md">
        <span :class="['grid size-11 shrink-0 place-items-center rounded-xl', action.tone]"><component :is="action.icon" class="size-5" aria-hidden="true" /></span>
        <span class="min-w-0 flex-1"><span class="block font-extrabold text-slate-900">{{ action.label }}</span><span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ action.helper }}</span></span>
        <ArrowRight class="size-4 shrink-0 text-slate-300 transition group-hover:translate-x-1 group-hover:text-orange-600" aria-hidden="true" />
      </DashLink>
    </div>
  </section>
</template>
