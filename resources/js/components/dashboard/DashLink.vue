<script setup lang="ts">
import { computed } from "vue";
import { useRouter } from "vue-router";

// Client-side navigation for dashboard pages in Vue; a full page load for any other path.
// Rendered as a plain <a> in both cases so RouterLink adds no attributes (aria-current, active classes) of its own.
defineOptions({ inheritAttrs: false });
const props = defineProps<{ to: string }>();
const router = useRouter();
// A bare layout match (e.g. /dashboard before its own page exists) is not a page.
const inVue = computed(() => router.resolve(props.to).matched.at(-1)?.children.length === 0);

function onClick(event: MouseEvent) {
  if (!inVue.value || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  event.preventDefault();
  void router.push(props.to);
}
</script>

<template>
  <a v-bind="$attrs" :href="to" @click="onClick"><slot /></a>
</template>
