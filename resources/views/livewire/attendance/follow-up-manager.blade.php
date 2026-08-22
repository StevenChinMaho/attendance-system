<div class="mt-1 rounded-md bg-slate-50 p-3">
    @if ($followUps->isNotEmpty())
        <ul class="space-y-1 text-xs text-slate-600">
            @foreach ($followUps as $followUp)
                <li>
                    <span class="text-slate-400">{{ $followUp->created_at->format('H:i') }}</span>
                    {{ $followUp->content }}
                    <span class="text-slate-400">— {{ $followUp->createdBy->name }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addFollowUp" class="mt-2 flex gap-2">
        <input
            type="text"
            wire:model="content"
            placeholder="例如：電聯未接、9:19已到"
            class="flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs"
        >
        <button type="submit" class="rounded-md bg-slate-700 px-2 py-1 text-xs font-medium text-white hover:bg-slate-600">
            記錄
        </button>
    </form>
    @error('content') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
</div>
