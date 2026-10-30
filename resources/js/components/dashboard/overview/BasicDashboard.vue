<script setup lang="ts">
import { computed } from "vue";
import { ArrowRight, BookOpenCheck, CheckCircle2, Clock3, Eye, GraduationCap, MousePointerClick, School, Users } from "@lucide/vue";
import MetricCard from "./MetricCard.vue";
import QuickActions from "./QuickActions.vue";
import { audienceCopy, number, type BasicDashboardData, type Overview } from "./shared";

const props = defineProps<{ audience: Overview["audience"]; data: BasicDashboardData | null }>();

const copy = computed(() => audienceCopy[props.audience]);
const cards = computed(() => {
  const { audience, data } = props;
  return audience === "STUDENT" ? [
    { label: "Tugas aktif", value: number(data?.activeAssignmentsCount || 0), helper: "Tugas yang masih berada dalam periode pengerjaan", icon: BookOpenCheck, tone: "blue" as const },
    { label: "Perlu dikerjakan", value: number(data?.pendingTasksCount || 0), helper: "Tugas aktif yang belum dikumpulkan", icon: Clock3, tone: "amber" as const },
    { label: "Konten affiliate", value: number(data?.recentVideosCount || 0), helper: "Konten yang tercatat pada akunmu", icon: MousePointerClick, tone: "green" as const },
    { label: "Tayangan terverifikasi", value: number(data?.kpi?.views || 0), helper: "Snapshot performa terbaru yang sudah diverifikasi", icon: Eye, tone: "orange" as const },
  ] : audience === "TEACHER" ? [
    { label: "Siswa dampingan", value: number(data?.kpi?.totalStudents || 0), helper: "Siswa dalam kelas dan course yang Anda ampu", icon: Users, tone: "blue" as const },
    { label: "Antrean review", value: number(data?.kpi?.pendingReviewsCount || 0), helper: "Tugas yang menunggu penilaian", icon: CheckCircle2, tone: "green" as const },
    { label: "Tugas aktif", value: number(data?.kpi?.activeAssignmentsCount || 0), helper: "Tugas yang masih berjalan", icon: BookOpenCheck, tone: "orange" as const },
  ] : audience === "SCHOOL" || audience === "PROGRAM" ? [
    { label: "Siswa", value: number(data?.stats?.totalStudents || 0), helper: "Siswa pada sekolah aktif", icon: Users, tone: "blue" as const },
    { label: "Guru", value: number(data?.stats?.totalTeachers || 0), helper: "Guru pada sekolah aktif", icon: GraduationCap, tone: "green" as const },
    { label: "Kelas", value: number(data?.stats?.totalClasses || 0), helper: "Kelas yang tercatat", icon: School, tone: "orange" as const },
  ] : [];
});
</script>

<template>
  <main class="mx-auto max-w-[1600px] p-4 sm:p-6 lg:p-8">
    <header class="relative overflow-hidden rounded-[28px] bg-slate-950 p-6 text-white shadow-sm lg:p-8">
      <div class="pointer-events-none absolute -right-20 -top-36 size-80 rounded-full bg-orange-500/25 blur-3xl" />
      <div class="relative max-w-3xl">
        <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-amber-300">Akses dasar aktif</p>
        <h2 class="mt-2 text-2xl font-extrabold tracking-[-0.03em] text-white sm:text-3xl lg:text-4xl">{{ copy.title }}</h2>
        <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">{{ copy.subtitle }}</p>
      </div>
    </header>

    <QuickActions :audience="audience" />

    <section v-if="cards.length > 0" class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <MetricCard v-for="card in cards" :key="card.label" v-bind="card" />
    </section>

    <section class="mt-6 flex flex-col gap-4 rounded-2xl border border-orange-200 bg-orange-50 p-5 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="font-bold text-slate-900">Fitur utama siap digunakan</h3>
        <p class="mt-1 text-sm leading-6 text-slate-600">Materi, tugas, praktik toko, affiliate, dan penilaian tetap mengikuti akses sekolah. Analitik lanjutan serta ekspor tersedia sesuai paket.</p>
      </div>
      <a href="/paket" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-bold text-white hover:bg-orange-700">Lihat detail paket <ArrowRight aria-hidden="true" class="size-4" /></a>
    </section>
  </main>
</template>
