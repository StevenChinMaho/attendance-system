<div class="mx-auto max-w-md px-4 py-10">
    <h1 class="page-title">變更密碼</h1>

    @error('must_change_password')
        <div class="alert-info mt-4">
            {{ $message }}
        </div>
    @enderror

    @if (session('status'))
        <div class="alert-success mt-4">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="updatePassword" class="surface mt-6 space-y-4 p-6">
        <div>
            <label class="field-label">目前密碼</label>
            <input type="password" wire:model="currentPassword" class="field-input">
            @error('currentPassword') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="field-label">新密碼</label>
            <input type="password" wire:model="newPassword" class="field-input">
            @error('newPassword') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="field-label">確認新密碼</label>
            <input type="password" wire:model="newPassword_confirmation" class="field-input">
        </div>

        <button type="submit" class="btn-primary">
            更新密碼
        </button>
    </form>
</div>
