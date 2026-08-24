<x-layouts.app :title="$schoolClass->shortLabel().' 學生名單 - 國中點名系統'">
    <livewire:admin.class-roster-manager :school-class="$schoolClass" />
</x-layouts.app>
