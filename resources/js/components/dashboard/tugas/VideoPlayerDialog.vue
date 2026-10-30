<script lang="ts">
/* eslint-disable @typescript-eslint/no-explicit-any -- payload shapes are untyped in the source app too */
const REPORT_INTERVAL_MS = 5000;
// Module-level so every dialog instance shares one script tag per provider.
const scriptLoaders: Record<string, Promise<any>> = {};

function loadScript(key: string, src: string, ready: () => any) {
  const existing = ready();
  if (existing) return Promise.resolve(existing);
  scriptLoaders[key] ??= new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = src;
    script.async = true;
    script.onload = () => {
      const api = ready();
      if (api) resolve(api);
    };
    script.onerror = () => reject(new Error("Pemutar video tidak dapat dimuat."));
    document.head.appendChild(script);
  });
  return scriptLoaders[key];
}

function loadYouTube() {
  const win = window as any;
  if (win.YT?.Player) return Promise.resolve(win.YT);
  scriptLoaders.youtube ??= new Promise((resolve, reject) => {
    const previous = win.onYouTubeIframeAPIReady;
    win.onYouTubeIframeAPIReady = () => {
      previous?.();
      resolve(win.YT);
    };
    const script = document.createElement("script");
    script.src = "https://www.youtube.com/iframe_api";
    script.async = true;
    script.onerror = () => reject(new Error("Pemutar video tidak dapat dimuat."));
    document.head.appendChild(script);
  });
  return scriptLoaders.youtube;
}
</script>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { CheckCircle, X } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type PlayerHandle = { position: () => Promise<number>; duration: () => Promise<number>; destroy: () => void };

const props = defineProps<{
  material: { id: string; title: string; video: any };
  resumeSeconds: number;
  initial?: any;
  onSnapshot: (snapshot: any) => void;
  onClose: () => void;
}>();

const container = ref<HTMLDivElement | null>(null);
let player: PlayerHandle | null = null;
let interval: ReturnType<typeof setInterval> | null = null;
let playing = false;
const loaded = ref(false);
const component = ref<any>(props.initial || null);
const error = ref("");
const saving = ref(false);
const providerLabel = computed(() => (props.material.video.provider === "YOUTUBE" ? "YouTube" : "Vimeo"));

async function report(isPlaying: boolean) {
  const current = player;
  if (!current) return;
  try {
    const [position, duration] = await Promise.all([current.position(), current.duration()]);
    saving.value = true;
    const response = await csrfFetch(`/api/backend/learning/materials/${props.material.id}/progress`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ kind: "VIDEO", position, durationSeconds: duration >= 10 ? Math.round(duration) : undefined, playing: isPlaying }),
    });
    if (!response.ok) throw new Error("Progres menonton belum dapat disimpan.");
    const data = await response.json();
    component.value = data.component;
    error.value = "";
    props.onSnapshot(data);
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Progres menonton belum dapat disimpan.";
  } finally {
    saving.value = false;
  }
}

const stopTimer = () => {
  if (interval) clearInterval(interval);
  interval = null;
};

const handlePlay = () => {
  if (playing) return;
  playing = true;
  void report(false);
  stopTimer();
  interval = setInterval(() => { void report(true); }, REPORT_INTERVAL_MS);
};

const handlePause = () => {
  if (!playing) return;
  playing = false;
  stopTimer();
  void report(true);
};

