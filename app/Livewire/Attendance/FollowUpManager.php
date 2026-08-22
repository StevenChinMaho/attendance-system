<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceRecord;
use Livewire\Component;

class FollowUpManager extends Component
{
    public AttendanceRecord $record;

    public string $content = '';

    /**
     * 跟 Recorder::boot() 同樣的理由：每次 mount/hydrate 都重新檢查，
     * 不是只依賴父層頁面的路由 middleware——這個元件被嵌在 Recorder
     * 頁面裡，父層的 can:recordAttendance 只保證「能點名這個班」，不
     * 保證「能管理處理情形」（副班長就點名得了但沒有這個權限），需要
     * 自己再做一次獨立的授權檢查。
     */
    public function boot(): void
    {
        if (! isset($this->record)) {
            return;
        }

        $this->authorize('manageFollowUp', $this->record);
    }

    public function mount(AttendanceRecord $record): void
    {
        $this->record = $record;
    }

    public function addFollowUp(): void
    {
        $this->validate([
            'content' => ['required', 'string', 'max:1000'],
        ]);

        $this->record->followUps()->create([
            'created_by' => auth()->id(),
            'content' => $this->content,
        ]);

        $this->content = '';
    }

    public function render()
    {
        return view('livewire.attendance.follow-up-manager', [
            'followUps' => $this->record->followUps()->with('createdBy')->get(),
        ]);
    }
}
