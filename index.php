
<?php
/**
 * Dashboard de Monitoramento - Somente Visualização
 * Este arquivo apenas exibe os dados coletados pelo monitor.php
 */

// Configurações
$data_file = __DIR__ . '/monitoring_data.json';
$status_file = __DIR__ . '/status.json';

// Função para calcular uptime
function calculateUptime($site_data) {
    if (empty($site_data)) return 0;
    
    $total_checks = count($site_data);
    $successful_checks = 0;
    
    foreach ($site_data as $check) {
        if ($check['status'] >= 200 && $check['status'] < 400) {
            $successful_checks++;
        }
    }
    
    return $total_checks > 0 ? round(($successful_checks / $total_checks) * 100, 2) : 0;
}

// Função para obter dados das últimas 24 horas
function getLast24HoursData($site_data) {
    $cutoff_time = time() - (24 * 60 * 60);
    return array_filter($site_data, function($check) use ($cutoff_time) {
        return $check['timestamp'] >= $cutoff_time;
    });
}

// Carrega dados
$historical_data = [];
$status_summary = [];

if (file_exists($data_file)) {
    $content = file_get_contents($data_file);
    $historical_data = $content ? json_decode($content, true) : [];
}

if (file_exists($status_file)) {
    $content = file_get_contents($status_file);
    $status_summary = $content ? json_decode($content, true) : [];
}

