<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import {
  CheckCircle2,
  KeyRound,
  Laptop,
  LoaderCircle,
  LogOut,
  MonitorSmartphone,
  Save,
  ShieldCheck,
  Smartphone,
  UserRound,
} from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type Account = {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  phone: string | null;
  status: string;
  emailVerified: boolean;
  mfaEnabled: boolean;
  lastLoginAt: string | null;
  createdAt: string;
  organization: { name: string } | null;
};

type AccountSession = {
  id: string;
  ipAddress: string | null;
  userAgent: string | null;
  expiresAt: string;
  lastUsedAt: string | null;
  mfaVerifiedAt: string | null;
  createdAt: string;
  current: boolean;
};

type Setup = { secret: string; otpauthUri: string };
type Notice = { tone: "success" | "error"; text: string } | null;

function formatDate(value: string | null) {
  if (!value) return "Belum tercatat";
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Jakarta",
  }).format(new Date(value));
}

function deviceName(userAgent: string | null) {
  if (!userAgent) return "Perangkat tidak dikenal";
  const browser = userAgent.includes("Edg/") ? "Microsoft Edge" : userAgent.includes("Chrome/") ? "Chrome" : userAgent.includes("Firefox/") ? "Firefox" : userAgent.includes("Safari/") ? "Safari" : "Browser";
  const device = /iPhone|Android|Mobile/i.test(userAgent) ? "Ponsel" : /iPad|Tablet/i.test(userAgent) ? "Tablet" : /Macintosh/i.test(userAgent) ? "Mac" : /Windows/i.test(userAgent) ? "Windows" : "Komputer";
  return `${browser} · ${device}`;
}

const account = ref<Account | null>(null);
const sessions = ref<AccountSession[]>([]);
const firstName = ref("");
const lastName = ref("");
const phone = ref("");
const currentPassword = ref("");
const newPassword = ref("");
const passwordConfirmation = ref("");
const setup = ref<Setup | null>(null);
const mfaCode = ref("");
const mfaPassword = ref("");
const notice = ref<Notice>(null);
const busy = ref("");
const loading = ref(true);
const showAllSessions = ref(false);

async function load() {
  try {
    const [accountResponse, sessionsResponse] = await Promise.all([
      fetch("/api/backend/auth/account", { cache: "no-store" }),
      fetch("/api/backend/auth/sessions", { cache: "no-store" }),
    ]);
    if (accountResponse.status === 401) return window.location.replace("/login");
    const accountData = await accountResponse.json().catch(() => ({}));
    const sessionData = await sessionsResponse.json().catch(() => []);
    if (!accountResponse.ok) throw new Error(accountData.message || "Data akun belum dapat dimuat");
    if (!sessionsResponse.ok) throw new Error("Daftar perangkat belum dapat dimuat");
    account.value = accountData;
    firstName.value = accountData.firstName || "";
    lastName.value = accountData.lastName || "";
    phone.value = accountData.phone || "";
    sessions.value = Array.isArray(sessionData) ? sessionData : [];
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "Kontrol akun belum dapat dimuat" };
  } finally {
    loading.value = false;
  }
}

onMounted(() => { void load(); });

async function api(path: string, init: RequestInit = {}) {
  const response = await csrfFetch(`/api/backend/auth/${path}`, init);
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || "Permintaan belum dapat diproses");
  return data;
}

async function saveProfile() {
  busy.value = "profile";
  notice.value = null;
  try {
    const updated = await api("account", {
      method: "PATCH",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ firstName: firstName.value.trim(), lastName: lastName.value.trim(), phone: phone.value.trim() || undefined }),
    });
    account.value = updated;
    notice.value = { tone: "success", text: "Informasi akun berhasil diperbarui." };
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "Informasi akun belum dapat disimpan" };
  } finally {
    busy.value = "";
  }
}

async function changePassword() {
  if (newPassword.value !== passwordConfirmation.value) {
    notice.value = { tone: "error", text: "Konfirmasi kata sandi baru belum sama." };
    return;
  }
  busy.value = "password";
  notice.value = null;
  try {
    const result = await api("change-password", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ currentPassword: currentPassword.value, newPassword: newPassword.value }),
    });
    currentPassword.value = "";
    newPassword.value = "";
    passwordConfirmation.value = "";
    notice.value = { tone: "success", text: `${result.message}. ${result.revokedOtherSessions || 0} sesi lain telah dikeluarkan.` };
    await load();
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "Kata sandi belum dapat diperbarui" };
  } finally {
    busy.value = "";
  }
}

