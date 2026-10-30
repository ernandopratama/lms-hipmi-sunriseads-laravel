<script setup lang="ts">
import type { Component } from "vue";

withDefaults(defineProps<{ label: string; value: string; helper: string; icon: Component; tone?: "orange" | "blue" | "green" | "amber" }>(), { tone: "orange" });

const tones = {
  orange: { icon: "bg-orange-50 text-orange-700", line: "bg-orange-500" },
  blue: { icon: "bg-blue-50 text-blue-700", line: "bg-blue-500" },
  green: { icon: "bg-emerald-50 text-emerald-700", line: "bg-emerald-500" },
  amber: { icon: "bg-amber-50 text-amber-700", line: "bg-amber-500" },
};
</script>

<template>
  <article class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
    <span :class="['absolute inset-x-0 top-0 h-1', tones[tone].line]" />
    <div class="flex items-start justify-between gap-4">
      <div>
        <p class="text-sm font-medium text-slate-500">{{ label }}</p>
        <p class="mt-2 text-3xl font-bold tracking-tight text-slate-900">{{ value }}</p>
      </div>
      <span :class="['rounded-xl p-3', tones[tone].icon]"><component :is="icon" class="h-5 w-5" aria-hidden="true" /></span>
    </div>
    <p class="mt-3 text-xs leading-5 text-slate-500">{{ helper }}</p>
  </article>
</template>
