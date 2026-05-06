<?php

use App\Support\PrefixedIds;

it('creates lowercase prefixed ids', function (): void {
    $id = PrefixedIds::make('file');

    expect($id)
        ->toStartWith('file_')
        ->toBe(strtolower($id));
});
