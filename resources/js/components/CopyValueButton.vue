<script setup lang="ts">
import { onUnmounted, ref } from "vue";
import { Check, Copy } from "@lucide/vue";

const props = withDefaults(defineProps<{ value: string; label?: string }>(), { label: "Salin" });
const copied = ref(false);
let timer: number | undefined;

async function copy() {
  try {
    await navigator.clipboard.writeText(props.value);
    copied.value = true;
    window.clearTimeout(timer);
    timer = window.setTimeout(() => { copied.value = false; }, 1800);
  } catch {
    copied.value = false;
  }
}

onUnmounted(() => window.clearTimeout(timer));
</script>

<template>
  <button
    type="button"
    @click="copy"
    class="inline-flex min-h-9 items-center justify-center gap-2 rounded-md border border-slate-900/15 bg-white px-3 text-xs font-extrabold text-slate-700 transition hover:border-orange-400 hover:text-orange-700"
    :aria-label="`${label}: ${value}`"
  >
    <Check v-if="copied" :size="14" aria-hidden="true" />
    <Copy v-else :size="14" aria-hidden="true" />
    {{ copied ? "Tersalin" : label }}
  </button>
</template>
