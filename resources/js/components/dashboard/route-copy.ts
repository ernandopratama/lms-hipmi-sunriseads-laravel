export const roleCopy: Record<string, string> = {
  "super-admin": "Super Admin",
  "program-manager": "Pengelola Program",
  "school-admin": "Admin Sekolah",
  principal: "Pimpinan Sekolah",
  teacher: "Guru",
  student: "Murid",
  parent: "Orang Tua / Wali",
  support: "Tim Dukungan",
};

// Most specific paths first: the first match decides the page heading.
export const routeCopy = [
  { path: "/dashboard/toko/review", title: "Review Student Store", eyebrow: "Pembelajaran" },
  { path: "/dashboard/affiliate/review", title: "Verifikasi Affiliate", eyebrow: "Pembelajaran" },
  { path: "/dashboard/identitas/verifikasi", title: "Verifikasi Identitas", eyebrow: "Operasional sekolah" },
  { path: "/dashboard/master-data", title: "Data Sekolah", eyebrow: "Operasional sekolah" },
  { path: "/dashboard/monitoring", title: "Pendampingan Murid", eyebrow: "Operasional sekolah" },
  { path: "/dashboard/pengelolaan", title: "Pengelolaan Instansi", eyebrow: "Operasional sekolah" },
  { path: "/dashboard/laporan", title: "Laporan & Ekspor", eyebrow: "Operasional sekolah" },
  { path: "/dashboard/kurikulum", title: "Struktur & Rubrik", eyebrow: "Pembelajaran" },
  { path: "/dashboard/review", title: "Antrean Review", eyebrow: "Pembelajaran" },
  { path: "/dashboard/tugas", title: "Materi & Tugas", eyebrow: "Pembelajaran" },
  { path: "/dashboard/affiliate", title: "Affiliate Saya", eyebrow: "Praktik digital" },
  { path: "/dashboard/toko", title: "Student Store", eyebrow: "Praktik digital" },
  { path: "/dashboard/identitas", title: "Identitas Saya", eyebrow: "Akun" },
  { path: "/dashboard/dukungan", title: "Bantuan & Sertifikat", eyebrow: "Bantuan" },
  { path: "/dashboard/privasi", title: "Privasi & Persetujuan", eyebrow: "Akun" },
  { path: "/dashboard/keamanan", title: "Keamanan Akun", eyebrow: "Akun" },
  { path: "/dashboard/pembelian/approval", title: "Approval Pembayaran", eyebrow: "Operasional instansi" },
  { path: "/dashboard/pembelian", title: "Pembelian & Akses", eyebrow: "Akun" },
  { path: "/dashboard", title: "Ringkasan", eyebrow: "Beranda" },
];

export function isActiveRoute(pathname: string, href: string) {
  return pathname === href || (href !== "/dashboard" && pathname.startsWith(`${href}/`));
}
