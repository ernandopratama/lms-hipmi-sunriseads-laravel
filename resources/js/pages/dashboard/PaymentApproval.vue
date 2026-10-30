<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { BadgeCheck, CheckCircle2, Clock3, RefreshCw, School, Search, ShieldCheck, UserRound, XCircle } from "@lucide/vue";
import { csrfFetch } from "@/lib/csrf";

type Identity = {
  userId: string;
  email: string;
  fullName: string;
  studentNumber: string | null;
  schoolId: string | null;
  schoolName: string | null;
  isStudent: boolean;
};

type StudentOption = {
  id: string;
  userId: string;
  fullName: string;
  studentNumber: string;
  email: string;
  schoolName: string;
};

type ApprovalOrder = {
  id: string;
  orderNumber: string;
  status: "PENDING_PAYMENT" | "PAID" | "FAILED" | "EXPIRED";
  currency: string;
  subtotalAmount: string;
  feeAmount: string;
  totalAmount: string;
  paymentExpiresAt: string | null;
  paidAt: string | null;
  createdAt: string;
  pricingUnit: "PER_STUDENT";
  purchaser: Identity | null;
  beneficiary: Identity | null;
  items: Array<{
    plan: { code: string; name: string; moduleLimit: number };
    offer: { name: string; accessDurationDays: number | null };
  }>;
};

const statusCopy = {
  PENDING_PAYMENT: { label: "Perlu ditinjau", className: "bg-amber-50 text-amber-800" },
  PAID: { label: "Disetujui", className: "bg-emerald-50 text-emerald-700" },
  FAILED: { label: "Ditolak", className: "bg-red-50 text-red-700" },
  EXPIRED: { label: "Kedaluwarsa", className: "bg-slate-100 text-slate-600" },
};

const filters = ["PENDING_PAYMENT", "PAID", "FAILED", "EXPIRED", "ALL"] as const;

function money(amount: string, currency: string) {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency, maximumFractionDigits: 0 }).format(Number(amount));
}

function date(value: string | null) {
  if (!value) return "-";
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" }).format(new Date(value));
}

const orders = ref<ApprovalOrder[]>([]);
const students = ref<StudentOption[]>([]);
const selectedStudents = ref<Record<string, string>>({});
const references = ref<Record<string, string>>({});
const notes = ref<Record<string, string>>({});
const filter = ref<"ALL" | ApprovalOrder["status"]>("PENDING_PAYMENT");
const search = ref("");
const loading = ref(true);
const busy = ref("");
const error = ref("");
const notice = ref("");
const authorized = ref<boolean | null>(null);

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const [ordersResponse, studentsResponse] = await Promise.all([
      fetch("/api/backend/commerce/admin/orders", { cache: "no-store" }),
      fetch("/api/backend/commerce/admin/students", { cache: "no-store" }),
    ]);
    const [orderData, studentData] = await Promise.all([
      ordersResponse.json().catch(() => []),
      studentsResponse.json().catch(() => []),
    ]);
    if (!ordersResponse.ok) throw new Error(orderData.message || "Antrean approval belum dapat dimuat");
    if (!studentsResponse.ok) throw new Error(studentData.message || "Daftar siswa belum dapat dimuat");
    const nextOrders = Array.isArray(orderData) ? orderData as ApprovalOrder[] : [];
    orders.value = nextOrders;
    students.value = Array.isArray(studentData) ? studentData : [];
    const current = selectedStudents.value;
    selectedStudents.value = Object.fromEntries(nextOrders.map((order) => [
      order.id,
      current[order.id] || (order.beneficiary?.isStudent ? order.beneficiary.userId : ""),
    ]));
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Antrean approval belum dapat dimuat";
  } finally {
    loading.value = false;
  }
}

let active = true;
onMounted(() => {
  const authorize = async () => {
    try {
      const response = await fetch("/api/session", { cache: "no-store" });
      const session = response.ok ? await response.json() as { roles?: string[]; permissions?: Array<{ resource: string; action: string }> } : null;
      const canManageAccess = Boolean(session?.roles?.includes("super-admin") || session?.permissions?.some((permission) => permission.resource === "access-grants" && permission.action === "manage"));
      if (!canManageAccess) {
        if (active) authorized.value = false;
        window.location.replace("/dashboard");
        return;
      }
      if (active) authorized.value = true;
      await load();
    } catch {
      if (active) authorized.value = false;
      window.location.replace("/dashboard");
    }
  };
  void authorize();
});
onUnmounted(() => { active = false; });

