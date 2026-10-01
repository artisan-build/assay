<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Curate Assay run</title>
    <link rel="stylesheet" href="/build/assets/app.css">
</head>
<body>
<main data-testid="run-curation">
    <h1>Curate run</h1>
    <form method="post" action="{{ route('assay.runs.flag', ['run' => $run]) }}" data-testid="run-curation-flag">
        @csrf
        @method('put')
        <label for="labels">Labels</label>
        <input id="labels" name="labels[]" value="{{ $flag['labels'][0] ?? '' }}">
        <label for="rating">Rating</label>
        <select id="rating" name="rating">
            <option value="">Not rated</option>
            @foreach (['pass', 'fail', 1, 2, 3, 4, 5] as $rating)
                <option value="{{ $rating }}" @selected($flag['rating'] === $rating)>{{ $rating }}</option>
            @endforeach
        </select>
        <label for="note">Note</label>
        <textarea id="note" name="note">{{ $flag['note'] }}</textarea>
        <button type="submit">Save flag</button>
    </form>
</main>
</body>
</html>
