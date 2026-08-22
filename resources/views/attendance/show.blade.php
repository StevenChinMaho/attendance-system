<x-layouts.app :title="$schoolClass->label().' 點名 - 國中點名系統'">
    <livewire:attendance.recorder :school-class="$schoolClass" />
</x-layouts.app>
