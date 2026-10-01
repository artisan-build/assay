<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assay usage</title>
    <link rel="stylesheet" href="/build/assets/app.css">
</head>
<body>
<main data-testid="dashboard">
    <h1>Usage dashboard</h1>

    <form method="get" action="{{ route('assay.dashboard') }}" data-testid="dashboard-metric-selector">
        <label for="metric">Usage metric</label>
        <select id="metric" name="metric">
            @foreach ($metrics as $metric)
                <option value="{{ $metric->value }}" @selected($metric === $selectedMetric)>
                    {{ str($metric->value)->replace('_', ' ') }}
                </option>
            @endforeach
        </select>
        <button type="submit">Apply</button>
    </form>

    @foreach ($tables as $name => $table)
        <section data-testid="dashboard-{{ $name }}">
            <h2>{{ str($name)->replace('-', ' ')->title() }}</h2>
            <table>
                <thead>
                    <tr>
                        @foreach ($table->headers as $header)
                            <th scope="col">{{ str($header)->replace('_', ' ') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($table->headers as $header)
                                <td>
                                    @if ($name === 'top-runs' && $header === 'run_tree_id' && $decision->content)
                                        <a href="{{ route('assay.runs.tree', ['run' => $row[$header]]) }}">Run tree</a>
                                    @else
                                        {{ $row[$header] ?? '' }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach
</main>
</body>
</html>
