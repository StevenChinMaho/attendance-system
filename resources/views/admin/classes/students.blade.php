<x-layouts.app :title="$schoolClass->shortLabel().' 學生管理 - 國中點名系統'">
    <livewire:admin.student-manager :school-class="$schoolClass" />
</x-layouts.app>
