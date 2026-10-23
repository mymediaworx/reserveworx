@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="button" aria-disabled="true">Previous</span>
        @else
            <button type="button" class="button" wire:click="previousPage" wire:loading.attr="disabled">Previous</button>
        @endif
        <span class="muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <button type="button" class="button" wire:click="nextPage" wire:loading.attr="disabled">Next</button>
        @else
            <span class="button" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