const visibleOrders = computed(() => orders.value.filter((order) => {
  if (filter.value !== "ALL" && order.status !== filter.value) return false;
  const query = search.value.trim().toLowerCase();
  if (!query) return true;
  return [order.orderNumber, order.purchaser?.fullName, order.purchaser?.email, order.beneficiary?.fullName, order.items[0]?.offer.name]
    .some((value) => value?.toLowerCase().includes(query));
}));

async function approve(order: ApprovalOrder) {
  const beneficiaryUserId = selectedStudents.value[order.id];
  const paymentReference = references.value[order.id]?.trim() || "";
  const note = notes.value[order.id]?.trim() || "";
  if (!beneficiaryUserId) {
    error.value = "Pilih satu siswa penerima akses terlebih dahulu.";
    return;
  }
  if (paymentReference.length < 3) {
    error.value = "Isi referensi mutasi atau transfer sebelum menyetujui pembayaran.";
    return;
  }
  if (note.length < 5) {
    error.value = "Isi catatan hasil pemeriksaan minimal 5 karakter.";
    return;
  }
  const student = students.value.find((item) => item.userId === beneficiaryUserId);
  if (!window.confirm(`Setujui pembayaran ${order.orderNumber} dan aktifkan ${order.items[0]?.plan.name} untuk ${student?.fullName || "siswa ini"}?`)) return;
  busy.value = order.id;
  error.value = "";
  notice.value = "";
  try {
    const response = await csrfFetch(`/api/backend/commerce/admin/orders/${order.id}/approve`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({
        beneficiaryUserId,
        paymentReference,
        note,
      }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || "Pembayaran belum dapat disetujui");
    notice.value = `Pembayaran ${order.orderNumber} disetujui. Akses siswa sudah aktif.`;
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Pembayaran belum dapat disetujui";
  } finally {
    busy.value = "";
  }
}

async function reject(order: ApprovalOrder) {
  const reason = window.prompt(`Alasan menolak pembayaran ${order.orderNumber}:`);
  if (!reason) return;
  if (reason.trim().length < 5) {
    error.value = "Alasan penolakan minimal 5 karakter.";
    return;
  }
  busy.value = order.id;
  error.value = "";
  notice.value = "";
  try {
    const response = await csrfFetch(`/api/backend/commerce/admin/orders/${order.id}/reject`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ reason: reason.trim() }),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || "Pembayaran belum dapat ditolak");
    notice.value = `Pembayaran ${order.orderNumber} ditolak dan akses tidak diberikan.`;
    await load();
  } catch (caught) {
    error.value = caught instanceof Error ? caught.message : "Pembayaran belum dapat ditolak";
  } finally {
    busy.value = "";
  }
}

const pendingCount = computed(() => orders.value.filter((order) => order.status === "PENDING_PAYMENT").length);
const paidCount = computed(() => orders.value.filter((order) => order.status === "PAID").length);
</script>