async function revokeSession(session: AccountSession) {
  if (!window.confirm(`Keluarkan ${deviceName(session.userAgent)} dari akun?`)) return;
  busy.value = session.id;
  notice.value = null;
  try {
    await api(`sessions/${session.id}`, { method: "DELETE" });
    sessions.value = sessions.value.filter((item) => item.id !== session.id);
    notice.value = { tone: "success", text: "Perangkat berhasil dikeluarkan dari akun." };
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "Perangkat belum dapat dikeluarkan" };
  } finally {
    busy.value = "";
  }
}

async function logoutAll() {
  if (!window.confirm("Keluar dari seluruh perangkat, termasuk perangkat ini?")) return;
  busy.value = "logout-all";
  try {
    await api("logout-all", { method: "POST" });
  } finally {
    await csrfFetch("/api/session", { method: "DELETE" }).catch(() => undefined);
    // router.replace + router.refresh in the source; a full-page replace already reloads.
    window.location.replace("/login");
  }
}

async function beginMfa() {
  busy.value = "mfa";
  notice.value = null;
  try {
    setup.value = await api("mfa/setup", { method: "POST", headers: { "content-type": "application/json" }, body: "{}" });
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "MFA belum dapat disiapkan" };
  } finally {
    busy.value = "";
  }
}

async function confirmMfa() {
  busy.value = "mfa";
  notice.value = null;
  try {
    await api("mfa/confirm", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ code: mfaCode.value }) });
    setup.value = null;
    mfaCode.value = "";
    notice.value = { tone: "success", text: "Verifikasi dua langkah berhasil diaktifkan." };
    await load();
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "Kode verifikasi tidak valid" };
  } finally {
    busy.value = "";
  }
}

async function disableMfa() {
  busy.value = "mfa";
  notice.value = null;
  try {
    await api("mfa/disable", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ code: mfaCode.value, password: mfaPassword.value }) });
    await csrfFetch("/api/session", { method: "DELETE" }).catch(() => undefined);
    window.location.replace("/login");
  } catch (error) {
    notice.value = { tone: "error", text: error instanceof Error ? error.message : "MFA belum dapat dinonaktifkan" };
    busy.value = "";
  }
}

// Controlled input: write the filtered value back so rejected characters do not stay in the field.
function onMfaCodeInput(event: Event) {
  const input = event.target as HTMLInputElement;
  mfaCode.value = input.value.replace(/\D/g, "").slice(0, 6);
  input.value = mfaCode.value;
}

const mobile = (session: AccountSession) => /iPhone|Android|Mobile|iPad|Tablet/i.test(session.userAgent || "");
const visibleSessions = computed(() => (showAllSessions.value ? sessions.value : sessions.value.slice(0, 5)));
</script>

