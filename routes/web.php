<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeletedController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\FilePreviewController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FolderUploadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\ShareLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/robots.txt', function () {
    $content = implode("\n", [
        'User-agent: *',
        'Disallow: /',
        '',
    ]);

    return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

Route::get('/sitemap.xml', function () {
    return response()
        ->view('sitemap', [
            'urls' => [
            ],
        ])
        ->header('Content-Type', 'application/xml; charset=UTF-8');
})->name('sitemap');

Route::get('/api/health', HealthController::class)->name('health');

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('/privacy', 'public/Privacy')->name('privacy');
Route::get('/s/{token}', [PublicShareController::class, 'show'])->name('public-share.show');
Route::get('/api/public-share/{token}/download', [PublicShareController::class, 'download'])->name('public-share.download');
Route::get('/api/public-share/{token}/preview', [PublicShareController::class, 'preview'])->name('public-share.preview');
Route::get('/api/public-share/{token}/files/{file}/download', [PublicShareController::class, 'downloadFile'])->name('public-share.files.download');
Route::get('/api/public-share/{token}/files/{file}/preview', [PublicShareController::class, 'previewFile'])->name('public-share.files.preview');

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('files', [FilesController::class, 'index'])->name('files.index');
    Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
    Route::post('/api/folders/upload-tree', FolderUploadController::class)->name('api.folders.upload-tree');
    Route::patch('folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::delete('folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
    Route::patch('files/{file}', [FileController::class, 'update'])->name('files.update');
    Route::delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
    Route::post('files/{file}/shares', [ShareLinkController::class, 'store'])->name('files.shares.store');
    Route::post('folders/{folder}/shares', [ShareLinkController::class, 'storeFolder'])->name('folders.shares.store');
    Route::get('shared', [ShareLinkController::class, 'index'])->name('shared.index');
    Route::patch('shares/{share}/revoke', [ShareLinkController::class, 'revoke'])->name('shares.revoke');
    Route::get('deleted', [DeletedController::class, 'index'])->name('deleted.index');
    Route::patch('deleted/files/{file}/restore', [DeletedController::class, 'restoreFile'])->name('deleted.files.restore');
    Route::patch('deleted/folders/{folder}/restore', [DeletedController::class, 'restoreFolder'])->name('deleted.folders.restore');
    Route::delete('deleted/files/{file}/hard-delete', [DeletedController::class, 'hardDeleteFile'])->middleware('admin')->name('deleted.files.hard-delete');
    Route::delete('deleted/folders/{folder}/hard-delete', [DeletedController::class, 'hardDeleteFolder'])->middleware('admin')->name('deleted.folders.hard-delete');

    Route::post('/api/files/initiate-upload', [FileUploadController::class, 'initiate'])->name('api.files.initiate-upload');
    Route::post('/api/files/{file}/multipart-part', [FileUploadController::class, 'multipartPart'])->name('api.files.multipart-part');
    Route::post('/api/files/{file}/complete-upload', [FileUploadController::class, 'complete'])->name('api.files.complete-upload');
    Route::post('/api/files/{file}/cancel-upload', [FileUploadController::class, 'cancel'])->name('api.files.cancel-upload');
    Route::get('/api/files/{file}/download', FileDownloadController::class)->name('api.files.download');
    Route::get('/api/files/{file}/preview', FilePreviewController::class)->name('api.files.preview');

    Route::middleware('admin')->group(function () {
        Route::get('admin', [AdminController::class, 'index'])->name('admin.index');
        Route::post('admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
        Route::patch('admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
        Route::patch('admin/settings', [AdminController::class, 'updateSettings'])->name('admin.settings.update');
        Route::get('audit', AuditController::class)->name('audit.index');
        Route::redirect('admin/audit', '/audit');
    });
});

require __DIR__.'/settings.php';
