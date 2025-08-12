<?php
// Ajuste de fuso horário para São Paulo
date_default_timezone_set("America/Sao_Paulo");
$report_date = date("d/m/Y H:i");

// Configurações do NetBox
$netbox_url   = "http://10.255.8.19/netbox/api";
$netbox_token = "931547c237320de0b63e07a5d8c4ca9dc6d95e65";
$headers = [
    "Authorization: Token $netbox_token",
    "Content-Type: application/json",
    "Accept: application/json"
];

// Arquivo com as redes CIDR a serem verificadas
$ipFile = __DIR__ . '/netbox-ip-list.txt';
$redes_verificadas     = [];
$total_ips_verificados = 0;

/**
 * Retorna todos os IPs de um CIDR
 */
function getIpRange(string $cidr): array {
    [$ip, $mask] = explode('/', $cidr);
    $ip_long  = ip2long($ip);
    $mask     = (int) $mask;
    $netmask  = ~((1 << (32 - $mask)) - 1);
    $network  = $ip_long & $netmask;
    $broadcast = $network | ~$netmask;

    $ips = [];
    for ($i = $network + 1; $i < $broadcast; $i++) {
        $ips[] = long2ip($i);
    }
    return $ips;
}

/**
 * Busca todas as VMs registradas no NetBox (virtualização).
 */
function getNetboxVMs(string $url, array $headers): array {
    $all_vms = [];
    $next = "$url/virtualization/virtual-machines/";
    while ($next) {
        $ch = curl_init($next);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200 || !$response) {
            echo "<pre>Erro ao buscar VMs. HTTP Status: $httpcode</pre>";
            break;
        }

        $data = json_decode($response, true);
        if (!isset($data['results'])) break;

        foreach ($data['results'] as $vm) {
            $all_vms[] = strtolower(trim($vm['name']));
        }
        $next = $data['next'];
    }
    return $all_vms;
}

/**
 * Busca todos os dispositivos (máquinas físicas) registrados no NetBox.
 * A API DCIM usa a rota /api/dcim/devices/:contentReference[oaicite:2]{index=2}; basta seguir a propriedade `next` para paginar.
 */
function getNetboxDevices(string $url, array $headers): array {
    $all_devices = [];
    $next = "$url/dcim/devices/";
    while ($next) {
        $ch = curl_init($next);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200 || !$response) {
            echo "<pre>Erro ao buscar Devices. HTTP Status: $httpcode</pre>";
            break;
        }

        $data = json_decode($response, true);
        if (!isset($data['results'])) break;

        foreach ($data['results'] as $device) {
            $all_devices[] = strtolower(trim($device['name']));
        }
        $next = $data['next'];
    }
    return $all_devices;
}

// Obtenha todas as VMs e dispositivos do NetBox e normalize o nome até o primeiro ponto
$vm_names_netbox       = getNetboxVMs($netbox_url, $headers);
$vm_names_netbox_base  = array_map(fn($name) => strtok($name, '.'), $vm_names_netbox);

$device_names_netbox       = getNetboxDevices($netbox_url, $headers);
$device_names_netbox_base  = array_map(fn($name) => strtok($name, '.'), $device_names_netbox);

$missing_vms     = [];
$missing_devices = [];

// Processa as redes
if (file_exists($ipFile)) {
    $cidrs = file($ipFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cidrs as $cidr) {
        $cidr = trim($cidr);
        $redes_verificadas[] = $cidr;

        $ips = getIpRange($cidr);
        $total_ips_verificados += count($ips);

        foreach ($ips as $ip) {
            $hostname = gethostbyaddr($ip);
            if ($hostname !== $ip) {
                $base       = strtolower(trim(strtok($hostname, '.')));
                $firstChar  = substr($base, 0, 1);

                // Identifica se é virtual (v) ou física (f) pelo prefixo e consulta a lista correspondente
                if ($firstChar === 'v') {
                    if (!in_array($base, $vm_names_netbox_base)) {
                        $missing_vms[] = [
                            'hostname' => $hostname,
                            'ip'       => $ip,
                            'base'     => $base,
                        ];
                    }
                } elseif ($firstChar === 'f') {
                    if (!in_array($base, $device_names_netbox_base)) {
                        $missing_devices[] = [
                            'hostname' => $hostname,
                            'ip'       => $ip,
                            'base'     => $base,
                        ];
                    }
                }
            }
        }
    }
}