<template>
  <main v-if="authorized !== true" class="grid min-h-[40vh] place-items-center p-6"><div class="flex items-center gap-3 rounded-xl border border-orange-100 bg-white px-5 py-4 text-sm font-bold text-slate-600"><RefreshCw aria-hidden="true" class="animate-spin text-orange-600" :size="17" /> Memeriksa kewenangan akun…</div></main>

  <main v-else class="min-h-screen bg-[#FFFFFF] p-4 sm:p-8">
    <div class="mx-auto max-w-7xl">
      <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p class="text-xs font-extrabold uppercase tracking-[0.16em] text-orange-700">Pembayaran &amp; akses</p>
          <h2 class="mt-2 text-3xl font-extrabold tracking-[-0.04em] text-slate-950">Approval paket siswa</h2>
          <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-600">Cocokkan nominal transfer, pilih satu siswa penerima, lalu aktifkan paket. Setiap pembayaran berbayar hanya berlaku untuk satu siswa.</p>
        </div>
        <div class="flex gap-3">
          <div class="min-w-28 border border-slate-900/10 bg-white px-4 py-3"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Perlu ditinjau</p><p class="mt-1 text-2xl font-black text-amber-700">{{ pendingCount }}</p></div>
          <div class="min-w-28 border border-slate-900/10 bg-white px-4 py-3"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Sudah aktif</p><p class="mt-1 text-2xl font-black text-emerald-700">{{ paidCount }}</p></div>
        </div>
      </div>

      <section class="mt-7 grid gap-4 border border-emerald-200 bg-emerald-50 p-5 md:grid-cols-[auto_1fr]" aria-label="Aturan akses paket">
        <span class="grid size-11 place-items-center rounded-full bg-white text-emerald-700"><School aria-hidden="true" :size="21" /></span>
        <div><h3 class="font-extrabold text-emerald-950">Paket Gratis tidak menunggu approval</h3><p class="mt-1 text-sm leading-6 text-emerald-800">Modul pada paket Gratis otomatis terbuka sebagai akses dasar sekolah dan seluruh siswanya. Approval ini khusus paket Medium dan Advanced yang dihitung per siswa.</p></div>
      </section>

      <div v-if="error || notice" :class="['mt-5 border-l-2 px-4 py-3 text-sm', error ? 'border-red-600 bg-red-50 text-red-700' : 'border-emerald-600 bg-emerald-50 text-emerald-800']">{{ error || notice }}</div>

      <div class="mt-7 flex flex-col gap-3 border-y border-slate-900/10 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap gap-2">
          <button v-for="value in filters" :key="value" @click="filter = value" :class="['min-h-10 rounded-md px-4 text-xs font-extrabold', filter === value ? 'bg-slate-950 text-white' : 'border border-slate-900/10 bg-white text-slate-600']">{{ value === "PENDING_PAYMENT" ? "Perlu ditinjau" : value === "PAID" ? "Disetujui" : value === "FAILED" ? "Ditolak" : value === "EXPIRED" ? "Kedaluwarsa" : "Semua" }}</button>
        </div>
        <label class="relative block sm:w-80"><Search aria-hidden="true" class="absolute left-3 top-3 text-slate-400" :size="16" /><span class="sr-only">Cari pesanan</span><input v-model="search" placeholder="Cari nomor, pembeli, atau siswa" class="min-h-10 w-full rounded-md border border-slate-900/15 bg-white pl-9 pr-3 text-sm outline-none focus:border-orange-500" /></label>
      </div>

      <div v-if="loading" class="mt-7 flex min-h-56 items-center justify-center gap-3 border border-slate-900/10 bg-white text-sm font-bold text-slate-500"><RefreshCw aria-hidden="true" class="animate-spin" :size="17" /> Memuat antrean approval…</div>
      <div v-else-if="visibleOrders.length === 0" class="mt-7 grid min-h-56 place-items-center border border-slate-900/10 bg-white p-8 text-center"><div><CheckCircle2 aria-hidden="true" class="mx-auto text-emerald-600" :size="28" /><h3 class="mt-3 font-extrabold text-slate-950">Tidak ada pesanan pada tampilan ini</h3><p class="mt-2 text-sm text-slate-500">Antrean baru akan muncul setelah siswa membuat pesanan.</p></div></div>
      <div v-else class="mt-7 space-y-5">
        <article v-for="order in visibleOrders" :key="order.id" class="border border-slate-900/10 bg-white p-5 shadow-sm sm:p-6">
          <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-3">
                <span class="grid size-10 place-items-center rounded-full bg-orange-50 text-orange-700"><BadgeCheck aria-hidden="true" :size="19" /></span>
                <div><p class="text-xs font-bold text-slate-500">{{ order.orderNumber }}</p><h3 class="mt-0.5 text-lg font-extrabold text-slate-950">{{ order.items[0]?.offer.name || order.items[0]?.plan.name }}</h3></div>
                <span :class="['rounded-full px-3 py-1 text-[11px] font-extrabold', statusCopy[order.status].className]">{{ statusCopy[order.status].label }}</span>
              </div>
              <div class="mt-5 grid gap-4 border-t border-slate-900/10 pt-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-slate-500">Transfer tepat</p><p class="mt-1 flex items-center gap-1.5 font-black text-slate-950">{{ money(order.totalAmount, order.currency) }}</p></div>
                <div><p class="text-xs text-slate-500">Harga + kode unik</p><p class="mt-1 flex items-center gap-1.5 font-bold text-slate-700">{{ `${money(order.subtotalAmount, order.currency)} + ${String(Number(order.feeAmount)).padStart(3, "0")}` }}</p></div>
                <div><p class="text-xs text-slate-500">Pembeli</p><p class="mt-1 flex items-center gap-1.5 font-bold text-slate-700">{{ order.purchaser?.fullName || order.purchaser?.email || "Tidak diketahui" }}</p></div>
                <div><p class="text-xs text-slate-500">{{ order.status === "PAID" ? "Disetujui" : "Batas pembayaran" }}</p><p class="mt-1 flex items-center gap-1.5 font-bold text-slate-700"><Clock3 aria-hidden="true" :size="14" />{{ date(order.paidAt || order.paymentExpiresAt) }}</p></div>
              </div>
            </div>
          </div>

          <div v-if="order.status === 'PENDING_PAYMENT'" class="mt-6 grid gap-5 border-t border-slate-900/10 pt-6 lg:grid-cols-[1.25fr_.75fr]">
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="text-xs font-extrabold text-slate-700 sm:col-span-2">Siswa penerima akses<select :value="selectedStudents[order.id] || ''" @change="selectedStudents = { ...selectedStudents, [order.id]: ($event.target as HTMLSelectElement).value }" class="mt-2 min-h-12 w-full rounded-md border border-slate-900/15 bg-white px-3 text-sm font-bold outline-none focus:border-orange-500"><option value="">Pilih satu siswa…</option><option v-for="student in students" :key="student.userId" :value="student.userId">{{ student.fullName }} · {{ student.studentNumber }} · {{ student.schoolName }}</option></select></label>
              <label class="text-xs font-extrabold text-slate-700">Referensi transfer <span class="text-red-600">*</span><input required :minlength="3" :maxlength="120" :value="references[order.id] || ''" @input="references = { ...references, [order.id]: ($event.target as HTMLInputElement).value }" placeholder="Contoh: mutasi 23/09 14:10" class="mt-2 min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></label>
              <label class="text-xs font-extrabold text-slate-700">Catatan pemeriksaan <span class="text-red-600">*</span><input required :minlength="5" :maxlength="500" :value="notes[order.id] || ''" @input="notes = { ...notes, [order.id]: ($event.target as HTMLInputElement).value }" placeholder="Nominal dan pengirim sesuai" class="mt-2 min-h-11 w-full rounded-md border border-slate-900/15 px-3 text-sm font-normal outline-none focus:border-orange-500" /></label>
            </div>
            <div class="flex flex-col justify-end gap-3 border-l-0 border-slate-900/10 lg:border-l lg:pl-5">
              <p class="flex gap-2 text-xs leading-6 text-slate-500"><ShieldCheck aria-hidden="true" class="mt-0.5 shrink-0 text-emerald-600" :size="15" /> Persetujuan mengaktifkan maksimal {{ order.items[0]?.plan.moduleLimit || "-" }} modul untuk satu siswa selama {{ order.items[0]?.offer.accessDurationDays || "masa penawaran" }} hari.</p>
              <button :disabled="busy === order.id || !selectedStudents[order.id] || (references[order.id]?.trim().length || 0) < 3 || (notes[order.id]?.trim().length || 0) < 5" @click="void approve(order)" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-emerald-700 px-4 text-sm font-extrabold text-white disabled:opacity-45"><CheckCircle2 aria-hidden="true" :size="17" /> Setujui &amp; aktifkan</button>
              <button :disabled="busy === order.id" @click="void reject(order)" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-red-200 px-4 text-xs font-extrabold text-red-700 disabled:opacity-45"><XCircle aria-hidden="true" :size="15" /> Tolak pembayaran</button>
            </div>
          </div>
          <div v-else :class="['mt-6 flex flex-col gap-3 border-l-2 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between', order.status === 'PAID' ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : order.status === 'EXPIRED' ? 'border-slate-400 bg-slate-50 text-slate-700' : 'border-red-600 bg-red-50 text-red-700']">
            <p class="flex items-center gap-2 font-bold"><CheckCircle2 v-if="order.status === 'PAID'" aria-hidden="true" :size="17" /><Clock3 v-else-if="order.status === 'EXPIRED'" aria-hidden="true" :size="17" /><XCircle v-else aria-hidden="true" :size="17" />{{ order.status === "PAID" ? "Paket telah aktif untuk siswa." : order.status === "EXPIRED" ? "Batas pembayaran telah lewat; siswa perlu membuat pesanan baru." : "Pembayaran ditolak; akses tidak diberikan." }}</p>
            <p v-if="order.beneficiary" class="flex items-center gap-2"><UserRound aria-hidden="true" :size="15" /> {{ order.beneficiary.fullName }} · {{ order.beneficiary.schoolName }}</p>
          </div>
        </article>
      </div>
    </div>
  </main>
</template>
