<script setup lang="ts">
import { computed } from "vue";
import { number, type Metric } from "./shared";

const props = defineProps<{ title: string; items: Metric[]; emptyText: string }>();
const max = computed(() => Math.max(...props.items.map((item) => item.count), 1));
</script>

<template>
  <section class="rounded-2xl border border-[#E4F1EE] bg-white p-5 shadow-sm">
    <h3 class="font-bold text-slate-900">{{ title }}</h3>
    <p v-if="items.length === 0" class="mt-5 text-sm text-slate-500">{{ emptyText }}</p>
    <div v-else class="mt-5 space-y-4">
      <div v-for="item in items" :key="item.key">
        <div class="mb-1.5 flex items-center justify-between gap-3 text-xs">
          <span class="font-medium text-slate-600">{{ item.key.replaceAll("_", " ") }}</span>
          <span class="font-bold text-slate-900">{{ number(item.count) }}</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500" :style="{ width: `${Math.max((item.count / max) * 100, 4)}%` }" />
        </div>
      </div>
    </div>
  </section>
</template>
