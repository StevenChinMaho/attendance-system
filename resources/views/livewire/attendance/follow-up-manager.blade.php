<div class="mt-1 rounded-md bg-slate-50 p-3 dark:bg-slate-800/60">
    @if ($followUps->isNotEmpty())
        <ul class="space-y-1 text-xs text-slate-600 dark:text-slate-400">
            @foreach ($followUps as $followUp)
                <li>
                    <span class="text-slate-400 dark:text-slate-500">{{ $followUp->created_at->format('H:i') }}</span>
                    {{ $followUp->content }}
                    <span class="text-slate-400 dark:text-slate-500">— {{ $followUp->createdBy->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addFollowUp" class="mt-2 flex gap-2">
        <input
            type="text"
            wire:model="content"
            placeholder="例如：電聯未接、9:19已到"
            class="field-input mt-0 flex-1 py-1 text-xs"
        >
        <button type="submit" class="btn-primary btn-xs">
            記錄
        </button>
    </form>
    @error('content') <p class="field-error text-xs">{{ $message }}</p> @enderror
</div>
