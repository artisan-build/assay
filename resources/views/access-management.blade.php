<x-bfc-layout title="Assay access">
    @push('head')
    <link rel="stylesheet" href="/build/assets/app.css">
    @endpush
<div data-testid="access-management">
    <h1>Content access</h1>
    <table>
        <thead>
            <tr>
                <th scope="col">Person</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col">Usage</th>
                <th scope="col">Content</th>
                <th scope="col">Source</th>
                <th scope="col">Redundant</th>
                <th scope="col">Manage</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user['name'] }} ({{ $user['email'] }})</td>
                    <td>{{ $user['role'] }}</td>
                    <td>{{ $user['status'] }}</td>
                    <td>{{ $user['usage'] ? 'yes' : 'no' }}</td>
                    <td>{{ $user['content'] ? 'yes' : 'no' }}</td>
                    <td>{{ $user['source'] }}</td>
                    <td>{{ $user['redundant'] ? 'yes' : 'no' }}</td>
                    <td>
                        <form method="post" action="{{ route('assay.access.set', ['actor' => $user['actor_id']]) }}">
                            @csrf
                            @method('put')
                            <label>
                                Access
                                <select name="access">
                                    <option value="granted">granted</option>
                                    <option value="denied">denied</option>
                                </select>
                            </label>
                            <label>
                                Reason
                                <input name="reason" maxlength="500">
                            </label>
                            <button type="submit">Save</button>
                        </form>
                        <form method="post" action="{{ route('assay.access.reset', ['actor' => $user['actor_id']]) }}">
                            @csrf
                            @method('delete')
                            <button type="submit">Reset to role default</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
</x-bfc-layout>
