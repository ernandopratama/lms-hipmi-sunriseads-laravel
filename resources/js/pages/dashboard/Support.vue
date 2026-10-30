<script setup lang="ts">
import { onMounted, ref } from "vue";
import { Award, Bell, Check, Download, HelpCircle, Loader2, Megaphone, Settings2, ShieldCheck } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type Notification = {
  id: string;
  title: string;
  body: string;
  actionUrl?: string | null;
  readAt?: string | null;
  createdAt: string;
};
type Announcement = {
  id: string;
  title: string;
  body: string;
  audience: string;
  publishedAt?: string | null;
};
type Certificate = {
  id: string;
  title: string;
  description?: string | null;
  status: string;
  verificationCode: string;
  publicVisible: boolean;
  issuedAt?: string | null;
  student: { fullName: string; studentNumber?: string };
  template?: { name: string };
};
type Achievement = {
  id: string;
  awardedAt: string;
  achievement: { title: string; description: string; icon: string };
  student: { fullName: string };
};
type Portfolio = {
  id: string;
  title: string;
  description?: string | null;
  sourceType: string;
  status: string;
  publicSlug?: string | null;
  student: { fullName: string };
};
type Ticket = {
  id: string;
  ticketNumber: string;
  category: string;
  subject: string;
  priority: string;
  status: string;
  updatedAt: string;
  _count: { messages: number };
};
type TicketDetail = Ticket & {
  description: string;
  resolution?: string | null;
  messages: Array<{ id: string; body: string; isInternal: boolean; createdAt: string; fileName?: string | null; mimeType?: string | null; scanStatus?: string | null; author: { firstName: string; lastName: string } }>;
};
type Preference = {
  emailEnabled: boolean;
  whatsappEnabled: boolean;
  smsEnabled: boolean;
  digestFrequency: string;
  quietHoursStart?: string | null;
  quietHoursEnd?: string | null;
};

async function readJson<T>(response: Response): Promise<T> {
  const data = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new Error(
      (data as { message?: string }).message ||
        "Permintaan belum dapat diproses",
    );
  return data as T;
}

const channels = [
  ["emailEnabled", "Email"],
  ["whatsappEnabled", "WhatsApp"],
  ["smsEnabled", "SMS"],
] as const;
const ticketStatuses = [
  ["OPEN", "Terbuka"],
  ["IN_PROGRESS", "Ditangani"],
  ["WAITING_REQUESTER", "Menunggu pemohon"],
  ["RESOLVED", "Selesai"],
  ["CLOSED", "Ditutup"],
] as const;

const notifications = ref<Notification[]>([]);
const announcements = ref<Announcement[]>([]);
const certificates = ref<Certificate[]>([]);
const achievements = ref<Achievement[]>([]);
const portfolio = ref<Portfolio[]>([]);
const tickets = ref<Ticket[]>([]);
const selectedTicket = ref<TicketDetail | null>(null);
const isStaff = ref(false);
const hasAdvanced = ref(false);
const preference = ref<Preference>({
  emailEnabled: false,
  whatsappEnabled: false,
  smsEnabled: false,
  digestFrequency: "IMMEDIATE",
});
const loading = ref(true);
const busy = ref("");
const message = ref("");
const error = ref("");

