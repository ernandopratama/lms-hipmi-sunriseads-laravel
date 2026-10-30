<?php
use App\Http\Controllers\{SessionController,PublicLeadController,PublicContentController,PublicStatisticsController,PublicAnalyticsController,SchoolDashboardApiController,TeacherDashboardApiController,StudentDashboardApiController,EffectiveAccessApiController};
use Illuminate\Support\Facades\Route;
// Search-engine files, equivalent to the source app's robots.ts and sitemap.ts.
Route::get('/robots.txt', function () {
    $body = "User-Agent: *\nAllow: /\nDisallow: /dashboard/\nDisallow: /api/\nDisallow: /login\nDisallow: /lupa-password\nDisallow: /reset-password\nDisallow: /aktivasi\n\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n";
    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});
Route::get('/sitemap.xml', function () {
    $base = rtrim(config('app.url'), '/');
    $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (['', '/tentang', '/fitur', '/paket', '/cara-kerja', '/untuk-sekolah', '/keamanan', '/faq', '/kontak', '/daftar-sekolah', '/kebijakan-privasi', '/syarat-ketentuan', '/kebijakan-data-anak'] as $path) {
        $xml .= '<url><loc>'.e($base.$path).'</loc><lastmod>'.now()->toDateString().'</lastmod><changefreq>'.($path === '' ? 'weekly' : 'monthly').'</changefreq><priority>'.($path === '' ? '1' : '0.7').'</priority></url>';
    }
    return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
});
Route::get('/api/csrf', [SessionController::class, 'csrf']);
Route::get('/api/session', [SessionController::class, 'current']);
Route::post('/api/session', [SessionController::class, 'login']);
Route::delete('/api/session', [SessionController::class, 'logout'])->middleware('auth');
Route::post('/api/backend/leads', PublicLeadController::class)->middleware('throttle:5,10,public-lead');
Route::get('/api/backend/public/content', PublicContentController::class)->middleware('throttle:60,1,public-content');
Route::get('/api/backend/public/statistics', PublicStatisticsController::class)->middleware('throttle:60,1,public-statistics');
Route::post('/api/backend/public/analytics/events', PublicAnalyticsController::class)->middleware('throttle:30,1,public-analytics');
Route::middleware(['auth', 'active', 'source-session'])->group(function () {
 Route::get('/api/backend/auth/schools', [SessionController::class, 'schools']);
 Route::post('/api/school-context', [SessionController::class, 'selectSchool']);
 Route::get('/api/backend/dashboard/school', SchoolDashboardApiController::class);
 Route::get('/api/backend/dashboard/teacher', TeacherDashboardApiController::class);
 Route::get('/api/backend/dashboard/student', StudentDashboardApiController::class);
 Route::get('/api/backend/access/effective', EffectiveAccessApiController::class);
 Route::get('/api/materials/{id}', [\App\Http\Controllers\MaterialFileController::class, 'show']);
});
// Pages rewritten in Vue (resources/js/router.ts) share the `app` view; vue-router picks the page.
foreach (['/', '/paket', '/lupa-password', '/reset-password', '/aktivasi', '/offline', '/checkout/{offerSlug}', '/portofolio/{slug}', '/verifikasi-sertifikat/{code}'] as $path) {
    Route::view($path, 'app');
}
Route::view('/login', 'app')->name('login');
Route::view('/{slug}', 'app')->whereIn('slug', [
    'tentang', 'fitur', 'cara-kerja', 'untuk-sekolah', 'keamanan', 'faq', 'kontak',
    'daftar-sekolah', 'kebijakan-privasi', 'syarat-ketentuan', 'kebijakan-data-anak',
]);
// Dashboard pages (Vue).
Route::middleware(['auth', 'active'])->group(function () {
    foreach (['/dashboard/toko/review', '/dashboard/review', '/dashboard/monitoring', '/dashboard/privasi', '/dashboard/dukungan', '/dashboard/toko',
        '/dashboard/pengelolaan', '/dashboard/affiliate/review', '/dashboard/kurikulum', '/dashboard/pembelian', '/dashboard/laporan',
        '/dashboard/identitas/verifikasi', '/dashboard/affiliate', '/dashboard/review/{id}', '/dashboard/identitas', '/dashboard/keamanan',
        '/dashboard/pembelian/approval', '/dashboard/master-data', '/dashboard/tugas'] as $path) {
        Route::view($path, 'app');
    }
    Route::view('/dashboard', 'app')->name('dashboard');
});

require __DIR__.'/backend.php';