// Remove duplicados
$missing_vms     = array_unique($missing_vms, SORT_REGULAR);
$missing_devices = array_unique($missing_devices, SORT_REGULAR);

// Exporta para XLS se solicitado: inclui VM e física na mesma planilha
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=maquinas_nao_encontradas.xls");
    echo "Tipo\tNome\tHostname\tIP\n";
    foreach ($missing_vms as $entry) {
        echo "Virtual\t" . $entry['base'] . "\t" . $entry['hostname'] . "\t" . $entry['ip'] . "\n";
    }
    foreach ($missing_devices as $entry) {
        echo "Física\t" . $entry['base'] . "\t" . $entry['hostname'] . "\t" . $entry['ip'] . "\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>NetBox Reports</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body {
            font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            background: #f0f2f5;
            color: #333;
        }
        header {
            background-color: #263238;
            padding: 0.75rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        main {
            padding: 2rem;
            max-width: 960px;
            margin: auto;
        }
        .list-section {
            background: white;
            border-radius: 6px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        li:last-child {
            border-bottom: none;
        }
        .entry strong {
            display: inline-block;
            width: 150px;
            color: #1a73e8;
        }
        .net-title {
            font-size: 1.1rem;
            color: #555;
            margin-bottom: 0.5rem;
        }
        .box {
            background: #e3f2fd;
            padding: 0.7rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9rem;
            border: none;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #263238;">
  <div class="container-fluid">
    <span class="navbar-brand mb-0 h1">NetBox Reports</span>
    <div>
      <a href="?export=xls" class="btn btn-success btn-sm me-2">Gerar planilha (.xls)</a>
      <a href="javascript:history.back()" class="btn btn-warning btn-sm" style="background-color: #f57c00; border-color: #f57c00; color: white;">Voltar</a>
    </div>
  </div>
</nav>

<main>
  <!-- Relatório para máquinas virtuais -->
  <section class="list-section">
    <h2 style="font-size: 1.2rem;">Máquinas virtuais não encontradas no NetBox</h2>
    <p style="font-size: 0.85rem; color: #666;">Relatório gerado em: <?= $report_date ?></p>
    <p>Total: <strong><?= count($missing_vms) ?></strong></p>
    <ul>
      <?php foreach ($missing_vms as $entry): ?>
        <li class="entry">
          <strong><?= htmlspecialchars($entry['base']) ?></strong>
          <?= htmlspecialchars($entry['hostname']) ?> (<?= htmlspecialchars($entry['ip']) ?>)
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- Relatório para máquinas físicas -->
  <section class="list-section">
    <h2 style="font-size: 1.2rem;">Máquinas físicas (Devices) não encontradas no NetBox</h2>
    <p style="font-size: 0.85rem; color: #666;">Relatório gerado em: <?= $report_date ?></p>
    <p>Total: <strong><?= count($missing_devices) ?></strong></p>
    <ul>
      <?php foreach ($missing_devices as $entry): ?>
        <li class="entry">
          <strong><?= htmlspecialchars($entry['base']) ?></strong>
          <?= htmlspecialchars($entry['hostname']) ?> (<?= htmlspecialchars($entry['ip']) ?>)
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- Redes verificadas -->
  <section class="net-list">
    <p class="net-title">Redes verificadas</p>
    <div class="box">
      <?= htmlspecialchars(implode(", ", $redes_verificadas)) ?>
    </div>
    <p style="font-size: 0.9rem; color: #555; margin-top: 1rem;">
      Para editar as redes de IPs que devem ser verificadas,
      <a href='http://10.255.8.19/netbox-reports/ip_manager.php' style='color: #1a73e8; text-decoration: none;'>clique aqui</a>.
    </p>
  </section>
</main>
</body>
</html>
