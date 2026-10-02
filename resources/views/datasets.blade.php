<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assay datasets</title>
    <link rel="stylesheet" href="/build/assets/app.css">
</head>
<body>
<main data-testid="datasets">
    <h1>Datasets</h1>
    <section data-testid="datasets-retention-disclosure">
        <h2>Curated-copy retention</h2>
        <p>Dataset items are immutable curated copies. They can outlive their source run content, but their retention cannot exceed usage metadata retention so source ancestry remains available.</p>
    </section>
    <form method="post" action="{{ route('assay.datasets.create') }}" data-testid="datasets-create">
        @csrf
        <label for="name">Name</label>
        <input id="name" name="name" required>
        <fieldset>
            <legend>Retention</legend>
            <label><input name="retention_mode" type="radio" value="default" checked> Operator default</label>
            <label><input name="retention_mode" type="radio" value="custom"> Custom days</label>
            <input id="retention_days" name="retention_days" type="number" min="1" max="{{ config('assay.retention.usage_days') }}" aria-label="Custom retention days">
        </fieldset>
        <button type="submit">Create dataset</button>
    </form>
    <section data-testid="datasets-list">
        @foreach ($datasets as $dataset)
            <article>
                <a href="{{ route('assay.datasets.show', ['dataset' => $dataset['id']]) }}">{{ $dataset['name'] }}</a>
                <span>{{ $dataset['item_count'] }}</span>
            </article>
        @endforeach
    </section>
</main>
</body>
</html>
