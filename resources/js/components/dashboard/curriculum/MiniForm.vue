<script setup lang="ts">
import { Plus } from "@lucide/vue";

defineProps<{ title: string }>();
const emit = defineEmits<{ submit: [event: Event] }>();
</script>

<template>
  <form @submit.prevent="emit('submit', $event)" class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm"><h3 class="mb-3 flex items-center gap-2 font-bold text-slate-800"><Plus aria-hidden="true" :size="17" class="text-orange-600" />{{ title }}</h3><div class="space-y-3"><slot /></div><button class="mt-4 min-h-11 w-full rounded-xl bg-orange-600 px-3 text-sm font-bold text-white">Simpan</button></form>
</template>
