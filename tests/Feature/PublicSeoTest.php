<?php

it('serves robots rules that keep app-only areas out of search', function (): void {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /dashboard', false)
        ->assertSee('Disallow: /files', false)
        ->assertSee('Disallow: /s/', false)
        ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
});

it('serves a sitemap for public indexable pages only', function (): void {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.url('/').'</loc>', false)
        ->assertSee('<loc>'.url('/privacy').'</loc>', false)
        ->assertDontSee('/dashboard', false)
        ->assertDontSee('/files', false);
});
