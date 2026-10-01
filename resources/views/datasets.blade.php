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
        <p>Dataset items are immutable curated copies. They can outlive their source run content, use their dataset's retention period from the date each item was added, and may explicitly have no expiry.</p>
    </section>
    <form method="post" action="{{ route('assay.datasets.create') }}" data-testid="datasets-create">
        @csrf
        <label for="name">Name</label>
        <input id="name" name="name" required>
        <label for="retention_days">Retention days (leave blank for no expiry)</label>
        <input id="retention_days" name="retention_days" type="number" min="1">
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
