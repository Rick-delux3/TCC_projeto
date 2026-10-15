<section class="mb-4" aria-labelledby="batch-title">
    <h2 id="batch-title" class="h5">Andamento do pacote</h2>
    @if ($awaitingBatch)
        <div class="alert alert-info" role="status">Aguardando a criação do pacote de análises deste lead.</div>
    @else
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <span>Pacote #{{ $batchData['id'] }} · {{ $statusLabels[$batchData['status']] ?? $batchData['status'] }}</span>
                    <strong>{{ $progress['finished'] }} de {{ $progress['total'] }} análises finalizadas ({{ $progress['percent'] }}%)</strong>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge text-bg-success">{{ $progress['approved'] }} aprovadas</span>
                    <span class="badge text-bg-danger">{{ $progress['rejected'] }} recusadas</span>
                    <span class="badge text-bg-warning">{{ $progress['failed'] }} com falha</span>
                    <span class="badge text-bg-secondary">{{ $progress['pending'] }} aguardando finalização</span>
                </div>
                <p class="mb-2"><strong>Resultado consolidado:</strong>
                    {{ $result['is_final'] ? (['approved' => 'Aprovado', 'rejected' => 'Recusado', 'failed' => 'Falha na análise'][$result['status']] ?? 'Indisponível') : 'Aguardando conclusão' }}
                </p>
                <p class="small text-body-secondary mb-0">
                    Início: {{ $formatDate($batchData['started_at']) }} ·
                    Conclusão: {{ $batchData['finished_at'] ? $formatDate($batchData['finished_at']) : 'Ainda não concluído' }} ·
                    Última atualização: {{ $formatDate($batchData['updated_at']) }}
                </p>
            </div>
        </div>
    @endif
</section>