// Se não há dados históricos, mostra mensagem
if (empty($historical_data)) {
    $no_data = true;
    $results = [];
} else {
    $no_data = false;
    $results = [];
    
    foreach ($historical_data as $site_key => $site_data) {
        if (!isset($site_data['checks']) || empty($site_data['checks'])) {
            continue;
        }
        
        // Pega a última verificação
        $last_check = end($site_data['checks']);
        
        // Calcula métricas das últimas 24h
        $last_24h_data = getLast24HoursData($site_data['checks']);
        $uptime = calculateUptime($last_24h_data);
        
        // Calcula tempo médio de resposta das últimas 24h
        $avg_response_time = 0;
        if (!empty($last_24h_data)) {
            $total_response = array_sum(array_column($last_24h_data, 'response_time'));
            $avg_response_time = round($total_response / count($last_24h_data), 0);
        }
        
        $results[] = [
            'name' => $site_data['name'] ?? 'Site Desconhecido',
            'url' => $site_data['url'] ?? '',
            'status' => $last_check['status'],
            'response_time' => $last_check['response_time'],
            'uptime' => $uptime,
            'avg_response_time' => $avg_response_time,
            'checks_count' => count($last_24h_data),
            'last_24h_data' => array_values($last_24h_data),
            'last_check_time' => $last_check['timestamp']
        ];
    }
    
    // Ordena por nome
    usort($results, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
}

// Informações gerais
$last_update = isset($status_summary['last_update']) ? $status_summary['last_update'] : time();
$total_sites = count($results);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="./images/icon.svg">
    <title>santoswebservices - Website Monitor</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #0c0c0c;
        min-height: 100vh;
        padding: 20px;
        color: #333;
    }

    .dashboard {
        max-width: 1400px;
        margin: 0 auto;
    }

    .dashboard-header {
        text-align: center;
        color: white;
        margin-bottom: 40px;
    }

    .dashboard-header h1 {
        font-size: 2.8rem;
        margin-bottom: 10px;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
    }

    .dashboard-header p {
        font-size: 1.1rem;
        opacity: 0.9;
    }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .summary-card {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .summary-card h3 {
        font-size: 2rem;
        margin-bottom: 5px;
    }

    .summary-card p {
        font-size: 0.9rem;
        opacity: 0.8;
    }

    .last-update {
        text-align: center;
        color: rgba(255, 255, 255, 0.8);
        margin-bottom: 30px;
        font-size: 0.9rem;
    }

    .no-data {
        text-align: center;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        padding: 40px;
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .no-data h2 {
        margin-bottom: 15px;
        color: #f39c12;
    }

    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
        gap: 25px;
    }

    .server-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(15px);
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .server-card:hover {
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        transform: translateY(-5px);
    }

    .server-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }

    .server-info h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 8px;
    }

    .server-url {
        font-size: 0.95rem;
        color: #3498db;
        text-decoration: none;
        background: rgba(52, 152, 219, 0.1);
        padding: 4px 10px;
        border-radius: 8px;
        display: inline-block;
        margin-bottom: 15px;
        transition: all 0.3s ease;
    }

    .server-url:hover {
        background: rgba(52, 152, 219, 0.2);
        transform: scale(1.02);
    }

    .status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 100px;
        justify-content: center;
    }

    .status-200 {
        background: rgba(39, 174, 96, 0.15);
        color: #27ae60;
        border: 2px solid rgba(39, 174, 96, 0.3);
    }

    .status-500 {
        background: rgba(231, 76, 60, 0.15);
        color: #e74c3c;
        border: 2px solid rgba(231, 76, 60, 0.3);
    }

    .status-404 {
        background: rgba(241, 196, 15, 0.15);
        color: #f1c40f;
        border: 2px solid rgba(241, 196, 15, 0.3);
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: currentColor;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.5; }
        100% { opacity: 1; }
    }

    .metrics {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        margin: 20px 0;
    }

    .metric {
        text-align: center;
        padding: 15px;
        background: rgba(0, 0, 0, 0.03);
        border-radius: 12px;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .metric-value {
        font-size: 1.4rem;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 5px;
    }

    .metric-label {
        font-size: 0.8rem;
        color: #7f8c8d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .chart-container {
        position: relative;
        height: 220px;
        margin-top: 20px;
        background: rgba(0, 0, 0, 0.02);
        border-radius: 12px;
        padding: 15px;
    }

    .chart-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: #34495e;
        margin-bottom: 10px;
        text-align: center;
    }

    .uptime-high { color: #27ae60; }
    .uptime-medium { color: #f39c12; }
    .uptime-low { color: #e74c3c; }

    .refresh-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: rgba(52, 152, 219, 0.9);
        color: white;
        border: none;
        border-radius: 50px;
        padding: 15px 25px;
        cursor: pointer;
        font-size: 0.9rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        transition: all 0.3s ease;
    }

    .refresh-btn:hover {
        background: rgba(52, 152, 219, 1);
        transform: scale(1.05);
    }

    .last-check {
        font-size: 0.8rem;
        color: #7f8c8d;
        margin-top: 10px;
        text-align: center;
    }
    </style>
</head>

<body>
    <div class="dashboard">
        <div class="dashboard-header">
            <h1>🖥️ Monitoramento de Servidores</h1>
            <p>Dashboard em tempo real do status dos seus serviços</p>
        </div>

        <?php if (!empty($status_summary)): ?>
        <div class="summary-cards">
            <div class="summary-card">
                <h3><?= $status_summary['total_sites'] ?? $total_sites ?></h3>
                <p>Total de Sites</p>
            </div>
            <div class="summary-card">
                <h3><?= $status_summary['sites_online'] ?? 0 ?></h3>
                <p>Sites Online</p>
            </div>
            <div class="summary-card">
                <h3><?= $status_summary['sites_offline'] ?? 0 ?></h3>
                <p>Sites Offline</p>
            </div>
            <div class="summary-card">
                <h3><?= $status_summary['uptime_percentage'] ?? 0 ?>%</h3>
                <p>Uptime Geral</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="last-update">
            Última atualização: <span id="lastUpdate"><?= date('d/m/Y H:i:s', $last_update) ?></span>
        </div>

        <?php if ($no_data): ?>
        <div class="no-data">
            <h2>⚠️ Nenhum dado encontrado</h2>
            <p>O sistema de monitoramento ainda não coletou dados.</p>
            <p>Verifique se o cron job está configurado corretamente e se o arquivo <strong>monitor.php</strong> está sendo executado.</p>
            <br>
            <p><strong>Para configurar o cron job:</strong></p>
            <p><code>*/5 * * * * /usr/bin/php <?= __DIR__ ?>/monitor.php</code></p>
        </div>
        <?php else: ?>

        <div class="cards-grid">
            <?php foreach ($results as $index => $result): ?>
            <div class="server-card">
                <?php
                $status = (int) $result['status'];
                if ($status >= 500 || $status == 0) {
                    $badgeClass = 'status-500';
                    $statusText = $status == 0 ? 'OFFLINE' : $status . ' ERROR';
                } elseif ($status >= 400 && $status < 500) {
                    $badgeClass = 'status-404';
                    $statusText = $status . ' NOT FOUND';
                } elseif ($status >= 200 && $status < 400) {
                    $badgeClass = 'status-200';
                    $statusText = $status . ' OK';
                } else {
                    $badgeClass = 'status-404';
                    $statusText = $status;
                }

                $uptimeClass = 'uptime-low';
                if ($result['uptime'] >= 95) {
                    $uptimeClass = 'uptime-high';
                } elseif ($result['uptime'] >= 80) {
                    $uptimeClass = 'uptime-medium';
                }
                ?>

                <div class="server-header">
                    <div class="server-info">
                        <h3><?= htmlspecialchars($result['name']) ?></h3>
                        <a href="<?= htmlspecialchars('https://' . $result['url']) ?>" 
                           class="server-url" target="_blank">
                            <?= htmlspecialchars($result['url']) ?>
                        </a>
                    </div>
                    <div class="status-badge <?= $badgeClass ?>">
                        <div class="status-dot"></div>
                        <?= $statusText ?>
                    </div>
                </div>

                <div class="metrics">
                    <div class="metric">
                        <div class="metric-value <?= $uptimeClass ?>"><?= $result['uptime'] ?>%</div>
                        <div class="metric-label">Uptime 24h</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?= round($result['response_time']) ?>ms</div>
                        <div class="metric-label">Último Check</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value"><?= $result['avg_response_time'] ?>ms</div>
                        <div class="metric-label">Média 24h</div>
                    </div>
                </div>

                <div class="chart-container">
                    <div class="chart-title">Tempo de Resposta (últimas 24h)</div>
                    <canvas id="chart<?= $index ?>"></canvas>
                </div>

                <div class="last-check">
                    Última verificação: <?= date('d/m/Y H:i:s', $result['last_check_time']) ?>
                </div>

                <script>
                window.siteData<?= $index ?> = <?= json_encode($result['last_24h_data']) ?>;
                </script>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <button class="refresh-btn" onclick="location.reload()">
            🔄 Atualizar
        </button>
    </div>

    <script>
    // Função para processar dados das últimas 24 horas
    function processLast24HoursData(siteData) {
        if (!siteData || siteData.length === 0) {
            // Se não há dados, retorna arrays vazios
            return { hours: [], data: [] };
        }
        
        // Cria array de horas para as últimas 24 horas
        const hours = [];
        const data = [];
        
        for (let i = 23; i >= 0; i--) {
            const targetTime = Date.now() - (i * 60 * 60 * 1000);
            const targetHour = new Date(targetTime).getHours();
            hours.push(targetHour.toString().padStart(2, '0') + 'h');
            
            // Encontra dados próximos a esta hora (dentro de 1 hora)
            const hourData = siteData.filter(check => {
                const checkTime = check.timestamp * 1000;
                const timeDiff = Math.abs(checkTime - targetTime);
                return timeDiff <= 60 * 60 * 1000; // Dentro de 1 hora
            });
            
            if (hourData.length > 0) {
                const avgResponse = hourData.reduce((sum, check) => sum + check.response_time, 0) / hourData.length;
                data.push(Math.round(avgResponse));
            } else {
                data.push(null); // Sem dados para esta hora
            }
        }
        
        return { hours, data };
    }

    // Configuração dos gráficos
    const chartConfig = {
        type: 'line',
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: {
                    display: true,
                    grid: { display: false },
                    ticks: {
                        font: { size: 10 },
                        maxTicksLimit: 12
                    }
                },
                y: {
                    display: true,
                    grid: { color: 'rgba(0,0,0,0.1)' },
                    ticks: {
                        font: { size: 10 },
                        callback: function(value) {
                            return value + 'ms';
                        }
                    }
                }
            },
            elements: {
                point: {
                    radius: 2,
                    hoverRadius: 4
                },
                line: {
                    borderWidth: 2,
                    tension: 0.4
                }
            }
        }
    };

    // Cores para os gráficos
    const colors = ['#3498db', '#9b59b6', '#e67e22', '#e74c3c', '#f39c12', '#2ecc71', '#1abc9c', '#34495e'];

    // Criar gráficos para cada site
    <?php foreach ($results as $index => $result): ?>
    {
        const siteData = window.siteData<?= $index ?> || [];
        const { hours, data } = processLast24HoursData(siteData);
        const ctx = document.getElementById('chart<?= $index ?>');
        
        if (ctx && hours.length > 0) {
            new Chart(ctx, {
                ...chartConfig,
                data: {
                    labels: hours,
                    datasets: [{
                        data: data,
                        borderColor: colors[<?= $index ?> % colors.length],
                        backgroundColor: colors[<?= $index ?> % colors.length] + '20',
                        fill: true,
                        spanGaps: true
                    }]
                }
            });
        }
    }
    <?php endforeach; ?>

    // Auto-refresh da página a cada 2 minutos
    setTimeout(() => {
        location.reload();
    }, 2 * 60 * 1000);
    </script>
</body>
</html>