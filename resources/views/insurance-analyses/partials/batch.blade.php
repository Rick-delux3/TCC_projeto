<section class="ia-batch mb-4" aria-labelledby="batch-title" data-analysis-component="batch" data-batch-final="{{ ($result['is_final'] ?? false) ? 'true' : 'false' }}">
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
                <div class="progress" role="progressbar" aria-labelledby="batch-title" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: {{ $progress['percent'] }}%"></div>
                </div>
                @if ($nvs)
                    <dl class="ia-ledger">
                        @foreach (['approved' => 'Aprovadas', 'rejected' => 'Recusadas', 'failed' => 'Com falha', 'pending' => 'Em aberto'] as $state => $label)
                            <div data-progress-metric="{{ $state }}">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $progress[$state] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge text-bg-success">{{ $progress['approved'] }} aprovadas</span>
                    <span class="badge text-bg-danger">{{ $progress['rejected'] }} recusadas</span>
                    <span class="badge text-bg-warning">{{ $progress['failed'] }} com falha</span>
                    <span class="badge text-bg-secondary">{{ $progress['pending'] }} aguardando finalização</span>
                </div>
                @endif
                <p class="mb-2"><strong>Resultado consolidado:</strong>
                    <span class="ia-result" data-result="{{ $result['is_final'] ? $result['status'] : 'pending' }}">{{ $result['is_final'] ? (['approved' => 'Aprovado', 'rejected' => 'Recusado', 'failed' => 'Falha na análise'][$result['status']] ?? 'Indisponível') : 'Aguardando conclusão' }}</span>
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