watch(loaded, (isLoaded, _previous, onCleanup) => {
  if (!isLoaded || !container.value) return;
  let cancelled = false;
  const host = container.value;
  void (async () => {
    try {
      if (props.material.video.provider === "YOUTUBE") {
        const YT = await loadYouTube();
        if (cancelled) return;
        const mount = document.createElement("div");
        host.appendChild(mount);
        const instance = new YT.Player(mount, {
          host: "https://www.youtube-nocookie.com",
          videoId: props.material.video.videoId,
          width: "100%",
          height: "100%",
          playerVars: { rel: 0, playsinline: 1, start: Math.max(0, Math.floor(props.resumeSeconds)), origin: window.location.origin },
          events: {
            onStateChange: (event: any) => {
              if (event.data === 1) handlePlay();
              else if (event.data === 2 || event.data === 0) handlePause();
            },
          },
        });
        player = { position: async () => Number(instance.getCurrentTime?.() || 0), duration: async () => Number(instance.getDuration?.() || 0), destroy: () => instance.destroy?.() };
      } else {
        const Vimeo = await loadScript("vimeo", "https://player.vimeo.com/api/player.js", () => (window as any).Vimeo);
        if (cancelled) return;
        const iframe = document.createElement("iframe");
        iframe.src = props.material.video.embedUrl;
        iframe.className = "h-full w-full";
        iframe.allow = "autoplay; fullscreen; picture-in-picture";
        iframe.title = props.material.title;
        host.appendChild(iframe);
        const instance = new Vimeo.Player(iframe);
        if (props.resumeSeconds > 1) instance.setCurrentTime(Math.floor(props.resumeSeconds)).catch(() => {});
        instance.on("play", handlePlay);
        instance.on("pause", handlePause);
        instance.on("ended", handlePause);
        player = { position: () => instance.getCurrentTime(), duration: () => instance.getDuration(), destroy: () => { instance.destroy(); } };
      }
    } catch (caught) {
      error.value = caught instanceof Error ? caught.message : "Pemutar video tidak dapat dimuat.";
    }
  })();
  onCleanup(() => {
    cancelled = true;
    handlePause();
    stopTimer();
    // Delay teardown so the final progress report can still read the player position.
    window.setTimeout(() => {
      player?.destroy();
      player = null;
      host.replaceChildren();
    }, 700);
  });
});

const closeOnEscape = (event: KeyboardEvent) => {
  if (event.key === "Escape") props.onClose();
};
onMounted(() => window.addEventListener("keydown", closeOnEscape));
onUnmounted(() => window.removeEventListener("keydown", closeOnEscape));

const percent = computed(() => Math.max(0, Math.min(100, Number(component.value?.percent || 0))));
const threshold = computed(() => Number(component.value?.threshold || props.material.video.completionThreshold || 90));
const completed = computed(() => component.value?.state === "COMPLETED");
</script>

<template>
  <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/80 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-labelledby="video-viewer-title">
    <div class="flex max-h-[96vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
      <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-6">
        <div class="min-w-0">
          <p class="text-xs font-bold uppercase tracking-wide text-orange-600">Video · {{ providerLabel }}</p>
          <h3 id="video-viewer-title" class="truncate text-base font-extrabold text-slate-900 sm:text-lg">{{ material.title }}</h3>
        </div>
        <button type="button" @click="onClose()" aria-label="Tutup video" class="grid size-10 shrink-0 place-items-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-900"><X aria-hidden="true" :size="21" /></button>
      </header>
      <div class="min-h-0 flex-1 bg-slate-950">
        <div class="relative mx-auto aspect-video w-full max-w-4xl">
          <div ref="container" class="absolute inset-0 [&_iframe]:h-full [&_iframe]:w-full" />
          <div v-if="!loaded" class="absolute inset-0 grid place-items-center bg-slate-900 p-6 text-center text-white">
            <div>
              <p class="text-sm text-slate-300">Memutar video memuat layanan pihak ketiga ({{ providerLabel }}).</p>
              <button type="button" @click="loaded = true" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-orange-600 px-6 text-sm font-bold text-white hover:bg-orange-700">Muat pemutar</button>
            </div>
          </div>
        </div>
      </div>
      <footer class="border-t border-slate-200 bg-white px-4 py-3 sm:px-6">
        <div class="mb-2 flex items-center justify-between gap-3 text-xs">
          <span class="font-medium text-slate-500">{{ saving ? "Menyimpan progres…" : completed ? "Video sudah ditonton" : `Tonton minimal ${threshold}% video · tercatat ${Number(component?.coveredPercent || 0)}%` }}</span>
          <span class="font-bold text-orange-700"><CheckCircle v-if="completed" :size="14" class="mr-1 inline" aria-hidden="true" />{{ percent }}%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuemin="0" :aria-valuemax="100" :aria-valuenow="percent" :aria-label="`Progres menonton ${percent}%`">
          <div class="h-full rounded-full bg-orange-500 transition-[width]" :style="{ width: `${percent}%` }" />
        </div>
        <p class="mt-2 text-xs text-slate-500">Progres dihitung dari bagian yang benar-benar diputar; melompat ke depan tidak dihitung.</p>
        <p v-if="error" role="alert" class="mt-2 text-xs font-semibold text-red-600">{{ error }}</p>
      </footer>
    </div>
  </div>
</template>
