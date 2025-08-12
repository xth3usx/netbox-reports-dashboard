<?php
ob_implicit_flush(true);
ob_end_flush();

date_default_timezone_set("America/Sao_Paulo");
$report_date = date("d/m/Y H:i");
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Verificação de IPs públicos do Netbox</title>

<!-- Bootstrap + DataTables CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">

<style>
    body {
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        background: #f4f6f9;
        color: #333;
        padding: 30px;
        margin: 0;
    }
    h2 {
        color: #2c3e50;
        font-weight: 500;
    }
    .card {
        background: #fff;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        margin-top: 20px;
    }
    .badge-status {
        display: inline-block;
        padding: 1px 5px;
        border-radius: 3px;
        font-weight: 500;
        font-size: 0.8em;
        line-height: 1.2em;
        border: 1px solid transparent;
    }
    .status-ok {
        background: #eaf7ee;
        color: #1b5e20;
        border-color: #c8e6c9;
    }
    .status-inconsistente {
        background: #fdecea;
        color: #b71c1c;
        border-color: #f5c6cb;
    }
    .status-sem-ptr {
        background: #fff8e1;
        color: #8c6d1f;
        border-color: #ffe082;
    }
    .legend {
        margin-top: 20px;
        background: #fff;
        padding: 10px;
        border-left: 4px solid #2c3e50;
        border-radius: 6px;
        font-size: 0.8em;
    }
    .legend p {
        margin: 4px 0;
    }
    table.dataTable thead th {
        position: relative;
    }
    table.dataTable thead th.sorting:after,
    table.dataTable thead th.sorting_asc:after,
    table.dataTable thead th.sorting_desc:after {
        content: "";
        position: absolute;
        right: 10px;
        top: 50%;
        margin-top: -6px;
        border: 5px solid transparent;
    }
    table.dataTable thead th.sorting_asc:after {
        border-bottom-color: #000;
    }
    table.dataTable thead th.sorting_desc:after {
        border-top-color: #000;
    }
    table.dataTable thead th.sorting:after {
        border-top-color: #ccc;
        border-bottom-color: #ccc;
    }
</style>
</head>
<body>

<?php
echo str_repeat(' ', 1024);
flush();

$netbox_url = "http://10.255.8.19/netbox/api/ipam/ip-addresses/?limit=100";
$netbox_token = "931547c237320de0b63e07a5d8c4ca9dc6d95e65";
$headers = [
    "Authorization: Token $netbox_token",
    "Content-Type: application/json",
    "Accept: application/json"
];

function fetch_all_ips($base_url, $headers) {
    $all_ips = [];
    $url = $base_url;

    do {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($curl);
        curl_close($curl);

        $data = json_decode($response, true);
        if (isset($data['results'])) {
            $all_ips = array_merge($all_ips, $data['results']);
        }

        $url = $data['next'];
    } while ($url);

    return $all_ips;
}

function check_dns($ip) {
    $hostname = gethostbyaddr($ip);
    if ($hostname === $ip) {
        return ['status' => 'Sem PTR', 'hostname' => '-'];
    }
    $resolved_ip = gethostbyname($hostname);
    if ($resolved_ip === $ip) {
        return ['status' => 'OK', 'hostname' => $hostname];
    } else {
        return ['status' => 'Inconsistente', 'hostname' => $hostname];
    }
}

$ip_entries = fetch_all_ips($netbox_url, $headers);
$public_ips = [];
foreach ($ip_entries as $ip_entry) {
    $ip = explode('/', $ip_entry['address'])[0];
    if (
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_IPV4) &&
        str_starts_with($ip, '200.')
    ) {
        $dns = check_dns($ip);
        $public_ips[] = [
            'ip' => $ip,
            'hostname' => $dns['hostname'],
            'status' => $dns['status']
        ];
    }
}
$total_ips = count($public_ips);
?>

<div class="card">
    <p><strong>Data/Hora da verificação:</strong> <?= $report_date ?></p>
    <p><strong>Total de IPs filtrados (200.*):</strong> <?= $total_ips ?></p>

    <div class="table-responsive">
        <table id="dnsTable" class="table table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>IP</th>
                    <th>Hostname (PTR)</th>
                    <th>Status DNS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($public_ips as $row):
                    $status_class = match ($row['status']) {
                        'OK' => 'status-ok',
                        'Inconsistente' => 'status-inconsistente',
                        'Sem PTR' => 'status-sem-ptr',
                        default => ''
                    }; ?>
                    <tr>
                        <td><?= $row['ip'] ?></td>
                        <td><?= $row['hostname'] ?></td>
                        <td><span class="badge-status <?= $status_class ?>"><?= $row['status'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="legend">
        <strong>Legenda:</strong>
        <p><span class='badge-status status-ok'>OK</span> – Registro PTR válido, reverso (IP→hostname) e direto (hostname→IP) consistentes.</p>
        <p><span class='badge-status status-sem-ptr'>Sem PTR</span> – Nenhum registro PTR encontrado para o IP (sem resolução reversa).</p>
        <p><span class='badge-status status-inconsistente'>Inconsistente</span> – Existe PTR, mas o hostname não resolve de volta para o mesmo IP.</p>
    </div>
</div>

<!-- JS: jQuery + DataTables + Bootstrap -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#dnsTable').DataTable({
        responsive: true,
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/pt-BR.json'
        },
        ordering: true,
        order: [[0, 'asc']],
        pageLength: 500,
        lengthMenu: [10, 50, 100, 250, 500, 1000]
    });
});
</script>

</body>
</html>