<template>
  <main v-if="loading" class="grid min-h-[60vh] place-items-center"><p class="flex items-center gap-2 text-sm font-bold text-slate-500"><LoaderCircle aria-hidden="true" class="animate-spin" :size="18" /> Menyiapkan kontrol akun…</p></main>
  <main v-else class="mx-auto max-w-6xl p-4 sm:p-8">
    <div class="flex flex-col gap-5 border-b border-slate-900/10 pb-7 md:flex-row md:items-end md:justify-between">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-orange-700">Akun &amp; keamanan</p>
        <h2 class="mt-2 text-3xl font-extrabold tracking-[-0.04em] text-slate-950">Kontrol akun</h2>
        <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-600">Perbarui informasi pribadi, lindungi kata sandi, dan periksa perangkat yang masih terhubung.</p>
      </div>
      <div class="flex items-center gap-3 border border-slate-900/10 bg-white px-4 py-3">
        <span class="grid size-10 place-items-center rounded-full bg-orange-50 font-black text-orange-700">{{ account?.firstName?.charAt(0).toUpperCase() || "A" }}</span>
        <div><p class="text-sm font-extrabold text-slate-950">{{ account ? `${account.firstName} ${account.lastName}` : "Pengguna" }}</p><p class="text-xs text-slate-500">{{ account?.organization?.name || account?.email }}</p></div>
      </div>
    </div>

    <div v-if="notice" :role="notice.tone === 'error' ? 'alert' : 'status'" aria-live="polite" :class="['mt-5 border-l-2 px-4 py-3 text-sm font-semibold', notice.tone === 'error' ? 'border-red-600 bg-red-50 text-red-700' : 'border-emerald-600 bg-emerald-50 text-emerald-800']">{{ notice.text }}</div>

    <div class="mt-7 grid gap-6 lg:grid-cols-[1.05fr_.95fr]">
      <section class="border border-slate-900/10 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex items-start gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-orange-50 text-orange-700"><UserRound aria-hidden="true" :size="19" /></span><div><h3 class="font-extrabold text-slate-950">Informasi akun</h3><p class="mt-1 text-sm text-slate-500">Nama dan nomor kontak yang digunakan di dalam platform.</p></div></div>
        <form @submit.prevent="saveProfile" class="mt-6 grid gap-4 sm:grid-cols-2">
          <label class="block text-xs font-extrabold text-slate-700">Nama depan<span class="mt-2 block"><input required minlength="2" maxlength="80" autocomplete="given-name" v-model="firstName" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <label class="block text-xs font-extrabold text-slate-700">Nama belakang<span class="mt-2 block"><input required minlength="2" maxlength="80" autocomplete="family-name" v-model="lastName" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <label class="block text-xs font-extrabold text-slate-700">Email akun<span class="mt-2 block"><input disabled :value="account?.email || ''" class="min-h-11 w-full rounded-md border border-slate-900/15 bg-slate-50 px-3 text-sm font-normal text-slate-500" /></span></label>
          <label class="block text-xs font-extrabold text-slate-700">Nomor telepon<span class="mt-2 block"><input type="tel" maxlength="30" autocomplete="tel" v-model="phone" placeholder="Contoh: 0812 3456 7890" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-900/10 pt-5 sm:col-span-2">
            <p class="text-xs text-slate-500">Bergabung {{ formatDate(account?.createdAt || null) }}</p>
            <button :disabled="busy === 'profile' || firstName.trim().length < 2 || lastName.trim().length < 2" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-extrabold text-white disabled:opacity-45"><Save aria-hidden="true" :size="16" /> Simpan perubahan</button>
          </div>
        </form>
      </section>

      <section class="border border-slate-900/10 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex items-start gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-700"><KeyRound aria-hidden="true" :size="19" /></span><div><h3 class="font-extrabold text-slate-950">Ubah kata sandi</h3><p class="mt-1 text-sm text-slate-500">Perangkat lain otomatis dikeluarkan setelah perubahan.</p></div></div>
        <form @submit.prevent="changePassword" class="mt-6 space-y-4">
          <label class="block text-xs font-extrabold text-slate-700">Kata sandi saat ini<span class="mt-2 block"><input required minlength="8" type="password" autocomplete="current-password" v-model="currentPassword" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <label class="block text-xs font-extrabold text-slate-700">Kata sandi baru<span class="mt-2 block"><input required minlength="8" maxlength="128" type="password" autocomplete="new-password" v-model="newPassword" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <label class="block text-xs font-extrabold text-slate-700">Ulangi kata sandi baru<span class="mt-2 block"><input required minlength="8" maxlength="128" type="password" autocomplete="new-password" v-model="passwordConfirmation" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label>
          <button :disabled="busy === 'password' || currentPassword.length < 8 || newPassword.length < 8 || passwordConfirmation.length < 8" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-orange-600 px-5 text-sm font-extrabold text-white disabled:opacity-45"><KeyRound aria-hidden="true" :size="16" /> Perbarui kata sandi</button>
        </form>
      </section>
    </div>

    <section class="mt-6 border border-slate-900/10 bg-white p-5 shadow-sm sm:p-6">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-700"><MonitorSmartphone aria-hidden="true" :size="19" /></span><div><h3 class="font-extrabold text-slate-950">Perangkat yang terhubung</h3><p class="mt-1 text-sm text-slate-500">Periksa dan keluarkan perangkat yang tidak Anda kenali.</p></div></div>
        <button :disabled="busy === 'logout-all' || sessions.length === 0" @click="logoutAll" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-red-200 px-4 text-xs font-extrabold text-red-700 disabled:opacity-45"><LogOut aria-hidden="true" :size="15" /> Keluar dari semua perangkat</button>
      </div>
      <div class="mt-6 divide-y divide-slate-900/10 border-y border-slate-900/10">
        <p v-if="sessions.length === 0" class="py-6 text-sm text-slate-500">Tidak ada sesi aktif yang tercatat.</p>
        <template v-else><div v-for="session in visibleSessions" :key="session.id" class="flex flex-col gap-4 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex min-w-0 items-start gap-3"><Smartphone v-if="mobile(session)" aria-hidden="true" class="mt-0.5 shrink-0 text-slate-500" :size="20" /><Laptop v-else aria-hidden="true" class="mt-0.5 shrink-0 text-slate-500" :size="20" /><div class="min-w-0"><p class="flex flex-wrap items-center gap-2 text-sm font-extrabold text-slate-900">{{ deviceName(session.userAgent) }}<span v-if="session.current" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] text-emerald-700">Perangkat ini</span></p><p class="mt-1 text-xs leading-5 text-slate-500">Masuk {{ formatDate(session.createdAt) }} · IP {{ session.ipAddress || "tidak tersedia" }} · Berakhir {{ formatDate(session.expiresAt) }}</p></div></div>
          <button v-if="!session.current" :disabled="busy === session.id" @click="revokeSession(session)" class="min-h-9 shrink-0 rounded-md border border-slate-200 px-3 text-xs font-extrabold text-slate-700 disabled:opacity-45">Keluarkan</button>
        </div></template>
      </div>
      <button v-if="sessions.length > 5" @click="showAllSessions = !showAllSessions" class="mt-4 min-h-10 text-xs font-extrabold text-orange-700 hover:underline">{{ showAllSessions ? "Tampilkan lebih ringkas" : `Tampilkan semua ${sessions.length} perangkat` }}</button>
    </section>

    <section class="mt-6 border border-slate-900/10 bg-white p-5 shadow-sm sm:p-6">
      <div class="flex items-start gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-700"><ShieldCheck aria-hidden="true" :size="19" /></span><div><h3 class="font-extrabold text-slate-950">Verifikasi dua langkah</h3><p class="mt-1 text-sm text-slate-500">Tambahkan kode autentikator untuk tindakan yang sensitif.</p></div><span :class="['ml-auto rounded-full px-3 py-1 text-[11px] font-extrabold', account?.mfaEnabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600']">{{ account?.mfaEnabled ? "Aktif" : "Belum aktif" }}</span></div>
      <button v-if="!account?.mfaEnabled && !setup" :disabled="busy === 'mfa'" @click="beginMfa" class="mt-5 min-h-11 rounded-md bg-emerald-700 px-5 text-sm font-extrabold text-white disabled:opacity-45">Aktifkan verifikasi dua langkah</button>
      <div v-if="setup" class="mt-5 max-w-2xl space-y-4 border-t border-slate-900/10 pt-5"><p class="text-sm leading-6 text-slate-600">Masukkan kunci berikut ke Google Authenticator, Microsoft Authenticator, atau aplikasi TOTP lain.</p><code class="block break-all bg-slate-950 p-4 text-sm text-amber-300">{{ setup.secret }}</code><label class="block text-xs font-extrabold text-slate-700">Kode 6 digit pertama<span class="mt-2 block"><input inputmode="numeric" autocomplete="one-time-code" :value="mfaCode" @input="onMfaCodeInput" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" maxlength="6" /></span></label><button :disabled="busy === 'mfa' || mfaCode.length !== 6" @click="confirmMfa" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-emerald-700 px-5 text-sm font-extrabold text-white disabled:opacity-45"><CheckCircle2 aria-hidden="true" :size="16" /> Konfirmasi dan aktifkan</button></div>
      <div v-if="account?.mfaEnabled" class="mt-5 max-w-2xl space-y-4 border-t border-slate-900/10 pt-5"><p class="text-sm leading-6 text-slate-600">Untuk menonaktifkan verifikasi dua langkah, masukkan kata sandi dan kode autentikator saat ini. Seluruh sesi akan dikeluarkan.</p><label class="block text-xs font-extrabold text-slate-700">Kata sandi<span class="mt-2 block"><input type="password" autocomplete="current-password" v-model="mfaPassword" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></span></label><label class="block text-xs font-extrabold text-slate-700">Kode 6 digit<span class="mt-2 block"><input inputmode="numeric" autocomplete="one-time-code" :value="mfaCode" @input="onMfaCodeInput" class="min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" maxlength="6" /></span></label><button :disabled="busy === 'mfa' || mfaPassword.length < 8 || mfaCode.length !== 6" @click="disableMfa" class="min-h-11 rounded-md border border-red-200 px-5 text-sm font-extrabold text-red-700 disabled:opacity-45">Nonaktifkan verifikasi dua langkah</button></div>
    </section>
  </main>
</template>
