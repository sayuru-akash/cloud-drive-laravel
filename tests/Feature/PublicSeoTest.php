<?php

use App\Enums\FileStatus;
use App\Enums\ResourceVisibility;
use App\Enums\ShareMode;
use App\Enums\ShareResourceType;
use App\Models\DriveFile;
use App\Models\FileVersion;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Support\Str;

it('serves robots rules that expose only public marketing and legal pages', function (): void {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('User-agent: *', false)
        ->assertSee('Allow: /$', false)
        ->assertSee('Allow: /privacy$', false)
        ->assertSee('Allow: /terms$', false)
        ->assertSee('Disallow: /dashboard', false)
        ->assertSee('Disallow: /s/', false)
        ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

it('serves a sitemap containing only canonical public pages', function (): void {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<urlset', false)
        ->assertSee('<loc>'.route('home').'</loc>', false)
        ->assertSee('<loc>'.route('privacy').'</loc>', false)
        ->assertSee('<loc>'.route('terms').'</loc>', false)
        ->assertDontSee('/dashboard</loc>', false)
        ->assertDontSee('/s/</loc>', false);
});

it('renders indexable metadata and canonical URLs on public pages', function (string $path): void {
    $this->get($path)
        ->assertOk()
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertSee('name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1"', false)
        ->assertSee('property="og:image" content="'.url('/og-image.png').'"', false)
        ->assertSee('property="og:image:width" content="1200"', false)
        ->assertSee('rel="canonical" href="'.url($path).'"', false)
        ->assertSee('type="application/ld+json"', false);
})->with([
    'home' => '/',
    'privacy' => '/privacy',
    'terms' => '/terms',
]);

it('keeps authenticated and auth surfaces out of search', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('name="robots" content="noindex,nofollow,noarchive"', false)
        ->assertDontSee('rel="canonical"', false);
});

it('keeps public share crawler previews useful without exposing file metadata', function (): void {
    $owner = User::factory()->create();
    $file = DriveFile::query()->create([
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'original_name' => 'board-private-strategy.pdf',
        'display_name' => 'board-private-strategy.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 512,
        'status' => FileStatus::Ready,
        'visibility' => ResourceVisibility::Private,
    ]);
    $version = FileVersion::query()->create([
        'file_id' => $file->id,
        'version_number' => 1,
        'storage_bucket' => 'test-bucket',
        'storage_key' => 'objects/board-private-strategy.pdf',
        'size_bytes' => 512,
        'mime_type' => 'application/pdf',
        'uploaded_by_user_id' => $owner->id,
    ]);
    $file->update(['current_version_id' => $version->id]);

    ShareLink::query()->create([
        'resource_type' => ShareResourceType::File,
        'resource_id' => $file->id,
        'token_hash' => hash('sha256', 'share-preview-token'),
        'token_encrypted' => 'share-preview-token',
        'created_by_user_id' => $owner->id,
        'mode' => ShareMode::Download,
        'expires_at' => now()->addDay(),
    ]);

    $response = $this->get('/s/share-preview-token')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('name="robots" content="noindex,nofollow,noarchive"', false)
        ->assertSee('Secure file share', false)
        ->assertSee('Secure download-only file sharing for authorized Cloud Drive recipients.', false)
        ->assertSee('property="og:url" content="'.url('/').'"', false)
        ->assertSee('property="og:image" content="'.url('/og-image.png').'"', false);

    $head = Str::before($response->getContent(), '<body');

    expect($head)
        ->not->toContain('board-private-strategy.pdf')
        ->not->toContain('objects/board-private-strategy.pdf')
        ->not->toContain('share-preview-token');
});

it('renders a branded noindex page for missing routes', function (): void {
    $this->get('/not-a-real-cloud-drive-page')
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('This page could not be found.', false)
        ->assertSee('/favicon.svg', false)
        ->assertSee('name="robots" content="noindex,nofollow,noarchive"', false);
});
