<script setup lang="ts">
defineProps<{ label: string; value: string; strong?: boolean }>();
</script>

<template>
  <div class="flex items-center justify-between gap-6 py-4"><dt class="text-slate-500">{{ label }}</dt><dd :class="['text-right', strong ? 'font-extrabold text-slate-950' : 'font-bold text-slate-700']">{{ value }}</dd></div>
</template>
