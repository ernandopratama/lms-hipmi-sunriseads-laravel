import { createRouter, createWebHistory } from "vue-router";
import { infoPages } from "./pages/info-pages";

// Every page is rendered by Vue (original-ui is no longer served).
// Every path here also needs a Laravel route returning the `app` view in routes/web.php.
const router = createRouter({
  history: createWebHistory(),
  // Active styling is done with explicit classes (aria-current), as in the source app.
  linkActiveClass: "",
  linkExactActiveClass: "",
  routes: [
    { path: "/", component: () => import("./pages/Home.vue") },
    { path: "/paket", component: () => import("./pages/Paket.vue"), meta: { title: "Paket Belajar" } },
    { path: "/login", component: () => import("./pages/Login.vue"), meta: { title: "Masuk" } },
    { path: "/lupa-password", component: () => import("./pages/ForgotPassword.vue"), meta: { title: "Lupa Kata Sandi" } },
    { path: "/reset-password", component: () => import("./pages/ResetPassword.vue"), meta: { title: "Atur Ulang Kata Sandi" } },
    { path: "/aktivasi", component: () => import("./pages/Activation.vue"), meta: { title: "Aktivasi Akun" } },
    { path: "/offline", component: () => import("./pages/Offline.vue"), meta: { title: "Offline" } },
    { path: "/checkout/:offerSlug", component: () => import("./pages/Checkout.vue"), meta: { title: "Konfirmasi Pembelian" } },
    { path: "/portofolio/:slug", component: () => import("./pages/Portfolio.vue"), meta: { title: "Portofolio" } },
    { path: "/verifikasi-sertifikat/:code", component: () => import("./pages/CertificateVerification.vue"), meta: { title: "Verifikasi Sertifikat" } },
    { path: `/:slug(${Object.keys(infoPages).join("|")})`, component: () => import("./pages/InfoPage.vue") },
    {
      path: "/dashboard",
      component: () => import("./layouts/DashboardLayout.vue"),
      children: [
        { path: "toko/review", component: () => import("./pages/dashboard/StoreReview.vue") },
        { path: "review", component: () => import("./pages/dashboard/ReviewQueue.vue") },
        { path: "monitoring", component: () => import("./pages/dashboard/Monitoring.vue") },
        { path: "privasi", component: () => import("./pages/dashboard/Privacy.vue") },
        { path: "dukungan", component: () => import("./pages/dashboard/Support.vue") },
        { path: "toko", component: () => import("./pages/dashboard/Store.vue") },
        { path: "", component: () => import("./pages/dashboard/Overview.vue") },
        { path: "pengelolaan", component: () => import("./pages/dashboard/Management.vue") },
        { path: "affiliate/review", component: () => import("./pages/dashboard/AffiliateReview.vue") },
        { path: "kurikulum", component: () => import("./pages/dashboard/Curriculum.vue") },
        { path: "pembelian", component: () => import("./pages/dashboard/Purchases.vue") },
        { path: "laporan", component: () => import("./pages/dashboard/Reports.vue") },
        { path: "identitas/verifikasi", component: () => import("./pages/dashboard/IdentityVerification.vue") },
        { path: "affiliate", component: () => import("./pages/dashboard/Affiliate.vue") },
        { path: "review/:id", component: () => import("./pages/dashboard/ReviewDetail.vue") },
        { path: "identitas", component: () => import("./pages/dashboard/Identity.vue") },
        { path: "keamanan", component: () => import("./pages/dashboard/Security.vue") },
        { path: "pembelian/approval", component: () => import("./pages/dashboard/PaymentApproval.vue") },
        { path: "master-data", component: () => import("./pages/dashboard/MasterData.vue") },
        { path: "tugas", component: () => import("./pages/dashboard/Assignments.vue") },
      ],
    },
  ],
  scrollBehavior(to) {
    return to.hash ? { el: to.hash } : { top: 0 };
  },
});

router.afterEach((to) => {
  const title = (to.meta.title as string | undefined) ?? (to.params.slug ? infoPages[String(to.params.slug)]?.title : undefined);
  document.title = title ? `${title} | Sunrise Academy` : "Sunrise Academy";
});

export default router;
