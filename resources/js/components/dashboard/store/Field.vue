<script setup lang="ts">
defineProps<{ label: string }>();
</script>

<template>
  <label class="block text-xs font-bold text-slate-700">{{ label }}<span class="mt-1 block"><slot /></span></label>
</template>
