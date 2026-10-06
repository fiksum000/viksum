@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><h1>Pengguna dan Role</h1></div>
<div class="row g-3">
    <div class="col-xl-4">
        <form method="POST" action="{{ route('users.store') }}" class="card card-body">@csrf
            <h2 class="h5">Tambah Pengguna</h2>
            <label class="form-label">Nama</label><input name="name" class="form-control mb-2" required>
            <label class="form-label">Email</label><input name="email" type="email" class="form-control mb-2" required>
            <label class="form-label">Role</label><select name="role" class="form-select mb-2">@foreach($assignableRoles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach</select>
            <label class="form-label">Password awal (minimal 12 karakter)</label><input name="password" type="password" class="form-control mb-2" autocomplete="new-password" required>
            <label class="form-label">Ulangi password</label><input name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
            <button class="btn btn-primary mt-3">Buat pengguna</button>
        </form>
    </div>
    <div class="col-xl-8">
        <div class="table-responsive rounded"><table class="table align-middle mb-0"><thead><tr><th>Nama / Email</th><th>Role</th><th>Ubah</th><th></th></tr></thead><tbody>
        @foreach($users as $user)
            <tr><td>{{ $user->name }}<div class="text-muted small">{{ $user->email }}</div></td><td>{{ $user->role }}</td><td>
                <form method="POST" action="{{ route('users.update',$user) }}" class="d-flex flex-wrap gap-2">@csrf @method('PUT')
                    <input name="name" value="{{ $user->name }}" class="form-control form-control-sm" style="max-width:160px" required>
                    <input name="email" type="email" value="{{ $user->email }}" class="form-control form-control-sm" style="max-width:200px" required>
                    <select name="role" class="form-select form-select-sm" style="max-width:150px">@foreach($assignableRoles as $role)<option value="{{ $role }}" @selected($user->role===$role)>{{ $role }}</option>@endforeach</select>
                    <input name="password" type="password" placeholder="Password baru (opsional)" class="form-control form-control-sm" autocomplete="new-password">
                    <input name="password_confirmation" type="password" placeholder="Ulangi password baru" class="form-control form-control-sm" autocomplete="new-password">
                    <button class="btn btn-sm btn-outline-primary">Simpan</button>
                </form>
            </td><td><form method="POST" action="{{ route('users.destroy',$user) }}" onsubmit="return confirm('Hapus pengguna ini?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" @disabled($user->id===session('user_id'))>Hapus</button></form></td></tr>
        @endforeach
        </tbody></table></div><div class="mt-3">{{ $users->links() }}</div>
    </div>
</div>
@endsection