async function load() {
  error.value = "";
  const results = await Promise.allSettled([
    fetch("/api/backend/support/notifications").then(readJson<Notification[]>),
    fetch("/api/backend/support/announcements").then(readJson<Announcement[]>),
    fetch("/api/backend/support/certificates").then(readJson<Certificate[]>),
    fetch("/api/backend/support/achievements").then(readJson<Achievement[]>),
    fetch("/api/backend/support/portfolio").then(readJson<Portfolio[]>),
    fetch("/api/backend/support/helpdesk").then(readJson<Ticket[]>),
    fetch("/api/backend/support/notification-preferences").then(readJson<Preference>),
    fetch("/api/session").then(readJson<{ roles?: string[] }>),
    fetch("/api/backend/access/effective").then(readJson<{ entitlements?: Record<string, unknown> }>),
  ]);

  const [notificationsResult, announcementsResult, certificatesResult, achievementsResult, portfolioResult, ticketsResult, preferenceResult, sessionResult, accessResult] = results;
  if (notificationsResult.status === "fulfilled") notifications.value = notificationsResult.value;
  if (announcementsResult.status === "fulfilled") announcements.value = announcementsResult.value;
  if (certificatesResult.status === "fulfilled") certificates.value = certificatesResult.value;
  if (achievementsResult.status === "fulfilled") achievements.value = achievementsResult.value;
  if (portfolioResult.status === "fulfilled") portfolio.value = portfolioResult.value;
  if (ticketsResult.status === "fulfilled") tickets.value = ticketsResult.value;
  if (preferenceResult.status === "fulfilled") preference.value = preferenceResult.value;
  if (sessionResult.status === "fulfilled") {
    isStaff.value = (sessionResult.value.roles || []).some((role) => ["super-admin", "school-admin", "program-manager", "principal", "support"].includes(role));
  }
  const advancedEnabled = accessResult.status === "fulfilled" && accessResult.value.entitlements?.["support.advanced.use"] === true;
  hasAdvanced.value = advancedEnabled;
  if ([notificationsResult, announcementsResult, ticketsResult, preferenceResult, sessionResult, accessResult].some((result) => result.status === "rejected") || (advancedEnabled && [certificatesResult, achievementsResult, portfolioResult].some((result) => result.status === "rejected"))) {
    error.value = "Sebagian informasi belum dapat dimuat. Daftar yang tersedia tetap ditampilkan.";
  }
  loading.value = false;
}

onMounted(load);

async function savePreference() {
  busy.value = "preference";
  error.value = "";
  try {
    const response = await csrfFetch(
      "/api/backend/support/notification-preferences",
      {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(preference.value),
      },
    );
    preference.value = await readJson<Preference>(response);
    message.value = "Preferensi notifikasi tersimpan. Notifikasi dalam aplikasi tetap aktif untuk pesan penting.";
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Preferensi belum tersimpan";
  }
  busy.value = "";
}

async function markRead(id: string) {
  await csrfFetch(`/api/backend/support/notifications/${id}/read`, {
    method: "PATCH",
  });
  notifications.value = notifications.value.map((item) =>
    item.id === id ? { ...item, readAt: new Date().toISOString() } : item,
  );
}

async function downloadCertificate(id: string) {
  busy.value = id;
  error.value = "";
  try {
    const result = await readJson<{ url: string }>(
      await fetch(`/api/backend/support/certificates/${id}/download`),
    );
    window.open(result.url, "_blank", "noopener,noreferrer");
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Sertifikat belum dapat diunduh";
  }
  busy.value = "";
}

async function togglePublication(
  kind: "certificates" | "portfolio",
  item: Certificate | Portfolio,
) {
  busy.value = item.id;
  error.value = "";
  const visible =
    kind === "certificates"
      ? !(item as Certificate).publicVisible
      : (item as Portfolio).status !== "PUBLISHED";
  try {
    await readJson(
      await csrfFetch(`/api/backend/support/${kind}/${item.id}/publication`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ publicVisible: visible }),
      }),
    );
    message.value = visible
      ? "Karya berhasil dipublikasikan dengan persetujuan aktif."
      : "Publikasi telah dinonaktifkan.";
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Pilihan publikasi belum tersimpan";
  }
  busy.value = "";
}

