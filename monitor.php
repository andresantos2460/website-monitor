<?php
/**
 * Script de Monitoramento - Para execução via Cron Job
 * Este arquivo coleta os dados dos sites e salva no arquivo JSON
 */

// Configurações
$data_file = __DIR__ . '/monitoring_data.json';
$sites_file = __DIR__ . '/sites.txt';
$log_file = __DIR__ . '/monitor.log';
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

function sendTelegram($message) {

    $token   = $_ENV['TELEGRAM_TOKEN'];
    $chat_id = $_ENV['TELEGRAM_CHATID'];

    $url = "https://api.telegram.org/bot$token/sendMessage";


    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_POST, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}


// Função para log
function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

// Função para carregar dados históricos
function loadHistoricalData($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : [];
}

// Função para salvar dados históricos
function saveHistoricalData($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Verifica se o arquivo sites.txt existe
if (!file_exists($sites_file)) {
    writeLog("ERRO: Arquivo sites.txt não encontrado em: $sites_file");
    exit(1);
}

writeLog("Iniciando monitoramento...");

// Lê todas as linhas do arquivo sites.txt
$linhas = file($sites_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (empty($linhas)) {
    writeLog("ERRO: Arquivo sites.txt está vazio");
    exit(1);
}

$sites = array_map(function ($linha) {
    if (strpos($linha, '|') === false) {
        return null; // Linha inválida
    }
    list($url, $nome) = explode('|', $linha, 2);
    return [
        'url'  => trim($url),
        'name' => trim($nome),
    ];
}, $linhas);

// Remove linhas inválidas
$sites = array_filter($sites);

if (empty($sites)) {
    writeLog("ERRO: Nenhum site válido encontrado no arquivo sites.txt");
    exit(1);
}

writeLog("Encontrados " . count($sites) . " sites para monitorar");

// Carrega dados históricos
$historical_data = loadHistoricalData($data_file);
$current_timestamp = time();
$sites_checked = 0;
$sites_online = 0;

foreach ($sites as $site) {
    $site_key = md5($site['url']);
    
    // Inicializa dados do site se não existir
    if (!isset($historical_data[$site_key])) {
        $historical_data[$site_key] = [
            'url' => $site['url'],
            'name' => $site['name'],
            'checks' => []
        ];
        writeLog("Novo site adicionado: " . $site['name'] . " (" . $site['url'] . ")");
    }
    
    // Prepara a URL
    $url = $site['url'];
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'https://' . $url;
    }
    
    writeLog("Verificando: " . $site['name'] . " - " . $url);
    
    // Inicializa cURL
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Site Monitor/1.0 (https://santoswebservices.com)',
    ]);

    $start = microtime(true);
    $response = curl_exec($ch);
    $end = microtime(true);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time_ms = round(($end - $start) * 1000, 2);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    
    curl_close($ch);

    // Se houve erro no curl, define status como 0
    if ($curl_error || $curl_errno) {
        $http_code = 0;
        writeLog("Erro cURL para " . $site['name'] . ": $curl_error (Código: $curl_errno)");
    }

    // Adiciona o check atual ao histórico
    $current_check = [
        'timestamp' => $current_timestamp,
        'status' => $http_code,
        'response_time' => $total_time_ms,
        'error' => $curl_error,
        'date' => date('Y-m-d H:i:s', $current_timestamp)
    ];
    
    $historical_data[$site_key]['checks'][] = $current_check;
    
    // Mantém apenas os últimos 7 dias de dados
    $week_ago = $current_timestamp - (7 * 24 * 60 * 60);
    $historical_data[$site_key]['checks'] = array_filter(
        $historical_data[$site_key]['checks'],
        function($check) use ($week_ago) {
            return $check['timestamp'] >= $week_ago;
        }
    );
    
    // Reordena por timestamp
    usort($historical_data[$site_key]['checks'], function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    // Log do resultado
    $status_text = $http_code == 0 ? 'OFFLINE' : $http_code;
    writeLog("Resultado: " . $site['name'] . " - Status: $status_text - Tempo: {$total_time_ms}ms");
    
    $sites_checked++;
    if ($http_code >= 200 && $http_code < 400) {
        $sites_online++;
    }
    if ($http_code >= 500 || $http_code == 429) {
        $status_text = match($http_code) {
            429 => "🚨 Too Many Requests",
            404 => "🚨 Not found !",
            500 => "🚨 Internal Server Error",
            501 => "🚨 Not Implemented",
            502 => "🚨 Bad Gateway",
            503 => "🚨 Service Unavailable",
            504 => "🚨 Gateway Timeout",
            505 => "🚨 HTTP Version Not Supported",
            default => "🚨 Erro crítico HTTP: $http_code"
            
        };

        $message = "⚠️ ALERTA! Site: $url \nCódigo HTTP: $http_code → $status_text";
        sendTelegram($message);

    // Pequena pausa entre requisições para não sobrecarregar
}
    usleep(500000); // 0.5 segundos

}
// Salva dados históricos atualizados
$bytes_written = saveHistoricalData($data_file, $historical_data);

if ($bytes_written === false) {
    writeLog("ERRO: Não foi possível salvar o arquivo de dados em: $data_file");
    exit(1);
}

// Atualiza arquivo de status para o dashboard
$status_summary = [
    'last_update' => $current_timestamp,
    'total_sites' => count($sites),
    'sites_online' => $sites_online,
    'sites_offline' => count($sites) - $sites_online,
    'uptime_percentage' => count($sites) > 0 ? round(($sites_online / count($sites)) * 100, 2) : 0
];

file_put_contents(__DIR__ . '/status.json', json_encode($status_summary, JSON_PRETTY_PRINT));

writeLog("Monitoramento concluído: $sites_checked sites verificados, $sites_online online, " . 
         ($sites_checked - $sites_online) . " offline");
writeLog("Dados salvos: $bytes_written bytes escritos em $data_file");
writeLog("---");

// Se executado via linha de comando, mostra resumo
if (php_sapi_name() === 'cli') {
    echo "Monitoramento concluído!\n";
    echo "Sites verificados: $sites_checked\n";
    echo "Sites online: $sites_online\n";
    echo "Sites offline: " . ($sites_checked - $sites_online) . "\n";
    echo "Dados salvos em: $data_file\n";
}
sendTelegram("✅ Dados Atualizados!");
?>