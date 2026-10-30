<script setup lang="ts">
withDefaults(defineProps<{ name: string; label: string; placeholder?: string; type?: string }>(), { type: "text", placeholder: undefined });
</script>

<template>
  <label class="block text-xs font-bold text-slate-700">{{ label }}<input :name="name" :type="type" :required="name !== 'description' && name !== 'summary'" :min="type === 'number' ? 1 : undefined" :placeholder="placeholder" class="form-control mt-1" /></label>
</template>