async function createTicket(event: Event) {
  const form = event.currentTarget as HTMLFormElement;
  const data = new FormData(form);
  busy.value = "ticket";
  error.value = "";
  try {
    await readJson(
      await csrfFetch("/api/backend/support/helpdesk", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          category: data.get("category"),
          priority: data.get("priority"),
          subject: data.get("subject"),
          description: data.get("description"),
        }),
      }),
    );
    form.reset();
    message.value = "Tiket bantuan berhasil dibuat. Data sensitif otomatis disamarkan.";
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Tiket belum dapat dibuat";
  }
  busy.value = "";
}

async function openTicket(id: string) {
  busy.value = id; error.value = "";
  try { selectedTicket.value = await readJson<TicketDetail>(await fetch(`/api/backend/support/helpdesk/${id}`)); }
  catch (caught) { error.value = caught instanceof Error ? caught.message : "Detail tiket belum dapat dimuat"; }
  busy.value = "";
}

async function replyTicket(event: Event) {
  if (!selectedTicket.value) return; const ticket = selectedTicket.value; const form = event.currentTarget as HTMLFormElement; const data = new FormData(form); busy.value = "reply";
  try {
    await readJson(await csrfFetch(`/api/backend/support/helpdesk/${ticket.id}/messages`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ body: data.get("body"), isInternal: isStaff.value && data.get("isInternal") === "on" }) }));
    const file = data.get("attachment");
    if (file instanceof File && file.size > 0) {
      const metadata = { fileName: file.name, mimeType: file.type, sizeBytes: file.size };
      const upload = await readJson<{ storageKey: string; uploadUrl: string }>(await csrfFetch(`/api/backend/support/helpdesk/${ticket.id}/attachments/upload-url`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(metadata) }));
      const uploaded = await fetch(upload.uploadUrl, { method: "PUT", headers: { "content-type": file.type }, body: file });
      if (!uploaded.ok) throw new Error("Lampiran belum berhasil diunggah");
      await readJson(await csrfFetch(`/api/backend/support/helpdesk/${ticket.id}/attachments`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ ...metadata, storageKey: upload.storageKey, isInternal: isStaff.value && data.get("isInternal") === "on" }) }));
    }
    form.reset(); await openTicket(ticket.id); await load();
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Balasan belum dapat dikirim"; }
  busy.value = "";
}

