<?php
// Ajuste de fuso horário
date_default_timezone_set("America/Sao_Paulo");
$report_date = date("d/m/Y H:i");

$netbox_url = "http://IP_NETBOX/netbox/api";
$netbox_token = "TOKEN_AQUI";
$headers = [
    "Authorization: Token $netbox_token",
    "Content-Type: application/json",
    "Accept: application/json"
];

$ipFile = __DIR__ . '/netbox-ip-list.txt';
$redes_verificadas = file_exists($ipFile) ? array_map('trim', file($ipFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];

function getVMs($url, $headers) {
    $vms = [];
    $next = "$url/virtualization/virtual-machines/?limit=100&offset=0&expand=primary_ip4";

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
            $ip = $vm['primary_ip4']['address'] ?? null;
            if ($ip) {
                $ip = strtok($ip, '/');
                $vms[] = [
                    'vm' => strtolower(trim($vm['name'])),
                    'ip' => $ip
                ];
            }
        }

        $next = $data['next'];
    }

    return $vms;
}

$vms_with_ips = getVMs($netbox_url, $headers);
$inconsistencias = [];

foreach ($vms_with_ips as $entry) {
    $resolved_name = gethostbyaddr($entry['ip']);
    if ($resolved_name === $entry['ip']) continue;

    $base_resolvido = strtolower(trim(strtok($resolved_name, '.')));
    $base_netbox    = strtolower(trim(strtok($entry['vm'], '.')));

    if ($base_resolvido !== $base_netbox) {
        $inconsistencias[] = [
            'vm' => $entry['vm'],
            'ip' => $entry['ip'],
            'dns' => $resolved_name,
            'resolvido' => $base_resolvido
        ];
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=inconsistencias_vm_ip.xls");
    echo "VM Netbox\tIP\tNome DNS\n";
    foreach ($inconsistencias as $item) {
        echo "{$item['vm']}\t{$item['ip']}\t{$item['dns']}\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>NetBox - Validação Nome/IP</title>
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
        .vm-list, .net-list {
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
  <section class="vm-list">
    <h2 style="font-size: 1.2rem;">Possíveis inconsistencias entre <span style="color: #f57c00;">nome da VM e IP</span></h2>
    <p style="font-size: 0.85rem; color: #666;">Relatório gerado em: <?= $report_date ?></p>
    <p>Total: <strong><?= count($inconsistencias) ?></strong></p>
    <ul>
      <?php foreach ($inconsistencias as $item): ?>
        <li class="entry">
          <strong><?= htmlspecialchars($item['vm']) ?></strong>
          IP: <?= htmlspecialchars($item['ip']) ?> - DNS: <?= htmlspecialchars($item['dns']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

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
