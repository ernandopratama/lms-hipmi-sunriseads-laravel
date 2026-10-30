<script setup lang="ts">
withDefaults(defineProps<{ name: string; label: string; options: Array<{ value: string; label: string }>; optional?: boolean; optionalLabel?: string }>(), { optional: false, optionalLabel: "Tanpa program" });
</script>

<template>
  <label class="block text-xs font-bold text-slate-700">{{ label }}<select :name="name" :required="!optional" class="form-control mt-1"><option value="">{{ optional ? optionalLabel : "Pilih" }}</option><option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
</template>
