<?php
// status.php — Feature-rich public status page with links, filters, export
header('Content-Type: text/html; charset=utf-8');

// API - auto-detect base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $protocol . '://' . $host . $scriptDir . '/api.php';
$monitorsData    = @file_get_contents($apiBase . '?path=monitors&include_chart=true');
$statsData       = @file_get_contents($apiBase . '?path=stats');
$maintenanceData = @file_get_contents($apiBase . '?path=maintenance');
$incidentsData   = @file_get_contents($apiBase . '?path=incidents');

$monitors    = $monitorsData ? json_decode($monitorsData, true) : [];
$stats       = $statsData ? json_decode($statsData, true) : [];
$maintenance = $maintenanceData ? json_decode($maintenanceData, true) : ['enabled' => false];
$incidents   = $incidentsData ? json_decode($incidentsData, true) : [];

// helper: safe URL -> clickable
function cleanUrl($url) {
    // ensure scheme for display/anchors
    if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
        return 'http://' . $url;
    }
    return $url;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="300">
<title>System Status &mdash; Uptime Monitor</title>

<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

<style>
/* --- navigation --- */
.main-nav{background:rgba(15,23,42,.95);backdrop-filter:blur(12px);border-bottom:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:1000;padding:0 20px}
html[data-theme="light"] .main-nav{background:rgba(255,255,255,.95);border-bottom-color:rgba(0,0,0,.08)}
.nav-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:32px;height:56px}
.nav-brand{font-size:1.1rem;font-weight:800;background:linear-gradient(135deg,#3b82f6,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none;white-space:nowrap}
.nav-links{display:flex;gap:4px}
.nav-link{padding:8px 14px;border-radius:8px;color:#94a3b8;text-decoration:none;font-size:.9rem;font-weight:500;transition:all .2s}
.nav-link:hover{background:rgba(255,255,255,.06);color:#e2e8f0}
html[data-theme="light"] .nav-link:hover{background:rgba(0,0,0,.04);color:#0f172a}
.nav-link.active{background:rgba(59,130,246,.15);color:#60a5fa}
html[data-theme="light"] .nav-link.active{background:rgba(37,99,235,.1);color:#2563eb}
.nav-theme-toggle{margin-left:auto;background:none;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:6px 10px;cursor:pointer;font-size:1.1rem;color:#94a3b8;transition:all .2s}
.nav-theme-toggle:hover{background:rgba(255,255,255,.06)}
html[data-theme="light"] .nav-theme-toggle{border-color:rgba(0,0,0,.1);color:#475569}
html[data-theme="light"] .nav-theme-toggle:hover{background:rgba(0,0,0,.04)}
.theme-icon-light{display:none}
html[data-theme="light"] .theme-icon-dark{display:none}
html[data-theme="light"] .theme-icon-light{display:inline}
@media(max-width:600px){.nav-inner{gap:12px}.nav-link{padding:6px 10px;font-size:.8rem}.nav-brand{font-size:.95rem}}

/* --- base & theme --- */
:root{
  --bg0:#0f172a;--bg1:#1e293b;--panel:#1f2937;--muted:#94a3b8;--text:#e2e8f0;
  --primary:#3b82f6;--primary-2:#8b5cf6;--ok:#10b981;--warn:#f59e0b;--bad:#ef4444;
  --border:rgba(255,255,255,.1);--shadow:rgba(0,0,0,.3);
}
html[data-theme="light"]{
  --bg0:#f8fafc;--bg1:#eef2f7;--panel:#ffffff;--muted:#475569;--text:#0f172a;
  --primary:#2563eb;--primary-2:#7c3aed;--ok:#059669;--warn:#d97706;--bad:#dc2626;
  --border:rgba(0,0,0,.08);--shadow:rgba(0,0,0,.1);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:linear-gradient(135deg,var(--bg0) 0%,var(--bg1) 100%);color:var(--text);min-height:100vh;line-height:1.6}
.container{max-width:1200px;margin:0 auto;padding:15px}

/* header */
.header{display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between;padding:24px 0}
.brand{display:flex;flex-direction:column;gap:6px}
.brand h1{font-size:clamp(1.6rem,5vw,2.6rem);font-weight:800;background:linear-gradient(135deg,var(--primary),var(--primary-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;letter-spacing:-.02em}
.brand p{color:var(--muted)}
.last-updated{display:inline-block;padding:6px 14px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);border-radius:999px;color:#60a5fa;font-size:.85rem}
.header-actions{display:flex;flex-wrap:wrap;gap:8px}

/* unified button/link styles */
.btn,.tool-btn,.tool-link{
  -webkit-appearance:none;appearance:none;
  display:inline-flex;align-items:center;gap:6px;
  padding:8px 12px;border:1px solid var(--border);
  background:rgba(255,255,255,.04);color:var(--text);
  border-radius:10px;cursor:pointer;font-size:.9rem;line-height:1;text-decoration:none;
}
.btn:hover,.tool-btn:hover,.tool-link:hover{background:rgba(255,255,255,.08)}
.btn.primary{border-color:rgba(59,130,246,.4);color:#60a5fa}
.btn.ok{border-color:rgba(16,185,129,.35);color:var(--ok)}
.btn.warn{border-color:rgba(245,158,11,.35);color:var(--warn)}
.btn.bad{border-color:rgba(239,68,68,.35);color:var(--bad)}
.btn.ghost{background:transparent}

/* maintenance */
.maintenance-banner{display:none;padding:14px 18px;margin:12px 0;background:linear-gradient(135deg,var(--warn),#b45309);color:#fff;text-align:center;border-radius:12px;font-weight:600;box-shadow:0 10px 30px rgba(245,158,11,.3)}
.maintenance-banner.active{display:block}

/* summary */
.summary-panel{background:rgba(31,41,55,.5);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:20px;padding:18px;margin:12px 0 20px;box-shadow:0 20px 40px var(--shadow)}
.overall-status{text-align:center;margin-bottom:12px}
.status-badge{display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:999px;font-weight:700;box-shadow:0 4px 15px var(--shadow)}
.status-operational{background:linear-gradient(135deg,rgba(16,185,129,.2),rgba(16,185,129,.1));border:1px solid rgba(16,185,129,.3);color:var(--ok)}
.status-partial{background:linear-gradient(135deg,rgba(245,158,11,.2),rgba(245,158,11,.1));border:1px solid rgba(245,158,11,.3);color:var(--warn)}
.status-major{background:linear-gradient(135deg,rgba(239,68,68,.2),rgba(239,68,68,.1));border:1px solid rgba(239,68,68,.3);color:var(--bad)}
.status-dot{width:12px;height:12px;border-radius:50%;animation:pulse 2s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.6;transform:scale(1.1)}}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
.stat-card{background:rgba(15,23,42,.5);padding:14px;border-radius:12px;text-align:center;border:1px solid var(--border)}
.stat-value{font-size:clamp(1.6rem,4vw,2.2rem);font-weight:700;margin-bottom:6px;background:linear-gradient(135deg,var(--primary),var(--primary-2));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.stat-label{text-transform:uppercase;font-size:.7rem;color:var(--muted);letter-spacing:.1em}

/* filters/search bar */
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:18px 0}
.filters{display:flex;gap:8px;flex-wrap:wrap}
.search{flex:1;min-width:220px}
.search input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text)}

/* cards */
.section-title{display:flex;align-items:center;gap:10px;font-size:1.2rem;font-weight:600;margin:10px 0;color:var(--text)}
.badge{padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:700;white-space:nowrap;border:1px solid var(--border)}
.priority-critical{background:rgba(239,68,68,.15);color:var(--bad)}
.priority-high{background:rgba(245,158,11,.15);color:var(--warn)}
.priority-normal{background:rgba(59,130,246,.15);color:var(--primary)}
.priority-low{background:rgba(107,114,128,.15);color:#9ca3af}
.ssl-valid{background:rgba(16,185,129,.15);color:var(--ok)}
.ssl-warning{background:rgba(245,158,11,.15);color:var(--warn);animation:pulse 3s infinite}
.ssl-expired{background:rgba(239,68,68,.15);color:var(--bad);animation:pulse 1s infinite}

.monitors-grid{display:grid;gap:12px}
.monitor-card{background:rgba(30,41,59,.5);backdrop-filter:blur(8px);border:1px solid var(--border);border-radius:16px;overflow:hidden;transition:all .25s;position:relative}
.monitor-card.expanded{box-shadow:0 20px 40px var(--shadow);border-color:rgba(59,130,246,.35)}
.monitor-header{padding:16px;cursor:pointer;user-select:none}
.monitor-header:hover{background:rgba(255,255,255,.03)}
.header-row{display:flex;justify-content:space-between;gap:14px;align-items:center}
.title-block{display:flex;align-items:center;gap:10px;min-width:0;flex:1}
.monitor-name{font-size:1.05rem;font-weight:700;margin-bottom:2px;word-break:break-word}
.monitor-url{font-size:.83rem;color:var(--muted);word-break:break-all}
.quick{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.kv{line-height:1}
.kv .v{font-size:.9rem;font-weight:700}
.kv .k{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
.expand-icon{font-size:1.1rem;color:var(--muted);transition:transform .25s}
.monitor-card.expanded .expand-icon{transform:rotate(180deg)}
.tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.copy-note{display:none;font-size:.75rem;color:var(--muted)}
.monitor-content{max-height:0;overflow:hidden;transition:max-height .25s ease-out}
.monitor-card.expanded .monitor-content{max-height:900px}
.monitor-content-inner{padding:0 16px 16px}
.chart-container{position:relative;height:200px;margin:10px 0 14px;background:rgba(15,23,42,.3);border-radius:8px;padding:8px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
.info-item{background:rgba(15,23,42,.45);padding:10px;border-radius:8px;border:1px solid var(--border)}
.info-label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.info-value{font-size:.95rem;font-weight:700;color:var(--text)}

/* incidents */
.incidents-section{margin-top:28px}
.incident-card{background:rgba(30,41,59,.5);border-left:4px solid var(--bad);padding:14px;margin-bottom:12px;border-radius:8px}
.incident-card.resolved{border-left-color:var(--ok);opacity:.85}
.incident-title{font-weight:700;margin-bottom:4px;font-size:.95rem}
.incident-time{font-size:.83rem;color:var(--muted)}
.incident-actions{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap}

/* footer */
.footer{text-align:center;padding:26px 0;margin-top:40px;border-top:1px solid var(--border);color:var(--muted);font-size:.9rem}

/* mobile tweaks */
@media (max-width:768px){
  .header{gap:10px}
  .search{order:3;width:100%}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .info-grid{grid-template-columns:repeat(2,1fr)}
  .chart-container{height:160px}
}
@media (max-width:480px){
  .stats-grid,.info-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
  <nav class="main-nav">
    <div class="nav-inner">
      <a href="index.html" class="nav-brand">Uptime Monitor</a>
      <div class="nav-links">
        <a href="index.html" class="nav-link">Dashboard</a>
        <a href="status.php" class="nav-link active">Status</a>
        <a href="config.html" class="nav-link">Settings</a>
        <a href="data.html" class="nav-link">Data</a>
      </div>
      <button class="nav-theme-toggle" id="toggle-theme" title="Toggle theme">
        <span class="theme-icon-dark">&#9789;</span>
        <span class="theme-icon-light">&#9788;</span>
      </button>
    </div>
  </nav>

  <div class="container">
    <div class="header">
      <div class="brand">
        <h1>System Status</h1>
        <p>Real-time monitoring of our services</p>
        <div class="last-updated">Last updated: <?= date('g:i:s A') ?></div>
      </div>
      <div class="header-actions">
        <button class="btn ghost" id="export-csv" type="button">Export CSV</button>
        <button class="btn" id="expand-all" type="button"><span id="expand-text">Expand All</span></button>
      </div>
    </div>

    <?php if ($maintenance['enabled']): ?>
    <div class="maintenance-banner active">⚠️ <?= htmlspecialchars($maintenance['message'] ?: 'System maintenance in progress') ?></div>
    <?php endif; ?>

    <div class="summary-panel" id="main-content">
      <?php
        $down = $stats['down'] ?? 0;
        $total = $stats['total'] ?? 0;
        if ($down === 0)            { $statusClass='status-operational'; $statusText='All Systems Operational'; $dotColor='#10b981'; }
        elseif ($down < $total/2)   { $statusClass='status-partial';     $statusText='Partial Outage';         $dotColor='#f59e0b'; }
        else                        { $statusClass='status-major';       $statusText='Major Outage';           $dotColor='#ef4444'; }
      ?>
      <div class="overall-status">
        <div class="status-badge <?= $statusClass ?>"><span class="status-dot" style="background:<?= $dotColor ?>;"></span><?= $statusText ?></div>
      </div>
      <div class="stats-grid" role="list">
        <div class="stat-card" role="listitem"><div class="stat-value"><?= $stats['avg_uptime'] ?? 0 ?>%</div><div class="stat-label">Overall Uptime</div></div>
        <div class="stat-card" role="listitem"><div class="stat-value"><?= $stats['up'] ?? 0 ?>/<?= $total ?></div><div class="stat-label">Services Online</div></div>
        <div class="stat-card" role="listitem"><div class="stat-value"><?= $stats['ssl_warnings'] ?? 0 ?></div><div class="stat-label">SSL Warnings</div></div>
        <div class="stat-card" role="listitem"><div class="stat-value"><?= count(array_filter($incidents, fn($i) => $i['status'] === 'open')) ?></div><div class="stat-label">Active Incidents</div></div>
      </div>
    </div>

    <!-- toolbar -->
    <div class="toolbar" aria-label="Filters and search">
      <div class="filters">
        <button class="btn ok"   data-filter="up"   aria-pressed="false" type="button">UP</button>
        <button class="btn bad"  data-filter="down" aria-pressed="false" type="button">DOWN</button>
        <button class="btn warn" data-filter="warn" aria-pressed="false" type="button">WARN</button>
        <button class="btn"      data-filter="all"  aria-pressed="true"  type="button">All</button>
      </div>
      <div class="search">
        <input id="search" type="search" placeholder="Search name, URL, tag…" aria-label="Search monitors">
      </div>
    </div>

    <div class="section-title">Service Status <span class="badge" id="count-badge"><?= count($monitors) ?> services</span></div>
    <div class="monitors-grid" id="monitors">
      <?php foreach ($monitors as $monitor):
        $config      = $monitor['config'] ?? [];
        $status      = $monitor['status'] ?? 'unknown';
        $statusColor = $status === 'up' ? '#10b981' : ($status === 'down' ? '#ef4444' : '#f59e0b');
        $safeTarget  = htmlspecialchars($monitor['target']);
        $clickUrl    = htmlspecialchars(cleanUrl($monitor['target']));
        $priority    = htmlspecialchars($config['priority'] ?? 'normal');
        $checkType   = strtolower($config['check_type'] ?? 'http');
        $cardId      = 'm-' . htmlspecialchars($monitor['id']);
      ?>
      <div class="monitor-card" id="<?= $cardId ?>" data-name="<?= htmlspecialchars(strtolower($monitor['name'])) ?>" data-url="<?= htmlspecialchars(strtolower($monitor['target'])) ?>" data-status="<?= htmlspecialchars($status) ?>" data-priority="<?= $priority ?>" data-tags="<?= htmlspecialchars(strtolower(($config['tags'] ?? ''))) ?>">
        <div class="monitor-header" tabindex="0" role="button" aria-expanded="false" aria-controls="content-<?= $cardId ?>">
          <div class="header-row">
            <div class="title-block">
              <span class="status-dot" style="background: <?= $statusColor ?>;"></span>
              <div style="min-width:0">
                <div class="monitor-name"><?= htmlspecialchars($monitor['name']) ?></div>
                <div class="monitor-url">
                  <a class="tool-link btn" href="<?= $clickUrl ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:underline dotted"> <?= $safeTarget ?> </a>
                </div>
              </div>
              <span class="badge" title="Check type"><?= strtoupper($checkType) ?></span>
              <span class="badge priority-<?= $priority ?>"><?= ucfirst($priority) ?></span>
            </div>
            <div class="quick">
              <div class="kv"><div class="v" style="color:<?= $statusColor ?>;"><?= strtoupper($status) ?></div><div class="k">Status</div></div>
              <div class="kv"><div class="v"><?= number_format($monitor['uptime'] ?? 0,1) ?>%</div><div class="k">Uptime</div></div>
              <?php if (!empty($monitor['response_time'])): ?>
              <div class="kv"><div class="v"><?= (int)$monitor['response_time'] ?>ms</div><div class="k">Response</div></div>
              <?php endif; ?>
              <div class="expand-icon">▼</div>
            </div>
          </div>
          <!-- quick tools -->
          <div class="tools" aria-hidden="true">
            <a class="tool-link btn" href="<?= $clickUrl ?>" target="_blank" rel="noopener noreferrer">Open</a>
            <button type="button" class="tool-btn btn" data-copy="<?= $clickUrl ?>">Copy URL</button>
            <?php
              $curl = "curl -I " . escapeshellarg(cleanUrl($monitor['target']));
              $pingHost = parse_url(cleanUrl($monitor['target']), PHP_URL_HOST) ?: $monitor['target'];
            ?>
            <button type="button" class="tool-btn btn" data-copy="<?= htmlspecialchars($curl) ?>" title="Copy curl command">Copy curl</button>
            <button type="button" class="tool-btn btn" data-copy="ping -c 4 <?= htmlspecialchars($pingHost) ?>" title="Copy ping command">Copy ping</button>
            <a class="tool-link btn" href="<?= htmlspecialchars($apiBase) ?>?path=monitors&include_chart=true" target="_blank" rel="noopener">Raw JSON</a>
            <a class="tool-link btn" href="#<?= $cardId ?>" title="Share direct link">Share</a>
            <span class="copy-note">Copied!</span>
          </div>
        </div>

        <div class="monitor-content" id="content-<?= $cardId ?>">
          <div class="monitor-content-inner">
            <div class="monitor-badges" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 10px">
              <?php if (isset($monitor['ssl_info'])): ?>
                <?php if ($monitor['ssl_info']['is_expired']): ?><span class="badge ssl-expired">SSL Expired</span>
                <?php elseif ($monitor['ssl_info']['is_expiring_soon']): ?><span class="badge ssl-warning">SSL: <?= (int)$monitor['ssl_info']['days_remaining'] ?>d</span>
                <?php elseif ($monitor['ssl_info']['is_valid']): ?><span class="badge ssl-valid">SSL Valid</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>

            <div class="chart-toolbar" style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-bottom:6px">
              <span class="badge">Response Time</span>
              <button class="btn ghost rt-window" data-window="24" type="button">24h</button>
              <button class="btn ghost rt-window" data-window="168" type="button">7d</button>
              <button class="btn ghost rt-window" data-window="720" type="button">30d</button>
            </div>
            <div class="chart-container"><canvas id="chart-<?= htmlspecialchars($monitor['id']) ?>"></canvas></div>

            <div class="info-grid">
              <div class="info-item"><div class="info-label">Status</div><div class="info-value" style="color:<?= $statusColor ?>;"><?= strtoupper($status) ?></div></div>
              <div class="info-item"><div class="info-label">Uptime (90d)</div><div class="info-value"><?= number_format($monitor['uptime'] ?? 0,2) ?>%</div></div>
              <div class="info-item"><div class="info-label">Response Time</div><div class="info-value"><?= !empty($monitor['response_time']) ? ((int)$monitor['response_time'].'ms') : '—' ?></div></div>
              <div class="info-item"><div class="info-label">Last Check</div><div class="info-value"><?= !empty($monitor['last_check']) ? date('M j, g:i A', strtotime($monitor['last_check'])) : '—' ?></div></div>
              <div class="info-item"><div class="info-label">Check Interval</div><div class="info-value"><?= number_format((($monitor['interval'] ?? 60)/60),1) ?> min</div></div>
              <div class="info-item"><div class="info-label">Method</div><div class="info-value">
                <?php
                  $ct = $config['check_type'] ?? 'http';
                  if ($ct==='ping'||$ct==='icmp') echo 'PING';
                  elseif ($ct==='port'||$ct==='tcp') echo 'TCP';
                  else echo strtoupper($config['check_method'] ?? 'GET');
                ?>
              </div></div>

              <?php if (isset($monitor['ssl_info'])): ?>
                <div class="info-item"><div class="info-label">SSL Issuer</div><div class="info-value"><?= htmlspecialchars($monitor['ssl_info']['issuer']) ?></div></div>
                <div class="info-item"><div class="info-label">SSL Valid From</div><div class="info-value"><?= htmlspecialchars($monitor['ssl_info']['valid_from']) ?></div></div>
                <div class="info-item"><div class="info-label">SSL Valid To</div><div class="info-value"><?= htmlspecialchars($monitor['ssl_info']['valid_to']) ?></div></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($incidents)): ?>
    <div class="incidents-section">
      <h2 class="section-title">Recent Incidents</h2>
      <?php foreach (array_slice($incidents, 0, 6) as $incident): ?>
      <div class="incident-card <?= $incident['status'] === 'resolved' ? 'resolved' : '' ?>">
        <div class="incident-title">
          <?= htmlspecialchars($incident['title']) ?>
          <?php if ($incident['status'] === 'resolved'): ?><span style="color:var(--ok);margin-left:8px">✓ Resolved</span><?php endif; ?>
        </div>
        <div class="incident-time">
          Started: <?= date('M j, g:i A', $incident['time']) ?>
          <?php if (!empty($incident['resolved_time'])): ?> • Resolved: <?= date('M j, g:i A', $incident['resolved_time']) ?><?php endif; ?>
        </div>
        <div class="incident-actions">
          <a class="tool-link btn" href="<?= htmlspecialchars($apiBase) ?>?path=incidents" target="_blank" rel="noopener">View incidents JSON</a>
          <?php if (!empty($incident['link'])): ?>
            <a class="tool-link btn" href="<?= htmlspecialchars($incident['link']) ?>" target="_blank" rel="noopener">Details</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <footer class="footer">
      <p>Uptime Monitor &middot; Real-time infrastructure monitoring</p>
    </footer>
  </div>

<script>
// theme toggle
const root = document.documentElement;
const savedTheme = localStorage.getItem('theme');
if(savedTheme){ document.documentElement.setAttribute('data-theme', savedTheme); }
document.getElementById('toggle-theme').addEventListener('click', (e) => {
  e.stopPropagation();
  const t = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
  root.setAttribute('data-theme', t); localStorage.setItem('theme', t);
});

// base chart defaults
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim() || '#94a3b8';
Chart.defaults.borderColor = 'rgba(255,255,255,.1)';

// PHP -> JS
const monitorsData = <?= json_encode($monitors, JSON_UNESCAPED_SLASHES) ?>;
let allExpanded = false;

// expand/perm-link with guard so tools remain clickable
document.addEventListener('click', e=>{
  // ignore clicks inside the tools row
  if (e.target.closest('.tools')) return;
  const interactive = e.target.closest('a,button,[role="button"].not-header');
  // toggle only when clicking the header (and not an inner interactive control)
  const h = e.target.closest('.monitor-header');
  if(h && !interactive){ toggleCard(h.closest('.monitor-card').id); }
});

function toggleCard(cardId, expandOnly=false){
  const card = document.getElementById(cardId);
  if(!card) return;
  const header = card.querySelector('.monitor-header');
  const expanded = card.classList.contains('expanded');
  if(expandOnly && expanded) return;
  if(expanded && !expandOnly){ card.classList.remove('expanded'); header.setAttribute('aria-expanded','false'); }
  else{
    card.classList.add('expanded'); header.setAttribute('aria-expanded','true');
    if(!card.dataset.chartInitialized){ setTimeout(()=> initializeChart(cardId.replace('m-','')), 60); card.dataset.chartInitialized='1'; }
    if(!expandOnly) history.replaceState(null, '', '#'+cardId);
  }
}

// Expand-all
document.getElementById('expand-all').addEventListener('click', (e)=>{
  e.stopPropagation();
  const cards=[...document.querySelectorAll('.monitor-card')];
  allExpanded = !allExpanded;
  document.getElementById('expand-text').textContent = allExpanded ? 'Collapse All' : 'Expand All';
  cards.forEach(c=>{
    if(allExpanded){ toggleCard(c.id, true); }
    else{ c.classList.remove('expanded'); c.querySelector('.monitor-header').setAttribute('aria-expanded','false'); }
  });
});

// keyboard expand (only when header is focused)
document.addEventListener('keydown', e=>{
  if((e.key==='Enter'||e.key===' ') && e.target.classList.contains('monitor-header')){
    e.preventDefault(); toggleCard(e.target.closest('.monitor-card').id);
  }
});

// copy helpers (no bubbling to header)
function copyText(t, el){
  navigator.clipboard.writeText(t).then(()=>{
    const note = el?.parentElement?.querySelector('.copy-note');
    if(note){ note.style.display='inline'; setTimeout(()=>note.style.display='none', 1000); }
  });
}
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.tool-btn'); if(!btn) return;
  e.stopPropagation();
  const txt = btn.getAttribute('data-copy'); if(txt) copyText(txt, btn);
});

// search & filters
const searchEl = document.getElementById('search');
const countBadge = document.getElementById('count-badge');
let activeFilter = 'all';
function updateVisibility(){
  const q = (searchEl.value || '').toLowerCase();
  let visible=0;
  document.querySelectorAll('.monitor-card').forEach(card=>{
    const st = card.dataset.status || '';
    const passFilter = (activeFilter==='all') ||
      (activeFilter==='up' && st==='up') ||
      (activeFilter==='down' && st==='down') ||
      (activeFilter==='warn' && (st!=='up' && st!=='down'));
    const blob = (card.dataset.name+' '+card.dataset.url+' '+(card.dataset.tags||''));
    const passSearch = blob.includes(q);
    const show = passFilter && passSearch;
    card.style.display = show ? '' : 'none';
    if(show) visible++;
  });
  countBadge.textContent = visible + ' services';
}
searchEl.addEventListener('input', updateVisibility);
document.querySelectorAll('[data-filter]').forEach(btn=>{
  btn.addEventListener('click', (e)=>{
    e.stopPropagation();
    document.querySelectorAll('[data-filter]').forEach(b=>b.setAttribute('aria-pressed','false'));
    btn.setAttribute('aria-pressed','true');
    activeFilter = btn.getAttribute('data-filter'); updateVisibility();
  });
});
updateVisibility();

// CSV export (client-side)
document.getElementById('export-csv').addEventListener('click', (e)=>{
  e.stopPropagation();
  const rows = [['id','name','target','status','uptime','response_time','interval','check_type','priority']];
  monitorsData.forEach(m=>{
    const cfg = m.config||{};
    rows.push([
      m.id, m.name, m.target, m.status, (m.uptime??''), (m.response_time??''), (m.interval??''), (cfg.check_type??''), (cfg.priority??'')
    ]);
  });
  const csv = rows.map(r=>r.map(x=>{
    const s = (x??'').toString().replace(/"/g,'""'); return `"${s}"`;
  }).join(',')).join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download='status_export.csv'; a.click();
  URL.revokeObjectURL(url);
});

// charts
function initializeChart(monitorId){
  const canvas = document.getElementById(`chart-${monitorId}`);
  if(!canvas || canvas.chart) return;
  const m = monitorsData.find(x=>String(x.id)===String(monitorId));
  if(!m) return;
  const series = (m.chart_data || {labels:[],data:[]});
  const ctx = canvas.getContext('2d');
  const gradient = ctx.createLinearGradient(0,0,0,200);
  gradient.addColorStop(0,'rgba(59,130,246,.3)');
  gradient.addColorStop(1,'rgba(59,130,246,0)');
  const labels = (series.labels||[]).map(ts=>new Date(ts));
  const data   = (series.data||[]);
  const chart = new Chart(canvas, {
    type:'line',
    data:{ labels, datasets:[{ label:'Response Time', data, borderColor:getComputedStyle(root).getPropertyValue('--primary').trim()||'#3b82f6', backgroundColor:gradient, borderWidth:2, fill:true, tension:.35, pointRadius:0, spanGaps:true }] },
    options:{
      responsive:true, maintainAspectRatio:false,
      interaction:{ intersect:false, mode:'index' },
      plugins:{ legend:{display:false}, tooltip:{ backgroundColor:'rgba(15,23,42,.9)', borderColor:'rgba(59,130,246,.3)', borderWidth:1, padding:12, displayColors:false,
        callbacks:{ title:(c)=> new Date(c[0].parsed.x).toLocaleString(), label:(c)=> c.parsed.y!=null ? `Response: ${c.parsed.y}ms` : 'Service Down' } } },
      scales:{
        x:{ type:'time', time:{ displayFormats:{ hour:'HH:mm', day:'MMM d' } }, grid:{display:false}, ticks:{ maxTicksLimit: (innerWidth<768?4:6) } },
        y:{ beginAtZero:true, grid:{ color:'rgba(255,255,255,.05)' }, ticks:{ callback:(v)=> v+'ms' } }
      }
    }
  });
  canvas.chart = chart;

  // time window buttons for this card
  const wrap = canvas.closest('.monitor-content-inner');
  wrap.querySelectorAll('.rt-window').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.stopPropagation();
      const hours = parseInt(btn.dataset.window,10);
      const cutoff = new Date(Date.now()-hours*3600000);
      const idx = labels.findIndex(d=>d>=cutoff);
      const l = idx>-1 ? labels.slice(idx) : labels;
      const d = idx>-1 ? data.slice(idx) : data;
      chart.data.labels = l; chart.data.datasets[0].data = d; chart.update('none');
    });
  });
}

// deep link open if hash matches
window.addEventListener('DOMContentLoaded', ()=>{
  const hash = location.hash.replace('#','');
  if(hash){
    const target = document.getElementById(hash);
    if(target){ toggleCard(hash, true); target.scrollIntoView({behavior:'smooth',block:'start'}); }
  }
  // auto-expand first card if none specified
  if(!hash && monitorsData.length>0){ setTimeout(()=>toggleCard('m-'+monitorsData[0].id,true), 80); }
});

// graceful chart failure
window.addEventListener('error',(e)=>{
  if((e.message||'').includes('Chart')) document.querySelectorAll('.chart-container').forEach(c=> c.style.display='none');
});

// responsive tick tweaks
let rsTO; addEventListener('resize', ()=>{
  clearTimeout(rsTO);
  rsTO=setTimeout(()=>{
    (Chart.instances||[]).forEach(ch=>{
      try{ ch.options.scales.x.ticks.maxTicksLimit = innerWidth<768?4:6; ch.update('none'); }catch(_){}
    });
  }, 200);
});

// smooth refresh
function smoothRefresh(){ document.body.style.opacity='0.5'; setTimeout(()=>location.reload(), 280); }
setTimeout(smoothRefresh, 300000);
</script>
</body>
</html>