async function downloadHelpdeskAttachment(messageId: string) {
  busy.value = messageId;
  try {
    const result = await readJson<{ url: string }>(await fetch(`/api/backend/support/helpdesk/messages/${messageId}/attachment`));
    window.open(result.url, "_blank", "noopener,noreferrer");
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Lampiran belum dapat diunduh"; }
  busy.value = "";
}

async function resolveTicket(event: Event) {
  if (!selectedTicket.value) return; const ticket = selectedTicket.value; const data = new FormData(event.currentTarget as HTMLFormElement); busy.value = "resolve";
  try {
    await readJson(await csrfFetch(`/api/backend/support/helpdesk/${ticket.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ status: data.get("status"), resolution: data.get("resolution") || undefined }) }));
    await openTicket(ticket.id); await load();
  } catch (caught) { error.value = caught instanceof Error ? caught.message : "Status tiket belum dapat diperbarui"; }
  busy.value = "";
}
</script>

<template>
  <div v-if="loading" class="flex min-h-[60vh] items-center justify-center gap-2 text-orange-600"><Loader2 aria-hidden="true" class="animate-spin" :size="18" /> Memuat pusat dukungan...</div>
  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-6xl">
      <p class="text-sm font-bold uppercase tracking-[0.16em] text-orange-600">Fase 7 · Pengalaman pendukung</p>
      <h2 class="mt-2 text-2xl font-extrabold text-slate-900">Pusat Dukungan &amp; Pencapaian</h2>
      <p class="mt-2 text-sm text-slate-600">Notifikasi, pengumuman, sertifikat, karya, dan bantuan dalam satu tempat yang aman.</p>
      <p v-if="error" role="alert" class="mt-5 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700">{{ error }}</p>
      <p v-if="message" role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ message }}</p>

      <div class="mt-7 grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <div class="flex items-center justify-between">
            <h3 class="flex items-center gap-2 font-bold text-slate-900"><Bell aria-hidden="true" :size="19" class="text-orange-600" /> Notifikasi</h3>
            <button
              @click="async () => {
                await csrfFetch(
                  '/api/backend/support/notifications/read-all',
                  { method: 'POST' },
                );
                await load();
              }"
              class="text-xs font-bold text-orange-700"
            >Tandai semua dibaca</button>
          </div>
          <div class="mt-4 max-h-80 space-y-3 overflow-auto">
            <p v-if="notifications.length === 0" class="text-sm text-slate-500">Belum ada notifikasi.</p>
            <template v-else>
              <article v-for="item in notifications" :key="item.id" :class="['rounded-xl p-4', item.readAt ? 'bg-slate-50' : 'bg-orange-50']">
                <div class="flex justify-between gap-3">
                  <div>
                    <p class="text-sm font-bold text-slate-900">{{ item.title }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ item.body }}</p>
                    <p class="mt-2 text-xs text-slate-400">{{ new Date(item.createdAt).toLocaleString("id-ID") }}</p>
                  </div>
                  <button v-if="!item.readAt" aria-label="Tandai dibaca" @click="markRead(item.id)" class="h-10 w-10 shrink-0 rounded-full bg-white text-emerald-700"><Check aria-hidden="true" class="mx-auto" :size="17" /></button>
                </div>
              </article>
            </template>
          </div>
        </section>

        <form @submit.prevent="savePreference" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 font-bold text-slate-900"><Settings2 aria-hidden="true" :size="19" class="text-orange-600" /> Preferensi notifikasi</h3>
          <p class="mt-2 text-xs text-slate-500">Kanal eksternal hanya mengirim bila penyedia resmi dikonfigurasi.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <label v-for="[key, label] in channels" :key="key" class="flex min-h-11 items-center gap-2 rounded-xl bg-slate-50 px-3 text-sm font-semibold"><input type="checkbox" :checked="preference[key]" @change="preference = { ...preference, [key]: ($event.target as HTMLInputElement).checked }" />{{ label }}</label>
          </div>
          <label class="mt-4 block text-sm font-bold text-slate-700">Ringkasan<select class="form-control mt-1" :value="preference.digestFrequency" @change="preference = { ...preference, digestFrequency: ($event.target as HTMLSelectElement).value }"><option value="IMMEDIATE">Langsung</option><option value="DAILY">Harian</option><option value="WEEKLY">Mingguan</option></select></label>
          <div class="mt-3 grid grid-cols-2 gap-3">
            <label class="text-sm font-bold text-slate-700">Jam tenang mulai<input type="time" class="form-control mt-1" :value="preference.quietHoursStart || ''" @input="preference = { ...preference, quietHoursStart: ($event.target as HTMLInputElement).value || null }" /></label>
            <label class="text-sm font-bold text-slate-700">Selesai<input type="time" class="form-control mt-1" :value="preference.quietHoursEnd || ''" @input="preference = { ...preference, quietHoursEnd: ($event.target as HTMLInputElement).value || null }" /></label>
          </div>
          <button :disabled="busy === 'preference'" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white disabled:opacity-50">Simpan preferensi</button>
        </form>
      </div>

      <section class="mt-6 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
        <h3 class="flex items-center gap-2 font-bold text-slate-900"><Megaphone aria-hidden="true" :size="19" class="text-orange-600" /> Pengumuman</h3>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <p v-if="announcements.length === 0" class="text-sm text-slate-500">Belum ada pengumuman aktif.</p>
          <template v-else>
            <article v-for="item in announcements" :key="item.id" class="rounded-xl bg-amber-50 p-4">
              <p class="text-xs font-bold text-orange-700">{{ item.audience }}</p>
              <h4 class="mt-1 font-bold text-slate-900">{{ item.title }}</h4>
              <p class="mt-2 text-sm leading-6 text-slate-600">{{ item.body }}</p>
            </article>
          </template>
        </div>
      </section>

      <section v-if="!hasAdvanced" class="mt-6 rounded-2xl border border-orange-200 bg-orange-50 p-5"><p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-700">Paket Advanced</p><h3 class="mt-1 font-bold text-slate-900">Sertifikat, pencapaian, dan portofolio</h3><p class="mt-1 text-sm text-slate-600">Fitur publikasi karya dan penerbitan sertifikat tersedia setelah akses Advanced aktif.</p><a href="/paket" class="mt-3 inline-flex min-h-10 items-center rounded-xl bg-orange-600 px-4 text-sm font-bold text-white">Lihat paket</a></section>

      <template v-if="hasAdvanced"><div class="mt-6 grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 font-bold text-slate-900"><ShieldCheck aria-hidden="true" :size="19" class="text-orange-600" /> Sertifikat</h3>
          <div class="mt-4 space-y-3">
            <div v-if="certificates.length === 0" class="rounded-xl border border-dashed border-slate-200 p-4">
              <p class="text-sm font-semibold text-slate-700">Belum ada sertifikat yang diterbitkan.</p>
              <p class="mt-1 text-xs leading-5 text-slate-500">Sertifikat akan muncul setelah admin menerbitkannya dan proses pembuatan PDF selesai.</p>
            </div>
            <template v-else>
              <article v-for="item in certificates" :key="item.id" class="rounded-xl bg-slate-50 p-4">
                <div class="flex flex-wrap justify-between gap-3">
                  <div>
                    <p class="font-bold text-slate-900">{{ item.title }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ item.student.fullName }}{{ item.student.studentNumber ? ` · ${item.student.studentNumber}` : "" }}</p>
                    <p v-if="item.description" class="mt-2 text-sm leading-6 text-slate-600">{{ item.description }}</p>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                      <span class="rounded-full bg-white px-2.5 py-1 font-semibold text-slate-600">{{ item.template?.name || "Sertifikat sekolah" }}</span>
                      <span class="rounded-full bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700">{{ item.status === "ISSUED" ? "Sudah terbit" : item.status === "QUEUED" ? "Sedang dibuat" : item.status }}</span>
                      <span v-if="item.issuedAt" class="rounded-full bg-white px-2.5 py-1 text-slate-600">Terbit {{ new Date(item.issuedAt).toLocaleDateString("id-ID") }}</span>
                    </div>
                    <p class="mt-3 text-xs font-mono text-slate-500">Kode: {{ item.verificationCode }}</p>
                  </div>
                  <div class="flex gap-2">
                    <button v-if="item.status === 'ISSUED'" @click="downloadCertificate(item.id)" class="min-h-10 rounded-lg border px-3 text-xs font-bold text-slate-700"><Download aria-hidden="true" :size="15" class="inline" /> Unduh</button>
                    <button :disabled="busy === item.id || item.status !== 'ISSUED'" @click="togglePublication('certificates', item)" class="min-h-10 rounded-lg border px-3 text-xs font-bold text-orange-700 disabled:opacity-40">{{ item.publicVisible ? "Jadikan privat" : "Publikasikan" }}</button>
                  </div>
                </div>
                <a v-if="item.publicVisible" class="mt-3 block text-xs font-bold text-orange-700 underline" :href="`/verifikasi-sertifikat/${item.verificationCode}`" target="_blank">Buka halaman verifikasi</a>
              </article>
            </template>
          </div>
        </section>
        <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 font-bold text-slate-900"><Award aria-hidden="true" :size="19" class="text-orange-600" /> Pencapaian</h3>
          <div class="mt-4 space-y-3">
            <p v-if="achievements.length === 0" class="text-sm text-slate-500">Belum ada pencapaian.</p>
            <template v-else>
              <article v-for="item in achievements" :key="item.id" class="rounded-xl bg-amber-50 p-4">
                <p class="font-bold text-slate-900">🏆 {{ item.achievement.title }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ item.achievement.description }}</p>
                <p class="mt-2 text-xs text-slate-500">{{ item.student.fullName }} · {{ new Date(item.awardedAt).toLocaleDateString("id-ID") }}</p>
              </article>
            </template>
          </div>
        </section>
      </div>

      <section class="mt-6 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
        <h3 class="font-bold text-slate-900">Portofolio karya yang disetujui</h3>
        <p class="mt-1 text-xs text-slate-500">Karya publik hanya tampil saat persetujuan publikasi aktif.</p>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
          <p v-if="portfolio.length === 0" class="text-sm text-slate-500">Belum ada karya dalam portofolio.</p>
          <template v-else>
            <article v-for="item in portfolio" :key="item.id" class="rounded-xl bg-slate-50 p-4">
              <p class="text-xs font-bold text-orange-700">{{ item.sourceType }}</p>
              <p class="mt-1 font-bold text-slate-900">{{ item.title }}</p>
              <p class="mt-1 text-sm text-slate-600">{{ item.description }}</p>
              <div class="mt-3 flex gap-3">
                <button :disabled="busy === item.id" @click="togglePublication('portfolio', item)" class="text-xs font-bold text-orange-700">{{ item.status === "PUBLISHED" ? "Jadikan privat" : "Publikasikan" }}</button>
                <a v-if="item.publicSlug" :href="`/portofolio/${item.publicSlug}`" target="_blank" class="text-xs font-bold text-slate-600 underline">Lihat publik</a>
              </div>
            </article>
          </template>
        </div>
      </section></template>

      <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <form @submit.prevent="createTicket" class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <h3 class="flex items-center gap-2 font-bold text-slate-900"><HelpCircle aria-hidden="true" :size="19" class="text-orange-600" /> Buat tiket bantuan</h3>
          <div class="mt-4 grid grid-cols-2 gap-3">
            <label class="text-sm font-bold text-slate-700">Kategori<select name="category" class="form-control mt-1"><option>Akun &amp; akses</option><option>Materi &amp; tugas</option><option>Student Store</option><option>Privasi</option><option>Lainnya</option></select></label>
            <label class="text-sm font-bold text-slate-700">Prioritas<select name="priority" class="form-control mt-1"><option value="NORMAL">Normal</option><option value="HIGH">Tinggi</option><option value="URGENT">Mendesak</option><option value="LOW">Rendah</option></select></label>
          </div>
          <label class="mt-3 block text-sm font-bold text-slate-700">Subjek<input name="subject" minlength="5" required class="form-control mt-1" /></label>
          <label class="mt-3 block text-sm font-bold text-slate-700">Keterangan<textarea name="description" minlength="10" maxlength="5000" required class="form-control mt-1 min-h-28" placeholder="Jangan menuliskan kata sandi atau nomor identitas lengkap." /></label>
          <button :disabled="busy === 'ticket'" class="bg-sunrise mt-4 min-h-11 rounded-xl px-4 text-sm font-bold text-white disabled:opacity-50">Kirim tiket</button>
        </form>
        <section class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
          <h3 class="font-bold text-slate-900">Riwayat tiket</h3>
          <div class="mt-4 space-y-3">
            <p v-if="tickets.length === 0" class="text-sm text-slate-500">Belum ada tiket.</p>
            <template v-else>
              <article v-for="ticket in tickets" :key="ticket.id" class="rounded-xl bg-slate-50 p-4">
                <div class="flex justify-between gap-3">
                  <div>
                    <p class="text-xs font-bold text-orange-700">{{ ticket.ticketNumber }} · {{ ticket.category }}</p>
                    <p class="mt-1 font-bold text-slate-900">{{ ticket.subject }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ ticket._count.messages }} pesan · diperbarui {{ new Date(ticket.updatedAt).toLocaleString("id-ID") }}</p>
                    <button type="button" :disabled="busy === ticket.id" @click="openTicket(ticket.id)" class="mt-3 text-xs font-bold text-orange-700">Buka percakapan</button>
                  </div>
                  <span class="h-fit rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-700">{{ ticket.status }}</span>
                </div>
              </article>
            </template>
          </div>
        </section>
      </div>
      <section v-if="selectedTicket" class="mt-6 rounded-2xl border border-orange-100 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold text-orange-700">{{ selectedTicket.ticketNumber }} · {{ selectedTicket.category }}</p><h3 class="mt-1 text-lg font-bold text-slate-900">{{ selectedTicket.subject }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ selectedTicket.description }}</p></div><button type="button" @click="selectedTicket = null" class="min-h-10 rounded-lg border px-3 text-xs font-bold">Tutup</button></div><div class="mt-5 space-y-3"><p v-if="selectedTicket.messages.length === 0" class="text-sm text-slate-500">Belum ada balasan.</p><template v-else><article v-for="item in selectedTicket.messages" :key="item.id" :class="['rounded-xl p-4', item.isInternal ? 'bg-amber-50' : 'bg-slate-50']"><div class="flex justify-between gap-3 text-xs"><strong>{{ item.author.firstName }} {{ item.author.lastName }}</strong><span class="text-slate-400">{{ new Date(item.createdAt).toLocaleString("id-ID") }}</span></div><p class="mt-2 text-sm leading-6 text-slate-600">{{ item.body }}</p><button v-if="item.fileName" type="button" :disabled="item.scanStatus !== 'CLEAN' || busy === item.id" @click="void downloadHelpdeskAttachment(item.id)" class="mt-3 min-h-10 rounded-lg border bg-white px-3 text-xs font-bold text-orange-700 disabled:text-slate-400">{{ item.scanStatus === "CLEAN" ? `Unduh ${item.fileName}` : item.scanStatus === "INFECTED" ? "Lampiran ditolak" : "Lampiran sedang diperiksa" }}</button><span v-if="item.isInternal" class="mt-2 block text-xs font-bold text-amber-700">Catatan internal</span></article></template></div><form @submit.prevent="replyTicket" class="mt-5 border-t pt-5"><label class="block text-sm font-bold text-slate-700">Balasan<textarea name="body" required minlength="2" maxlength="5000" class="form-control mt-1 min-h-24" /></label><label class="mt-3 block text-sm font-bold text-slate-700">Lampiran opsional<input name="attachment" type="file" accept="application/pdf,image/jpeg,image/png" class="form-control mt-1" /><span class="mt-1 block text-xs font-normal text-slate-500">PDF, JPG, atau PNG maksimal 8 MB. File diperiksa sebelum dapat diunduh.</span></label><label v-if="isStaff" class="mt-3 flex items-center gap-2 text-sm"><input name="isInternal" type="checkbox" /> Catatan internal (tidak terlihat pemohon)</label><button :disabled="busy === 'reply'" class="bg-sunrise mt-3 min-h-11 rounded-xl px-4 text-sm font-bold text-white">Kirim balasan</button></form><form v-if="isStaff" @submit.prevent="resolveTicket" class="mt-5 grid gap-3 border-t pt-5 sm:grid-cols-[180px_1fr_auto]"><select name="status" class="form-control"><option v-for="[value, label] in ticketStatuses" :key="value" :value="value" :selected.attr="selectedTicket.status === value">{{ label }}</option></select><input name="resolution" :value.attr="selectedTicket.resolution || ''" placeholder="Ringkasan penyelesaian" class="form-control" /><button :disabled="busy === 'resolve'" class="min-h-11 rounded-xl border border-orange-200 px-4 text-sm font-bold text-orange-700">Perbarui</button></form></section>
    </div>
  </main>
</template>
