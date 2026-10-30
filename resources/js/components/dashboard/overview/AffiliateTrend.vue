<script setup lang="ts">
import { computed } from "vue";
import { number, type TrendPoint } from "./shared";

const props = defineProps<{ points: TrendPoint[] }>();
const width = 720;
const height = 220;
const max = computed(() => Math.max(...props.points.map((point) => point.views), 1));
const x = (index: number) => props.points.length === 1 ? width / 2 : (index / (props.points.length - 1)) * width;
const y = (point: TrendPoint) => height - (point.views / max.value) * (height - 28) - 14;
const polyline = computed(() => props.points.map((point, index) => `${x(index)},${y(point)}`).join(" "));
</script>

<template>
  <section class="rounded-2xl border border-[#E4F1EE] bg-white p-5 shadow-sm lg:col-span-2">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h3 class="font-bold text-slate-900">Riwayat performa affiliate</h3>
        <p class="mt-1 text-xs text-slate-500">Views dari snapshot terverifikasi pada periode terpilih.</p>
      </div>
      <div class="flex gap-4 text-xs text-slate-500">
        <span><strong class="text-slate-900">{{ number(points.reduce((sum, point) => sum + point.clicks, 0)) }}</strong> clicks</span>
        <span><strong class="text-slate-900">{{ number(points.reduce((sum, point) => sum + point.orders, 0)) }}</strong> orders</span>
      </div>
    </div>
    <div v-if="points.length === 0" class="mt-5 flex min-h-52 items-center justify-center rounded-xl bg-slate-50 text-sm text-slate-500">Belum ada snapshot performa pada periode ini.</div>
    <div v-else class="mt-5 overflow-x-auto" role="img" aria-label="Grafik tren views affiliate">
      <svg :viewBox="`0 0 ${width} ${height}`" class="min-w-[620px] w-full" aria-hidden="true">
        <line v-for="ratio in [0.25, 0.5, 0.75, 1]" :key="ratio" x1="0" :x2="width" :y1="height - ratio * (height - 28) - 14" :y2="height - ratio * (height - 28) - 14" stroke="#E2E8F0" stroke-width="1" />
        <polyline fill="none" stroke="#377C78" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" :points="polyline" />
        <circle v-for="(point, index) in points" :key="`${point.date}-${index}`" :cx="x(index)" :cy="y(point)" r="5" fill="#FFF" stroke="#286864" stroke-width="3"><title>{{ `${point.date}: ${point.views} views` }}</title></circle>
      </svg>
      <div class="mt-2 flex justify-between text-[11px] text-slate-400"><span>{{ points[0]?.date }}</span><span>{{ points.at(-1)?.date }}</span></div>
    </div>
  </section>
</template>
