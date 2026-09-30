<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Assay') : config('app.name', 'Assay') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
<link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@fluxAppearance
