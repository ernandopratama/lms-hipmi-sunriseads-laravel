<script setup lang="ts">
import { computed } from "vue";

/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const props = defineProps<{ components?: any; lessonTitle: string }>();

const COMPONENT_LABELS = [["video", "Video"], ["reading", "Materi"], ["quiz", "Tanya jawab"]] as const;
const items = computed(() => COMPONENT_LABELS.filter(([key]) => props.components?.[key]));
const percentOf = (key: string) => Math.max(0, Math.min(100, Number(props.components?.[key]?.percent || 0)));
</script>

<template>
  <ul v-if="items.length" class="mt-2 flex flex-wrap gap-x-4 gap-y-2" :aria-label="`Rincian progres ${lessonTitle}`">
    <li v-for="[key, label] in items" :key="key" class="min-w-[8rem] flex-1 text-xs">
      <div class="flex items-center justify-between">
        <span class="font-medium text-slate-500">{{ components?.[key]?.state === "COMPLETED" ? "✓ " : "" }}{{ label }}</span>
        <span class="font-bold text-orange-700">{{ percentOf(key) }}%</span>
      </div>
      <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-orange-100" role="progressbar" :aria-valuemin="0" :aria-valuemax="100" :aria-valuenow="percentOf(key)" :aria-label="`${label} ${percentOf(key)}%`">
        <div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${percentOf(key)}%` }" />
      </div>
    </li>
  </ul>
</template>
