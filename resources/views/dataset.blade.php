<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $dataset['name'] }} - Assay dataset</title>
    <link rel="stylesheet" href="/build/assets/app.css">
</head>
<body>
<main data-testid="dataset-detail">
    <h1>{{ $dataset['name'] }}</h1>
    <p data-testid="dataset-retention">{{ $dataset['retention_days'] === null ? 'No expiry' : $dataset['retention_days'].' days from item addition' }}</p>
    <form method="post" action="{{ route('assay.datasets.items.add', ['dataset' => $dataset['id']]) }}" data-testid="dataset-add-item">
        @csrf
        <label for="run_id">Run ID</label>
        <input id="run_id" name="run_id" required>
        <button type="submit">Add immutable snapshot</button>
    </form>
    <form method="post" action="{{ route('assay.datasets.exports.request', ['dataset' => $dataset['id']]) }}" data-testid="dataset-export-request">
        @csrf
        <button type="submit">Request JSONL export</button>
    </form>
    <section data-testid="dataset-items">
        @foreach ($dataset['items'] as $item)
            <article data-testid="dataset-item">{{ $item['id'] }}</article>
        @endforeach
    </section>
</main>
</body>
</html>
